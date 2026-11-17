<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateWorkspaceOwners — who owns each workspace (WHIT-1169).
 *
 * Until now a workspace had no owner: whoever created it got the base `admin`
 * role, like every admin after them. `workspace_owners` names the owners. Co-
 * owners are equal (owner, 2026-11-16): each row is a full owner. Only owners
 * add or remove owners, hand ownership over, change the plan and pay invoices
 * (`billing:pay`, capped in RoleChecker). An owner always also holds an admin
 * membership; this table never grants anything on its own.
 *
 * Backfill: every workspace except the system tenant (0) that has no owner
 * yet gets one, the person holding the base `admin` role (a staff, active
 * membership) whose membership is the oldest. A workspace with no such person
 * gets none, and RoleChecker then caps nothing there.
 *
 * Table name spelled out literally for TenantOwnedTablesTest and
 * CoreTablesTest. Safe to run twice; reversible.
 */
final class CreateWorkspaceOwners
{
    public static function up(Database $db): void
    {
        $db->exec('
            CREATE TABLE IF NOT EXISTS workspace_owners (
                id                  SERIAL     PRIMARY KEY,
                tenant_id           INTEGER    NOT NULL REFERENCES tenants(id)  ON DELETE CASCADE,
                profile_id          INTEGER    NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
                added_by_profile_id INTEGER             REFERENCES profiles(id) ON DELETE SET NULL,
                created_at          TIMESTAMP  NOT NULL DEFAULT NOW(),
                UNIQUE (tenant_id, profile_id)
            )
        ');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_workspace_owners_profile ON workspace_owners (profile_id)');

        // Unscoped by design (migration): every workspace gets its first owner.
        // Only workspaces with no owner yet, so a second run adds nothing.
        $db->exec("
            INSERT INTO workspace_owners (tenant_id, profile_id, added_by_profile_id, created_at)
            SELECT m.tenant_id, m.profile_id, NULL, NOW()
              FROM memberships m
              JOIN roles r ON r.id = m.role_id AND r.name = 'admin' AND r.tenant_id IS NULL
             WHERE m.tenant_id <> 0
               AND m.status = 'active'
               AND m.kind = 'member'
               AND NOT EXISTS (SELECT 1 FROM workspace_owners o WHERE o.tenant_id = m.tenant_id)
               AND m.id = (
                   SELECT m2.id
                     FROM memberships m2
                     JOIN roles r2 ON r2.id = m2.role_id AND r2.name = 'admin' AND r2.tenant_id IS NULL
                    WHERE m2.tenant_id = m.tenant_id
                      AND m2.status = 'active'
                      AND m2.kind = 'member'
                    ORDER BY m2.created_at ASC, m2.id ASC
                    LIMIT 1
               )
        ");
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS workspace_owners');
    }
}
