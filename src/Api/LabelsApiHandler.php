<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\Labels\LabelResolver;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * POST /api/labels/resolve: the names of a few units and roles (#1019).
 *
 * GATED ON THE SURFACE ASKING, NOT ON THE DIRECTORY. A document screen that
 * shows "forwarded from the Department of Civil Engineering" needs that one name,
 * and it used to get it by listing every unit (`ous:read`). The caller now says
 * which surface it is rendering, and holds that surface's own permission:
 * `documents` needs `documents:read`, `documents.route` needs `documents:route`.
 * The directory permissions are neither required nor consulted.
 *
 * LABELS ONLY, BOUNDED, TENANT-SCOPED. {@see LabelResolver} returns names and
 * nothing else, from this tenant's rows only. An id that is unknown, or belongs to
 * another tenant, is simply absent from the answer, so the client keeps showing
 * the id under its existing "names could not be loaded" notice. The number of ids
 * per request is capped by `labels.resolve_max_ids`, which resolves per-tenant ??
 * global ?? registry default.
 *
 * The route itself carries no permission: which permission applies depends on the
 * `surface` in the body, so the handler checks it.
 */
final class LabelsApiHandler
{
    /**
     * Surface key => the permission a caller rendering it already holds.
     *
     * @var array<string, string>
     */
    public const SURFACES = [
        'documents' => CorePermissions::DOCUMENTS_READ,
        'documents.route' => CorePermissions::DOCUMENTS_ROUTE,
    ];

    public function __construct(
        private readonly LabelResolver $labels,
        private readonly RoleChecker $roleChecker,
        private readonly SettingsService $settings,
    ) {
    }

    public function resolve(Request $request): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 400);
        }
        $actor = $request->user;
        $callerId = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id)
            ? $actor->profile_id
            : null;
        if ($callerId === null) {
            return Response::error('Authentication required', 401);
        }

        $body = JsonBody::parsed($request);

        $surface = $body['surface'] ?? null;
        if (!is_string($surface) || !array_key_exists($surface, self::SURFACES)) {
            return Response::error(
                "'surface' must be one of: " . implode(', ', array_keys(self::SURFACES)),
                422
            );
        }

        $permission = self::SURFACES[$surface];
        if (!$this->roleChecker->hasPermissionForProfile($callerId, $permission, $tenantId)) {
            return Response::json(['error' => 'Insufficient permissions', 'required' => $permission], 403);
        }

        foreach (array_keys($body) as $key) {
            if ($key !== 'surface' && !in_array($key, LabelResolver::KINDS, true)) {
                return Response::error(
                    "Unknown key '{$key}'. Labels can be resolved for: " . implode(', ', LabelResolver::KINDS),
                    422
                );
            }
        }

        $ids = [];
        $total = 0;
        foreach (LabelResolver::KINDS as $kind) {
            $raw = $body[$kind] ?? [];
            if (!is_array($raw) || !array_is_list($raw)) {
                return Response::error("'{$kind}' must be a list of ids", 422);
            }
            $list = [];
            foreach ($raw as $id) {
                if (!is_int($id) || $id < 1) {
                    return Response::error("'{$kind}' must contain only positive whole numbers", 422);
                }
                $list[$id] = $id;
            }
            $ids[$kind] = array_values($list);
            $total += count($list);
        }

        $max = $this->maxIds($tenantId);
        if ($total > $max) {
            return Response::error("At most {$max} ids can be resolved in one request", 422);
        }

        $resolved = $this->labels->resolve($tenantId, $ids);
        $data = [];
        foreach (LabelResolver::KINDS as $kind) {
            // An object even when empty, so a client indexes it by id either way.
            $data[$kind] = (object) $resolved[$kind];
        }

        return Response::json(['data' => $data]);
    }

    private function maxIds(int $tenantId): int
    {
        $raw = $this->settings->effective($tenantId)[SettingsRegistry::LABELS_RESOLVE_MAX_IDS]
            ?? SettingsRegistry::defaultFor(SettingsRegistry::LABELS_RESOLVE_MAX_IDS);
        $max = (int) $raw;

        return $max >= 1 ? $max : (int) SettingsRegistry::defaultFor(SettingsRegistry::LABELS_RESOLVE_MAX_IDS);
    }
}
