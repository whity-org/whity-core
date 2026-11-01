<?php

declare(strict_types=1);

namespace Whity\Sdk\Secrets;

use Whity\Sdk\PluginInterface;

/**
 * Where a plugin keeps secrets: API keys, signing secrets, connection
 * credentials (SDK 1.60, WHIT-838). Resolve it with
 * `\Whity\app(\Whity\Sdk\Secrets\PluginSecrets::class)` in a route handler, an
 * MCP tool handler or a job, then take the plugin's own store:
 *
 *     $secrets = \Whity\app(PluginSecrets::class)->for($this);
 *     $secrets->set($tenantId, 'connection', $json);
 *
 * Settings refuse secrets on purpose ({@see \Whity\Sdk\Settings\PluginSettingsInterface}):
 * settings are read back on admin screens and over the API. This is the place
 * for what must never be read back.
 *
 * Each plugin's secrets are kept apart, under the plugin's name, and each
 * workspace's apart from every other's. The host implements it; a plugin never
 * does.
 */
interface PluginSecrets
{
    /** The store for $plugin's own secrets. */
    public function for(PluginInterface $plugin): SecretStore;
}
