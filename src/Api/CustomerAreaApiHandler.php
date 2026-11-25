<?php

declare(strict_types=1);

namespace Whity\Api;

use PDO;
use Whity\Auth\TenantConfinement;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Branding\BrandingService;
use Whity\Core\Branding\HostResolver;
use Whity\Core\Branding\TenantHostRepository;
use Whity\Core\Calendar\BookingService;
use Whity\Core\Calendar\CalendarRejectedException;
use Whity\Core\Calendar\Interval;
use Whity\Core\CustomerArea\CustomerRecords;
use Whity\Core\Identity\MembershipRepository;
use Whity\Core\Log\ScrubbedErrorLog;
use Whity\Core\Messaging\MessagesPresenter;
use Whity\Core\Messaging\MessagingException;
use Whity\Core\Messaging\MessagingService;
use Whity\Core\Messaging\WebChat\WebChatChannel;
use Whity\Core\RateLimit\RateLimitRule;
use Whity\Core\RateLimit\RateLimits;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Sales\Quotes;
use Whity\Core\Sales\SalesRejectedException;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Signup\CustomerSignupService;
use Whity\Core\Signup\WorkspaceSignupSettings;
use Whity\Core\Store\SharedStoreInterface;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;
use Whity\Http\Validation\Field;
use Whity\Http\Validation\FieldValidator;
use Whity\Sdk\Messaging\InboundMessage;

/**
 * The customer area (design section 41, WHIT-1416): a workspace's customers,
 * signed in, see their own bookings, bills, messages and forms at /@handle.
 *
 * ROUTES. `/api/v1/customer-area/{handle}` and below. They bypass
 * EnforceTenantIsolation (anchored shapes there), because the workspace comes
 * from the HANDLE, not from the session's active workspace: one person can be
 * a customer of several workspaces, or staff in one and a customer of
 * another, and opening an area must not switch their team app. So this
 * handler does what the middleware would:
 *
 *  - the session comes from {@see \Whity\Auth\TokenValidator} (revocation,
 *    token epoch and active-membership checks included);
 *  - the person must hold an ACTIVE membership in the handle's workspace
 *    (any kind: staff see the area as a customer too, CA.7);
 *  - a session confined to one workspace's own sign-in provider (#2249) may
 *    open only that workspace's area;
 *  - the handle never decides access by itself (HD.5) and must agree with a
 *    workspace's own host;
 *  - the area must be on (`customer_area.enabled`).
 *
 * WHOSE RECORDS. Every read and write goes through {@see CustomerRecords},
 * which binds the tenant and the person's own contacts. Another customer's id
 * answers 404, the same as an id that does not exist. Staff routes are never
 * called from here, and staff permissions never widen what this shows.
 */
final class CustomerAreaApiHandler
{
    private const SYSTEM_TENANT_ID = 0;
    private const NOT_FOUND = 'This page is not here.';
    private const SIGN_IN = 'Sign in to see your account.';
    private const LEAVE_STAFF = 'You work here, so you leave from My workspaces.';

    /**
     * @param \Closure(): ?array<string, mixed> $claims the validated session's claims, or null
     */
    public function __construct(
        private readonly \Closure $claims,
        private readonly PDO $db,
        private readonly CustomerRecords $records,
        private readonly TenantHostRepository $tenants,
        private readonly HostResolver $hosts,
        private readonly BrandingService $branding,
        private readonly SettingsService $settings,
        private readonly MembershipRepository $memberships,
        private readonly WorkspaceSignupSettings $signupSettings,
        private readonly CustomerSignupService $signup,
        private readonly BookingService $booking,
        private readonly Quotes $quotes,
        private readonly MessagingService $messaging,
        private readonly SharedStoreInterface $store,
        private readonly ?AuditLogger $audit = null,
        /** @var (\Closure(): list<array<string, mixed>>)|null the plugins' customer screens (SDK 1.174) */
        private readonly ?\Closure $pluginScreens = null,
        /** @var (\Closure(int, string, int): bool)|null whether a profile holds a permission in a workspace */
        private readonly ?\Closure $hasPermission = null,
        private readonly ?\Whity\Auth\JwtParser $jwt = null,
    ) {
    }

    // ---- The area itself ----

    /**
     * The workspace's area: brand, whether it is on, who is looking and what
     * they may do, and the tabs to show. Answers signed out too, so the front
     * door and the sign-in page can draw themselves.
     *
     * @param array<string, string> $params
     */
    public function area(Request $request, array $params): Response
    {
        return $this->read($request, $params, function (array $ws, ?array $me): Response {
            $tenantId = $ws['id'];
            $effective = $this->settings->effective($tenantId);
            $signup = $this->signupSettings->get($tenantId);
            $calendar = $this->booking->settingsFor($tenantId);
            $chatOn = $this->chatOn($tenantId);
            $data = [
                'workspace' => ['name' => $ws['name'], 'handle' => $ws['handle'], 'brand' => $ws['brand']],
                'enabled' => $ws['enabled'],
                'signup' => $signup['mode'],
                'booking' => $calendar->bookingPageEnabled,
                'chat' => $chatOn,
                'welcome' => self::bilingual($effective, SettingsRegistry::CUSTOMER_AREA_WELCOME_EN, SettingsRegistry::CUSTOMER_AREA_WELCOME_AR),
                'me' => null,
                'tabs' => [],
            ];
            if ($me !== null && $ws['enabled']) {
                $data['me'] = [
                    'name' => $me['name'],
                    'email' => $me['email'],
                    'state' => $me['state'],
                    'staff' => $me['staff'],
                ];
                if ($me['state'] === 'customer') {
                    $this->link($tenantId, $me['profile_id']);
                    $pluginTabs = array_map(static fn (array $sc): array => [
                        'id' => 'x:' . $sc['id'],
                        'count' => 0,
                        'label' => $sc['label'],
                        'icon' => $sc['icon'],
                        'count_path' => $sc['count']['apiPath'] ?? null,
                    ], $this->visibleScreens($tenantId, $me['profile_id'], 'tab'));
                    // Home, then the plugins' tabs by their order, then the core tabs (CA.13).
                    $data['tabs'] = array_merge($pluginTabs, $this->tabs($tenantId, $me['profile_id'], $effective, $calendar->bookingPageEnabled, $chatOn));
                }
            }

            return Response::json(['data' => $data]);
        }, true);
    }

    /**
     * Join the workspace as a customer with the identity already proved (CA.6):
     * one tap when sign-up is open, a request when it needs approval.
     *
     * @param array<string, string> $params
     */
    public function join(Request $request, array $params): Response
    {
        return $this->write($request, $params, function (array $ws, array $me): Response {
            if ($me['state'] === 'customer') {
                return Response::json(['data' => ['result' => 'joined']]);
            }
            $email = $this->verifiedEmailFor($me);
            if ($email === null) {
                return Response::error('Confirm your email address first, then try again.', 409);
            }
            $out = $this->signup->joinVerified($ws['id'], $me['profile_id'], $email, $me['name']);
            $result = match ($out['result']) {
                CustomerSignupService::CONFIRM_JOINED, CustomerSignupService::CONFIRM_ALREADY_MEMBER => 'joined',
                CustomerSignupService::CONFIRM_PENDING => 'pending',
                default => 'closed',
            };
            if ($result === 'joined') {
                $this->link($ws['id'], $me['profile_id']);
            }

            return Response::json(['data' => ['result' => $result]]);
        }, false);
    }

    /**
     * Home (CA.14): what is waiting, the next booking, the latest message.
     *
     * @param array<string, string> $params
     */
    public function home(Request $request, array $params): Response
    {
        return $this->member($request, $params, function (array $ws, array $me): Response {
            $tenantId = $ws['id'];
            $now = $this->now();
            $waiting = array_values(array_filter(
                $this->records->bills($tenantId, $me['profile_id']),
                static fn (array $b): bool => $b['waiting'] === true
            ));
            $upcoming = $this->records->bookings($tenantId, $me['profile_id'], 'upcoming', $now);
            $conversations = $this->messaging->conversations()->listForCustomer($tenantId, $me['profile_id'], 1);
            $latest = null;
            if ($conversations !== []) {
                $c = $conversations[0];
                $messages = $this->messaging->messages()->forConversation($tenantId, (int) $c['id'], null, MessagingService::pageSize(), true);
                $last = $messages !== [] ? MessagesPresenter::customerMessage($messages[count($messages) - 1]) : null;
                $latest = ['id' => (int) $c['id'], 'last_message_at' => (string) $c['last_message_at'], 'message' => $last];
            }

            $cards = array_map(static fn (array $sc): array => [
                'id' => $sc['id'],
                'label' => $sc['label'],
                'blocks' => $sc['blocks'] ?? [],
                'api_paths' => \Whity\Core\Plugin\PluginCustomerRoutes::pathsOf((string) $sc['namespace']),
            ], $this->visibleScreens($tenantId, $me['profile_id'], 'home'));

            return Response::json(['data' => [
                'cards' => $cards,
                'waiting' => array_slice($waiting, 0, 10),
                'time_zone' => $this->booking->settingsFor($tenantId)->zone->getName(),
                'next_booking' => $upcoming[0] ?? null,
                'latest_message' => $latest,
            ]]);
        });
    }

    // ---- Bookings (CA.13, §8.2) ----

    /** @param array<string, string> $params */
    public function bookings(Request $request, array $params): Response
    {
        return $this->member($request, $params, function (array $ws, array $me) use ($request): Response {
            $when = (PublicBookingApiHandler::query($request)['when'] ?? 'upcoming') === 'past' ? 'past' : 'upcoming';

            return Response::json(['data' => [
                'time_zone' => $this->booking->settingsFor($ws['id'])->zone->getName(),
                'bookings' => $this->records->bookings($ws['id'], $me['profile_id'], $when, $this->now()),
            ]]);
        });
    }

    /** @param array<string, string> $params */
    public function booking(Request $request, array $params): Response
    {
        return $this->ownBooking($request, $params, false, function (int $tenantId, int $id): Response {
            $booking = $this->booking->bookingOrFail($tenantId, $id);

            return Response::json(['data' => PublicBookingApiHandler::forCustomer($booking) + [
                'room_name' => is_string($booking['room_name'] ?? null) ? $booking['room_name'] : null,
                'can_change' => in_array($booking['status'], \Whity\Core\Calendar\BookingRepository::LIVE, true)
                    && (string) $booking['starts_at'] > Interval::toApi($this->booking->now()),
                'time_zone' => $this->booking->settingsFor($tenantId)->zone->getName(),
            ]]);
        });
    }

    /** @param array<string, string> $params */
    public function bookingSlots(Request $request, array $params): Response
    {
        return $this->ownBooking($request, $params, false, function (int $tenantId, int $id) use ($request): Response {
            $booking = $this->booking->bookingOrFail($tenantId, $id);
            [$from, $to] = PublicBookingApiHandler::range(PublicBookingApiHandler::query($request));
            if (count($booking['items']) > 1) {
                $items = array_values(array_map(static fn (array $i): array => ['service_id' => (int) $i['service_id'], 'profile_id' => (int) $i['profile_id']], $booking['items']));
                $slots = $this->booking->availabilityForMany($tenantId, $items, false, $from, $to, true, $id, false);
            } else {
                $slots = $this->booking->availability($tenantId, (int) $booking['service_id'], (int) $booking['profile_id'], $from, $to, true, $id);
            }

            return Response::json(['data' => array_map(static fn (array $s): array => ['starts_at' => $s['starts_at'], 'ends_at' => $s['ends_at']], $slots)]);
        });
    }

    /**
     * Move to another free time, under the same rules as the booking's own
     * link (the notice and the furthest day ahead, the person's hours).
     *
     * @param array<string, string> $params
     */
    public function moveBooking(Request $request, array $params): Response
    {
        return $this->ownBooking($request, $params, true, function (int $tenantId, int $id) use ($request): Response {
            $start = Interval::fromApi(JsonBody::parsed($request)['starts_at'] ?? null)
                ?? throw new CalendarRejectedException('Choose a time', 422);
            $booking = $this->booking->move($tenantId, $id, $start, null, true);

            return Response::json(['data' => PublicBookingApiHandler::forCustomer($booking)]);
        });
    }

    /** @param array<string, string> $params */
    public function cancelBooking(Request $request, array $params): Response
    {
        return $this->ownBooking($request, $params, true, function (int $tenantId, int $id) use ($request): Response {
            $checked = FieldValidator::check(JsonBody::parsed($request), ['reason' => Field::text()->truncate(500)]);
            $booking = $this->booking->cancel($tenantId, $id, 'customer', $checked->value('reason'));

            return Response::json(['data' => PublicBookingApiHandler::forCustomer($booking)]);
        });
    }

    // ---- Bills (CA.15, §8.3) ----

    /** @param array<string, string> $params */
    public function bills(Request $request, array $params): Response
    {
        return $this->member($request, $params, function (array $ws, array $me) use ($request): Response {
            $kind = PublicBookingApiHandler::query($request)['kind'] ?? '';
            $kind = in_array($kind, ['quote', 'invoice', 'credit_note', 'receipt'], true) ? $kind : '';
            $effective = $this->settings->effective($ws['id']);

            return Response::json(['data' => [
                'bills' => $this->records->bills($ws['id'], $me['profile_id'], $kind),
                'how_to_pay' => self::bilingual($effective, SettingsRegistry::CUSTOMER_AREA_HOW_TO_PAY_EN, SettingsRegistry::CUSTOMER_AREA_HOW_TO_PAY_AR),
            ]]);
        });
    }

    /** @param array<string, string> $params */
    public function quote(Request $request, array $params): Response
    {
        return $this->member($request, $params, function (array $ws, array $me) use ($params): Response {
            $id = self::id($params);
            if (!$this->records->ownsQuote($ws['id'], $me['profile_id'], $id)) {
                return Response::error(self::NOT_FOUND, 404);
            }

            return Response::json(['data' => ['id' => $id] + $this->quotes->publicView($ws['id'], $id)]);
        });
    }

    /** @param array<string, string> $params */
    public function acceptQuote(Request $request, array $params): Response
    {
        return $this->decideQuote($request, $params, true);
    }

    /** @param array<string, string> $params */
    public function declineQuote(Request $request, array $params): Response
    {
        return $this->decideQuote($request, $params, false);
    }

    /** @param array<string, string> $params */
    public function invoice(Request $request, array $params): Response
    {
        return $this->member($request, $params, function (array $ws, array $me) use ($params): Response {
            $invoice = $this->records->invoice($ws['id'], $me['profile_id'], self::id($params));
            if ($invoice === null) {
                return Response::error(self::NOT_FOUND, 404);
            }
            $effective = $this->settings->effective($ws['id']);

            return Response::json(['data' => $invoice + [
                'how_to_pay' => self::bilingual($effective, SettingsRegistry::CUSTOMER_AREA_HOW_TO_PAY_EN, SettingsRegistry::CUSTOMER_AREA_HOW_TO_PAY_AR),
            ]]);
        });
    }

    /** @param array<string, string> $params */
    public function receipt(Request $request, array $params): Response
    {
        return $this->member($request, $params, function (array $ws, array $me) use ($params): Response {
            $receipt = $this->records->receipt($ws['id'], $me['profile_id'], self::id($params));

            return $receipt === null ? Response::error(self::NOT_FOUND, 404) : Response::json(['data' => $receipt]);
        });
    }

    // ---- Messages (CA.16, §8.4) ----

    /** @param array<string, string> $params */
    public function conversations(Request $request, array $params): Response
    {
        return $this->member($request, $params, function (array $ws, array $me): Response {
            $rows = $this->messaging->conversations()->listForCustomer($ws['id'], $me['profile_id'], 50);

            return Response::json(['data' => [
                'can_start' => $this->chatOn($ws['id']),
                'conversations' => array_map(static fn (array $row): array => [
                    'id' => (int) $row['id'],
                    'subject' => $row['subject'] !== null ? (string) $row['subject'] : null,
                    'status' => (string) $row['status'],
                    'last_message_at' => (string) $row['last_message_at'],
                ], $rows),
            ]]);
        });
    }

    /** @param array<string, string> $params */
    public function conversation(Request $request, array $params): Response
    {
        return $this->member($request, $params, function (array $ws, array $me) use ($request, $params): Response {
            $c = $this->ownConversation($ws['id'], $me['profile_id'], self::id($params));
            if ($c === null) {
                return Response::error(self::NOT_FOUND, 404);
            }
            $after = PublicBookingApiHandler::query($request)['after'] ?? '';
            $messages = $this->messaging->messages()->forConversation(
                $ws['id'],
                (int) $c['id'],
                ctype_digit($after) ? (int) $after : null,
                MessagingService::pageSize(),
                true
            );

            return Response::json(['data' => [
                'id' => (int) $c['id'],
                'subject' => $c['subject'] !== null ? (string) $c['subject'] : null,
                'status' => (string) $c['status'],
                'messages' => array_map([MessagesPresenter::class, 'customerMessage'], $messages),
            ]]);
        });
    }

    /**
     * Write to the workspace: continues the customer's open chat or starts one.
     *
     * @param array<string, string> $params
     */
    public function startConversation(Request $request, array $params): Response
    {
        return $this->write($request, $params, function (array $ws, array $me) use ($request): Response {
            if (!$this->chatOn($ws['id'])) {
                return Response::error('This business does not take messages here yet.', 404);
            }
            $text = $this->messageText($request);
            if ($text === null) {
                return Response::error('Write a message first.', 422);
            }
            $message = $this->messaging->ingest(
                $ws['id'],
                WebChatChannel::KEY,
                new InboundMessage(from: 'profile:' . $me['profile_id'], fromName: $me['name'] !== '' ? $me['name'] : null, body: $text),
                null,
                $me['profile_id']
            );

            return Response::json(['data' => $message !== null ? [
                'conversation_id' => (int) $message['conversation_id'],
                'message' => MessagesPresenter::customerMessage($message),
            ] : null], 201);
        });
    }

    /** @param array<string, string> $params */
    public function sendMessage(Request $request, array $params): Response
    {
        return $this->write($request, $params, function (array $ws, array $me) use ($request, $params): Response {
            $c = $this->ownConversation($ws['id'], $me['profile_id'], self::id($params));
            if ($c === null) {
                return Response::error(self::NOT_FOUND, 404);
            }
            $text = $this->messageText($request);
            if ($text === null) {
                return Response::error('Write a message first.', 422);
            }
            $message = $this->messaging->ingest(
                $ws['id'],
                (string) $c['channel'],
                new InboundMessage(from: (string) $c['contact_address'], fromName: $me['name'] !== '' ? $me['name'] : null, body: $text),
                (int) $c['id'],
                $me['profile_id']
            );

            return Response::json(['data' => $message !== null ? MessagesPresenter::customerMessage($message) : null], 201);
        });
    }

    // ---- Plugin screens (SDK 1.174, CA.17) ----

    /**
     * One plugin screen the customer may see: its blocks or embed page.
     *
     * @param array<string, string> $params
     */
    public function screen(Request $request, array $params): Response
    {
        return $this->member($request, $params, function (array $ws, array $me) use ($params): Response {
            $screen = $this->findScreen($ws['id'], $me['profile_id'], (string) ($params['screen'] ?? ''));
            if ($screen === null) {
                return Response::error(self::NOT_FOUND, 404);
            }
            $out = [
                'id' => $screen['id'],
                'label' => $screen['label'],
                'placement' => $screen['placement'],
                'screen' => $screen['screen'],
                'icon' => $screen['icon'],
                // Where the screen's token goes: the plugin's customer routes only.
                'api_paths' => \Whity\Core\Plugin\PluginCustomerRoutes::pathsOf((string) $screen['namespace']),
            ];
            if ($screen['screen'] === 'blocks') {
                $out['blocks'] = $screen['blocks'] ?? [];
            } else {
                $out['embed'] = $screen['embed'] ?? [];
            }
            if (isset($screen['count'])) {
                $out['count'] = $screen['count'];
            }

            return Response::json(['data' => $out]);
        });
    }

    /**
     * A five-minute token for one plugin screen (CA.18): bound to this
     * workspace and marked for customers, it opens only that plugin's routes
     * declared `'customer' => true`. Never sent to the plugin's frame.
     *
     * @param array<string, string> $params
     */
    public function screenToken(Request $request, array $params): Response
    {
        return $this->member($request, $params, function (array $ws, array $me) use ($params): Response {
            $screen = $this->findScreen($ws['id'], $me['profile_id'], (string) ($params['screen'] ?? ''));
            $claims = ($this->claims)();
            if ($screen === null || $this->jwt === null || !is_array($claims)) {
                return Response::error(self::NOT_FOUND, 404);
            }
            $issued = \Whity\Core\Plugin\PluginFrameTokens::issueForCustomer($this->jwt, $claims, (string) $screen['id'], (string) $screen['namespace'], $ws['id']);

            return Response::json(['data' => ['token' => $issued['token'], 'expires_in' => $issued['expiresIn']]]);
        });
    }

    // ---- Account (§8.5) ----

    /** @param array<string, string> $params */
    public function account(Request $request, array $params): Response
    {
        return $this->member($request, $params, function (array $ws, array $me): Response {
            return Response::json(['data' => [
                'name' => $me['name'],
                'email' => $me['email'],
                'staff' => $me['staff'],
                'forms' => $this->records->formSubmissions($ws['id'], $me['profile_id']),
                'other_places' => $this->otherPlaces($me['profile_id'], $ws['id']),
            ]]);
        });
    }

    /**
     * Leave the workspace as a customer (MW.4): the customer membership ends,
     * the business keeps its records. Staff leave from My workspaces.
     *
     * @param array<string, string> $params
     */
    public function leave(Request $request, array $params): Response
    {
        return $this->write($request, $params, function (array $ws, array $me): Response {
            if ($me['state'] !== 'customer') {
                return Response::error(self::NOT_FOUND, 404);
            }
            if ($me['staff']) {
                return Response::error(self::LEAVE_STAFF, 409);
            }
            $stmt = $this->db->prepare(
                "SELECT id FROM memberships WHERE tenant_id = :tid AND profile_id = :pid AND kind = 'customer'"
            );
            $stmt->execute([':tid' => $ws['id'], ':pid' => $me['profile_id']]);
            foreach (array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)) as $membershipId) {
                $this->memberships->delete($membershipId, $ws['id']);
            }
            $this->audit?->record('customer_area.left', [
                'tenant_id' => $ws['id'],
                'actor_user_id' => $me['profile_id'],
                'target_type' => 'profile',
                'target_id' => $me['profile_id'],
            ]);

            return Response::json(['data' => ['result' => 'left']]);
        });
    }

    // ---- Guards ----

    /**
     * @param array<string, string> $params
     * @param callable(array<string, mixed>, ?array<string, mixed>): Response $work
     */
    private function read(Request $request, array $params, callable $work, bool $signedOutOk): Response
    {
        return $this->run($request, $params, RateLimits::customerAreaReadPerPerson(), $work, $signedOutOk);
    }

    /**
     * Signed in AND a customer (any active membership) of an area that is on.
     *
     * @param array<string, string> $params
     * @param callable(array<string, mixed>, array<string, mixed>): Response $work
     */
    private function member(Request $request, array $params, callable $work): Response
    {
        return $this->read($request, $params, function (array $ws, ?array $me) use ($work): Response {
            if ($me === null) {
                return Response::error(self::SIGN_IN, 401);
            }
            if (!$ws['enabled'] || $me['state'] !== 'customer') {
                return Response::error(self::NOT_FOUND, 404);
            }

            return $work($ws, $me);
        }, false);
    }

    /**
     * A write. `$mustBeMember` is false only for joining.
     *
     * @param array<string, string> $params
     * @param callable(array<string, mixed>, array<string, mixed>): Response $work
     */
    private function write(Request $request, array $params, callable $work, bool $mustBeMember = true): Response
    {
        return $this->run($request, $params, RateLimits::customerAreaWritePerPerson(), function (array $ws, ?array $me) use ($work, $mustBeMember): Response {
            if ($me === null) {
                return Response::error(self::SIGN_IN, 401);
            }
            if (!$ws['enabled'] || ($mustBeMember && $me['state'] !== 'customer')) {
                return Response::error(self::NOT_FOUND, 404);
            }

            return $work($ws, $me);
        }, false);
    }

    /**
     * @param array<string, string> $params
     * @param callable(int, int): Response $work
     */
    private function ownBooking(Request $request, array $params, bool $isWrite, callable $work): Response
    {
        $inner = function (array $ws, array $me) use ($params, $work): Response {
            $id = self::id($params);
            if (!$this->records->ownsBooking($ws['id'], $me['profile_id'], $id)) {
                return Response::error(self::NOT_FOUND, 404);
            }

            return $work($ws['id'], $id);
        };

        return $isWrite ? $this->write($request, $params, $inner) : $this->member($request, $params, $inner);
    }

    /** @param array<string, string> $params */
    private function decideQuote(Request $request, array $params, bool $accepted): Response
    {
        return $this->write($request, $params, function (array $ws, array $me) use ($request, $params, $accepted): Response {
            $id = self::id($params);
            if (!$this->records->ownsQuote($ws['id'], $me['profile_id'], $id)) {
                return Response::error(self::NOT_FOUND, 404);
            }
            $checked = FieldValidator::check(JsonBody::parsed($request), [
                'reason' => Field::text()->max(1000),
            ]);
            if (!$checked->ok()) {
                return $checked->refusal('Your answer could not be sent');
            }
            // The signed-in customer answers in their own name.
            $this->quotes->decide($ws['id'], $id, $accepted, $me['name'] !== '' ? $me['name'] : $me['email'], $checked->value('reason'), null, true);

            return Response::json(['data' => ['id' => $id] + $this->quotes->publicView($ws['id'], $id)]);
        });
    }

    /**
     * Resolve the workspace and the person, rate-limit, enter the tenant, run.
     *
     * @param array<string, string> $params
     * @param callable(array<string, mixed>, ?array<string, mixed>): Response $work
     */
    private function run(Request $request, array $params, RateLimitRule $rule, callable $work, bool $signedOutOk): Response
    {
        $answer = (function () use ($request, $params, $rule, $work, $signedOutOk): Response {
            try {
                $claims = ($this->claims)();
                $profileId = is_array($claims) && is_int($claims['profile_id'] ?? null) ? $claims['profile_id'] : null;
                $key = $profileId !== null ? 'p' . $profileId : 'ip' . (\Whity\Core\RateLimit\ClientIp::fromRequest($request) ?? '');
                $limited = $rule->take($this->store, 'customer-area:' . $key);
                if ($limited !== null) {
                    return $limited;
                }
                $ws = $this->workspace($request, $params);
                if ($ws === null) {
                    return Response::error(self::NOT_FOUND, 404);
                }
                if ($profileId === null && !$signedOutOk) {
                    return Response::error(self::SIGN_IN, 401);
                }
                // #2249: a session from one workspace's own sign-in provider stays in that workspace.
                if ($claims !== null && ($confined = TenantConfinement::of($claims)) !== null && $confined !== $ws['id']) {
                    return $signedOutOk ? $work($ws, null) : Response::error(self::SIGN_IN, 401);
                }
                if (TenantContext::getTenantId() !== $ws['id']) {
                    TenantContext::setTenantId($ws['id']);
                }

                return $work($ws, $profileId !== null ? $this->person($ws['id'], $profileId, $claims) : null);
            } catch (CalendarRejectedException $e) {
                return $e->status === 404 ? Response::error(self::NOT_FOUND, 404) : Response::error($e->clientMessage, $e->status, $e->extra);
            } catch (SalesRejectedException $e) {
                return $e->status === 404 ? Response::error(self::NOT_FOUND, 404) : Response::error($e->clientMessage, $e->status, $e->extra);
            } catch (MessagingException $e) {
                return Response::error($e->plain(), 422);
            } catch (\Throwable $e) {
                ScrubbedErrorLog::failure('[CustomerAreaApiHandler]', $e);

                return Response::error('Something went wrong. Please try again.', 500);
            }
        })();

        return $answer->withHeaders(['Cache-Control' => 'private, no-store', 'Vary' => 'Cookie']);
    }

    /**
     * The workspace the handle names, or null: not found, the system
     * workspace, or another workspace's own host.
     *
     * @param array<string, string> $params
     * @return array{id: int, name: string, handle: string, brand: array<string, mixed>, enabled: bool}|null
     */
    private function workspace(Request $request, array $params): ?array
    {
        $handle = strtolower(trim((string) ($params['handle'] ?? '')));
        if ($handle === '' || strlen($handle) > 64 || preg_match('/^[a-z0-9][a-z0-9-]*$/', $handle) !== 1) {
            return null;
        }
        $tenantId = $this->tenants->findIdBySlug($handle);
        if ($tenantId === null || $tenantId === self::SYSTEM_TENANT_ID) {
            return null;
        }
        $hostTenant = $this->hosts->resolveTenantIdByHost(\Whity\Core\Http\RequestHost::fromRequest($request));
        if ($hostTenant !== null && $hostTenant !== $tenantId) {
            return null;
        }
        $name = $this->tenants->findNameById($tenantId) ?? $handle;
        $enabled = ($this->settings->effective($tenantId)[SettingsRegistry::CUSTOMER_AREA_ENABLED] ?? 'true') === 'true';

        return [
            'id' => $tenantId,
            'name' => $name,
            'handle' => $this->tenants->findSlugById($tenantId) ?? $handle,
            'brand' => $this->branding->forPublicPage($tenantId, $name),
            'enabled' => $enabled,
        ];
    }

    /**
     * Who is looking, in this workspace: `customer` with any active
     * membership, `pending` while a sign-up waits for approval, else `none`.
     *
     * @param array<string, mixed> $claims
     * @return array{profile_id: int, name: string, email: string, state: string, staff: bool}
     */
    private function person(int $tenantId, int $profileId, array $claims): array
    {
        $stmt = $this->db->prepare(
            "SELECT kind FROM memberships WHERE tenant_id = :tid AND profile_id = :pid AND status = 'active'"
        );
        $stmt->execute([':tid' => $tenantId, ':pid' => $profileId]);
        $kinds = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $state = $kinds !== [] ? 'customer' : 'none';
        if ($state === 'none') {
            $pending = $this->db->prepare(
                "SELECT 1 FROM customer_signups WHERE tenant_id = :tid AND profile_id = :pid AND status = 'pending'"
            );
            $pending->execute([':tid' => $tenantId, ':pid' => $profileId]);
            $state = $pending->fetchColumn() !== false ? 'pending' : 'none';
        }
        // profiles is a sanctioned GLOBAL identity table (ADR 0005); the id is the caller's own, from their validated session.
        $p = $this->db->prepare('SELECT display_name FROM profiles WHERE id = :id');
        $p->execute([':id' => $profileId]);
        $name = trim((string) ($p->fetchColumn() ?: ''));
        $email = is_string($claims['email'] ?? null) ? strtolower(trim($claims['email'])) : '';

        return [
            'profile_id' => $profileId,
            'name' => mb_substr($name, 0, 200),
            'email' => $email,
            'state' => $state,
            'staff' => in_array(MembershipRepository::KIND_MEMBER, $kinds, true),
        ];
    }

    // ---- Helpers ----

    /**
     * The tabs to show between Home and Account, with counts (CA.13).
     *
     * @param array<string, string> $effective
     * @return list<array{id: string, count: int}>
     */
    private function tabs(int $tenantId, int $profileId, array $effective, bool $bookingOn, bool $chatOn): array
    {
        $hidden = json_decode($effective[SettingsRegistry::CUSTOMER_AREA_HIDDEN_TABS] ?? '[]', true);
        $hidden = is_array($hidden) ? $hidden : [];
        $order = json_decode($effective[SettingsRegistry::CUSTOMER_AREA_TAB_ORDER] ?? '[]', true);
        $order = is_array($order) ? array_values(array_intersect($order, SettingsRegistry::CUSTOMER_AREA_TABS)) : [];
        $order = array_values(array_unique(array_merge($order, SettingsRegistry::CUSTOMER_AREA_TABS)));

        $tabs = [];
        foreach ($order as $id) {
            if (in_array($id, $hidden, true)) {
                continue;
            }
            $tab = match ($id) {
                'bookings' => $bookingOn || $this->records->hasBookings($tenantId, $profileId) ? ['id' => 'bookings', 'count' => 0] : null,
                'bills' => ($bills = $this->records->bills($tenantId, $profileId)) !== []
                    ? ['id' => 'bills', 'count' => count(array_filter($bills, static fn (array $b): bool => $b['waiting'] === true))]
                    : null,
                'messages' => $chatOn || $this->messaging->conversations()->listForCustomer($tenantId, $profileId, 1) !== []
                    ? ['id' => 'messages', 'count' => 0]
                    : null,
                default => null,
            };
            if ($tab !== null) {
                $tabs[] = $tab;
            }
        }

        return $tabs;
    }

    /**
     * The plugin screens of one placement the person may see here, by order.
     *
     * @return list<array<string, mixed>>
     */
    private function visibleScreens(int $tenantId, int $profileId, ?string $placement): array
    {
        if ($this->pluginScreens === null || $this->hasPermission === null) {
            return [];
        }
        $out = array_values(array_filter(
            ($this->pluginScreens)(),
            fn (array $sc): bool => ($placement === null || $sc['placement'] === $placement)
                && ($this->hasPermission)($profileId, (string) $sc['requiredPermission'], $tenantId)
        ));
        usort($out, static fn (array $a, array $b): int => [(int) $a['order'], (string) $a['id']] <=> [(int) $b['order'], (string) $b['id']]);

        return $out;
    }

    /** @return array<string, mixed>|null */
    private function findScreen(int $tenantId, int $profileId, string $id): ?array
    {
        if ($id === '' || preg_match('/^[a-z][a-z0-9-]*$/', $id) !== 1) {
            return null;
        }
        foreach ($this->visibleScreens($tenantId, $profileId, null) as $screen) {
            if ($screen['id'] === $id) {
                return $screen;
            }
        }

        return null;
    }

    /** Link contacts on a verified address, and say so in the audit log (CA.9). */
    private function link(int $tenantId, int $profileId): void
    {
        $linked = $this->records->linkVerified($tenantId, $profileId);
        foreach ($linked as $contactId) {
            $this->audit?->record('customer_area.contact_linked', [
                'tenant_id' => $tenantId,
                'actor_user_id' => $profileId,
                'target_type' => 'contact',
                'target_id' => $contactId,
                'metadata' => ['by' => 'verified_email'],
            ]);
        }
    }

    /** @param array<string, mixed> $me */
    private function verifiedEmailFor(array $me): ?string
    {
        $verified = $this->records->verifiedEmails($me['profile_id']);
        if ($me['email'] !== '' && in_array($me['email'], $verified, true)) {
            return $me['email'];
        }

        return $verified[0] ?? null;
    }

    /** @return array<string, mixed>|null */
    private function ownConversation(int $tenantId, int $profileId, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $c = $this->messaging->conversations()->find($tenantId, $id);

        return $c !== null && (int) ($c['customer_profile_id'] ?? 0) === $profileId ? $c : null;
    }

    private function messageText(Request $request): ?string
    {
        $checked = FieldValidator::check(JsonBody::parsed($request), ['message' => Field::text()->required()->max(4000)]);
        if (!$checked->ok()) {
            return null;
        }
        $text = trim($checked->value('message'));

        return $text !== '' ? $text : null;
    }

    private function chatOn(int $tenantId): bool
    {
        return $this->messaging->channels()->get(WebChatChannel::KEY)?->isEnabled($tenantId) === true;
    }

    /**
     * The other workspaces the person is a customer of (MW.2), for Account.
     *
     * @return list<array{name: string, handle: string}>
     */
    private function otherPlaces(int $profileId, int $tenantId): array
    {
        // Not tenant-bound on purpose: the caller's OWN memberships across workspaces (profile_id from their validated session), like /api/v1/me; only names and handles leave
        $stmt = $this->db->prepare(
            "SELECT t.name, t.slug FROM memberships m JOIN tenants t ON t.id = m.tenant_id
              WHERE m.profile_id = :pid AND m.status = 'active' AND m.kind = 'customer' AND m.tenant_id <> :tid AND m.tenant_id <> 0
              ORDER BY t.name"
        );
        $stmt->execute([':pid' => $profileId, ':tid' => $tenantId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (is_string($r['slug']) && $r['slug'] !== '') {
                $out[$r['slug']] = ['name' => (string) $r['name'], 'handle' => $r['slug']];
            }
        }

        return array_values($out);
    }

    /**
     * @param array<string, string> $effective
     * @return array{en: string, ar: string}
     */
    private static function bilingual(array $effective, string $en, string $ar): array
    {
        return ['en' => (string) ($effective[$en] ?? ''), 'ar' => (string) ($effective[$ar] ?? '')];
    }

    /** @param array<string, string> $params */
    private static function id(array $params): int
    {
        $raw = (string) ($params['id'] ?? '');

        return ctype_digit($raw) && strlen($raw) <= 18 ? (int) $raw : 0;
    }

    private function now(): string
    {
        return Interval::toDb($this->booking->now());
    }
}
