<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Calendar\CalendarRejectedException;
use Whity\Core\Calendar\Commission;
use Whity\Core\Calendar\CommissionCloses;
use Whity\Core\i18n\LanguageRegistry;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * Staff commission (WHIT-1334, design section 35 §4, register SL.5-SL.7),
 * under `/api/calendar/commission`. Every route needs `calendar:commission`
 * (the router checks it). Ids from another workspace answer 404.
 */
final class CommissionApiHandler
{
    public function __construct(
        private readonly Commission $commission,
        private readonly LanguageRegistry $languages,
        private readonly ?CommissionCloses $closes = null,
    ) {
    }

    /** Every closed period, newest first (SL.6). */
    public function closes(Request $request): Response
    {
        return $this->run($request, fn (int $t): array => $this->closesOrFail()->list($t, $this->language()));
    }

    /** The periods that may be closed now. */
    public function closablePeriods(Request $request): Response
    {
        return $this->run($request, fn (int $t): array => $this->closesOrFail()->closable($t, $this->language()));
    }

    /** Close a period: `period`, one that has ended. */
    public function close(Request $request): Response
    {
        return $this->run($request, function (int $t, ?int $actor) use ($request): array {
            $body = JsonBody::parsed($request);
            $period = is_string($body['period'] ?? null) ? $body['period'] : '';

            return $this->closesOrFail()->close($t, $period, $actor, $this->language());
        });
    }

    /**
     * Send a close to the books again.
     *
     * @param array<string, string> $params
     */
    public function book(Request $request, array $params): Response
    {
        return $this->run($request, fn (int $t): array => $this->closesOrFail()->book($t, (int) ($params['id'] ?? 0), $this->language()));
    }

    private function closesOrFail(): CommissionCloses
    {
        return $this->closes ?? throw new CalendarRejectedException('Closing periods is not available here.', 404);
    }

    public function rates(Request $request): Response
    {
        return $this->run($request, fn (int $t): array => $this->commission->rates($t, $this->language()));
    }

    public function setRate(Request $request): Response
    {
        return $this->run($request, fn (int $t, ?int $actor): array => $this->commission->setRate($t, JsonBody::parsed($request), $actor, $this->language()));
    }

    /** @param array<string, string> $params */
    public function removeRate(Request $request, array $params): Response
    {
        return $this->run($request, function (int $t) use ($params): array {
            $this->commission->removeRate($t, (int) ($params['id'] ?? 0));

            return ['removed' => true];
        });
    }

    public function periods(Request $request): Response
    {
        return $this->run($request, fn (int $t): array => $this->commission->periods($t, $this->language()));
    }

    /** `?period=` one of {@see Commission::PERIODS}; this month when absent. */
    public function report(Request $request): Response
    {
        $query = $_GET;
        $raw = parse_url($request->getPath(), PHP_URL_QUERY);
        if (is_string($raw)) {
            parse_str($raw, $query);
        }
        $period = is_string($query['period'] ?? null) && $query['period'] !== '' ? $query['period'] : 'this_month';

        return $this->run($request, fn (int $t): array => $this->commission->report($t, $period, $this->language()));
    }

    public function staff(Request $request): Response
    {
        return $this->run($request, fn (int $t): array => $this->commission->staff($t));
    }

    public function services(Request $request): Response
    {
        return $this->run($request, fn (int $t): array => $this->commission->services($t, $this->language()));
    }

    /** @param callable(int, ?int): array<mixed> $work */
    private function run(Request $request, callable $work): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Open a workspace to see commission.', 403);
        }
        $user = $request->user;
        $actor = is_object($user) && isset($user->profile_id) && is_int($user->profile_id) ? $user->profile_id : null;

        try {
            return Response::json(['data' => $work($tenantId, $actor)]);
        } catch (CalendarRejectedException $e) {
            return Response::error($e->clientMessage, $e->status, $e->extra);
        } catch (\Throwable $e) {
            error_log('[CommissionApiHandler] failed: ' . $e->getMessage());

            return Response::error('Commission could not be read or saved', 500);
        }
    }

    private function language(): string
    {
        return $this->languages->getCurrentLanguageCode() === 'ar' ? 'ar' : 'en';
    }
}
