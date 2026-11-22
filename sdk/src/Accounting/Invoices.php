<?php

declare(strict_types=1);

namespace Whity\Sdk\Accounting;

/**
 * Sales invoices and credit notes, for a plugin (SDK 1.155, WHIT-1318, design
 * section 32 §5.2, register AC.11). Bills arrive with purchases (A3).
 *
 * Reach it through the plugin context, `$ctx->accounting()->invoices()`, or
 * the container (`\Whity\app(Invoices::class)`). Both read. Only the
 * context's copy writes: what it drafts belongs to your plugin, and its
 * `externalRef` is kept under your plugin's name, so a retried call returns
 * the same invoice. The container's writes refuse with reason
 * `use_plugin_context`. Writes act as a person holding `invoices:write`.
 *
 * It works in the workspace the call runs in; with none, it throws
 * {@see AccountingRejected} `no_workspace`.
 */
interface Invoices
{
    /**
     * Newest first. `filter`: `kind` (`sale`, `sale_credit`), `status` (or
     * `overdue`), `contactId`, `from`, `to` (issue dates).
     *
     * @param array{kind?: string, status?: string, contactId?: int, from?: string, to?: string} $filter
     * @return list<Invoice>
     * @throws AccountingRejected
     */
    public function list(array $filter = [], int $limit = 50, int $offset = 0): array;

    /** @throws AccountingRejected `not_found` */
    public function get(int $invoiceId): Invoice;

    /**
     * A draft sales invoice. `data`: `contactId` (a customer), `issueDate`,
     * `dueDate` or `paymentTermsDays`, `pricesIncludeTax`, `notes`, `lines`
     * (each `variantId` or `description`, `quantity` as text such as "2.5",
     * `unit`, `unitPriceMinor`, `discountBp`, `taxCode` or `taxCategory`,
     * `accountId`). `externalRef` makes it idempotent.
     *
     * @param array<string, mixed> $data
     * @throws AccountingRejected
     */
    public function createDraft(array $data, int $actingProfileId, ?string $externalRef = null): Invoice;

    /**
     * Issues a draft: numbered, frozen and posted, or sent for approval when
     * the workspace has an invoice route (it comes back `waiting_approval`).
     *
     * @throws AccountingRejected `vetoed` when a plugin refused it
     */
    public function issue(int $invoiceId, int $actingProfileId): Invoice;

    /**
     * A credit note for an issued invoice, issued at once: every line, or
     * `lines` as `[['lineId' => 12, 'quantity' => '1']]`.
     *
     * @param list<array{lineId: int, quantity?: string}>|null $lines
     * @throws AccountingRejected
     */
    public function credit(int $invoiceId, int $actingProfileId, ?array $lines = null, string $reason = '', ?string $externalRef = null): Invoice;

    /** Cancels a draft or one waiting for approval. @throws AccountingRejected */
    public function cancelDraft(int $invoiceId, int $actingProfileId): Invoice;
}
