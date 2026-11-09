<?php

declare(strict_types=1);

namespace Whity\Mcp\Auth;

use PDO;
use Whity\Core\Hooks\HookManager;
use Whity\Sdk\Hooks\Events;

/**
 * Tells plugins about AI connections (MCP tokens) (WHIT-992): one was made, its
 * grant changed, or it was disconnected.
 *
 * Notifications only, after the change is saved; what a listener returns or
 * throws changes nothing. The payload names the connection by its row id, never
 * by the token, its jti or anything that could be used to call as it.
 */
final class McpConnectionEvents
{
    /** Who disconnected it. */
    public const BY_OWNER = 'owner';
    public const BY_ADMIN = 'admin';
    /** The person stopped using the workspace agent the connection was for. */
    public const BY_AGENT_REMOVED = 'agent_removed';

    public function __construct(
        private readonly PDO $db,
        private readonly ?HookManager $hooks,
    ) {
    }

    public function created(string $jti, int $tenantId): void
    {
        $row = $this->row($jti, $tenantId);
        if ($row !== null) {
            $this->announce(Events::MCP_CONNECTION_CREATED, $row + ['actor_profile_id' => $row['profile_id']]);
        }
    }

    public function grantChanged(string $jti, int $tenantId, int $actorProfileId): void
    {
        $row = $this->row($jti, $tenantId);
        if ($row !== null) {
            $this->announce(Events::MCP_CONNECTION_GRANT_CHANGED, $row + ['actor_profile_id' => $actorProfileId]);
        }
    }

    /** @param string $by {@see self::BY_OWNER}, {@see self::BY_ADMIN} or {@see self::BY_AGENT_REMOVED} */
    public function revoked(string $jti, int $tenantId, ?int $actorProfileId, string $by): void
    {
        $row = $this->row($jti, $tenantId);
        if ($row !== null) {
            $this->announce(Events::MCP_CONNECTION_REVOKED, $row + ['actor_profile_id' => $actorProfileId, 'by' => $by]);
        }
    }

    /** @return array{tenant_id: int, connection_id: int, profile_id: int, kind: string, agent_assignment_id: int|null}|null */
    private function row(string $jti, int $tenantId): ?array
    {
        if ($this->hooks === null) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT id, tenant_id, profile_id, principal_kind, agent_assignment_id FROM mcp_tokens WHERE jti = ? AND tenant_id = ? LIMIT 1');
        $stmt->execute([$jti, $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'tenant_id'           => (int) $row['tenant_id'],
            'connection_id'       => (int) $row['id'],
            'profile_id'          => (int) $row['profile_id'],
            'kind'                => (string) $row['principal_kind'],
            'agent_assignment_id' => $row['agent_assignment_id'] === null ? null : (int) $row['agent_assignment_id'],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function announce(string $event, array $payload): void
    {
        if ($this->hooks === null) {
            return;
        }
        try {
            $this->hooks->dispatch($event, $payload);
        } catch (\Throwable $e) {
            error_log('[mcp] a listener on ' . $event . ' failed: ' . $e->getMessage());
        }
    }
}
