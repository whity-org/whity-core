<?php

declare(strict_types=1);

namespace Whity\Sdk\Inbox;

/**
 * The one order of the merged inbox (SDK 1.97, decided in ADR 0016 for #881).
 *
 * 1. Urgent items first. Urgency is set per item by its source, so an approval
 *    that is due can rise without the host guessing what "due" means.
 * 2. Then the newest first, by the item's timestamp.
 * 3. Then by source key, ascending, so two sources with the same timestamp
 *    always interleave the same way.
 * 4. Then by id, descending, compared as numbers when both are numeric.
 *
 * The order is total, which is what lets a cursor name a position exactly.
 */
final class InboxOrder
{
    private function __construct()
    {
    }

    /**
     * Negative when `a` comes first, positive when `b` does, 0 only for the
     * same item of the same source.
     */
    public static function compare(string $sourceA, InboxItem $a, string $sourceB, InboxItem $b): int
    {
        return self::compareKeys(
            $a->urgent,
            $a->timestamp,
            $sourceA,
            $a->id,
            $b->urgent,
            $b->timestamp,
            $sourceB,
            $b->id
        );
    }

    /** Whether the item comes strictly after the cursor. */
    public static function isAfter(string $source, InboxItem $item, InboxCursor $cursor): bool
    {
        return self::compareKeys(
            $item->urgent,
            $item->timestamp,
            $source,
            $item->id,
            $cursor->urgent,
            $cursor->timestamp,
            $cursor->source,
            $cursor->id
        ) > 0;
    }

    /**
     * A timestamp as whole microseconds since the epoch, so `2026-11-11 05:00:00`
     * from Postgres and `2026-11-11T05:00:00+00:00` from a plugin compare
     * correctly. Without a zone the time is read as UTC. Null when unreadable;
     * such an item sorts as the oldest.
     */
    public static function epochMicros(string $timestamp): ?int
    {
        if (trim($timestamp) === '') {
            return null;
        }
        try {
            $at = new \DateTimeImmutable($timestamp, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }

        return ((int) $at->format('U')) * 1_000_000 + (int) $at->format('u');
    }

    private static function compareKeys(
        bool $urgentA,
        string $atA,
        string $sourceA,
        string $idA,
        bool $urgentB,
        string $atB,
        string $sourceB,
        string $idB,
    ): int {
        if ($urgentA !== $urgentB) {
            return $urgentA ? -1 : 1;
        }

        $timeA = self::epochMicros($atA) ?? PHP_INT_MIN;
        $timeB = self::epochMicros($atB) ?? PHP_INT_MIN;
        if ($timeA !== $timeB) {
            return $timeA > $timeB ? -1 : 1;
        }

        $bySource = strcmp($sourceA, $sourceB);
        if ($bySource !== 0) {
            return $bySource < 0 ? -1 : 1;
        }

        if (ctype_digit($idA) && ctype_digit($idB) && strlen($idA) < 19 && strlen($idB) < 19) {
            return (int) $idB <=> (int) $idA;
        }

        return strcmp($idB, $idA) <=> 0;
    }
}
