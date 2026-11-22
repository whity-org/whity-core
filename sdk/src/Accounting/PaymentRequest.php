<?php

declare(strict_types=1);

namespace Whity\Sdk\Accounting;

/**
 * Money a customer paid, to record (SDK 1.156, WHIT-1318, design section 32
 * §5.4, §5.5, register AC.17).
 *
 * `amountMinor` is in minor units of the books' base currency. `method` is
 * `cash`, `bank_transfer`, `cliq`, `card`, `cheque`, `whity_pay` or `other`;
 * it picks the account the money goes to (Money at payment providers for a
 * card or Whity Pay). `feeMinor` is what a payment provider kept. `invoiceId`
 * settles that invoice with it; leave it null to keep it as the customer's
 * credit. `externalRef` makes it idempotent: Whity Pay passes its own payment
 * id, so a webhook delivered twice records once. A cheque gives
 * `chequeNumber`, and `chequeDueOn` when it is dated later.
 */
final class PaymentRequest
{
    public function __construct(
        public readonly int $actingProfileId,
        public readonly int $contactId,
        public readonly int $amountMinor,
        public readonly string $method = 'bank_transfer',
        public readonly ?string $paidOn = null,
        public readonly ?int $invoiceId = null,
        public readonly int $feeMinor = 0,
        public readonly string $reference = '',
        public readonly ?string $externalRef = null,
        public readonly ?string $chequeNumber = null,
        public readonly ?string $chequeBank = null,
        public readonly ?string $chequeDueOn = null,
    ) {
    }
}
