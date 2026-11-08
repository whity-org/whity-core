<?php

declare(strict_types=1);

namespace Whity\Sdk\Settings;

/**
 * One plugin's settings in the current workspace (SDK 1.88, WHIT-1016). Get one
 * from {@see PluginSettings::for()}.
 *
 * No call names a workspace. The host uses the one the current request or job
 * runs in, at the moment of each call, so a store can't reach another
 * workspace's values. Every call throws a \RuntimeException when there is no
 * workspace.
 *
 * Keys are the BARE keys the plugin declared. A value comes back in its declared
 * type: `bool`, `int`, or `string` for `string` and `enum`.
 *
 * Secrets don't belong here: see {@see \Whity\Sdk\Secrets\PluginSecrets}.
 */
interface PluginSettingsStore
{
    /**
     * The value in this workspace: its own, else the deployment's, else the
     * declared default.
     *
     * @throws \InvalidArgumentException for a key this plugin did not declare
     */
    public function get(string $key): string|int|bool;

    /**
     * Every key this plugin declared, resolved as {@see self::get()} does.
     *
     * @return array<string, string|int|bool> bare key => value
     */
    public function all(): array;

    /**
     * Set this workspace's own value, checked against the declaration.
     * {@see \Whity\Sdk\Hooks\Events::PLUGIN_SETTINGS_CHANGED} fires when it changes.
     *
     * @throws \InvalidArgumentException for an undeclared key, a `global_only`
     *                                   key, or a value the declaration refuses
     */
    public function set(string $key, string|int|bool $value): void;

    /**
     * Remove this workspace's own value, so it follows the deployment's again.
     *
     * @throws \InvalidArgumentException for an undeclared or `global_only` key
     */
    public function reset(string $key): void;
}
