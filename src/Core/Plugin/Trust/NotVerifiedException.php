<?php

declare(strict_types=1);

namespace Whity\Core\Plugin\Trust;

/** Only a Verified plugin may be granted agent context (WHIT-993). */
final class NotVerifiedException extends \RuntimeException
{
    public function __construct(public readonly string $plugin)
    {
        parent::__construct("{$plugin} is not Verified: only a Verified plugin, installed from its signed package, can reach AI agents");
    }
}
