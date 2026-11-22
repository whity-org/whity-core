<?php

declare(strict_types=1);

namespace Whity\Core\Plugin;

use Whity\Sdk\Context\PluginScope;
use Whity\Sdk\PluginContext;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginNamespace;

/**
 * The host's {@see PluginContext} (SDK 1.124, decision D3).
 *
 * It holds nothing of its own: each store comes from the factory already bound
 * at this entry point, asked for this plugin, the first time the plugin uses
 * it. So the context reaches exactly what the factories reach, with the same
 * checks, at the web, CLI and queue-worker entry points alike.
 */
final class HostPluginContext implements PluginContext
{
    public function for(PluginInterface $plugin): PluginScope
    {
        if (PluginNamespace::slug($plugin->getName()) === null) {
            throw new \InvalidArgumentException('This plugin has no usable name.');
        }

        return new HostPluginScope($plugin);
    }
}
