<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * The Verified mark (docs/design/VERIFIED-PLUGINS-SECURITY.md, section 4).
 *
 * It belongs to one version of one manifest, not to a plugin or a publisher.
 * Only {@see \Whity\Core\Plugin\Trust\PluginTrust} writes here, and only after a
 * signature checks out against a key pinned in code; a test holds every other
 * file to that. `attestation` keeps the signed statement, so a mark can always be
 * checked again.
 *
 * Install-wide, with no tenant_id: a mark says something about the version
 * itself, and a workspace can't produce one without Whity's signature.
 */
class CreatePluginVersionMarks
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS plugin_version_marks (
                id              BIGSERIAL    NOT NULL PRIMARY KEY,
                plugin_name     VARCHAR(64)  NOT NULL,
                version         VARCHAR(64)  NOT NULL,
                package_sha256  CHAR(64)     NOT NULL,
                manifest_hash   CHAR(64)     NOT NULL,
                source          VARCHAR(16)  NOT NULL CHECK (source IN ('whity')),
                attestation     TEXT         NOT NULL,
                granted_by_profile_id INTEGER NULL REFERENCES profiles(id) ON DELETE SET NULL,
                granted_at      TIMESTAMP    NOT NULL DEFAULT NOW(),
                revoked_at      TIMESTAMP    NULL,
                revoke_reason   TEXT         NULL,
                UNIQUE (plugin_name, version, manifest_hash)
            )
        ");
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS plugin_version_marks');
    }
}
