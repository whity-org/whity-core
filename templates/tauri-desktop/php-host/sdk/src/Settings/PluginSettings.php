<?php

declare(strict_types=1);

namespace Whity\Sdk\Settings;

use Whity\Sdk\PluginInterface;

/**
 * A plugin's way to read and write the settings it declared (SDK 1.88,
 * WHIT-1016). Resolve it with `\Whity\app(PluginSettings::class)` in a route
 * handler, an MCP tool handler or a job, then take the plugin's own store:
 *
 *     $settings = \Whity\app(PluginSettings::class)->for($this);
 *     $interval = $settings->get('sync_interval');
 *
 * It only reaches the keys this plugin declared
 * ({@see PluginSettingsInterface}), never core's or another plugin's. The host
 * implements it; a plugin never does.
 */
interface PluginSettings
{
    /**
     * The store for $plugin's own settings.
     *
     * @throws \InvalidArgumentException when $plugin is not a plugin the host has loaded
     */
    public function for(PluginInterface $plugin): PluginSettingsStore;
}
