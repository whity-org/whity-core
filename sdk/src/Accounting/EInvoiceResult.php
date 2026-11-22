<?php

declare(strict_types=1);

namespace Whity\Sdk\Accounting;

/**
 * What a tax authority answered about an invoice (SDK 1.155, WHIT-1318,
 * design section 32 §7.4), returned by
 * {@see \Whity\Sdk\Contribute\WorkspaceEInvoicing::submit()}.
 *
 * `status` is `accepted`, `rejected` or `pending` (sent, no answer yet).
 * `uuid`, `reference`, `qr` and `counter` are what the authority gave back and
 * what the printed tax invoice shows; `message` is the authority's reason, in
 * plain words, when it refused.
 */
final class EInvoiceResult
{
    public const STATUSES = ['accepted', 'rejected', 'pending'];

    public function __construct(
        public readonly string $status,
        public readonly ?string $uuid = null,
        public readonly ?string $reference = null,
        public readonly ?string $qr = null,
        public readonly ?int $counter = null,
        public readonly string $message = '',
    ) {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('EInvoiceResult status is accepted, rejected or pending');
        }
    }

    public static function accepted(string $uuid, string $reference, ?string $qr = null, ?int $counter = null): self
    {
        return new self('accepted', $uuid, $reference, $qr, $counter);
    }

    public static function rejected(string $message): self
    {
        return new self('rejected', message: $message);
    }

    public static function pending(?string $uuid = null): self
    {
        return new self('pending', $uuid);
    }
}
