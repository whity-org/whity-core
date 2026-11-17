<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateCalendarBookingServices — one booking, several services back to back
 * (WHIT-1173, ADR 0017).
 *
 * A salon customer books a haircut, a colour and a blow-dry as ONE booking.
 * Each service is a row here, in order (`position` 0, 1, …), with the person
 * who does it, its own service time (`starts_at`/`ends_at`) and the time it
 * blocks with its own buffers (`blocks_from`/`blocks_until`), its price and
 * the room it needs. One person may do them all, or each may have its own.
 *
 * The booking row stays the booking: its status, customer, manage link and
 * reminder. Its `service_id`/`profile_id`/`room_id` are the FIRST service's,
 * its `starts_at`/`ends_at`/`blocks_*` span every service, and `price_minor`
 * is the total. A booking of one service has NO rows here and is read exactly
 * as before, so nothing that reads `calendar_bookings` alone breaks.
 *
 * Busy time: a booking with rows here makes each person busy only for their
 * own services' blocks (CalendarRepository::busy, RoomRepository::busy).
 *
 * Table name spelled out literally for TenantOwnedTablesTest and
 * CoreTablesTest. Idempotent and reversible.
 */
final class CreateCalendarBookingServices
{
    public static function up(Database $db): void
    {
        $db->exec('
            CREATE TABLE IF NOT EXISTS calendar_booking_services (
                id            SERIAL PRIMARY KEY,
                tenant_id     INTEGER   NOT NULL REFERENCES tenants(id)           ON DELETE CASCADE,
                booking_id    INTEGER   NOT NULL REFERENCES calendar_bookings(id) ON DELETE CASCADE,
                service_id    INTEGER   NOT NULL REFERENCES calendar_services(id) ON DELETE RESTRICT,
                profile_id    INTEGER   NOT NULL REFERENCES profiles(id)          ON DELETE RESTRICT,
                room_id       INTEGER            REFERENCES calendar_rooms(id)    ON DELETE SET NULL,
                position      INTEGER   NOT NULL,
                starts_at     TIMESTAMP NOT NULL,
                ends_at       TIMESTAMP NOT NULL,
                blocks_from   TIMESTAMP NOT NULL,
                blocks_until  TIMESTAMP NOT NULL,
                price_minor   BIGINT,
                created_at    TIMESTAMP NOT NULL DEFAULT NOW(),
                UNIQUE (tenant_id, booking_id, position)
            )
        ');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_calendar_booking_services_person ON calendar_booking_services(tenant_id, profile_id, blocks_from)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_calendar_booking_services_room ON calendar_booking_services(tenant_id, room_id, blocks_from)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS calendar_booking_services');
    }
}
