<?php

declare(strict_types=1);

namespace Whity\Api;

use DateTimeImmutable;
use Whity\Core\Branding\BrandingService;
use Whity\Core\Branding\HostResolver;
use Whity\Core\Branding\TenantHostRepository;
use Whity\Core\Calendar\BookingRepository;
use Whity\Core\Calendar\BookingService;
use Whity\Core\Calendar\CalendarFeed;
use Whity\Core\Calendar\CalendarRejectedException;
use Whity\Core\Calendar\CalendarRepository;
use Whity\Core\Calendar\Ics;
use Whity\Core\Calendar\Interval;
use Whity\Core\Calendar\ManageLinks;
use Whity\Core\Calendar\ServiceRepository;
use Whity\Core\Form\LocalizedLabel;
use Whity\Core\Plugin\PublicForm\BotCheck;
use Whity\Core\RateLimit\ClientIp;
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
    private const HOUR = 3600;
    private const DAY = 86400;
    private const IP_INFO_MAX = 120;
    private const IP_SLOTS_MAX = 240;
    private const IP_BOOK_MAX = 10;
    private const IP_MANAGE_MAX = 60;
    private const IP_FEED_MAX = 120;
    private const WORKSPACE_DAY_MAX = 200;
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
    ) {
    }

    // ── the page ────────────────────────────────────────────────────────

    /** @param array<string, string> $params */
    public function page(Request $request, array $params): Response
    {
        return $this->guard($request, 'cal:info:', self::IP_INFO_MAX, function () use ($request, $params): Response {
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
                'settings' => array_intersect_key($settings->toApi(), array_flip(['time_zone', 'min_notice_minutes', 'max_advance_days', 'week_start', 'weekend_days'])),
                'services' => $services,
                'staff' => array_map(static fn (array $s): array => ['profile_id' => $s['profile_id'], 'display_name' => $s['display_name']], $staff),
                'bot_check' => $this->bot->isRequired() && $this->bot->provider() !== null
                    ? ['provider' => $this->bot->provider(), 'site_key' => $this->bot->siteKey()]
                    : null,
            ]]);
        });
    }

    /** @param array<string, string> $params */
    public function slots(Request $request, array $params): Response
    {
        return $this->guard($request, 'cal:slots:', self::IP_SLOTS_MAX, function () use ($request, $params): Response {
            $workspace = $this->workspace($request, $params) ?? throw CalendarRejectedException::notFound('Booking page');
            $q = self::query($request);
            $serviceId = self::intOrNull($q['service_id'] ?? null) ?? throw new CalendarRejectedException('service_id is required', 422);
            $profileId = self::intOrNull($q['profile_id'] ?? null);
            [$from, $to] = self::range($q);
            $slots = $this->booking->availability($workspace['id'], $serviceId, $profileId, $from, $to, true);
            $bookable = array_map(static fn (array $s): int => $s['profile_id'], array_filter($this->calendar->listStaff($workspace['id']), static fn (array $s): bool => $s['bookable']));

            return Response::json(['data' => array_values(array_filter(array_map(static function (array $slot) use ($bookable): ?array {
                $who = array_values(array_intersect($slot['profile_ids'], $bookable));

                return $who === [] ? null : ['starts_at' => $slot['starts_at'], 'ends_at' => $slot['ends_at'], 'profile_ids' => $who];
            }, $slots)))]);
        });
    }

    /** @param array<string, string> $params */
    public function book(Request $request, array $params): Response
    {
        return $this->guard($request, 'cal:book:', self::IP_BOOK_MAX, function () use ($request, $params): Response {
            $workspace = $this->workspace($request, $params) ?? throw CalendarRejectedException::notFound('Booking page');
            $tenantId = $workspace['id'];
            $body = JsonBody::parsed($request);

            // People never see the `website` field, and need more than a
            // moment to choose a time and type their details.
            $elapsed = $body['elapsed_ms'] ?? null;
            if (($body['website'] ?? '') !== '' || !is_int($elapsed) || $elapsed < self::MIN_FILL_MS) {
                return Response::error('We could not take this booking. Please try again.', 422);
            }

            $limited = $this->count('cal:ws-day:' . $tenantId, self::WORKSPACE_DAY_MAX);
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
            $serviceId = is_int($body['service_id'] ?? null) ? $body['service_id'] : throw new CalendarRejectedException('Choose a service', 422);
            $start = Interval::fromApi($body['starts_at'] ?? null) ?? throw new CalendarRejectedException('Choose a time', 422);
            $profileId = is_int($body['profile_id'] ?? null) ? $body['profile_id'] : null;

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
            $this->store->increment('cal:ws-day:' . $tenantId, self::DAY);

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
            $service = is_array($booking['service'] ?? null) ? LocalizedLabel::preferred($booking['service']['name']) : '';
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
        return $this->guard($request, 'cal:feed:', self::IP_FEED_MAX, function () use ($params): Response {
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
        ];
    }

    /**
     * @param array<string, string> $params
     * @param callable(int, int): Response $work
     */
    private function withLink(Request $request, array $params, callable $work): Response
    {
        return $this->guard($request, 'cal:manage:', self::IP_MANAGE_MAX, function () use ($params, $work): Response {
            $found = $this->links->find((string) ($params['token'] ?? ''), $this->booking->now());
            if ($found === null) {
                return Response::error(self::LINK_INVALID, 404);
            }
            self::enter($found[0]);

            return $work($found[0], $found[1]);
        });
    }

    /** @param callable(): Response $work */
    private function guard(Request $request, string $prefix, int $max, callable $work): Response
    {
        try {
            $ip = ClientIp::fromRequest($request);
            if ($ip !== null && $ip !== '') {
                $limited = $this->count($prefix . $ip, $max);
                if ($limited !== null) {
                    return $limited;
                }
                $this->store->increment($prefix . $ip, self::HOUR);
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

    private function count(string $key, int $max): ?Response
    {
        if ($this->store->count($key) >= $max) {
            return Response::error('Too many attempts. Please try again later.', 429)
                ->withHeaders(['Retry-After' => (string) max($this->store->ttl($key), 1)]);
        }

        return null;
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
