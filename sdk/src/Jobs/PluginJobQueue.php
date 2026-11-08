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
 */
interface PluginJobQueue
{
    public function for(PluginInterface $plugin): JobQueue;
}
