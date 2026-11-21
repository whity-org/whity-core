<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Calendar\BookingService;
use Whity\Core\Calendar\CalendarRejectedException;
use Whity\Core\Calendar\WalkinQueue;
use Whity\Core\i18n\LanguageRegistry;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * The walk-in queue at the front desk (WHIT-1334, design section 35 §3,
 * register SL.2-SL.4), under `/api/calendar/walkins`.
 *
 * Every route needs `calendar:front_desk` (the router checks it) and the
 * workspace's `calendar.walkins_enabled` (checked here: off answers 409 with
 * reason `walkins_off`). Handing someone over books as the person at the desk.
 * Ids from another workspace answer 404.
 */
final class WalkinsApiHandler
{
    public function __construct(
        private readonly WalkinQueue $queue,
        private readonly BookingService $booking,
        private readonly LanguageRegistry $languages,
    ) {
    }

    /** Who is waiting, first come first served. */
    public function list(Request $request): Response
    {
        return $this->run($request, fn (int $t): array => $this->queue->waiting($t, $this->language()));
    }

    public function add(Request $request): Response
    {
        return $this->run($request, fn (int $t, ?int $actor): array => $this->queue->add($t, JsonBody::parsed($request), $actor), 201);
    }

    /** @param array<string, string> $params */
    public function assignNext(Request $request, array $params): Response
    {
        return $this->run($request, fn (int $t, ?int $actor): array => $this->queue->assign($t, (int) ($params['id'] ?? 0), null, $actor));
    }

    /** Hand a walk-in to the person chosen: `walkin_id`, `profile_id`. */
    public function assignChosen(Request $request): Response
    {
        return $this->run($request, function (int $t, ?int $actor) use ($request): array {
            $body = JsonBody::parsed($request);
            $id = self::int($body['walkin_id'] ?? null) ?? throw new CalendarRejectedException('Choose who to serve.', 422, ['field' => 'walkin_id']);
            $profileId = self::int($body['profile_id'] ?? null) ?? throw new CalendarRejectedException('Choose someone from the list.', 422, ['field' => 'profile_id']);

            return $this->queue->assign($t, $id, $profileId, $actor);
        });
    }

    /** @param array<string, string> $params */
    public function left(Request $request, array $params): Response
    {
        return $this->run($request, fn (int $t): array => $this->queue->left($t, (int) ($params['id'] ?? 0)));
    }

    /** The services a walk-in can choose. */
    public function services(Request $request): Response
    {
        return $this->run($request, fn (int $t): array => $this->queue->services($t, $this->language()));
    }

    /** The bookable staff, free ones first. */
    public function staff(Request $request): Response
    {
        return $this->run($request, fn (int $t): array => $this->queue->staff($t, $this->language()));
    }

    /** @param callable(int, ?int): array<mixed> $work */
    private function run(Request $request, callable $work, int $status = 200): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Open a workspace to use the walk-in queue.', 403);
        }
        $user = $request->user;
        $actor = is_object($user) && isset($user->profile_id) && is_int($user->profile_id) ? $user->profile_id : null;

        try {
            if (!$this->booking->settingsFor($tenantId)->walkinsEnabled) {
                throw new CalendarRejectedException('Walk-ins are off for this workspace. Turn them on in Calendar settings.', 409, ['reason' => 'walkins_off']);
            }

            return Response::json(['data' => $work($tenantId, $actor)], $status);
        } catch (CalendarRejectedException $e) {
            return Response::error($e->clientMessage, $e->status, $e->extra);
        } catch (\Throwable $e) {
            error_log('[WalkinsApiHandler] failed: ' . $e->getMessage());

            return Response::error('The walk-in queue could not be updated', 500);
        }
    }

    private function language(): string
    {
        return $this->languages->getCurrentLanguageCode() === 'ar' ? 'ar' : 'en';
    }

    private static function int(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }
}
