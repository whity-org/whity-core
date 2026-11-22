<?php

declare(strict_types=1);

namespace Whity\Core\Accounting;

use Whity\Core\Inventory\UnitCatalog;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Contribute\WorkspaceQuoteConversion;
use Whity\Sdk\Hooks\HookVetoException;
use Whity\Sdk\Sales\ConversionCheck;
use Whity\Sdk\Sales\ConversionResult;
use Whity\Sdk\Sales\QuoteSnapshot;

/**
 * An accepted quote turned into a draft invoice (WHIT-1318 A2a, design
 * section 34 §3.3, register QT.6): core accounting's `invoice` target. The
 * draft keeps the quote's customer, prices, discounts and tax categories; a
 * person checks it and issues it.
 */
final class InvoiceQuoteConversion implements WorkspaceQuoteConversion
{
    /** What a refusal names as its source. */
    private const EVENT = 'sales.quote.converting';

    /** @var \Closure(): ?int */
    private readonly \Closure $workspace;

    /** @param (\Closure(): ?int)|null $workspace the call's workspace; {@see TenantContext} by default */
    public function __construct(
        private readonly Invoices $invoices,
        private readonly UnitCatalog $units,
        ?\Closure $workspace = null,
    ) {
        $this->workspace = $workspace ?? static fn (): ?int => TenantContext::getTenantId();
    }

    public function key(): string
    {
        return 'invoice';
    }

    public function label(): array
    {
        return ['en' => 'Invoice', 'ar' => 'فاتورة'];
    }

    public function canConvert(QuoteSnapshot $quote, int $actingProfileId): ConversionCheck
    {
        return $quote->lines === [] ? ConversionCheck::no('The quote has no lines to invoice') : ConversionCheck::yes();
    }

    public function convert(QuoteSnapshot $quote, int $actingProfileId): ConversionResult
    {
        $tenantId = ($this->workspace)();
        if ($tenantId === null) {
            throw new HookVetoException(self::EVENT, 'There is no workspace to invoice in');
        }
        $lines = [];
        foreach ($quote->lines as $l) {
            $line = [
                'description' => $l->description,
                'quantity' => $l->quantity,
                'unit_price_minor' => $l->priceMinor,
                'discount_bp' => $l->discountBp,
                'tax_category' => $l->taxCategory !== '' ? $l->taxCategory : ($l->taxBp > 0 ? 'standard' : 'zero'),
            ];
            if ($l->variantId !== null) {
                $line['variant_id'] = $l->variantId;
                if ($l->unitId !== null && is_string($code = $this->units->row($tenantId, $l->unitId)['code'] ?? null)) {
                    $line['unit'] = $code;
                }
            }
            $lines[] = $line;
        }
        try {
            $made = $this->invoices->create($tenantId, $actingProfileId, [
                'contact_id' => $quote->contactId,
                'prices_include_tax' => $quote->pricesIncludeTax,
                'notes' => trim('Quote ' . $quote->number . "\n" . $quote->notes),
                'lines' => $lines,
            ], ['quote_id' => $quote->id, 'external_ref' => 'quote:' . $quote->id]);
        } catch (AccountingRejectedException $e) {
            throw new HookVetoException(self::EVENT, $e->clientMessage);
        }
        $id = (int) $made['invoice']['id'];

        return new ConversionResult($id, 'Draft invoice for ' . $quote->contactName, '/money/invoices/' . $id);
    }
}
