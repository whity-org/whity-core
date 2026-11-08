<?php

declare(strict_types=1);

namespace Whity\Sdk\Billing;

/**
 * What is true for one licence, as the provider says now (SDK 1.86).
 *
 * `status`:
 *   - pending: checkout started, not paid yet;
 *   - paid: the first payment arrived;
 *   - renewed: a later period was paid;
 *   - past_due: a renewal failed and the provider is retrying;
 *   - cancelled: it ended or will not renew.
 * `periodEnd` is the ISO 8601 end of the paid period, when there is one.
 *
 * `testMode` is true when the licence was bought in the provider's test or
 * sandbox mode. No real money moved, so it is never a real licence: a store
 * on a live deployment must not grant one from it, and a seller that has since
 * switched to live reports such a licence as cancelled.
 */
final class LicenceSaleStatus
{
    public const PENDING = 'pending';
    public const PAID = 'paid';
    public const RENEWED = 'renewed';
    public const PAST_DUE = 'past_due';
    public const CANCELLED = 'cancelled';

    public function __construct(
        public readonly string $reference,
        public readonly string $product,
        public readonly string $status,
        public readonly ?string $periodEnd = null,
        public readonly bool $testMode = false,
    ) {
        if (!in_array($status, [self::PENDING, self::PAID, self::RENEWED, self::PAST_DUE, self::CANCELLED], true)) {
            throw new \InvalidArgumentException('Unknown licence status ' . $status);
        }
    }

    /** Whether the licence lets the installation use the plugin now. A test-mode licence never does. */
    public function isActive(): bool
    {
        return !$this->testMode && in_array($this->status, [self::PAID, self::RENEWED, self::PAST_DUE], true);
    }
}
