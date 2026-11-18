<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateWorkspaceOwnershipOffers — handing a workspace over, and co-owners
 * (WHIT-1170, WHIT-1171).
 *
 * An owner OFFERS ownership (`transfer`) or co-ownership (`co_owner`) to a
 * member, who accepts or declines within 7 days (owner, 2026-11-16). Nothing
 * changes until it is accepted. `leave_after` is the giver's choice, on a
 * transfer only, to leave the workspace once it is accepted.
 *
 * status: open → accepted | declined | cancelled (withdrawn by an owner) |
 * expired (past expires_at) | void (the giver stopped being an owner first).
 *
 * Table name spelled out literally for TenantOwnedTablesTest and
 * CoreTablesTest. Safe to run twice; reversible.
 */
final class CreateWorkspaceOwnershipOffers
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS workspace_ownership_offers (
                id                    SERIAL       PRIMARY KEY,
                tenant_id             INTEGER      NOT NULL REFERENCES tenants(id)  ON DELETE CASCADE,
                kind                  VARCHAR(16)  NOT NULL CHECK (kind IN ('transfer', 'co_owner')),
                from_profile_id       INTEGER      NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
                to_profile_id         INTEGER      NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
                leave_after           BOOLEAN      NOT NULL DEFAULT FALSE,
                status                VARCHAR(16)  NOT NULL DEFAULT 'open'
                                      CHECK (status IN ('open', 'accepted', 'declined', 'cancelled', 'expired', 'void')),
                expires_at            TIMESTAMP    NOT NULL,
                decided_at            TIMESTAMP,
                decided_by_profile_id INTEGER               REFERENCES profiles(id) ON DELETE SET NULL,
                created_at            TIMESTAMP    NOT NULL DEFAULT NOW()
            )
        ");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_workspace_ownership_offers_tenant ON workspace_ownership_offers (tenant_id, status)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_workspace_ownership_offers_to ON workspace_ownership_offers (to_profile_id, status)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS workspace_ownership_offers');
    }
}
