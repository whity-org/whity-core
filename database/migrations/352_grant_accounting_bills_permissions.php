<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * GrantAccountingBillsPermissions — `bills:read` and `bills:write` (WHIT-1318
 * A3a, design section 32 §9.1, register AC.13, AC.24). Supplier bills and
 * supplier credits live in `acc_invoices` (kinds `purchase` and
 * `purchase_credit`, migration 350) and payments out in `acc_payments`
 * (direction `out`, migration 351), so no table changes.
 *
 * Granted by capability: read to the roles that run the workspace or post to
 * the books, write to those holding `settings:write` or `accounting:post` (so
 * each workspace's Accountant role gets both). Idempotent and reversible.
 */
final class GrantAccountingBillsPermissions
{
    /** @var array<string, string> */
    private const PERMISSIONS = [
        CorePermissions::BILLS_READ => 'See supplier bills and supplier credits',
        CorePermissions::BILLS_WRITE => 'Enter, post and credit supplier bills',
    ];

    /** @var array<string, list<string>> */
    private const AUDIENCES = [
        CorePermissions::BILLS_READ => [CorePermissions::SETTINGS_WRITE, CorePermissions::ROLES_WRITE, CorePermissions::ACCOUNTING_POST],
        CorePermissions::BILLS_WRITE => [CorePermissions::SETTINGS_WRITE, CorePermissions::ACCOUNTING_POST],
    ];

    public static function up(Database $db): void
    {
        foreach (self::PERMISSIONS as $name => $description) {
            $db->query(
                'INSERT INTO permissions (name, description, created_at)
                 VALUES (:name, :description, NOW())
                 ON CONFLICT (name) DO NOTHING',
                [':name' => $name, ':description' => $description]
            );
        }
        foreach (self::AUDIENCES as $slug => $audience) {
            $permissionId = self::permissionId($db, $slug);
            if ($permissionId === null) {
                continue;
            }
            foreach (self::rolesHoldingAny($db, $audience) as $roleId) {
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
        foreach (array_keys(self::PERMISSIONS) as $name) {
            $permissionId = self::permissionId($db, $name);
            if ($permissionId !== null) {
                $db->query('DELETE FROM role_permissions WHERE permission_id = :id', [':id' => $permissionId]);
                $db->query('DELETE FROM permissions WHERE id = :id', [':id' => $permissionId]);
            }
        }
    }

    /**
     * @param list<string> $permissions
     * @return list<int>
     */
    private static function rolesHoldingAny(Database $db, array $permissions): array
    {
        $roleIds = [];
        foreach ($permissions as $permission) {
            $rows = $db->query(
                'SELECT rp.role_id
                   FROM role_permissions rp
                   JOIN permissions p ON p.id = rp.permission_id
                  WHERE p.name = :name',
                [':name' => $permission]
            )->fetchAll();
            foreach ($rows as $row) {
                $roleIds[(int) $row['role_id']] = true;
            }
        }

        return array_map('intval', array_keys($roleIds));
    }

    private static function permissionId(Database $db, string $name): ?int
    {
        $result = $db->query('SELECT id FROM permissions WHERE name = :name', [':name' => $name])->fetch();

        return $result === false ? null : (int) $result['id'];
    }
}
