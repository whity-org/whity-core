<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * AddAuditLogAgentIndex — the audit log's Agent filter (W.4).
 *
 * Filtering a workspace's trail by agent would otherwise walk every row the
 * workspace has. Most rows have no agent, so the index holds only those that do.
 *
 * Additive and idempotent; down() drops the index.
 */
final class AddAuditLogAgentIndex
{
    public static function up(Database $db): void
    {
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_audit_log_tenant_agent
                 ON audit_log (tenant_id, agent_principal_id, id DESC)
              WHERE agent_principal_id IS NOT NULL'
        );
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP INDEX IF EXISTS idx_audit_log_tenant_agent');
    }
}
