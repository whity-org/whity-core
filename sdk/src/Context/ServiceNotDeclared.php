<?php

declare(strict_types=1);

namespace Whity\Sdk\Context;

use Whity\Sdk\SdkException;

/**
 * The plugin asked its context for a service it did not declare (SDK 1.151,
 * #1693). Add the name to
 * {@see \Whity\Sdk\Contribute\PluginCapabilitiesInterface::getServices()}.
 */
final class ServiceNotDeclared extends \RuntimeException implements SdkException
{
    public static function named(string $plugin, string $service): self
    {
        return new self("The {$plugin} plugin did not declare the '{$service}' service in getServices().");
    }
}
