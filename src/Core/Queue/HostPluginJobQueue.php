<?php

declare(strict_types=1);

namespace Whity\Core\Queue;

use Whity\Core\Plugin\PluginIdentity;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Jobs\JobQueue;
use Whity\Sdk\Jobs\PluginJobQueue;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginJobsInterface;

/**
 * The host's plugin job queue (WHIT-1031). The plugin comes from the loader's
 * identity and the workspace from {@see TenantContext}, so neither is something
 * a plugin can state.
 */
final class HostPluginJobQueue implements PluginJobQueue
{
    /** @var \Closure(): ?int */
    private readonly \Closure $tenant;

    /**
     * Declared names per plugin object, read once: getJobs() builds the handlers.
     *
     * @var \WeakMap<PluginInterface, array<string, true>>
     */
    private \WeakMap $declared;

    /**
     * @param (\Closure(): ?int)|null $tenant the workspace in scope; the request's or job's by default
     */
    public function __construct(
        private readonly JobRepository $jobs,
        ?\Closure $tenant = null,
    ) {
        $this->tenant = $tenant ?? static fn (): ?int => TenantContext::getTenantId();
        $this->declared = new \WeakMap();
    }

    public function for(PluginInterface $plugin): JobQueue
    {
        $slug = PluginIdentity::slugOf($plugin);
        $this->declared[$plugin] ??= $plugin instanceof PluginJobsInterface
            ? array_fill_keys(array_map('strval', array_keys($plugin->getJobs())), true)
            : [];

        return new HostJobQueue($this->jobs, $slug, $this->declared[$plugin], $this->tenant);
    }
}
