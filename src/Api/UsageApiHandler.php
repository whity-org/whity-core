<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Core\Usage\UsageReport;

/**
 * GET /api/usage[?period=YYYY-MM] — every usage meter for this workspace and
 * month: what was used, the base, and whether it is near or past it
 * (section 33 §5, SC.14).
 *
 * Gated on `billing:view` like the plan limits: the people who can change the
 * plan are the ones who need to see it. Read-only. Nothing is refused for
 * being past a base (BM.4), so a workspace can always read this.
 */
final class UsageApiHandler
{
    public function __construct(
        private readonly UsageReport $report,
        private readonly RoleChecker $roleChecker,
    ) {
    }

    public function show(Request $request): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 403);
        }

        $actor = $request->user;
        $userId = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id)
            ? $actor->profile_id
            : null;
        if ($userId === null
            || !$this->roleChecker->hasPermissionForProfile($userId, CorePermissions::BILLING_VIEW, $tenantId)) {
            return Response::error('Insufficient permissions', 403, ['required' => CorePermissions::BILLING_VIEW]);
        }

        $period = self::queryParam($request, 'period');
        if ($period !== null && $period !== '' && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
            return Response::error('The month is written YYYY-MM.', 422, ['field' => 'period']);
        }

        return Response::json(['data' => $this->report->forTenant($tenantId, $period === '' ? null : $period)])
            ->withHeaders(['Cache-Control' => 'no-store']);
    }

    private static function queryParam(Request $request, string $name): ?string
    {
        $value = isset($_GET[$name]) && is_string($_GET[$name]) ? $_GET[$name] : null;
        $query = parse_url($request->getPath(), PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            $params = [];
            parse_str($query, $params);
            if (isset($params[$name]) && is_string($params[$name])) {
                $value = $params[$name];
            }
        }

        return $value;
    }
}
