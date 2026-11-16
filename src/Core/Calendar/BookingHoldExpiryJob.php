<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use DateTimeImmutable;
use Whity\Core\Queue\QueueService;
use Whity\Sdk\JobInterface;

/**
 * Expires a payment hold nobody settled (ADR 0017 section 4). Queued when the
 * booking is held, delayed until the hold ends; does nothing when the booking
 * was confirmed or released meanwhile. Availability already ignores a lapsed
 * hold, so a late job never blocks a slot.
 */
final class BookingHoldExpiryJob implements JobInterface
{
    public const NAME = 'core.calendar.booking_hold_expiry';

    public function __construct(private readonly BookingService $bookings)
    {
    }

    public static function schedule(QueueService $queue, int $tenantId, int $bookingId, DateTimeImmutable $holdExpiresAt, DateTimeImmutable $now): void
    {
        $queue->dispatch(
            self::NAME,
            ['tenant_id' => $tenantId, 'booking_id' => $bookingId],
            [
                'tenant_id' => $tenantId,
                'delay' => max(0, $holdExpiresAt->getTimestamp() - $now->getTimestamp() + 5),
                'idempotency_key' => 'calendar-hold:' . $bookingId,
                'max_attempts' => 3,
            ],
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        $tenantId = (int) ($payload['tenant_id'] ?? 0);
        $bookingId = (int) ($payload['booking_id'] ?? 0);
        if ($tenantId <= 0 || $bookingId <= 0) {
            return ['skipped' => 'bad payload'];
        }

        return ['expired' => $this->bookings->expireHold($tenantId, $bookingId)];
    }
}
