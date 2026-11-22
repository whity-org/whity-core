<?php

declare(strict_types=1);

namespace Whity\Sdk\Sales;

/**
 * What a conversion made (SDK 1.132, WHIT-1321): its id, a label people read
 * ("SO-0042") and the app path that opens it ("/inventory/sales-orders/42").
 */
final class ConversionResult
{
    public function __construct(
        public readonly int $id,
        public readonly string $label,
        public readonly string $link = '',
    ) {
    }
}
