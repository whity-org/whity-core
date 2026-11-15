<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * A booking's calendar-file revision (design 3.4 E2, WHIT-1032).
 *
 * The `.ics` a booking email carries keeps one UID for the booking's life, so
 * a move updates the same entry in the customer's calendar and a cancel
 * removes it (METHOD:REQUEST / CANCEL, RFC 5546). Calendars apply a change
 * only when its SEQUENCE is higher than the one they hold: this counts the
 * booking's moves and its cancellation, starting at 0 when it is made.
 *
 * Bookings made before this migration were sent files whose SEQUENCE was the
 * time of sending (time()), and a calendar that kept one ignores any lower
 * number. So they start at the time of this migration, above every file they
 * were sent, and their next move or cancel still updates the entry. Running it
 * again only raises a counter, which is always safe (a SEQUENCE may jump).
 * Idempotent and reversible.
 */
final class AddBookingIcsSequence
{
    public static function up(Database $db): void
    {
        $db->exec('ALTER TABLE calendar_bookings ADD COLUMN IF NOT EXISTS ics_sequence INTEGER NOT NULL DEFAULT 0');
        $db->exec('UPDATE calendar_bookings SET ics_sequence = ' . time() . ' WHERE ics_sequence = 0');
    }

    public static function down(Database $db): void
    {
        $db->exec('ALTER TABLE calendar_bookings DROP COLUMN IF EXISTS ics_sequence');
    }
}
