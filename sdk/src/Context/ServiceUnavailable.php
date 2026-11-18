<?php

declare(strict_types=1);

namespace Whity\Sdk\Context;

use Whity\Sdk\SdkException;

/**
 * The host doesn't provide this service (SDK 1.124): it is older than the
 * service, or this entry point didn't bind it. The message names the service.
 */
final class ServiceUnavailable extends \RuntimeException implements SdkException
{
    public static function named(string $service): self
    {
        return new self("This host doesn't provide {$service}.");
    }
}
