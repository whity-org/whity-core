<?php

declare(strict_types=1);

namespace Whity\Core\Plugin;

use Whity\Sdk\Storage\PluginFile;
use Whity\Sdk\Storage\PluginFileStore;
use Whity\Storage\StorageKey;
use Whity\Storage\TenantStorageResolver;

/**
 * One plugin's files in one workspace (SDK 1.63, WHIT-886). Built by
 * {@see HostPluginFiles::for()}; not for direct construction by plugins.
 *
 * Every method resolves the workspace again, through the injected tenant
 * source, and builds every key with {@see StorageKey::pluginPath()}, which
 * refuses a path that would leave the plugin's prefix. The prefix of this
 * plugin in this workspace is the only place this store can read or write.
 */
final class HostPluginFileStore implements PluginFileStore
{
    /**
     * @param \Closure(): TenantStorageResolver $storage
     * @param \Closure(): ?int                  $tenant
     */
    public function __construct(
        private readonly \Closure $storage,
        private readonly string $slug,
        private readonly \Closure $tenant,
    ) {
    }

    public function put(string $path, string $contents, string $contentType = 'application/octet-stream'): void
    {
        $tenantId = $this->tenantId();
        $key = StorageKey::pluginPath($tenantId, $this->slug, $path);

        $this->driver($tenantId)->put($key, $contents, ['ContentType' => $contentType]);
    }

    public function get(string $path): ?PluginFile
    {
        $tenantId = $this->tenantId();
        $key = StorageKey::pluginPath($tenantId, $this->slug, $path);
        $driver = $this->driver($tenantId);

        if (!$driver->exists($key)) {
            return null;
        }

        return new PluginFile($path, $driver->get($key), $driver->mimeType($key));
    }

    public function exists(string $path): bool
    {
        $tenantId = $this->tenantId();

        return $this->driver($tenantId)->exists(StorageKey::pluginPath($tenantId, $this->slug, $path));
    }

    public function list(string $prefix = ''): array
    {
        $tenantId = $this->tenantId();
        $base = StorageKey::pluginPrefix($tenantId, $this->slug);
        $search = $prefix === ''
            ? $base
            : StorageKey::pluginPath($tenantId, $this->slug, rtrim($prefix, '/')) . '/';

        $names = [];
        foreach ($this->driver($tenantId)->list($search) as $key) {
            if (str_starts_with($key, $base)) {
                $names[] = substr($key, strlen($base));
            }
        }

        return $names;
    }

    public function delete(string $path): void
    {
        $tenantId = $this->tenantId();

        $this->driver($tenantId)->delete(StorageKey::pluginPath($tenantId, $this->slug, $path));
    }

    private function driver(int $tenantId): \Whity\Storage\StorageDriverInterface
    {
        return ($this->storage)()->forTenant($tenantId);
    }

    /** @throws \LogicException when no workspace is in scope */
    private function tenantId(): int
    {
        $tenantId = ($this->tenant)();
        if ($tenantId === null || $tenantId <= 0) {
            throw new \LogicException('No workspace is in scope, so there are no plugin files to reach.');
        }

        return $tenantId;
    }
}
