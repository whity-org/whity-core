<?php

declare(strict_types=1);

namespace Whity\Sdk\Audit;

use Whity\Sdk\PluginInterface;

/**
 * Host service (WHIT-1032). In a route handler, MCP tool or job:
 *
 *     \Whity\app(PluginAudit::class)->for($this)->record('order.refunded', 'order', $orderId, ['amount' => 500]);
 *
 * Pass the plugin itself. An object the host did not load is refused.
 *
 * @deprecated 1.124 Use `\Whity\app(\Whity\Sdk\PluginContext::class)->for($this)->audit()`
 *             (decision D3). This factory keeps working through SDK 2.0.x and goes in 2.1.
 */
interface PluginAudit
{
    public function for(PluginInterface $plugin): AuditTrail;
}
