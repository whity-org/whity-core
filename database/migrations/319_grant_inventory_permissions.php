<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * GrantInventoryPermissions — the inventory slugs and who holds them
 * (WHIT-1232, design section 31, 4.1).
 *
 * Granted BY CAPABILITY, the shape migration 110 set and
 * ci-grant-by-role-name-guard enforces: never to a role by name.
 *
 * - `inventory:read` goes to every role holding `documents:read` (every staff
 *   role, the anchor migration 262 uses for contacts): the whole team looks
 *   stock up.
 * - `inventory:write` (items, units, places) and `stock:adjust` (opening stock,
 *   adjustments and waste) go to every role holding `settings:write` or
 *   `roles:write`, the roles that run the workspace; admins hand them to
 *   managers on the roles page.
 * - `inventory:settings` goes to every role holding `settings:write`.
 *
 * Counts, transfers, purchases and sales get their own slugs in their phases.
 * Direct grants only, as migration 110 explains. Idempotent and reversible.
 */
final class GrantInventoryPermissions
{
    /** @var array<string, string> */
    private const PERMISSIONS = [
        CorePermissions::INVENTORY_READ => 'See items, places, stock and its history',
        CorePermissions::INVENTORY_WRITE => 'Add and change items, units, barcodes and places',
        CorePermissions::STOCK_ADJUST => 'Set opening stock and adjust stock, waste included',
        CorePermissions::INVENTORY_SETTINGS => 'Change the inventory settings',
    ];

    /** @var array<string, list<string>> */
    private const AUDIENCES = [
        CorePermissions::INVENTORY_READ => [CorePermissions::DOCUMENTS_READ, CorePermissions::SETTINGS_WRITE],
        CorePermissions::INVENTORY_WRITE => [CorePermissions::SETTINGS_WRITE, CorePermissions::ROLES_WRITE],
        CorePermissions::STOCK_ADJUST => [CorePermissions::SETTINGS_WRITE, CorePermissions::ROLES_WRITE],
        CorePermissions::INVENTORY_SETTINGS => [CorePermissions::SETTINGS_WRITE],
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

        foreach (self::AUDIENCES as $slug => $audiencePermissions) {
            $permissionId = self::permissionId($db, $slug);
            if ($permissionId === null) {
                continue;
            }
            foreach (self::rolesHoldingAny($db, $audiencePermissions) as $roleId) {
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
        foreach (self::AUDIENCES as $slug => $audiencePermissions) {
            $permissionId = self::permissionId($db, $slug);
            if ($permissionId === null) {
                continue;
            }
            foreach (self::rolesHoldingAny($db, $audiencePermissions) as $roleId) {
                $db->query(
                    'DELETE FROM role_permissions WHERE role_id = :role_id AND permission_id = :permission_id',
                    [':role_id' => $roleId, ':permission_id' => $permissionId]
                );
            }
        }

        foreach (array_keys(self::PERMISSIONS) as $name) {
            $db->query(
                'DELETE FROM permissions
                  WHERE name = :name
                    AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.permission_id = permissions.id)',
                [':name' => $name]
            );
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
