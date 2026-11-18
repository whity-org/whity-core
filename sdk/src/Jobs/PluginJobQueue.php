<?php

declare(strict_types=1);

namespace Whity\Sdk\Jobs;

use Whity\Sdk\PluginInterface;

/**
 * Host service (WHIT-1031). In a route handler, MCP tool or job:
 *
 *     $id = \Whity\app(PluginJobQueue::class)->for($this)->enqueue('catalog.sync', ['page' => 1]);
 *
 * Pass the plugin itself. An object the host did not load is refused.
 *
 * @deprecated 1.124 Use `\Whity\app(\Whity\Sdk\PluginContext::class)->for($this)->jobs()`
 *             (decision D3). This factory keeps working through SDK 2.0.x and goes in 2.1.
 */
interface PluginJobQueue
{
    public function for(PluginInterface $plugin): JobQueue;
}
