<?php

declare(strict_types=1);

namespace Whity\Core\i18n;

/**
 * A caller sent a name map that cannot be stored.
 *
 * `$clientMessage` is written for the caller and goes back as a 422. Catch
 * sites return it, never `->getMessage()` (WC-186).
 */
final class LocalizedTextRejected extends \InvalidArgumentException
{
    public function __construct(public readonly string $clientMessage)
    {
        parent::__construct($clientMessage);
    }
}
