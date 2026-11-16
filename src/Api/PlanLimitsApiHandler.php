<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Core\Usage\PlanLimitsReport;

/**
 * GET /api/billing/limits — where this workspace stands against its plan's
 * limits (WHIT-932).
 *
 * Gated on `billing:view`, the permission that already shows a workspace its
 * plan: the people who can change the plan are the people who need to know a
 * limit has been passed. Read-only, and exempt from nothing: a workspace past a
 * limit is never locked out, so it can always read this.
 */
final class PlanLimitsApiHandler
{
    public function __construct(
        private readonly PlanLimitsReport $report,
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

        return Response::json(['data' => $this->report->forTenant($tenantId)])
            ->withHeaders(['Cache-Control' => 'no-store']);
    }
}
