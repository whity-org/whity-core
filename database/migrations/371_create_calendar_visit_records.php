<?php

declare(strict_types=1);

namespace Database\Migrations;

use PDO;
use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * CreateCalendarVisitRecords — the visit record (WHIT-1443, design section 39
 * §7.3, register AP.21).
 *
 * 1. `calendar_visit_notes`: one record for each booking. Its notes, written
 *    by the person who treats the customer, can change until Finish visit
 *    (`finished_at`, `finished_by_profile_id`). After that they never change.
 * 2. `calendar_visit_corrections`: what is added after Finish visit, each
 *    dated and signed, never over the original.
 * 3. `calendar_visit_files`: files on the visit, in the tenant's own storage
 *    (`stored_files`, kind `visit_file`).
 * 4. `calendar:visit_notes`, the permission to see and write visit records.
 *    It is created and granted to NO role: an admin grants it to the roles
 *    that need it. The treating staff member always sees their own.
 *
 * Not a medical records system; the data is stored in the EU. Tenant-owned and
 * named literally for TenantOwnedTablesTest and CoreTablesTest. Idempotent and
 * reversible.
 */
final class CreateCalendarVisitRecords
{
    private const PERMISSION = CorePermissions::CALENDAR_VISIT_NOTES;
    private const DESCRIPTION = 'See and write visit records: notes, forms and files for a booking';

    public static function up(Database $db): void
    {
        $pgsql = (string) $db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
        $id = $pgsql ? 'BIGSERIAL PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $ref = $pgsql ? 'BIGINT' : 'INTEGER';

        $db->exec("
            CREATE TABLE IF NOT EXISTS calendar_visit_notes (
                id                      {$id},
                tenant_id               INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                booking_id              INTEGER NOT NULL REFERENCES calendar_bookings(id) ON DELETE CASCADE,
                body                    TEXT NOT NULL DEFAULT '',
                author_profile_id       INTEGER REFERENCES profiles(id) ON DELETE SET NULL,
                created_at              TIMESTAMP NOT NULL,
                updated_at              TIMESTAMP NOT NULL,
                finished_at             TIMESTAMP,
                finished_by_profile_id  INTEGER REFERENCES profiles(id) ON DELETE SET NULL,
                UNIQUE (tenant_id, booking_id)
            )
        ");
        $db->exec("
            CREATE TABLE IF NOT EXISTS calendar_visit_corrections (
                id                 {$id},
                tenant_id          INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                visit_id           {$ref} NOT NULL REFERENCES calendar_visit_notes(id) ON DELETE CASCADE,
                body               TEXT NOT NULL,
                author_profile_id  INTEGER REFERENCES profiles(id) ON DELETE SET NULL,
                created_at         TIMESTAMP NOT NULL
            )
        ");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_calendar_visit_corrections_visit ON calendar_visit_corrections(tenant_id, visit_id)');
        $db->exec("
            CREATE TABLE IF NOT EXISTS calendar_visit_files (
                id                     {$id},
                tenant_id              INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                booking_id             INTEGER NOT NULL REFERENCES calendar_bookings(id) ON DELETE CASCADE,
                stored_file_id         {$ref} NOT NULL REFERENCES stored_files(id) ON DELETE CASCADE,
                created_by_profile_id  INTEGER REFERENCES profiles(id) ON DELETE SET NULL,
                created_at             TIMESTAMP NOT NULL
            )
        ");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_calendar_visit_files_booking ON calendar_visit_files(tenant_id, booking_id)');

        // Created and granted to no one (AP.21).
        $db->query(
            'INSERT INTO permissions (name, description, created_at)
             VALUES (:name, :description, CURRENT_TIMESTAMP)
             ON CONFLICT (name) DO NOTHING',
            [':name' => self::PERMISSION, ':description' => self::DESCRIPTION]
        );
    }

    public static function down(Database $db): void
    {
        $stmt = $db->query('SELECT id FROM permissions WHERE name = :name', [':name' => self::PERMISSION]);
        $permissionId = $stmt->fetchColumn();
        $stmt->closeCursor();
        if ($permissionId !== false) {
            $db->query('DELETE FROM role_permissions WHERE permission_id = :id', [':id' => (int) $permissionId]);
            $db->query('DELETE FROM permissions WHERE id = :id', [':id' => (int) $permissionId]);
        }
        $db->exec('DROP TABLE IF EXISTS calendar_visit_files');
        $db->exec('DROP TABLE IF EXISTS calendar_visit_corrections');
        $db->exec('DROP TABLE IF EXISTS calendar_visit_notes');
    }
}
