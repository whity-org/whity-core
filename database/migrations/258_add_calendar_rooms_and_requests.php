<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * AddCalendarRoomsAndRequests — the calendar extras of design 3.3
 * (`TplAppointments`, `TplBookingPage`).
 *
 * - `calendar_rooms`: rooms a service can need. A room is booked with the
 *   person: a booking for a service that needs a room takes that room too, and
 *   no two live bookings hold one room at the same time. The room row is its
 *   own LOCK ROW, taken after the people's rows (ADR 0017, section 3).
 * - `unit_id` on staff and rooms: the branch (an organisational unit) they
 *   belong to, so the calendar can be filtered by branch.
 * - `calendar_services.room_id` and `needs_approval`: a service that needs
 *   approval turns a customer's booking into a request.
 * - `calendar_bookings.room_id`, `checked_in_at` (the customer arrived) and
 *   `suggested_at` (staff suggested another time for a request).
 *
 * Table names are spelled out literally for TenantOwnedTablesTest and
 * CoreTablesTest. Idempotent and reversible.
 */
final class AddCalendarRoomsAndRequests
{
    public static function up(Database $db): void
    {
        $db->exec('
            CREATE TABLE IF NOT EXISTS calendar_rooms (
                id          SERIAL PRIMARY KEY,
                tenant_id   INTEGER     NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                name        TEXT        NOT NULL,
                unit_id     INTEGER              REFERENCES organizational_units(id) ON DELETE SET NULL,
                active      BOOLEAN     NOT NULL DEFAULT TRUE,
                sort_order  INTEGER     NOT NULL DEFAULT 0,
                created_at  TIMESTAMP   NOT NULL DEFAULT NOW(),
                updated_at  TIMESTAMP   NOT NULL DEFAULT NOW()
            )
        ');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_calendar_rooms_tenant_id ON calendar_rooms(tenant_id)');

        $db->exec('ALTER TABLE calendar_staff ADD COLUMN IF NOT EXISTS unit_id INTEGER REFERENCES organizational_units(id) ON DELETE SET NULL');
        $db->exec('ALTER TABLE calendar_services ADD COLUMN IF NOT EXISTS room_id INTEGER REFERENCES calendar_rooms(id) ON DELETE SET NULL');
        $db->exec('ALTER TABLE calendar_services ADD COLUMN IF NOT EXISTS needs_approval BOOLEAN NOT NULL DEFAULT FALSE');
        $db->exec('ALTER TABLE calendar_bookings ADD COLUMN IF NOT EXISTS room_id INTEGER REFERENCES calendar_rooms(id) ON DELETE SET NULL');
        $db->exec('ALTER TABLE calendar_bookings ADD COLUMN IF NOT EXISTS checked_in_at TIMESTAMP');
        $db->exec('ALTER TABLE calendar_bookings ADD COLUMN IF NOT EXISTS suggested_at TIMESTAMP');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_calendar_bookings_room ON calendar_bookings(tenant_id, room_id, blocks_from)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP INDEX IF EXISTS idx_calendar_bookings_room');
        $db->exec('ALTER TABLE calendar_bookings DROP COLUMN IF EXISTS suggested_at');
        $db->exec('ALTER TABLE calendar_bookings DROP COLUMN IF EXISTS checked_in_at');
        $db->exec('ALTER TABLE calendar_bookings DROP COLUMN IF EXISTS room_id');
        $db->exec('ALTER TABLE calendar_services DROP COLUMN IF EXISTS needs_approval');
        $db->exec('ALTER TABLE calendar_services DROP COLUMN IF EXISTS room_id');
        $db->exec('ALTER TABLE calendar_staff DROP COLUMN IF EXISTS unit_id');
        $db->exec('DROP TABLE IF EXISTS calendar_rooms');
    }
}
