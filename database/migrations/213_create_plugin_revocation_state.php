<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * The newest signed revocation list this deployment has accepted (one row).
 *
 * `sequence` only rises, so a list from before a revocation can't be replayed.
 * `expires_at` says when the list goes stale: running Verified plugins carry on,
 * and nothing new activates (the owner's decision 7).
 *
 * Install-wide, with no tenant_id.
 */
class CreatePluginRevocationState
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS plugin_revocation_state (
                id          INTEGER  NOT NULL PRIMARY KEY CHECK (id = 1),
                sequence    BIGINT   NOT NULL,
                issued_at   BIGINT   NOT NULL,
                expires_at  BIGINT   NOT NULL,
                body        TEXT     NOT NULL,
                applied_at  TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ");
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS plugin_revocation_state');
    }
}
