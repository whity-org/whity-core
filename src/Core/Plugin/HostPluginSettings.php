<?php

declare(strict_types=1);

namespace Whity\Core\Plugin;

use Whity\Core\Settings\SettingsService;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\Settings\PluginSettings;
use Whity\Sdk\Settings\PluginSettingsStore;

/**
 * The host's reader and writer of a plugin's own settings (SDK 1.88, WHIT-1016).
 *
 * The plugin passed in must be one the loader loaded, by name and class, so a
 * stub reporting another plugin's name gets nothing. A job's fresh instance of
 * its own plugin class still passes. Plugins run in the host's process, so this
 * is a boundary of the API, not a sandbox.
 *
 * Worker-safe: holds no per-request state.
 */
final class HostPluginSettings implements PluginSettings
{
    /**
     * @param \Closure(): array<PluginInterface> $loaded the plugins the loader loaded
     */
    public function __construct(
        private readonly SettingsService $settings,
        private readonly \Closure $loaded,
    ) {
    }

    public function for(PluginInterface $plugin): PluginSettingsStore
    {
        foreach (($this->loaded)() as $candidate) {
            if ($candidate->getName() === $plugin->getName() && $candidate::class === $plugin::class) {
                return new HostPluginSettingsStore($this->settings, $plugin->getName());
            }
        }

        throw new \InvalidArgumentException('Only a plugin the host has loaded can use its settings.');
    }
}
