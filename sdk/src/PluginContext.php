<?php

declare(strict_types=1);

namespace Whity\Sdk;

use Whity\Sdk\Context\PluginScope;

/**
 * The one way into the host's services for a plugin (SDK 1.124, decision D3).
 *
 * In a route handler, an MCP tool handler, a job or a hook listener:
 *
 *     $ctx = \Whity\app(PluginContext::class)->for($this);
 *     $interval = $ctx->settings()->get('sync_interval');
 *     $ctx->secrets()->set('api_key', $key);
 *     $ctx->files()->put('exports/march.csv', $csv, 'text/csv');
 *     $ctx->notify()->toRole('order.shipped', $roleId, $notice);
 *     $ctx->jobs()->enqueue('catalog.sync');
 *     $ctx->audit()->record('order.refunded', 'order', $orderId);
 *     $ctx->documents()->issue($request);
 *
 * Everything it gives acts as this plugin, in the workspace the request or job
 * runs in (decision D2). No call takes a workspace, so none can name another.
 *
 * It replaces the seven `->for($this)` factories (`PluginSettings`,
 * `PluginSecrets`, `PluginFiles`, `PluginNotifier`, `PluginJobQueue`,
 * `PluginAudit`, `PluginDocuments`). They keep working through 2.0.x and go in
 * 2.1 (docs/wiki/Plugin-SDK-2.0-Plan.md). The host implements this; a plugin
 * never does. On a host older than 1.124 it isn't there: `\Whity\app()`
 * throws, so a plugin that supports older hosts checks the version first.
 */
interface PluginContext
{
    /**
     * This plugin's view of the host.
     *
     * @throws \InvalidArgumentException when $plugin has no usable name
     */
    public function for(PluginInterface $plugin): PluginScope;
}
