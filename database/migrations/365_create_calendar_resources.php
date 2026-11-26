<?php

declare(strict_types=1);

namespace Database\Migrations;

use PDO;
use Whity\Database\Database;

/**
 * CreateCalendarResources — rooms, chairs and equipment (WHIT-1439, design
 * section 39 §6, register AP.18).
 *
 * 1. `calendar_rooms` is renamed `calendar_resources` and keeps every column
 *    and id, so bookings, events and services that name a room keep naming
 *    it: their foreign keys follow the rename. Nothing in core, its plugins
 *    or templates reads the old name, and no workspace depends on it yet
 *    (owner, 16 Nov 2026), so there is no view under the old name. New columns:
 *    `kind` (`room`, `chair`, `equipment` or `other`; every room so far is a
 *    room) and `group_key` ("Chairs", "Treatment rooms"): several of a thing
 *    are several rows in one group. The branch stays `unit_id`.
 *
 * 2. `calendar_service_resources`: what a service needs, in order. Each row
 *    is a specific resource (`resource_id`) or any one from a group
 *    (`group_key`), never both. A service may need several. A service that
 *    named a room (`calendar_services.room_id`) gets that room as its first
 *    need; `room_id` is kept and stays the first specific need.
 *
 * Tenant-owned and named literally for TenantOwnedTablesTest and
 * CoreTablesTest. Idempotent and reversible.
 */
final class CreateCalendarResources
{
    public const KINDS = ['room', 'chair', 'equipment', 'other'];

    public static function up(Database $db): void
    {
        $pgsql = (string) $db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';

        if (self::isTable($db, 'calendar_rooms', $pgsql)) {
            // migration-lint: allow not-idempotent (guarded by isTable: the rename runs once, and a second run finds the view)
            $db->exec('ALTER TABLE calendar_rooms RENAME TO calendar_resources');
        }
        foreach (['kind' => "VARCHAR(16) NOT NULL DEFAULT 'room'", 'group_key' => "VARCHAR(80) NOT NULL DEFAULT ''"] as $column => $type) {
            if ($pgsql) {
                $db->exec("ALTER TABLE calendar_resources ADD COLUMN IF NOT EXISTS {$column} {$type}");
            } elseif (!self::sqliteHasColumn($db, 'calendar_resources', $column)) {
                // migration-lint: allow not-idempotent (guarded by sqliteHasColumn: SQLite has no ADD COLUMN IF NOT EXISTS)
                $db->exec("ALTER TABLE calendar_resources ADD COLUMN {$column} {$type}");
            }
        }

        $id = $pgsql ? 'SERIAL PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $db->exec("
            CREATE TABLE IF NOT EXISTS calendar_service_resources (
                id           {$id},
                tenant_id    INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                service_id   INTEGER NOT NULL REFERENCES calendar_services(id) ON DELETE CASCADE,
                resource_id  INTEGER REFERENCES calendar_resources(id) ON DELETE CASCADE,
                group_key    VARCHAR(80),
                sort_order   INTEGER NOT NULL DEFAULT 0,
                CHECK ((resource_id IS NULL) <> (group_key IS NULL))
            )
        ");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_calendar_service_resources_service ON calendar_service_resources(tenant_id, service_id)');

        // A service's room becomes its first need, once.
        // migration-lint: allow unbatched-backfill (one row per service that names a room; a workspace has tens)
        $db->exec('
            INSERT INTO calendar_service_resources (tenant_id, service_id, resource_id, sort_order)
            SELECT s.tenant_id, s.id, s.room_id, 0 FROM calendar_services s
             WHERE s.room_id IS NOT NULL
               AND NOT EXISTS (SELECT 1 FROM calendar_service_resources n WHERE n.tenant_id = s.tenant_id AND n.service_id = s.id)
        ');
    }

    public static function down(Database $db): void
    {
        $pgsql = (string) $db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
        $db->exec('DROP TABLE IF EXISTS calendar_service_resources');
        if (self::isTable($db, 'calendar_resources', $pgsql)) {
            foreach (['group_key', 'kind'] as $column) {
                if ($pgsql) {
                    $db->exec("ALTER TABLE calendar_resources DROP COLUMN IF EXISTS {$column}");
                } elseif (self::sqliteHasColumn($db, 'calendar_resources', $column)) {
                    $db->exec("ALTER TABLE calendar_resources DROP COLUMN {$column}");
                }
            }
            $db->exec('ALTER TABLE calendar_resources RENAME TO calendar_rooms');
        }
    }

    private static function isTable(Database $db, string $name, bool $pgsql): bool
    {
        $sql = $pgsql
            ? "SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = :name AND table_type = 'BASE TABLE'"
            : "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :name";

        return $db->query($sql, [':name' => $name])->fetchColumn() !== false;
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
