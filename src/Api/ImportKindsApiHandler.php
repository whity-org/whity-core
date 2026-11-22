<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\Import\ImportKinds;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;

/**
 * `GET /api/imports/kinds` (WHIT-1326, design section 34 §6.5): what the
 * signed-in person may import here, each kind described fully enough for the
 * shared import screen to draw it. A kind needing a permission they lack is
 * left out; `?area=inventory` keeps one list page's kinds.
 */
final class ImportKindsApiHandler
{
    public function __construct(
        private readonly ImportKinds $kinds,
        private readonly ?RoleChecker $roleChecker = null,
    ) {
    }

    public function list(Request $request): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null || $tenantId === 0) {
                return Response::json(['data' => []]);
            }
            $user = $request->user;
            $actor = is_object($user) && isset($user->profile_id) && is_int($user->profile_id) ? $user->profile_id : null;
            $area = self::area($request);
            $roleChecker = $this->roleChecker;
            $may = static fn (string $permission): bool => $roleChecker !== null && $actor !== null
                && $roleChecker->hasPermissionForProfile($actor, $permission, $tenantId);

            return Response::json(['data' => $this->kinds->forPerson($tenantId, $may, $area)]);
        } catch (\Throwable $e) {
            error_log('[ImportKindsApiHandler] failed: ' . $e->getMessage());

            return Response::error('The kinds of import could not be loaded. Try again.', 500);
        }
    }

    private static function area(Request $request): ?string
    {
        $area = $_GET['area'] ?? null;
        $qs = parse_url($request->getPath(), PHP_URL_QUERY);
        if (is_string($qs) && $qs !== '') {
            parse_str($qs, $parsed);
            $area = $parsed['area'] ?? $area;
        }

        return is_string($area) && preg_match('/^[a-z_]{1,40}$/', $area) === 1 ? $area : null;
    }
}
