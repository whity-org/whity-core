<?php

declare(strict_types=1);

namespace Database\Migrations;

use PDO;
use Whity\Database\Database;

/**
 * CreateCalendarBookingResources — every resource a booking takes, guarded
 * by the database (WHIT-1439, design section 39 §5.1 and §6, AP.11, AP.18).
 *
 * 1. `calendar_booking_resources`: one row for each resource a booking (or
 *    one part of a booking of several services) holds, over that part's time
 *    with buffers. A service may need several resources, and "any free
 *    chair" is chosen when the booking is made, so the booking says which.
 *    `calendar_bookings.room_id` (and a part's) stays the first of them.
 *
 * 2. Migration 360's `whity_calendar_holds_sync` also writes a hold for each
 *    of these rows, so `calendar_holds_no_overlap` refuses two bookings of
 *    the same chair at once, whoever writes them. Rows from `room_id` stay,
 *    so a writer that sets only `room_id` is still guarded; the same
 *    resource twice in one booking is allowed (the constraint compares other
 *    bookings only). A trigger on the new table keeps the holds in step.
 *
 * SQLite gets the table only, as in 360. Tenant-owned and named literally
 * for TenantOwnedTablesTest and CoreTablesTest. Idempotent and reversible.
 */
final class CreateCalendarBookingResources
{
    private const LIVE_SQL = "('confirmed', 'pending_payment', 'requested')";

    public static function up(Database $db): void
    {
        $pgsql = (string) $db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
        $id = $pgsql ? 'BIGSERIAL PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $db->exec("
            CREATE TABLE IF NOT EXISTS calendar_booking_resources (
                id            {$id},
                tenant_id     INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                booking_id    INTEGER NOT NULL REFERENCES calendar_bookings(id) ON DELETE CASCADE,
                resource_id   INTEGER NOT NULL REFERENCES calendar_resources(id) ON DELETE CASCADE,
                part          INTEGER NOT NULL DEFAULT 0,
                blocks_from   TIMESTAMP NOT NULL,
                blocks_until  TIMESTAMP NOT NULL
            )
        ");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_calendar_booking_resources_booking ON calendar_booking_resources(tenant_id, booking_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_calendar_booking_resources_resource ON calendar_booking_resources(tenant_id, resource_id, blocks_from)');
        if (!$pgsql) {
            return;
        }

        $db->exec(self::syncFunction(true));
        // Its own trigger function, so migration 360 can still be rolled back on its own.
        $db->exec("
            CREATE OR REPLACE FUNCTION whity_calendar_holds_on_resource() RETURNS trigger
            LANGUAGE plpgsql AS \$fn\$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    PERFORM whity_calendar_holds_sync(OLD.tenant_id, OLD.booking_id);
                    RETURN NULL;
                END IF;
                PERFORM whity_calendar_holds_sync(NEW.tenant_id, NEW.booking_id);
                IF TG_OP = 'UPDATE' AND (OLD.booking_id <> NEW.booking_id OR OLD.tenant_id <> NEW.tenant_id) THEN
                    PERFORM whity_calendar_holds_sync(OLD.tenant_id, OLD.booking_id);
                END IF;
                RETURN NULL;
            END
            \$fn\$
        ");
        $db->exec('DROP TRIGGER IF EXISTS calendar_booking_resources_holds ON calendar_booking_resources');
        $db->exec('
            CREATE TRIGGER calendar_booking_resources_holds
            AFTER INSERT OR UPDATE OR DELETE
            ON calendar_booking_resources
            FOR EACH ROW EXECUTE FUNCTION whity_calendar_holds_on_resource()
        ');
    }

    public static function down(Database $db): void
    {
        $pgsql = (string) $db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
        if ($pgsql) {
            $db->exec('DROP TRIGGER IF EXISTS calendar_booking_resources_holds ON calendar_booking_resources');
            $db->exec('DROP FUNCTION IF EXISTS whity_calendar_holds_on_resource()');
            $db->exec(self::syncFunction(false));
        }
        $db->exec('DROP TABLE IF EXISTS calendar_booking_resources');
        if ($pgsql) {
            // The holds now come from the bookings alone again.
            // migration-lint: allow unbatched-backfill (rebuilds the holds of live bookings once, as 360 did)
            $db->exec('SELECT whity_calendar_holds_sync(tenant_id, id) FROM calendar_bookings WHERE status IN ' . self::LIVE_SQL);
        }
    }

    /** Migration 360's function, with the booking's resources (`$withResources`) or without them. */
    private static function syncFunction(bool $withResources): string
    {
        $resources = $withResources ? "
                INSERT INTO calendar_holds (tenant_id, booking_id, holder_kind, holder_id, holds_from, holds_until, exclusive, expires_at)
                SELECT p_tenant, p_booking, 'resource', r.resource_id, r.blocks_from, GREATEST(r.blocks_until, r.blocks_from), any_exclusive, expiry
                  FROM calendar_booking_resources r
                 WHERE r.tenant_id = p_tenant AND r.booking_id = p_booking
                   AND NOT EXISTS (SELECT 1 FROM calendar_holds h
                        WHERE h.tenant_id = p_tenant AND h.booking_id = p_booking AND h.holder_kind = 'resource'
                          AND h.holder_id = r.resource_id AND h.holds_from = r.blocks_from);" : '';

        return "
            CREATE OR REPLACE FUNCTION whity_calendar_holds_sync(p_tenant INTEGER, p_booking INTEGER) RETURNS void
            LANGUAGE plpgsql AS \$fn\$
            DECLARE
                b calendar_bookings%ROWTYPE;
                any_exclusive BOOLEAN;
                person_exclusive BOOLEAN;
                expiry TIMESTAMP;
            BEGIN
                DELETE FROM calendar_holds WHERE tenant_id = p_tenant AND booking_id = p_booking;
                SELECT * INTO b FROM calendar_bookings WHERE tenant_id = p_tenant AND id = p_booking;
                IF NOT FOUND OR b.status NOT IN " . self::LIVE_SQL . " THEN
                    RETURN;
                END IF;
                any_exclusive := NOT b.overlap_before_guard;
                person_exclusive := any_exclusive AND NOT b.overlap_ok;
                expiry := CASE WHEN b.status = 'pending_payment' THEN b.hold_expires_at ELSE NULL END;

                IF EXISTS (SELECT 1 FROM calendar_booking_services s WHERE s.tenant_id = p_tenant AND s.booking_id = p_booking) THEN
                    INSERT INTO calendar_holds (tenant_id, booking_id, holder_kind, holder_id, holds_from, holds_until, exclusive, expires_at)
                    SELECT p_tenant, p_booking, 'person', s.profile_id, s.blocks_from, GREATEST(s.blocks_until, s.blocks_from), person_exclusive, expiry
                      FROM calendar_booking_services s
                     WHERE s.tenant_id = p_tenant AND s.booking_id = p_booking
                    UNION ALL
                    SELECT p_tenant, p_booking, 'resource', s.room_id, s.blocks_from, GREATEST(s.blocks_until, s.blocks_from), any_exclusive, expiry
                      FROM calendar_booking_services s
                     WHERE s.tenant_id = p_tenant AND s.booking_id = p_booking AND s.room_id IS NOT NULL;
                ELSE
                    INSERT INTO calendar_holds (tenant_id, booking_id, holder_kind, holder_id, holds_from, holds_until, exclusive, expires_at)
                    VALUES (p_tenant, p_booking, 'person', b.profile_id, b.blocks_from, GREATEST(b.blocks_until, b.blocks_from), person_exclusive, expiry);
                    IF b.room_id IS NOT NULL THEN
                        INSERT INTO calendar_holds (tenant_id, booking_id, holder_kind, holder_id, holds_from, holds_until, exclusive, expires_at)
                        VALUES (p_tenant, p_booking, 'resource', b.room_id, b.blocks_from, GREATEST(b.blocks_until, b.blocks_from), any_exclusive, expiry);
                    END IF;
                END IF;{$resources}
            END
            \$fn\$
        ";
    }
}
