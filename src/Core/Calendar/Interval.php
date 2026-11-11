<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use DateTimeImmutable;
use DateTimeZone;

/**
 * A half-open span of time `[start, end)` held in UTC (ADR 0017, section 2).
 *
 * Half-open so that a booking ending at 10:00 and one starting at 10:00 do not
 * overlap. Immutable and stateless — worker-safe.
 */
final class Interval
{
    public readonly DateTimeImmutable $start;
    public readonly DateTimeImmutable $end;

    public function __construct(DateTimeImmutable $start, DateTimeImmutable $end)
    {
        $utc = new DateTimeZone('UTC');
        $this->start = $start->setTimezone($utc);
        $this->end = $end->setTimezone($utc);
    }

    public function overlaps(self $other): bool
    {
        return $this->start < $other->end && $other->start < $this->end;
    }

    public function contains(self $inner): bool
    {
        return $this->start <= $inner->start && $inner->end <= $this->end;
    }

    public function isEmpty(): bool
    {
        return $this->end <= $this->start;
    }

    /**
     * This interval with every part covered by `$cuts` removed.
     *
     * @param list<self> $cuts
     * @return list<self>
     */
    public function subtract(array $cuts): array
    {
        $pieces = [$this];
        foreach ($cuts as $cut) {
            $next = [];
            foreach ($pieces as $piece) {
                if (!$piece->overlaps($cut)) {
                    $next[] = $piece;
                    continue;
                }
                if ($piece->start < $cut->start) {
                    $next[] = new self($piece->start, $cut->start);
                }
                if ($cut->end < $piece->end) {
                    $next[] = new self($cut->end, $piece->end);
                }
            }
            $pieces = $next;
        }

        return $pieces;
    }

    /**
     * Sort and merge touching or overlapping intervals.
     *
     * @param list<self> $intervals
     * @return list<self>
     */
    public static function merge(array $intervals): array
    {
        $intervals = array_values(array_filter($intervals, static fn (self $i): bool => !$i->isEmpty()));
        usort($intervals, static fn (self $a, self $b): int => $a->start <=> $b->start);
        $merged = [];
        foreach ($intervals as $interval) {
            $last = $merged === [] ? null : $merged[count($merged) - 1];
            if ($last !== null && $interval->start <= $last->end) {
                $merged[count($merged) - 1] = new self($last->start, max($last->end, $interval->end));
                continue;
            }
            $merged[] = $interval;
        }

        return $merged;
    }

    /** `Y-m-d H:i:s` in UTC, the stored form. */
    public static function toDb(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** A stored UTC timestamp read back. */
    public static function fromDb(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable(substr($value, 0, 19), new DateTimeZone('UTC'));
    }

    /** ISO 8601 with `Z`, the API's output form. */
    public static function toApi(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * Read an API instant. It must carry an offset or `Z`: a bare local time
     * is ambiguous across zones, so it is refused rather than guessed.
     */
    public static function fromApi(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})$/', $value) !== 1) {
            return null;
        }
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }
}
