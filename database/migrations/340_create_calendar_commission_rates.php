<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * CreateCalendarCommissionRates — staff commission (WHIT-1334, design section
 * 35 §4, register SL.5-SL.7), moved from the Salons plugin's
 * `salons_commission_rates`.
 *
 * - `calendar_commission_rates`: a member of staff's share of a booking's
 *   price, in basis points (12.5% is 1250, 0 to 10,000), for every service
 *   (`service_id` 0) or as an override for one service. One per person and
 *   service. The report is read from the calendar each time, so the rates are
 *   all that is stored.
 *
 * Grants `calendar:commission` by capability to every role holding
 * `calendar:manage` (ci-grant-by-role-name-guard).
 *
 * Tenant-owned and named literally for TenantOwnedTablesTest and
 * CoreTablesTest. Idempotent and reversible.
 */
final class CreateCalendarCommissionRates
{
    private const PERMISSION = CorePermissions::CALENDAR_COMMISSION;
    private const DESCRIPTION = 'Set staff commission rates and see what each earned';

    /** A role gets the permission only when it holds every one of these. */
    private const AUDIENCE = [CorePermissions::CALENDAR_MANAGE];

    public static function up(Database $db): void
    {
        $db->exec('
            CREATE TABLE IF NOT EXISTS calendar_commission_rates (
                id                    SERIAL PRIMARY KEY,
                tenant_id             INTEGER   NOT NULL REFERENCES tenants(id)  ON DELETE CASCADE,
                profile_id            INTEGER   NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
                -- @reference-lint-ignore: 0 means every service, so it cannot reference calendar_services
                service_id            INTEGER   NOT NULL DEFAULT 0,
                percent_bp            INTEGER   NOT NULL,
                updated_at            TIMESTAMP NOT NULL,
                updated_by_profile_id INTEGER            REFERENCES profiles(id) ON DELETE SET NULL,
                CHECK (percent_bp >= 0 AND percent_bp <= 10000)
            )
        ');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_calendar_commission_rates ON calendar_commission_rates(tenant_id, profile_id, service_id)');

        $db->query(
            'INSERT INTO permissions (name, description, created_at)
             VALUES (:name, :description, NOW())
             ON CONFLICT (name) DO NOTHING',
            [':name' => self::PERMISSION, ':description' => self::DESCRIPTION]
        );
        $permissionId = self::permissionId($db, self::PERMISSION);
        if ($permissionId !== null) {
            foreach (self::rolesHoldingAll($db, self::AUDIENCE) as $roleId) {
                $db->query(
                    'INSERT INTO role_permissions (role_id, permission_id, created_at)
                     VALUES (:role_id, :permission_id, NOW())
                     ON CONFLICT (role_id, permission_id) DO NOTHING',
                    [':role_id' => $roleId, ':permission_id' => $permissionId]
                );
            }
        }
    }

    public static function down(Database $db): void
    {
        $permissionId = self::permissionId($db, self::PERMISSION);
        if ($permissionId !== null) {
            foreach (self::rolesHoldingAll($db, self::AUDIENCE) as $roleId) {
                $db->query(
                    'DELETE FROM role_permissions WHERE role_id = :role_id AND permission_id = :permission_id',
                    [':role_id' => $roleId, ':permission_id' => $permissionId]
                );
            }
            $db->query(
                'DELETE FROM permissions
                  WHERE name = :name
                    AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.permission_id = permissions.id)',
                [':name' => self::PERMISSION]
            );
        }

        $db->exec('DROP TABLE IF EXISTS calendar_commission_rates');
    }

    /**
     * @param list<string> $permissions
     * @return list<int>
     */
    private static function rolesHoldingAll(Database $db, array $permissions): array
    {
        $holders = null;
        foreach ($permissions as $permission) {
            $rows = $db->query(
                'SELECT rp.role_id
                   FROM role_permissions rp
                   JOIN permissions p ON p.id = rp.permission_id
                  WHERE p.name = :name',
                [':name' => $permission]
            )->fetchAll();
            $ids = array_values(array_map(static fn (array $r): int => (int) $r['role_id'], $rows));
            $holders = $holders === null ? $ids : array_values(array_intersect($holders, $ids));
        }

        return $holders ?? [];
    }

    private static function permissionId(Database $db, string $name): ?int
    {
        $result = $db->query('SELECT id FROM permissions WHERE name = :name', [':name' => $name])->fetch();

        return $result === false ? null : (int) $result['id'];
    }
}
