<?php

declare(strict_types=1);

namespace Whity\Mcp\Auth;

use PDO;
use Whity\Auth\RoleChecker;
use Whity\Core\Audit\AuditContext;
use Whity\Core\Container\HostWiredService;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Mcp\McpConnections;

/**
 * The SDK's {@see McpConnections} (WHIT-992): the workspace's active AI
 * connections, for plugins, with nothing that could be used to call as one.
 *
 * The workspace comes from {@see TenantContext} and the person from
 * {@see AuditContext}. With `mcp:tokens:manage` it reads what
 * `GET /api/admin/mcp/tokens` lists; otherwise only the person's own, as
 * `GET /api/mcp/tokens` does. The jti is never selected.
 *
 * Worker-safe: no request state is kept on the instance.
 */
final class SdkMcpConnections implements McpConnections, HostWiredService
{
    private const LIMIT = 500;

    public function __construct(
        private readonly PDO $db,
        private readonly RoleChecker $roleChecker,
    ) {
    }

    public function list(): array
    {
        $tenantId  = TenantContext::getTenantId();
        $profileId = AuditContext::getActorUserId();
        if ($tenantId === null || $tenantId === 0 || $profileId === null) {
            return [];
        }
        $all = $this->roleChecker->hasPermissionForProfile($profileId, CorePermissions::MCP_TOKENS_MANAGE, $tenantId);

        $sql = 'SELECT t.id, t.name, t.principal_kind, t.profile_id, t.agent_assignment_id, a.agent_id,
                       t.scope, t.created_at, t.expires_at, t.last_used_at
                  FROM mcp_tokens t
                  LEFT JOIN agent_assignments a ON a.id = t.agent_assignment_id AND a.tenant_id = t.tenant_id
                 WHERE t.tenant_id = :tenant_id
                   AND t.expires_at > :now
                   AND NOT EXISTS (SELECT 1 FROM revoked_tokens r WHERE r.jti = t.jti)'
            . ($all ? '' : ' AND t.profile_id = :profile_id')
            . ' ORDER BY t.created_at DESC, t.id DESC LIMIT ' . self::LIMIT;
        $params = [':tenant_id' => $tenantId, ':now' => date('Y-m-d H:i:s')];
        if (!$all) {
            $params[':profile_id'] = $profileId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $scope = json_decode((string) $row['scope'], true);
            $out[] = [
                'id'                => (int) $row['id'],
                'name'              => (string) $row['name'],
                'kind'              => (string) $row['principal_kind'],
                'personId'          => (int) $row['profile_id'],
                'agentAssignmentId' => $row['agent_assignment_id'] === null ? null : (int) $row['agent_assignment_id'],
                'agentId'           => $row['agent_id'] === null ? null : (int) $row['agent_id'],
                'scope'             => is_array($scope) ? array_values(array_filter($scope, 'is_string')) : [],
                'createdAt'         => self::utc((string) $row['created_at']),
                'expiresAt'         => self::utc((string) $row['expires_at']),
                'lastUsedAt'        => $row['last_used_at'] === null ? null : self::utc((string) $row['last_used_at']),
            ];
        }

        return $out;
    }

    /** Stored in the server's zone, as `date()` writes them ({@see McpTokenService}). */
    private static function utc(string $time): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($time));
    }
}
