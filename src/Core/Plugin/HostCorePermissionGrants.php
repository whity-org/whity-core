<?php

declare(strict_types=1);

namespace Whity\Core\Plugin;

use Whity\Core\PluginLoader;
use Whity\Sdk\Contribute\CorePermissionGrants;

/**
 * The host side of {@see CorePermissionGrants} (SDK 1.130, #2256): read only.
 */
final class HostCorePermissionGrants implements CorePermissionGrants
{
    public function __construct(
        private readonly PluginCoreGrants $grants,
        private readonly PluginLoader $loader,
    ) {
    }

    public function isGranted(string $pluginName, string $role, string $permission, int $tenantId): bool
    {
        return $this->grants->isGranted($pluginName, $role, $permission, $tenantId);
    }

    public function missing(string $pluginName, string $role, int $tenantId): array
    {
        foreach ($this->loader->getPlugins() as $plugin) {
            if ($plugin->getName() !== $pluginName) {
                continue;
            }
            foreach ($this->grants->status($plugin, $tenantId) as $row) {
                if ($row['role'] === $role) {
                    return array_values(array_diff($row['requested'], $row['granted']));
                }
            }
        }

        return [];
    }
}
