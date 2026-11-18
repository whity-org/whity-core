<?php

declare(strict_types=1);

namespace Whity\Sdk\Notification;

use Whity\Sdk\PluginInterface;

/**
 * Host service (WHIT-1030). In a route handler, MCP tool or job:
 *
 *     \Whity\app(PluginNotifier::class)->for($this)->toRole('order.shipped', $roleId, $notice);
 *
 * Pass the plugin itself. An object the host did not load is refused.
 *
 * @deprecated 1.124 Use `\Whity\app(\Whity\Sdk\PluginContext::class)->for($this)->notify()`
 *             (decision D3). This factory keeps working through SDK 2.0.x and goes in 2.1.
 */
interface PluginNotifier
{
    public function for(PluginInterface $plugin): Notifier;
}
