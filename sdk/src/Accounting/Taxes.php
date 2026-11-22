<?php

declare(strict_types=1);

namespace Whity\Sdk\Accounting;

/**
 * The workspace's tax codes and the arithmetic of a line (SDK 1.155,
 * WHIT-1318, design section 32 §7.1, register AC.17–AC.20). Read-only.
 *
 * Reach it through `$ctx->accounting()->taxes()` or the container. It works
 * in the workspace the call runs in.
 */
interface Taxes
{
    /**
     * @param string|null $direction `output` (sales) or `input` (purchases); null for both
     * @return list<TaxCode>
     * @throws AccountingRejected
     */
    public function codes(?string $direction = null): array;

    /**
     * The code for an item's tax category (`standard`, `reduced_10`, `zero`,
     * `exempt`; empty is standard) in a direction, valid on a date
     * (`YYYY-MM-DD`). Null when the workspace's pack has none.
     *
     * @throws AccountingRejected
     */
    public function codeFor(string $taxCategory, string $direction, string $date): ?TaxCode;

    /**
     * Net, tax and total of a line amount (minor units) at a rate, rounded
     * half away from zero once, as invoices do. With `inclusive` the amount
     * already includes the tax.
     *
     * @return array{net: int, tax: int, total: int}
     */
    public function compute(int $amountMinor, int $rateBp, bool $inclusive = false): array;
}
