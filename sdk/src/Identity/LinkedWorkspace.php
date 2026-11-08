<?php

declare(strict_types=1);

namespace Whity\Sdk\Identity;

/** A workspace a linked person may act in, for one permission (SDK 1.84). */
final class LinkedWorkspace
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
    ) {
    }
}
