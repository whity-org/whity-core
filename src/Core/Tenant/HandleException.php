<?php

declare(strict_types=1);

namespace Whity\Core\Tenant;

/**
 * A handle that can't be used or changed. The message is one of the
 * {@see HandleRules} codes (`taken`, `reserved`, `too_soon`, …), which the API
 * turns into a plain sentence.
 */
final class HandleException extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
