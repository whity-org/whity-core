<?php

declare(strict_types=1);

namespace Database\Migrations;

use PDO;
use Whity\Database\Database;

/**
 * CreateCalendarHolds — the database refuses double bookings (WHIT-1409,
 * design section 39 §5.1, register AP.11–AP.13).
 *
 * Until now only the application kept two bookings apart: every write took
 * the person's `calendar_staff` row and the room's row `FOR UPDATE` and read
 * busy time again before inserting (ADR 0017 §3). That holds only while every
 * writer goes through `BookingService`. From here PostgreSQL refuses it too:
 *
 * 1. `calendar_holds`: one row for each person and each room a live booking
 *    takes, over its time WITH buffers (`blocks_from`–`blocks_until`), for
 *    each part of a booking of several services. `exclusive` is false only
 *    for the person on a Book anyway booking (`calendar_bookings.overlap_ok`,
 *    CV.9: Book anyway never passes a taken room) and for every row of a
 *    booking that already overlapped another when this guard arrived
 *    (`overlap_before_guard`). `expires_at` is a payment hold's expiry.
 *
 * 2. The rows are written by TRIGGERS on `calendar_bookings` and
 *    `calendar_booking_services`, not by PHP: whoever writes a booking, by any
 *    path, writes its holds. A booking that stops being live (cancelled,
 *    expired) loses them; a move rewrites them.
 *
 * 3. `calendar_holds_no_overlap`: an exclusion constraint (btree_gist) that no
 *    two exclusive rows of the same workspace, holder and kind overlap, unless
 *    they belong to the same booking. DEFERRABLE INITIALLY DEFERRED, because a
 *    booking of several services is written as the booking row and then its
 *    parts; `BookingService` checks it IMMEDIATE at the end of its own work so
 *    a refusal comes back as the usual 409 there.
 *
 * Existing data: the holds are filled from every live booking, then every
 * booking that overlaps an earlier one (lower id) on the same person or room
 * is marked `overlap_before_guard`, so the constraint can be added without
 * changing any booking. `whity-cli calendar:overlaps` lists them; nothing is
 * moved or cancelled automatically (AP.12).
 *
 * SQLite (tests, trials) gets the table and the two columns only: it already
 * serialises writers, and has neither btree_gist nor PL/pgSQL. The rows stay
 * empty there; the application's locks are the guard.
 *
 * `btree_gist` is a trusted extension (PostgreSQL 13+), so the database owner
 * can create it. Where it can't be created the migration stops with a plain
 * message.
 *
 * Tenant-owned and named literally for TenantOwnedTablesTest and
 * CoreTablesTest. Idempotent and reversible.
 */
final class CreateCalendarHolds
{
    /** Keep in step with {@see \Whity\Core\Calendar\BookingRepository::LIVE} (pinned by a test). */
    public const LIVE_SQL = "('confirmed', 'pending_payment', 'requested')";

    public const CONSTRAINT = 'calendar_holds_no_overlap';

    public static function up(Database $db): void
    {
        $driver = (string) $db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver !== 'pgsql') {
            $db->exec('
                CREATE TABLE IF NOT EXISTS calendar_holds (
                    id           INTEGER PRIMARY KEY AUTOINCREMENT,
                    tenant_id    INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                    booking_id   INTEGER NOT NULL REFERENCES calendar_bookings(id) ON DELETE CASCADE,
                    holder_kind  VARCHAR(16) NOT NULL,
                    holder_id    INTEGER NOT NULL,
                    holds_from   TIMESTAMP NOT NULL,
                    holds_until  TIMESTAMP NOT NULL,
                    exclusive    BOOLEAN NOT NULL DEFAULT TRUE,
                    expires_at   TIMESTAMP
                )
            ');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_calendar_holds_booking ON calendar_holds(tenant_id, booking_id)');
            foreach (['overlap_ok', 'overlap_before_guard'] as $column) {
                if (!self::sqliteHasColumn($db, 'calendar_bookings', $column)) {
                    // migration-lint: allow not-idempotent (guarded by sqliteHasColumn: SQLite has no ADD COLUMN IF NOT EXISTS)
                    $db->exec("ALTER TABLE calendar_bookings ADD COLUMN {$column} BOOLEAN NOT NULL DEFAULT FALSE");
                }
            }

            return;
        }

        try {
            $db->exec('CREATE EXTENSION IF NOT EXISTS btree_gist');
        } catch (\PDOException $e) {
            throw new \RuntimeException(
                'Whity needs the PostgreSQL extension btree_gist to stop double bookings. '
                . 'Ask your database administrator to run CREATE EXTENSION btree_gist; in this database, then migrate again. '
                . '(' . $e->getMessage() . ')',
                0,
                $e,
            );
        }

        $db->exec('ALTER TABLE calendar_bookings ADD COLUMN IF NOT EXISTS overlap_ok BOOLEAN NOT NULL DEFAULT FALSE');
        $db->exec('ALTER TABLE calendar_bookings ADD COLUMN IF NOT EXISTS overlap_before_guard BOOLEAN NOT NULL DEFAULT FALSE');

        $db->exec("
            CREATE TABLE IF NOT EXISTS calendar_holds (
                id           BIGSERIAL PRIMARY KEY,
                tenant_id    INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                booking_id   INTEGER NOT NULL REFERENCES calendar_bookings(id) ON DELETE CASCADE,
                holder_kind  VARCHAR(16) NOT NULL CHECK (holder_kind IN ('person', 'resource')),
                holder_id    INTEGER NOT NULL,
                holds_from   TIMESTAMP NOT NULL,
                holds_until  TIMESTAMP NOT NULL CHECK (holds_until >= holds_from),
                exclusive    BOOLEAN NOT NULL DEFAULT TRUE,
                expires_at   TIMESTAMP
            )
        ");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_calendar_holds_booking ON calendar_holds(tenant_id, booking_id)');

        // One booking's holds, rebuilt from the booking (and its parts).
        $db->exec("
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
                END IF;
            END
            \$fn\$
        ");

        $db->exec("
            CREATE OR REPLACE FUNCTION whity_calendar_holds_on_booking() RETURNS trigger
            LANGUAGE plpgsql AS \$fn\$
            BEGIN
                PERFORM whity_calendar_holds_sync(NEW.tenant_id, NEW.id);
                RETURN NULL;
            END
            \$fn\$
        ");
        $db->exec("
            CREATE OR REPLACE FUNCTION whity_calendar_holds_on_part() RETURNS trigger
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
        // Dropped and created, as 318 and 348 do, so a re-run after a
        // half-applied first run ends in the same place.
        $db->exec('DROP TRIGGER IF EXISTS calendar_bookings_holds ON calendar_bookings');
        $db->exec('
            CREATE TRIGGER calendar_bookings_holds
            AFTER INSERT OR UPDATE OF status, profile_id, room_id, blocks_from, blocks_until, hold_expires_at, overlap_ok, overlap_before_guard
            ON calendar_bookings
            FOR EACH ROW EXECUTE FUNCTION whity_calendar_holds_on_booking()
        ');
        $db->exec('DROP TRIGGER IF EXISTS calendar_booking_services_holds ON calendar_booking_services');
        $db->exec('
            CREATE TRIGGER calendar_booking_services_holds
            AFTER INSERT OR UPDATE OR DELETE
            ON calendar_booking_services
            FOR EACH ROW EXECUTE FUNCTION whity_calendar_holds_on_part()
        ');

        $exists = (bool) $db->query(
            'SELECT 1 FROM pg_constraint WHERE conname = :name',
            [':name' => self::CONSTRAINT],
        )->fetchColumn();
        if ($exists) {
            return;
        }

        // Fill from every live booking.
        $db->exec('SELECT whity_calendar_holds_sync(tenant_id, id) FROM calendar_bookings WHERE status IN ' . self::LIVE_SQL);

        // Bookings that already overlap an earlier one keep their time and
        // are listed by `whity-cli calendar:overlaps`; nothing is moved.
        // migration-lint: allow unbatched-backfill (only the rows that already overlap another booking, a handful at most; the constraint can't be added until they are marked, in this same step)
        $db->exec('
            UPDATE calendar_bookings SET overlap_before_guard = TRUE
             WHERE (tenant_id, id) IN (
                SELECT later.tenant_id, later.booking_id
                  FROM calendar_holds later
                  JOIN calendar_holds earlier
                    ON earlier.tenant_id = later.tenant_id
                   AND earlier.holder_kind = later.holder_kind
                   AND earlier.holder_id = later.holder_id
                   AND earlier.booking_id < later.booking_id
                   AND earlier.exclusive AND later.exclusive
                   AND earlier.holds_from < later.holds_until
                   AND later.holds_from < earlier.holds_until
             )
        ');

        $db->exec("
            ALTER TABLE calendar_holds ADD CONSTRAINT " . self::CONSTRAINT . "
            EXCLUDE USING gist (
                tenant_id WITH =,
                holder_kind WITH =,
                holder_id WITH =,
                tsrange(holds_from, holds_until, '[)') WITH &&,
                booking_id WITH <>
            ) WHERE (exclusive)
            DEFERRABLE INITIALLY DEFERRED
        ");
    }

    public static function down(Database $db): void
    {
        $driver = (string) $db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            $db->exec('DROP TRIGGER IF EXISTS calendar_booking_services_holds ON calendar_booking_services');
            $db->exec('DROP TRIGGER IF EXISTS calendar_bookings_holds ON calendar_bookings');
            $db->exec('DROP FUNCTION IF EXISTS whity_calendar_holds_on_part()');
            $db->exec('DROP FUNCTION IF EXISTS whity_calendar_holds_on_booking()');
            $db->exec('DROP FUNCTION IF EXISTS whity_calendar_holds_sync(INTEGER, INTEGER)');
            $db->exec('DROP TABLE IF EXISTS calendar_holds');
            $db->exec('ALTER TABLE calendar_bookings DROP COLUMN IF EXISTS overlap_before_guard');
            $db->exec('ALTER TABLE calendar_bookings DROP COLUMN IF EXISTS overlap_ok');
            // btree_gist stays: another table may use it, and it holds no data.

            return;
        }
        $db->exec('DROP TABLE IF EXISTS calendar_holds');
        foreach (['overlap_before_guard', 'overlap_ok'] as $column) {
            if (self::sqliteHasColumn($db, 'calendar_bookings', $column)) {
                $db->exec("ALTER TABLE calendar_bookings DROP COLUMN {$column}");
            }
        }
    }

    private static function sqliteHasColumn(Database $db, string $table, string $column): bool
    {
        foreach ($db->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (($row['name'] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }
}
