<?php

declare(strict_types=1);

namespace Whity\Core\Plugin;

use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginNamespace;
use Whity\Sdk\Storage\PluginFiles;
use Whity\Sdk\Storage\PluginFileStore;
use Whity\Storage\TenantStorageResolver;

/**
 * The host's plugin file service (SDK 1.63, WHIT-886).
 *
 * Plugin files live in the platform's ONE storage layer, under the key
 * `tenants/{tenant}/{plugin}/{path}` (see {@see \Whity\Storage\StorageKey}). The
 * driver for a workspace comes from {@see TenantStorageResolver::forTenant()}, so
 * a workspace with its own bucket keeps its plugin files there too. Nothing here
 * is a second store.
 *
 * Two scopes are fixed by the host, never by the caller: the plugin (the one
 * {@see for()} was given) and the workspace (the tenant of the request or job,
 * read from {@see TenantContext}). A plugin has no argument by which to name
 * another plugin or workspace, so isolation does not depend on the plugin
 * behaving.
 */
final class HostPluginFiles implements PluginFiles
{
    /** @var \Closure(): ?int */
    private readonly \Closure $tenant;

    /** @var \Closure(): TenantStorageResolver */
    private readonly \Closure $storage;

    /**
     * @param \Closure(): TenantStorageResolver $storage built on first use: the
     *        platform cipher it needs is checked when it is built, and a command
     *        that touches no plugin file must not need the key
     * @param (\Closure(): ?int)|null $tenant the workspace in scope; the request's tenant by default
     */
    public function __construct(
        \Closure $storage,
        ?\Closure $tenant = null,
    ) {
        $this->storage = $storage;
        $this->tenant = $tenant ?? static fn (): ?int => TenantContext::getTenantId();
    }

    public function for(PluginInterface $plugin): PluginFileStore
    {
        $slug = PluginNamespace::slug($plugin->getName());
        if ($slug === null) {
            throw new \InvalidArgumentException('This plugin has no usable name, so it can keep no files.');
        }

        $storage = $this->storage;
        $resolved = null;

        return new HostPluginFileStore(
            static function () use ($storage, &$resolved): TenantStorageResolver {
                return $resolved ??= $storage();
            },
            $slug,
            $this->tenant,
        );
    }
}
