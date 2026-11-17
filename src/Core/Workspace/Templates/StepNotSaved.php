<?php

declare(strict_types=1);

namespace Whity\Core\Workspace\Templates;

/**
 * A template setup step that could not be saved, with the reason in plain
 * words for the person filling it in ("Open at least one day."). Only these
 * reach the response; any other exception is logged and answered generically
 * (WC-186).
 */
final class StepNotSaved extends \InvalidArgumentException
{
    public function __construct(public readonly string $words)
    {
        parent::__construct($words);
    }
}
