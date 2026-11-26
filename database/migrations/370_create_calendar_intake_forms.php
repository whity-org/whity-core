<?php

declare(strict_types=1);

namespace Database\Migrations;

use PDO;
use Whity\Database\Database;

/**
 * CreateCalendarIntakeForms — forms to fill before a visit (WHIT-1443,
 * design section 39 §7.2, register AP.20).
 *
 * 1. `calendar_service_forms`: the forms (section 22) a service asks for.
 *    `fill_when` is `on_booking` or `before_visit`, `who` is `new` (only a
 *    customer's first visit) or `every`, and `is_required` says whether the desk
 *    shows it as missing. A missing form never blocks check-in.
 *
 * 2. `calendar_intake_links`: one link for a booking and a form. The customer's
 *    form page carries its token; only the SHA-256 is kept. A submission sent
 *    with it records the booking and the customer.
 *
 * 3. `form_submissions` gains `booking_id` and `contact_id`, both nullable:
 *    every submission that isn't an intake form is unchanged.
 *
 * Tenant-owned and named literally for TenantOwnedTablesTest and
 * CoreTablesTest. Idempotent and reversible.
 */
final class CreateCalendarIntakeForms
{
    private const SUBMISSION_COLUMNS = [
        'booking_id' => 'INTEGER REFERENCES calendar_bookings(id) ON DELETE SET NULL',
        'contact_id' => 'INTEGER REFERENCES contacts(id) ON DELETE SET NULL',
    ];

    public static function up(Database $db): void
    {
        $pgsql = (string) $db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
        $id = $pgsql ? 'SERIAL PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $bigId = $pgsql ? 'BIGSERIAL PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';

        $db->exec("
            CREATE TABLE IF NOT EXISTS calendar_service_forms (
                id          {$id},
                tenant_id   INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                service_id  INTEGER NOT NULL REFERENCES calendar_services(id) ON DELETE CASCADE,
                form_id     INTEGER NOT NULL REFERENCES forms(id) ON DELETE CASCADE,
                fill_when   VARCHAR(16) NOT NULL DEFAULT 'before_visit',
                who         VARCHAR(8) NOT NULL DEFAULT 'every',
                is_required BOOLEAN NOT NULL DEFAULT TRUE,
                sort_order  INTEGER NOT NULL DEFAULT 0,
                UNIQUE (tenant_id, service_id, form_id)
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS calendar_intake_links (
                id          {$bigId},
                tenant_id   INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                booking_id  INTEGER NOT NULL REFERENCES calendar_bookings(id) ON DELETE CASCADE,
                form_id     INTEGER NOT NULL REFERENCES forms(id) ON DELETE CASCADE,
                token_hash  VARCHAR(64) NOT NULL,
                created_at  TIMESTAMP NOT NULL
            )
        ");
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_calendar_intake_links_token ON calendar_intake_links(token_hash)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_calendar_intake_links_booking ON calendar_intake_links(tenant_id, booking_id)');

        foreach (self::SUBMISSION_COLUMNS as $column => $type) {
            if ($pgsql) {
                // migration-lint: allow constraint-not-valid (a nullable foreign key on a new, empty column: there is nothing to check)
                $db->exec("ALTER TABLE form_submissions ADD COLUMN IF NOT EXISTS {$column} {$type}");
            } elseif (!self::sqliteHasColumn($db, $column)) {
                // migration-lint: allow not-idempotent (guarded by sqliteHasColumn: SQLite has no ADD COLUMN IF NOT EXISTS)
                $db->exec("ALTER TABLE form_submissions ADD COLUMN {$column} {$type}");
            }
        }
        // migration-lint: allow index-not-concurrent (the column was added above and is empty)
        $db->exec('CREATE INDEX IF NOT EXISTS idx_form_submissions_booking ON form_submissions(tenant_id, booking_id)');
    }

    public static function down(Database $db): void
    {
        $pgsql = (string) $db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
        $db->exec('DROP INDEX IF EXISTS idx_form_submissions_booking');
        foreach (array_reverse(array_keys(self::SUBMISSION_COLUMNS)) as $column) {
            if ($pgsql) {
                $db->exec("ALTER TABLE form_submissions DROP COLUMN IF EXISTS {$column}");
            } elseif (self::sqliteHasColumn($db, $column)) {
                $db->exec("ALTER TABLE form_submissions DROP COLUMN {$column}");
            }
        }
        $db->exec('DROP TABLE IF EXISTS calendar_intake_links');
        $db->exec('DROP TABLE IF EXISTS calendar_service_forms');
    }

    private static function sqliteHasColumn(Database $db, string $column): bool
    {
        foreach ($db->query('PRAGMA table_info(form_submissions)')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (($row['name'] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }
}
