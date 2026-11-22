<?php

declare(strict_types=1);

namespace Whity\Sdk\Accounting;

/**
 * A sales invoice or credit note (SDK 1.155, WHIT-1318, design section 32
 * §5.2, register AC.11).
 *
 * `kind` is `sale` or `sale_credit` (bills arrive with purchases, A3).
 * `status` is `draft`, `waiting_approval`, `issued`, `partly_paid`, `paid`,
 * `credited` or `cancelled`. `number` is given when issued and never changes;
 * an issued invoice is never edited, only credited. Amounts are minor units of
 * `currency`; `dueMinor` is what is still owed (total less paid and credited).
 * `einvoiceStatus` is `none`, `pending`, `accepted`, `rejected` or
 * `not_required`.
 */
final class Invoice
{
    /** @param list<InvoiceLine> $lines */
    public function __construct(
        public readonly int $id,
        public readonly string $kind,
        public readonly ?string $number,
        public readonly string $status,
        public readonly int $contactId,
        public readonly string $issueDate,
        public readonly ?string $dueDate,
        public readonly string $currency,
        public readonly bool $pricesIncludeTax,
        public readonly int $subtotalMinor,
        public readonly int $taxMinor,
        public readonly int $totalMinor,
        public readonly int $paidMinor,
        public readonly int $creditedMinor,
        public readonly int $dueMinor,
        public readonly ?int $creditsInvoiceId,
        public readonly ?int $entryId,
        public readonly string $einvoiceStatus,
        public readonly ?string $externalRef,
        public readonly array $lines,
    ) {
    }

    /** @param array<string, mixed> $data the API's `AccountingInvoice` */
    public static function fromArray(array $data): self
    {
        $int = static fn (string $key): int => is_int($data[$key] ?? null) ? $data[$key] : 0;
        $text = static fn (string $key): string => is_scalar($data[$key] ?? null) ? (string) $data[$key] : '';
        $lines = [];
        foreach (is_array($data['lines'] ?? null) ? $data['lines'] : [] as $line) {
            if (is_array($line)) {
                /** @var array<string, mixed> $line */
                $lines[] = InvoiceLine::fromArray($line);
            }
        }

        return new self(
            id: $int('id'),
            kind: $text('kind'),
            number: is_string($data['number'] ?? null) ? $data['number'] : null,
            status: $text('status'),
            contactId: $int('contact_id'),
            issueDate: $text('issue_date'),
            dueDate: is_string($data['due_date'] ?? null) ? $data['due_date'] : null,
            currency: $text('currency'),
            pricesIncludeTax: ($data['prices_include_tax'] ?? false) === true,
            subtotalMinor: $int('subtotal_minor'),
            taxMinor: $int('tax_minor'),
            totalMinor: $int('total_minor'),
            paidMinor: $int('paid_minor'),
            creditedMinor: $int('credited_minor'),
            dueMinor: $int('due_minor'),
            creditsInvoiceId: is_int($data['credits_invoice_id'] ?? null) ? $data['credits_invoice_id'] : null,
            entryId: is_int($data['entry_id'] ?? null) ? $data['entry_id'] : null,
            einvoiceStatus: $text('einvoice_status'),
            externalRef: is_string($data['external_ref'] ?? null) ? $data['external_ref'] : null,
            lines: $lines,
        );
    }
}
