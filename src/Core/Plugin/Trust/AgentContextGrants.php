<?php

declare(strict_types=1);

namespace Whity\Core\Plugin\Trust;

use PDO;
use PDOException;
use Whity\Core\Audit\AuditLoggerInterface;
use Whity\Database\UndefinedTable;

/**
 * Which code plugins the operator trusts to put text in front of AI agents
 * (SDK 1.80, WHIT-993): their MCP prompts and resources.
 *
 * Prompt and resource text goes straight into an agent's context, where it can
 * steer what the agent does for the person using it. So it comes only from a
 * plugin the operator granted, by name: install-wide, never by a workspace,
 * every grant and revoke audited (`whity-cli plugin agent-context grant <name>`
 * on the server). Verified status is operator-granted only
 * (owner decision), and this is that grant for agent context.
 *
 * Only a Verified plugin can be granted, whoever asks, and the grant only counts
 * while it stays Verified ({@see CodePluginVerification}).
 *
 * Only this class writes `plugin_agent_context_grants`.
 */
final class AgentContextGrants
{
    public function __construct(
        private readonly PDO $db,
        private readonly CodePluginVerification $verification,
        private readonly ?AuditLoggerInterface $audit = null,
    ) {
    }

    /**
     * Whether the plugin of this name may reach agents now: granted, and still
     * Verified. Asked on every read, so a revoke, or a mark taken away, applies
     * at once.
     */
    public function isGranted(string $plugin): bool
    {
        try {
            $stmt = $this->db->prepare('SELECT 1 FROM plugin_agent_context_grants WHERE plugin_name = ?');
            $stmt->execute([$plugin]);
            $found = $stmt->fetchColumn() !== false;
        } catch (PDOException $e) {
            self::rethrowUnlessNotMigrated($e);

            return false;
        }

        return $found && $this->verification->isVerified($plugin);
    }

    /** @return list<string> the plugins that may reach agents now: granted and still Verified */
    public function granted(): array
    {
        try {
            $rows = $this->db->query('SELECT plugin_name FROM plugin_agent_context_grants ORDER BY plugin_name');
        } catch (PDOException $e) {
            self::rethrowUnlessNotMigrated($e);

            return [];
        }
        if ($rows === false) {
            return [];
        }

        return array_values(array_filter(
            array_map('strval', $rows->fetchAll(PDO::FETCH_COLUMN)),
            $this->verification->isVerified(...),
        ));
    }

    /** @return list<array{plugin: string, grantedBy: int|null, grantedAt: string}> */
    public function list(): array
    {
        try {
            $rows = $this->db->query('SELECT plugin_name, granted_by_profile_id, granted_at FROM plugin_agent_context_grants ORDER BY plugin_name');
        } catch (PDOException $e) {
            self::rethrowUnlessNotMigrated($e);

            return [];
        }
        if ($rows === false) {
            return [];
        }

        return array_values(array_map(static fn (array $row): array => [
            'plugin'    => (string) $row['plugin_name'],
            'grantedBy' => $row['granted_by_profile_id'] === null ? null : (int) $row['granted_by_profile_id'],
            'grantedAt' => (string) $row['granted_at'],
        ], $rows->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * The worker reads the grants while it boots, and on an upgrade it boots
     * before `migrate run`. A table that is not there yet means nothing has been
     * granted, which is also the safe answer. Every other database error is real.
     */
    private static function rethrowUnlessNotMigrated(PDOException $e): void
    {
        if (!UndefinedTable::is($e, 'plugin_agent_context_grants')) {
            throw $e;
        }
    }

    /**
     * Grant it; true when it was not granted before.
     *
     * @throws NotVerifiedException when the installed plugin is not Verified: only a
     *         Verified plugin may be granted, whoever asks, the operator included
     */
    public function grant(string $plugin, ?int $profileId): bool
    {
        if (!$this->verification->isVerified($plugin)) {
            $this->audit?->record('plugin.agent_context.refused', [
                'tenant_id'     => 0,
                'actor_user_id' => $profileId,
                'target_type'   => 'plugin',
                'metadata'      => ['plugin' => $plugin, 'reason' => 'not_verified'],
            ]);
            throw new NotVerifiedException($plugin);
        }
        $stmt = $this->db->prepare(
            'INSERT INTO plugin_agent_context_grants (plugin_name, granted_by_profile_id, granted_at)
             VALUES (?, ?, CURRENT_TIMESTAMP) ON CONFLICT (plugin_name) DO NOTHING'
        );
        $stmt->execute([$plugin, $profileId]);
        if ($stmt->rowCount() !== 1) {
            return false;
        }
        $this->audited('plugin.agent_context.granted', $plugin, $profileId);

        return true;
    }

    /** Take it back; true when it was granted. */
    public function revoke(string $plugin, ?int $profileId): bool
    {
        $stmt = $this->db->prepare('DELETE FROM plugin_agent_context_grants WHERE plugin_name = ?');
        $stmt->execute([$plugin]);
        if ($stmt->rowCount() !== 1) {
            return false;
        }
        $this->audited('plugin.agent_context.revoked', $plugin, $profileId);

        return true;
    }

    private function audited(string $event, string $plugin, ?int $profileId): void
    {
        $this->audit?->record($event, [
            'tenant_id'     => 0,
            'actor_user_id' => $profileId,
            'target_type'   => 'plugin',
            'metadata'      => ['plugin' => $plugin],
        ]);
    }
}
