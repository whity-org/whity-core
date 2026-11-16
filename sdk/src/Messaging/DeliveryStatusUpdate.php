<?php

declare(strict_types=1);

namespace Whity\Sdk\Messaging;

/**
 * The provider's news about a message already sent (SDK 1.97): delivered, read,
 * or failed after it was accepted.
 *
 * Matched to core's message by the `externalId` the channel returned from
 * {@see SendOutcome::sent()}. A status never moves backwards: `read` after
 * `delivered` sticks, a late `delivered` after `read` is ignored.
 */
final class DeliveryStatusUpdate
{
    public const DELIVERED = 'delivered';
    public const READ = 'read';
    public const FAILED = 'failed';

    public const STATUSES = [self::DELIVERED, self::READ, self::FAILED];

    /**
     * @param string      $externalId The provider's id for the message.
     * @param string      $status     One of {@see STATUSES}.
     * @param string|null $detail     Why it failed, in plain words.
     * @param string|null $at         When, ISO 8601. Null: now.
     */
    public function __construct(
        public readonly string $externalId,
        public readonly string $status,
        public readonly ?string $detail = null,
        public readonly ?string $at = null,
    ) {
        if ($externalId === '') {
            throw new \InvalidArgumentException('A status update needs the provider message id.');
        }
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException("Unknown delivery status '{$status}'.");
        }
    }
}
