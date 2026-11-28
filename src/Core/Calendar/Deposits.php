<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

/**
 * Deposits (WHIT-1445, design section 39 §9.3, register AP.26).
 *
 * A service's deposit is none, a fixed amount, or a percentage of the price,
 * in the booking's currency and stored in minor units (CU.1–CU.4: JOD 5.000
 * is 5000, $12.50 is 1250). What happened to a booking's deposit is worked
 * out from the booking each time, never stored twice:
 *
 * - `due`: the booking waits for its payment;
 * - `paid`: paid, the visit still to come;
 * - `kept`: a late cancel, or they didn't come;
 * - `to_refund`: an early cancel or "we cancelled": waiting for a person with
 *   `calendar:manage` to press Refund, as money only moves on a person's click;
 * - `refunded`: someone pressed Refund;
 * - `applied`: they came, so it counts toward the bill;
 * - `not_paid`: never paid (the hold expired, or it was cancelled first);
 * - `none`: no deposit.
 */
final class Deposits
{
    public const NONE = 'none';
    public const FIXED = 'fixed';
    public const PERCENT = 'percent';

    public const DUE = 'due';
    public const PAID = 'paid';
    public const KEPT = 'kept';
    public const TO_REFUND = 'to_refund';
    public const REFUNDED = 'refunded';
    public const APPLIED = 'applied';
    public const NOT_PAID = 'not_paid';

    /** The states a person may press Refund in. */
    public const REFUNDABLE = [self::TO_REFUND, self::KEPT];

    /**
     * A service's deposit as the API gives it.
     *
     * @return array{kind: string, amount_minor: ?int, percent: ?int}
     */
    public static function fromRow(string $kind, int $value): array
    {
        return match ($kind) {
            self::FIXED => $value > 0 ? ['kind' => self::FIXED, 'amount_minor' => $value, 'percent' => null] : self::none(),
            self::PERCENT => $value >= 1 && $value <= 100 ? ['kind' => self::PERCENT, 'amount_minor' => null, 'percent' => $value] : self::none(),
            default => self::none(),
        };
    }

    /**
     * What a caller sent, checked against the service's price.
     *
     * @return array{kind: string, amount_minor: ?int, percent: ?int}
     */
    public static function validate(mixed $raw, ?int $priceMinor): array
    {
        if ($raw === null) {
            return self::none();
        }
        if (!is_array($raw) || !is_string($raw['kind'] ?? null)) {
            throw new CalendarRejectedException('deposit is {kind: none}, {kind: fixed, amount_minor} or {kind: percent, percent}', 422, ['field' => 'deposit']);
        }

        return match ($raw['kind']) {
            self::NONE => self::none(),
            self::FIXED => self::fixed($raw['amount_minor'] ?? null, $priceMinor),
            self::PERCENT => self::percent($raw['percent'] ?? null, $priceMinor),
            default => throw new CalendarRejectedException('deposit kind is none, fixed or percent', 422, ['field' => 'deposit']),
        };
    }

    /**
     * Stored values, read leniently: anything unreadable is none.
     *
     * @return array{kind: string, amount_minor: ?int, percent: ?int}
     */
    public static function clean(mixed $raw): array
    {
        if (!is_array($raw)) {
            return self::none();
        }
        $kind = is_string($raw['kind'] ?? null) ? $raw['kind'] : self::NONE;

        return self::fromRow($kind, (int) ($kind === self::FIXED ? ($raw['amount_minor'] ?? 0) : ($raw['percent'] ?? 0)));
    }

    /**
     * The deposit for a booking at `$priceMinor` (the person's own price), in
     * minor units, or null for none. A fixed deposit is never more than the
     * price; a percentage needs a price and rounds to the nearest minor unit.
     *
     * @param array{kind: string, amount_minor: ?int, percent: ?int} $deposit
     */
    public static function amountFor(array $deposit, ?int $priceMinor): ?int
    {
        $amount = match ($deposit['kind']) {
            self::FIXED => $priceMinor !== null ? min((int) $deposit['amount_minor'], $priceMinor) : (int) $deposit['amount_minor'],
            self::PERCENT => $priceMinor !== null ? (int) round($priceMinor * (int) $deposit['percent'] / 100) : null,
            default => null,
        };

        return $amount !== null && $amount > 0 ? $amount : null;
    }

    /**
     * What happened to a booking's deposit, from the booking.
     *
     * @param array<string, mixed> $booking a calendar_bookings row
     */
    public static function state(array $booking): string
    {
        $amount = isset($booking['deposit_minor']) ? (int) $booking['deposit_minor'] : 0;
        if ($amount <= 0) {
            return self::NONE;
        }
        if (($booking['deposit_refunded_at'] ?? null) !== null) {
            return self::REFUNDED;
        }
        $status = (string) ($booking['status'] ?? '');
        if (($booking['deposit_paid_at'] ?? null) === null) {
            return $status === BookingRepository::STATUS_PENDING_PAYMENT ? self::DUE : self::NOT_PAID;
        }
        if ($status === BookingRepository::STATUS_CANCELLED) {
            return ($booking['cancel_kind'] ?? null) === BookingRepository::CANCEL_LATE ? self::KEPT : self::TO_REFUND;
        }
        $attendance = $booking['attendance'] ?? null;
        if ($attendance === BookingRepository::NO_SHOW) {
            return self::KEPT;
        }
        if ($attendance === BookingRepository::ATTENDED || ($booking['finished_at'] ?? null) !== null) {
            return self::APPLIED;
        }

        return self::PAID;
    }

    /** @return array{kind: string, amount_minor: ?int, percent: ?int} */
    private static function none(): array
    {
        return ['kind' => self::NONE, 'amount_minor' => null, 'percent' => null];
    }

    /** @return array{kind: string, amount_minor: ?int, percent: ?int} */
    private static function fixed(mixed $amount, ?int $priceMinor): array
    {
        if (!is_int($amount) || $amount <= 0) {
            throw new CalendarRejectedException('A fixed deposit is a whole number of minor units above 0 (JOD 5.000 is 5000, $12.50 is 1250).', 422, ['field' => 'deposit']);
        }
        if ($priceMinor !== null && $amount > $priceMinor) {
            throw new CalendarRejectedException('The deposit can be no more than the price.', 422, ['field' => 'deposit']);
        }

        return ['kind' => self::FIXED, 'amount_minor' => $amount, 'percent' => null];
    }

    /** @return array{kind: string, amount_minor: ?int, percent: ?int} */
    private static function percent(mixed $percent, ?int $priceMinor): array
    {
        if (!is_int($percent) || $percent < 1 || $percent > 100) {
            throw new CalendarRejectedException('A deposit percentage is a whole number from 1 to 100.', 422, ['field' => 'deposit']);
        }
        if ($priceMinor === null) {
            throw new CalendarRejectedException('Give the service a price before asking for a percentage of it.', 422, ['field' => 'deposit']);
        }

        return ['kind' => self::PERCENT, 'amount_minor' => null, 'percent' => $percent];
    }
}
