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
 */
interface PluginNotifier
{
    public function for(PluginInterface $plugin): Notifier;
}
