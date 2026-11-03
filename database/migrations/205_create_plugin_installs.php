<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Where each code plugin came from and what access it was installed with, and
 * any update waiting for approval (S.6, WHIT-870).
 *
 * `permissions` come from the plugin itself, read when the package was inspected.
 * `outside_domains` are what the store said, and no more than that: nothing can
 * check them against what PHP code really does.
 *
 * There's deliberately no trust or verified column. Nothing can become Verified
 * until docs/design/VERIFIED-PLUGINS-SECURITY.md is approved.
 *
 * Install-wide on purpose, with no tenant_id: a code plugin belongs to the
 * deployment (Q4), and only the system tenant changes these rows.
 */
class CreatePluginInstalls
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS plugin_installs (
                plugin_name     VARCHAR(64)  NOT NULL PRIMARY KEY,
                source          VARCHAR(16)  NOT NULL CHECK (source IN ('store', 'upload')),
                store_host      VARCHAR(255) NULL,
                store_slug      VARCHAR(128) NULL,
                version         VARCHAR(64)  NOT NULL,
                package_sha256  VARCHAR(64)  NULL,
                permissions     TEXT         NOT NULL DEFAULT '[]',
                outside_domains TEXT         NOT NULL DEFAULT '[]',
                installed_by_profile_id INTEGER NULL REFERENCES profiles(id) ON DELETE SET NULL,
                installed_at    TIMESTAMP    NOT NULL DEFAULT NOW(),
                updated_at      TIMESTAMP    NOT NULL DEFAULT NOW(),
                pending_version     VARCHAR(64) NULL,
                pending_sha256      VARCHAR(64) NULL,
                pending_permissions TEXT        NULL,
                pending_domains     TEXT        NULL,
                pending_at          TIMESTAMP   NULL
            )
        ");
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS plugin_installs');
    }
}
