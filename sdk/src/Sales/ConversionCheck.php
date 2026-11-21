<?php

declare(strict_types=1);

namespace Whity\Sdk\Sales;

/** Whether a quote can become a target now (SDK 1.132, WHIT-1321). */
final class ConversionCheck
{
    private function __construct(
        public readonly bool $possible,
        public readonly string $reason,
    ) {
    }

    public static function yes(): self
    {
        return new self(true, '');
    }

    /** A plain reason a person can act on: "Add a place to ship from first". */
    public static function no(string $reason): self
    {
        return new self(false, $reason);
    }
}
