<?php

declare(strict_types=1);

namespace Whity\Core\Plugin\Trust;

use PDO;
use Whity\Core\Plugin\Store\StoreMarks;

/**
 * Whether an installed code plugin is Verified now (WHIT-993).
 *
 * Verified means a live mark for the exact package installed, from either
 * source: the Whity store's signed mark ({@see StoreMarks}, WHIT-1014, read from
 * its cache), or a Whity mark in `plugin_version_marks` ({@see PluginTrust}) with
 * the same plugin name, version and package sha256 that `plugin_installs`
 * recorded, not revoked. Neither counts while the accepted revocation list
 * names the installed version (WHIT-1277). A plugin copied into `plugins/` by hand, or uploaded,
 * has no recorded package a mark could match, so it is never Verified. Asked on
 * every call, so a revoked mark applies as soon as it is known.
 */
final class CodePluginVerification
{
    public function __construct(
        private readonly PDO $db,
        private readonly ?StoreMarks $storeMarks = null,
    ) {
    }

    /** @phpstan-impure */
    public function isVerified(string $plugin): bool
    {
        // WHIT-1277 (#2233): a revocation list naming the installed version
        // wins over everything, including a store mark cached before it.
        $installed = $this->db->prepare('SELECT version FROM plugin_installs WHERE plugin_name = ?');
        $installed->execute([$plugin]);
        $version = $installed->fetchColumn();
        if (PluginTrust::entryFor(PluginTrust::acceptedEntries($this->db), $plugin, is_string($version) ? $version : null) !== null) {
            return false;
        }

        if ($this->storeMarks !== null && $this->storeMarks->isVerified($plugin)) {
            return true;
        }

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
