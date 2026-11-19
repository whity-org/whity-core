<?php

declare(strict_types=1);

namespace Whity\Api;

use PDO;
use Whity\Auth\RoleChecker;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Plugin\PluginCoreGrants;
use Whity\Core\Plugin\PluginOperatorGate;
use Whity\Core\PluginLoader;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;
use Whity\Sdk\PluginInterface;

/**
 * Core permissions a plugin asks for its roles, and the operator's approvals
 * (#2256). Operators only (the system workspace), audited.
 *
 *  - `GET  /api/plugins/{name}/core-grants[?tenant_id=]` — per workspace and
 *    role: what the plugin asks for, and what is approved.
 *  - `POST /api/plugins/{name}/core-grants/{role}/approve {tenant_id, permissions?}`
 *  - `POST /api/plugins/{name}/core-grants/{role}/revoke {tenant_id, permissions?}`
 *
 * `tenant_id` 0 (or absent) is the global roles. An approval grants exactly
 * what the plugin asked for that role, or the listed part of it, and only what
 * the approving operator holds themselves.
 */
final class PluginCoreGrantsApiHandler
{
    public function __construct(
        private readonly PDO $db,
        private readonly PluginLoader $loader,
        private readonly RoleChecker $roleChecker,
        private readonly ?AuditLogger $audit = null,
    ) {
    }

    /** @param array<string, string> $params */
    public function list(Request $request, array $params): Response
    {
        $refusal = PluginOperatorGate::refusal();
        if ($refusal !== null) {
            return $refusal;
        }
        $plugin = $this->plugin($params);
        if ($plugin === null) {
            return Response::error('Plugin not found', 404);
        }

        $requests = PluginCoreGrants::requests($plugin);
        $query = [];
        parse_str((string) parse_url($request->getPath(), PHP_URL_QUERY), $query);
        $only = isset($query['tenant_id']) && is_string($query['tenant_id']) && ctype_digit($query['tenant_id'])
            ? (int) $query['tenant_id']
            : null;

        $grants = new PluginCoreGrants($this->db);
        $rows = [];
        foreach ($this->workspacesWithRoles(array_keys($requests), $only) as $workspace) {
            foreach ($grants->status($plugin, $workspace['id']) as $row) {
                if ($row['role_id'] === null) {
                    continue;
                }
                $rows[] = [
                    'tenant_id' => $workspace['id'],
                    'workspace' => $workspace['name'],
                    'role' => $row['role'],
                    'requested' => $row['requested'],
                    'granted' => $row['granted'],
                ];
            }
        }

        return Response::json(['data' => ['plugin' => $plugin->getName(), 'requests' => $requests, 'grants' => $rows]]);
    }

    /** @param array<string, string> $params */
    public function approve(Request $request, array $params): Response
    {
        return $this->change($request, $params, true);
    }

    /** @param array<string, string> $params */
    public function revoke(Request $request, array $params): Response
    {
        return $this->change($request, $params, false);
    }

    /** @param array<string, string> $params */
    private function change(Request $request, array $params, bool $approve): Response
    {
        $refusal = PluginOperatorGate::refusal();
        if ($refusal !== null) {
            return $refusal;
        }
        $plugin = $this->plugin($params);
        if ($plugin === null) {
            return Response::error('Plugin not found', 404);
        }
        $role = (string) ($params['role'] ?? '');
        if ($role === '') {
            return Response::error('A role is required', 400);
        }

        $body = JsonBody::parsed($request);
        $tenantId = $body['tenant_id'] ?? 0;
        if (!is_int($tenantId) || $tenantId < 0) {
            return Response::error('tenant_id must be a workspace id', 422);
        }
        $permissions = null;
        if (array_key_exists('permissions', $body) && $body['permissions'] !== null) {
            if (!is_array($body['permissions']) || array_filter($body['permissions'], 'is_string') !== $body['permissions']) {
                return Response::error('permissions must be a list of permission names', 422);
            }
            /** @var list<string> $permissions */
            $permissions = array_values($body['permissions']);
        }

        $actor = is_object($request->user) && isset($request->user->profile_id) && is_int($request->user->profile_id)
            ? $request->user->profile_id
            : null;
        $grants = new PluginCoreGrants($this->db);

        if ($approve) {
            // The grant guard: the approving operator can only hand out what
            // they hold themselves.
            $wanted = $permissions ?? (PluginCoreGrants::requests($plugin)[$role] ?? []);
            $held = $actor === null ? [] : array_fill_keys(
                $this->roleChecker->getEffectivePermissionsForProfile($actor, (int) TenantContext::getTenantId()),
                true
            );
            $missing = array_values(array_filter($wanted, static fn (string $p): bool => !isset($held[$p])));
            if ($missing !== []) {
                return Response::error('You can only approve permissions you hold', 403, [
                    'reason' => 'exceeds_your_access',
                    'permissions' => $missing,
                ]);
            }
            try {
                $changed = $grants->approve($plugin, $role, $tenantId, $actor, $permissions);
            } catch (\InvalidArgumentException $e) {
                return Response::error($e->getMessage(), 422);
            }
        } else {
            $changed = $grants->revoke($plugin->getName(), $role, $tenantId, $permissions);
        }
        RoleChecker::clearCache();

        $this->audit?->record($approve ? 'plugin.core_grant_approve' : 'plugin.core_grant_revoke', [
            'target_type' => 'plugin',
            'target_id' => null,
            'metadata' => [
                'plugin' => $plugin->getName(),
                'role' => $role,
                'role_tenant_id' => $tenantId === 0 ? null : $tenantId,
                'permissions' => $changed,
            ],
        ]);

        $status = array_values(array_filter(
            $grants->status($plugin, $tenantId),
            static fn (array $row): bool => $row['role'] === $role
        ));

        return Response::json(['data' => [
            'plugin' => $plugin->getName(),
            'role' => $role,
            'tenant_id' => $tenantId,
            $approve ? 'approved' : 'revoked' => $changed,
            'requested' => $status[0]['requested'] ?? [],
            'granted' => $status[0]['granted'] ?? [],
        ]]);
    }

    /** @param array<string, string> $params */
    private function plugin(array $params): ?PluginInterface
    {
        $name = (string) ($params['name'] ?? '');
        foreach ($this->loader->getPlugins() as $key => $plugin) {
            if ($plugin->getName() === $name || (string) $key === $name) {
                return $plugin;
            }
        }

        return null;
    }

    /**
     * Workspaces (and 0 for the global roles) that have at least one of these
     * roles, with their names.
     *
     * @param list<string> $roles
     * @return list<array{id: int, name: string}>
     */
    private function workspacesWithRoles(array $roles, ?int $only): array
    {
        if ($roles === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($roles), '?'));
        // Operator-only read across workspaces: which workspaces have the
        // plugin's roles at all. Names only, no workspace data.
        // @tenant-guard-ignore: operator-only (PluginOperatorGate), lists which workspaces have the plugin's roles
        $stmt = $this->db->prepare(
            "SELECT DISTINCT COALESCE(r.tenant_id, 0) AS tenant_id, t.name
               FROM roles r LEFT JOIN tenants t ON t.id = COALESCE(r.tenant_id, 0)
              WHERE r.name IN ({$placeholders})
              ORDER BY tenant_id"
        );
        $stmt->execute($roles);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['tenant_id'];
            if ($only !== null && $id !== $only) {
                continue;
            }
            $out[] = ['id' => $id, 'name' => (string) ($row['name'] ?? '')];
        }

        return $out;
    }
}
