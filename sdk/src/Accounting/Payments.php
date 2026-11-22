<?php

declare(strict_types=1);

namespace Whity\Sdk\Accounting;

/**
 * Payments received, what they settle, and transfers between the workspace's
 * own accounts, for a plugin (SDK 1.156, WHIT-1318, design section 32 §5.4,
 * §5.5, register AC.13, AC.17). Whity Pay records its payments here.
 *
 * Reach it through `$ctx->accounting()->payments()` or the container. Both
 * read. Only the context's copy writes: its payments belong to your plugin
 * and their `externalRef` is kept under your plugin's name. The container's
 * writes refuse with reason `use_plugin_context`. Writes act as a person
 * holding `payments:write`.
 *
 * It works in the workspace the call runs in; with none, it throws
 * {@see AccountingRejected} `no_workspace`.
 */
interface Payments
{
    /**
     * Records and posts money a customer paid, settling `invoiceId` when
     * given. A provider's fee goes to Bank and payment fees.
     *
     * @throws AccountingRejected
     */
    public function recordReceived(PaymentRequest $request): Payment;

    /**
     * Records and posts money paid to a supplier (SDK 1.157): `contactId` is
     * the supplier and `invoiceId` the bill it settles. A cheque dated later
     * waits on Cheques we wrote until it clears.
     *
     * @throws AccountingRejected
     */
    public function recordMade(PaymentRequest $request): Payment;

    /**
     * Settles an invoice with what a payment has left: all of it, or
     * `amountMinor`.
     *
     * @throws AccountingRejected `conflict` when the invoice has nothing left to pay
     */
    public function allocate(int $paymentId, int $invoiceId, int $actingProfileId, ?int $amountMinor = null): Payment;

    /**
     * Moves money between two of the workspace's own accounts: a payment
     * provider's payout from Money at payment providers to the bank, or cash
     * to the bank.
     *
     * @throws AccountingRejected
     */
    public function recordTransfer(int $fromAccountId, int $toAccountId, int $amountMinor, int $actingProfileId, ?string $paidOn = null, string $reference = '', ?string $externalRef = null): Payment;

    /** @throws AccountingRejected `not_found` */
    public function get(int $paymentId): Payment;

    /**
     * Newest first. `filter`: `direction` (`in`, `transfer`), `contactId`,
     * `method`, `chequeStatus`, `from`, `to`.
     *
     * @param array{direction?: string, contactId?: int, method?: string, chequeStatus?: string, from?: string, to?: string} $filter
     * @return list<Payment>
     * @throws AccountingRejected
     */
    public function list(array $filter = [], int $limit = 50, int $offset = 0): array;
}
