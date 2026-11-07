<?php

declare(strict_types=1);

namespace Whity\Core\Plugin\Trust;

use PDO;

/**
 * Whether an installed code plugin is Verified now (WHIT-993).
 *
 * Verified means a live Whity mark ({@see PluginTrust}, `plugin_version_marks`)
 * for the exact package installed: the same plugin name, version and package
 * sha256 that `plugin_installs` recorded, not revoked. A plugin copied into
 * `plugins/` by hand, or uploaded, has no recorded package a mark could match,
 * so it is never Verified. Asked on every call, so a revoked mark applies at
 * once.
 */
final class CodePluginVerification
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function isVerified(string $plugin): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1
               FROM plugin_installs i
               JOIN plugin_version_marks m
                 ON m.plugin_name = i.plugin_name
                AND m.version = i.version
                AND m.package_sha256 = i.package_sha256
                AND m.revoked_at IS NULL
              WHERE i.plugin_name = ? AND i.package_sha256 IS NOT NULL'
        );
        $stmt->execute([$plugin]);

        return $stmt->fetchColumn() !== false;
    }
}
