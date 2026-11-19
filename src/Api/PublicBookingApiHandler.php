<?php

declare(strict_types=1);

namespace Whity\Api;

use DateTimeImmutable;
use Whity\Core\Branding\BrandingService;
use Whity\Core\Branding\HostResolver;
use Whity\Core\Branding\TenantHostRepository;
use Whity\Core\Calendar\BookingRepository;
use Whity\Core\Calendar\BookingService;
use Whity\Core\Calendar\BookingVisitor;
use Whity\Core\Calendar\CalendarFeed;
use Whity\Core\Calendar\CalendarRejectedException;
use Whity\Core\Calendar\CalendarRepository;
use Whity\Core\Calendar\Ics;
use Whity\Core\Calendar\Interval;
use Whity\Core\Calendar\ManageLinks;
use Whity\Core\Calendar\ServiceChoices;
use Whity\Core\Calendar\ServiceRepository;
use Whity\Core\i18n\LocalizedText;
use Whity\Core\Plugin\PublicForm\BotCheck;
use Whity\Core\RateLimit\ClientIp;
use Whity\Core\RateLimit\RateLimitRule;
use Whity\Core\RateLimit\RateLimits;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Store\SharedStoreInterface;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * The public booking page, the customer's manage link and the staff ICS feed
 * (WHIT-942, ADR 0017 section 6). Unauthenticated.
 *
 *   GET  /api/v1/public/booking/{workspace}                 services, staff, settings
 *   GET  /api/v1/public/booking/{workspace}/slots           free times
 *   GET  /api/v1/public/booking/{workspace}/me              the visitor's own name and email
 *   POST /api/v1/public/booking/{workspace}/bookings        book
 *   GET  /api/v1/public/bookings/{token}                    the customer's booking
 *   GET  /api/v1/public/bookings/{token}/slots              free times to move to
 *   POST /api/v1/public/bookings/{token}/move               move it
 *   POST /api/v1/public/bookings/{token}/cancel             cancel it
 *   GET  /api/v1/public/bookings/{token}/ics                the booking as .ics
 *   GET  /api/v1/public/calendar/feeds/{token}.ics          a person's feed
 *
 * WHICH WORKSPACE
 * Only from the path: the workspace slug (checked against the Host, as the
 * customer sign-up is), or a token that names its own booking or person. An
 * unknown workspace, one whose booking page is off, and a Host naming another
 * workspace all get the same 404.
 *
 * ABUSE
 * Per-IP limits are counted before anything is looked up. Then: a per-
 * workspace daily ceiling, a hidden field people never fill, a minimum time
 * on the page, at most three open future bookings for one email in a
 * workspace, and the instance's bot check when one is set up. Over a limit
 * is 429 with Retry-After.
 */
final class PublicBookingApiHandler
{
    private const OPEN_PER_EMAIL_MAX = 3;
    private const MIN_FILL_MS = 3000;
    private const MAX_RANGE_DAYS = 14;
    private const SYSTEM_TENANT_ID = 0;
    private const NOT_FOUND = 'This booking page is not available';
    private const LINK_INVALID = 'This link is invalid or has expired';

    public function __construct(
        private readonly BookingService $booking,
        private readonly CalendarRepository $calendar,
        private readonly ServiceRepository $services,
        private readonly BookingRepository $bookingRows,
        private readonly ManageLinks $links,
        private readonly CalendarFeed $feed,
        private readonly TenantHostRepository $tenants,
        private readonly HostResolver $hosts,
        private readonly BotCheck $bot,
        private readonly SharedStoreInterface $store,
        // WHIT-1014: the workspace's own brand on its public pages. Null: none sent.
        private readonly ?BrandingService $branding = null,
        // WHIT-1179: the signed-in visitor's own account. Null: never prefilled.
        private readonly ?BookingVisitor $visitor = null,
    ) {
    }

    // ── the page ────────────────────────────────────────────────────────

    /** @param array<string, string> $params */
    public function page(Request $request, array $params): Response
    {
        return $this->guard($request, 'cal:info:', RateLimits::publicBookingInfoPerAddress(), function () use ($request, $params): Response {
            $workspace = $this->workspace($request, $params) ?? throw CalendarRejectedException::notFound('Booking page');
            $tenantId = $workspace['id'];
            $settings = $this->booking->settingsFor($tenantId);
            $staff = array_values(array_filter($this->calendar->listStaff($tenantId), static fn (array $s): bool => $s['bookable']));
            $bookable = array_map(static fn (array $s): int => $s['profile_id'], $staff);
            $services = [];
            foreach ($this->services->list($tenantId, true, true) as $service) {
                $offered = array_values(array_intersect($service['staff_profile_ids'], $bookable));
                if ($offered === []) {
                    continue;
                }
                $services[] = [
                    'id' => $service['id'],
                    'name' => $service['name'],
                    'description' => $service['description'],
                    'duration_minutes' => $service['duration_minutes'],
                    'price_minor' => $service['price_minor'],
                    'currency' => $service['currency'],
                    'staff_profile_ids' => $offered,
                    // Design 3.3: the page says "Send request" instead of "Book it".
                    'sends_request' => $service['needs_approval'] || !$settings->instantBooking,
                ];
            }

            return Response::json(['data' => [
                'workspace' => ['name' => $workspace['name'], 'slug' => $workspace['slug']],
                // WHIT-1014: on a shared host the page's own branding is the
                // instance's, so the workspace's brand comes with the page.
                'brand' => $this->brandFor($tenantId, $workspace['name']),
                // WHIT-1173: `multi_service_booking` lets the customer add more services.
                'settings' => array_intersect_key($settings->toApi(), array_flip(['time_zone', 'min_notice_minutes', 'max_advance_days', 'week_start', 'weekend_days', 'multi_service_booking'])),
                'services' => $services,
                'staff' => array_map(static fn (array $s): array => ['profile_id' => $s['profile_id'], 'display_name' => $s['display_name']], $staff),
                // WHIT-1286 (#2203): only a check that is fully set up; never an empty site key.
                'bot_check' => $this->bot->isRequired() && $this->bot->isReady()
                    ? ['provider' => $this->bot->provider(), 'site_key' => $this->bot->siteKey()]
                    : null,
                // WHIT-1298 (#2203): false when booking is refused until an admin
                // sets up the bot check, so the page says so up front.
                'bot_check_ready' => $this->bot->isReady(),
            ]]);
        });
    }

    /** @param array<string, string> $params */
    public function slots(Request $request, array $params): Response
    {
        return $this->guard($request, 'cal:slots:', RateLimits::publicBookingSlotsPerAddress(), function () use ($request, $params): Response {
            $workspace = $this->workspace($request, $params) ?? throw CalendarRejectedException::notFound('Booking page');
            $q = self::query($request);
            $many = ServiceChoices::fromQuery($q);
            if ($many !== null && count($many) > 1) {
                // WHIT-1173: times when all of them fit back to back, each
                // with who would do which (only people the page offers).
                [$from, $to] = self::range($q);

                return Response::json(['data' => $this->booking->availabilityForMany($workspace['id'], $many, ServiceChoices::samePerson($q['same_person'] ?? null), $from, $to, true)]);
            }
            $serviceId = $many[0]['service_id'] ?? self::intOrNull($q['service_id'] ?? null) ?? throw new CalendarRejectedException('service_id is required', 422);
            $profileId = $many[0]['profile_id'] ?? self::intOrNull($q['profile_id'] ?? null);
            [$from, $to] = self::range($q);
            $slots = $this->booking->availability($workspace['id'], $serviceId, $profileId, $from, $to, true);
            $bookable = array_map(static fn (array $s): int => $s['profile_id'], array_filter($this->calendar->listStaff($workspace['id']), static fn (array $s): bool => $s['bookable']));

            return Response::json(['data' => array_values(array_filter(array_map(static function (array $slot) use ($bookable): ?array {
                $who = array_values(array_intersect($slot['profile_ids'], $bookable));

                return $who === [] ? null : ['starts_at' => $slot['starts_at'], 'ends_at' => $slot['ends_at'], 'profile_ids' => $who];
            }, $slots)))]);
        });
    }

    /**
     * The signed-in visitor's own name and email, so the page can fill its
     * details for them (WHIT-1179). `data` is null when nobody is signed in.
     * Only the person's own account is read (see {@see BookingVisitor}); the
     * workspace learns nothing until they book. Never cached: the answer is
     * the person's, so it is `private, no-store` and varies by cookie.
     *
     * @param array<string, string> $params
     */
    public function visitor(Request $request, array $params): Response
    {
        $answer = $this->guard($request, 'cal:me:', RateLimits::publicBookingInfoPerAddress(), function () use ($request, $params): Response {
            $this->workspace($request, $params) ?? throw CalendarRejectedException::notFound('Booking page');
            $me = null;
            if ($this->visitor !== null) {
                try {
                    $me = $this->visitor->current();
                } catch (\Throwable $e) {
                    error_log('[PublicBookingApiHandler] visitor: ' . $e->getMessage());
                }
            }

            return Response::json(['data' => $me]);
        });

        return $answer->withHeaders(['Cache-Control' => 'private, no-store', 'Vary' => 'Cookie']);
    }

    /** @param array<string, string> $params */
    public function book(Request $request, array $params): Response
    {
        return $this->guard($request, 'cal:book:', RateLimits::publicBookingBookPerAddress(), function () use ($request, $params): Response {
            $workspace = $this->workspace($request, $params) ?? throw CalendarRejectedException::notFound('Booking page');
            $tenantId = $workspace['id'];
            $body = JsonBody::parsed($request);

            // People never see the `website` field, and need more than a
            // moment to choose a time and type their details.
            $elapsed = $body['elapsed_ms'] ?? null;
            if (($body['website'] ?? '') !== '' || !is_int($elapsed) || $elapsed < self::MIN_FILL_MS) {
                return Response::error('We could not take this booking. Please try again.', 422);
            }

            // Checked here, counted only once a booking is made.
            $limited = RateLimits::publicBookingPerWorkspaceDay()->blocked($this->store, 'cal:ws-day:' . $tenantId);
            if ($limited !== null) {
                return $limited;
            }

            $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
            $email = is_string($body['email'] ?? null) ? strtolower(trim($body['email'])) : '';
            $phone = is_string($body['phone'] ?? null) ? trim($body['phone']) : '';
            $note = is_string($body['note'] ?? null) ? mb_substr(trim($body['note']), 0, 1000) : '';
            if ($name === '' || mb_strlen($name) > 200) {
                return Response::error('Your name is required', 422, ['field' => 'name']);
            }
            if ($email === '' || mb_strlen($email) > 320 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return Response::error('Your email address does not look right', 422, ['field' => 'email']);
            }
            if ($phone !== '' && preg_match('/^\+?[0-9 ()-]{6,40}$/', $phone) !== 1) {
                return Response::error('Your phone number does not look right', 422, ['field' => 'phone']);
            }
            // WHIT-1173: `services` books several back to back, when the workspace allows it.
            $many = ServiceChoices::fromBody($body);
            $serviceId = $many[0]['service_id'] ?? (is_int($body['service_id'] ?? null) ? $body['service_id'] : throw new CalendarRejectedException('Choose a service', 422));
            $start = Interval::fromApi($body['starts_at'] ?? null) ?? throw new CalendarRejectedException('Choose a time', 422);
            $profileId = is_int($body['profile_id'] ?? null) ? $body['profile_id'] : null;

            // #2203: a bot check that isn't set up refuses, never skips: a provider
            // half set up (WHIT-1286), or none with "allow without" off (WHIT-1298).
            if (!$this->bot->isReady()) {
                return Response::error('Booking is unavailable right now. Try again later.', 503);
            }
            if ($this->bot->isRequired()) {
                $verdict = $this->bot->verify(is_string($body['bot_token'] ?? null) ? $body['bot_token'] : '', ClientIp::fromRequest($request));
                if ($verdict === BotCheck::UNREACHABLE) {
                    return Response::error('Booking is unavailable right now. Try again later.', 503);
                }
                if ($verdict !== BotCheck::PASSED) {
                    return Response::error('We couldn\'t confirm you are a person. Try again.', 422, ['bot_token' => 'failed']);
                }
            }

            if ($this->bookingRows->openForEmail($tenantId, $email, $this->booking->now()) >= self::OPEN_PER_EMAIL_MAX) {
                return Response::error('You already have several bookings coming up here. Cancel one first, or contact the business.', 422, ['field' => 'email', 'code' => 'too_many_open_bookings']);
            }

            $booking = $this->booking->book($tenantId, [
                'items' => $many ?? [],
                'same_person' => ServiceChoices::samePerson($body['same_person'] ?? null),
                'service_id' => $serviceId,
                'profile_id' => $profileId,
                'starts_at' => $start,
                'customer_profile_id' => null,
                'customer_name' => $name,
                'customer_email' => $email,
                'customer_phone' => $phone,
                'customer_note' => $note,
                'source' => BookingRepository::SOURCE_PUBLIC,
                'created_by_profile_id' => null,
                'enforce_hours' => true,
                'public' => true,
            ]);
            RateLimits::publicBookingPerWorkspaceDay()->record($this->store, 'cal:ws-day:' . $tenantId);

            // With a payment step (SDK 1.98) the booking is held and the page
            // sends the customer to `payment.url`; they come back to the manage page.
            return Response::json(['data' => self::forCustomer($booking) + [
                'manage_token' => $booking['manage_token'] ?? null,
                'payment' => $booking['payment'] ?? null,
            ]], 201);
        });
    }

    // ── the customer's link ─────────────────────────────────────────────

    /** @param array<string, string> $params */
    public function showBooking(Request $request, array $params): Response
    {
        return $this->withLink($request, $params, function (int $tenantId, int $bookingId): Response {
            $booking = $this->booking->bookingOrFail($tenantId, $bookingId);
            $settings = $this->booking->settingsFor($tenantId);

            $name = $this->tenants->findNameById($tenantId) ?? '';

            return Response::json(['data' => self::forCustomer($booking) + [
                'workspace' => ['name' => $name],
                'brand' => $this->brandFor($tenantId, $name),
                'time_zone' => $settings->zone->getName(),
            ]]);
        });
    }

    /** @param array<string, string> $params */
    public function manageSlots(Request $request, array $params): Response
    {
        return $this->withLink($request, $params, function (int $tenantId, int $bookingId) use ($request): Response {
            $booking = $this->booking->bookingOrFail($tenantId, $bookingId);
            [$from, $to] = self::range(self::query($request));
            if (count($booking['items']) > 1) {
                // WHIT-1173: every service moves together, each with its own person.
                $items = [];
                foreach ($booking['items'] as $i) {
                    $items[] = ['service_id' => (int) $i['service_id'], 'profile_id' => (int) $i['profile_id']];
                }
                $slots = $this->booking->availabilityForMany($tenantId, $items, false, $from, $to, true, $bookingId, false);

                return Response::json(['data' => array_map(static fn (array $s): array => ['starts_at' => $s['starts_at'], 'ends_at' => $s['ends_at']], $slots)]);
            }
            $slots = $this->booking->availability($tenantId, (int) $booking['service_id'], (int) $booking['profile_id'], $from, $to, true);

            return Response::json(['data' => array_map(static fn (array $s): array => ['starts_at' => $s['starts_at'], 'ends_at' => $s['ends_at']], $slots)]);
        });
    }

    /** @param array<string, string> $params */
    public function move(Request $request, array $params): Response
    {
        return $this->withLink($request, $params, function (int $tenantId, int $bookingId) use ($request): Response {
            $start = Interval::fromApi(JsonBody::parsed($request)['starts_at'] ?? null) ?? throw new CalendarRejectedException('Choose a time', 422);
            $booking = $this->booking->move($tenantId, $bookingId, $start, null, true);

            return Response::json(['data' => self::forCustomer($booking) + ['manage_token' => $booking['manage_token'] ?? null]]);
        });
    }

    /**
     * The customer takes the time staff suggested for their request
     * (design 3.3). Their link changes: the answer carries the new one.
     *
     * @param array<string, string> $params
     */
    public function acceptSuggestion(Request $request, array $params): Response
    {
        return $this->withLink($request, $params, function (int $tenantId, int $bookingId): Response {
            $booking = $this->booking->acceptSuggestion($tenantId, $bookingId);

            return Response::json(['data' => self::forCustomer($booking) + ['manage_token' => $booking['manage_token'] ?? null]]);
        });
    }

    /** @param array<string, string> $params */
    public function cancel(Request $request, array $params): Response
    {
        return $this->withLink($request, $params, function (int $tenantId, int $bookingId) use ($request): Response {
            $reason = JsonBody::parsed($request)['reason'] ?? '';
            $booking = $this->booking->cancel($tenantId, $bookingId, 'customer', is_string($reason) ? trim($reason) : '');

            return Response::json(['data' => self::forCustomer($booking)]);
        });
    }

    /** @param array<string, string> $params */
    public function ics(Request $request, array $params): Response
    {
        return $this->withLink($request, $params, function (int $tenantId, int $bookingId): Response {
            $booking = $this->booking->bookingOrFail($tenantId, $bookingId);
            $service = is_array($booking['service'] ?? null) ? LocalizedText::resolve($booking['service']['name']) : '';
            $workspace = $this->tenants->findNameById($tenantId) ?? '';
            $body = Ics::calendar([[
                'uid' => \Whity\Core\Calendar\EmailBookingMessenger::uid($tenantId, $bookingId),
                'start' => Interval::fromApi($booking['starts_at']) ?? $this->booking->now(),
                'end' => Interval::fromApi($booking['ends_at']) ?? $this->booking->now(),
                'summary' => trim($service . ($workspace !== '' ? ' · ' . $workspace : '')),
                'description' => (string) $booking['staff_name'],
                // E2: the same revision as the emails' files, so opening this
                // one never rolls a calendar back to an older time.
                'sequence' => (int) ($booking['ics_sequence'] ?? 0),
            ]]);

            return new Response(200, $body, [
                'Content-Type' => 'text/calendar; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="booking.ics"',
                'Cache-Control' => 'no-store',
            ]);
        });
    }

    // ── the staff feed ──────────────────────────────────────────────────

    /** @param array<string, string> $params */
    public function feed(Request $request, array $params): Response
    {
        return $this->guard($request, 'cal:feed:', RateLimits::publicBookingFeedPerAddress(), function () use ($params): Response {
            $token = preg_replace('/\.ics$/', '', (string) ($params['token'] ?? '')) ?? '';
            $owner = $this->feed->find($token) ?? throw CalendarRejectedException::notFound('Calendar');
            [$tenantId, $profileId] = $owner;
            self::enter($tenantId);
            $name = trim($this->calendar->displayName($profileId) . ' · ' . ($this->tenants->findNameById($tenantId) ?? ''), ' ·');
            $body = $this->feed->render($tenantId, $profileId, $this->booking->settingsFor($tenantId), $this->booking->now(), $name);

            return new Response(200, $body, [
                'Content-Type' => 'text/calendar; charset=utf-8',
                'Cache-Control' => 'private, max-age=300',
            ]);
        });
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /**
     * What the customer may see of their booking: no internal ids beyond the
     * booking's own, no staff notes, no audit fields.
     *
     * @param array<string, mixed> $booking
     * @return array<string, mixed>
     */
    private static function forCustomer(array $booking): array
    {
        $service = is_array($booking['service'] ?? null) ? $booking['service'] : [];

        return [
            'id' => $booking['id'],
            'status' => $booking['status'],
            'suggested' => ($booking['suggested_at'] ?? null) !== null,
            'starts_at' => $booking['starts_at'],
            'ends_at' => $booking['ends_at'],
            'service' => [
                'id' => $service['id'] ?? null,
                'name' => $service['name'] ?? [],
                'duration_minutes' => $service['duration_minutes'] ?? null,
            ],
            'staff_name' => $booking['staff_name'] ?? '',
            'price_minor' => $booking['price_minor'],
            'currency' => $booking['currency'],
            'customer' => ['name' => $booking['customer']['name'] ?? '', 'email' => $booking['customer']['email'] ?? ''],
            // WHIT-1173: each service of a booking of several, in order; empty for one.
            'services' => count($booking['items'] ?? []) > 1 ? array_map(static fn (array $i): array => [
                'name' => $i['service_name'],
                'staff_name' => $i['staff_name'],
                'starts_at' => $i['starts_at'],
                'ends_at' => $i['ends_at'],
                'duration_minutes' => $i['duration_minutes'],
                'price_minor' => $i['price_minor'],
            ], $booking['items']) : [],
        ];
    }

    /**
     * @param array<string, string> $params
     * @param callable(int, int): Response $work
     */
    private function withLink(Request $request, array $params, callable $work): Response
    {
        return $this->guard($request, 'cal:manage:', RateLimits::publicBookingManagePerAddress(), function () use ($params, $work): Response {
            $found = $this->links->find((string) ($params['token'] ?? ''), $this->booking->now());
            if ($found === null) {
                return Response::error(self::LINK_INVALID, 404);
            }
            self::enter($found[0]);

            return $work($found[0], $found[1]);
        });
    }

    /** @param callable(): Response $work */
    private function guard(Request $request, string $prefix, RateLimitRule $rule, callable $work): Response
    {
        try {
            $ip = ClientIp::fromRequest($request);
            if ($ip !== null && $ip !== '') {
                $limited = $rule->take($this->store, $prefix . $ip);
                if ($limited !== null) {
                    return $limited;
                }
            }

            return $work();
        } catch (CalendarRejectedException $e) {
            // Every "not here" answer is the same, so the page reveals nothing.
            return $e->status === 404 && str_contains($prefix, 'manage')
                ? Response::error(self::LINK_INVALID, 404)
                : ($e->status === 404 ? Response::error(self::NOT_FOUND, 404) : Response::error($e->clientMessage, $e->status, $e->extra));
        } catch (\Throwable $e) {
            error_log('[PublicBookingApiHandler] failed: ' . $e->getMessage());

            return Response::error('Something went wrong. Please try again.', 500);
        }
    }

    /**
     * The workspace's public brand (WHIT-1014), or null without a branding
     * service or when it cannot be read: the page then keeps the host's.
     *
     * @return array<string, mixed>|null
     */
    private function brandFor(int $tenantId, string $workspaceName): ?array
    {
        if ($this->branding === null) {
            return null;
        }
        try {
            return $this->branding->forPublicPage($tenantId, $workspaceName);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The workspace a booking page is for, only when its page is on.
     *
     * @param array<string, string> $params
     * @return array{id: int, name: string, slug: string}|null
     */
    private function workspace(Request $request, array $params): ?array
    {
        $slug = strtolower(trim((string) ($params['workspace'] ?? '')));
        if ($slug === '' || strlen($slug) > 255 || preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug) !== 1) {
            return null;
        }
        $tenantId = $this->tenants->findIdBySlug($slug);
        if ($tenantId === null || $tenantId === self::SYSTEM_TENANT_ID) {
            return null;
        }
        $host = $request->getHeader('X-Forwarded-Host') ?? $request->getHeader('Host') ?? '';
        $hostTenant = $this->hosts->resolveTenantIdByHost($host);
        if ($hostTenant !== null && $hostTenant !== $tenantId) {
            return null;
        }
        if (!$this->booking->settingsFor($tenantId)->bookingPageEnabled) {
            return null;
        }
        self::enter($tenantId);

        return ['id' => $tenantId, 'name' => $this->tenants->findNameById($tenantId) ?? $slug, 'slug' => $slug];
    }

    /**
     * Lock the request to the workspace the path or token named. A request
     * already locked to the same workspace is fine; one locked to another
     * throws, which is the point of the lock.
     */
    private static function enter(int $tenantId): void
    {
        if (TenantContext::getTenantId() !== $tenantId) {
            TenantContext::setTenantId($tenantId);
        }
    }

    /** @return array<string, string> */
    private static function query(Request $request): array
    {
        $query = [];
        $qs = parse_url($request->getPath(), PHP_URL_QUERY);
        if (is_string($qs) && $qs !== '') {
            parse_str($qs, $parsed);
            foreach ($parsed as $k => $v) {
                if (is_string($k) && is_string($v)) {
                    $query[$k] = $v;
                }
            }
        }
        foreach ($_GET as $k => $v) {
            if (is_string($k) && is_string($v) && !isset($query[$k])) {
                $query[$k] = $v;
            }
        }

        return $query;
    }

    private static function intOrNull(?string $raw): ?int
    {
        return $raw !== null && preg_match('/^\d{1,9}$/', $raw) === 1 ? (int) $raw : null;
    }

    /**
     * @param array<string, string> $q
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    private static function range(array $q): array
    {
        $from = Interval::fromApi($q['from'] ?? null);
        $to = Interval::fromApi($q['to'] ?? null);
        if ($from === null || $to === null || $to <= $from || $to->getTimestamp() - $from->getTimestamp() > self::MAX_RANGE_DAYS * 86400) {
            throw new CalendarRejectedException('from and to are required, with an offset, at most ' . self::MAX_RANGE_DAYS . ' days apart', 422);
        }

        return [$from, $to];
    }
}
