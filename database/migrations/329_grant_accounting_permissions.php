<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * GrantAccountingPermissions — the accounting slugs of phase A1 and who holds
 * them (WHIT-1318, design section 32 §9.1, register AC.24).
 *
 * Granted BY CAPABILITY, the shape migration 110 set and
 * ci-grant-by-role-name-guard enforces: never to a role by name.
 *
 * - `accounting:read` (accounts, entries, reports) goes to every role holding
 *   `settings:write` or `roles:write`, the roles that run the workspace;
 *   admins hand it to managers on the roles page.
 * - `accounting:post` (manual entries, reversals), `accounting:close` (closing
 *   and reopening periods) and `accounting:settings` (the chart, journals,
 *   opening balances) go to every role holding `settings:write`.
 *
 * The workspace's Accountant role (AC.24) is made when accounting is first
 * opened, with these slugs, by AccountingWorkspace, not here. Invoices, bills
 * and payments get their own slugs in phases A2 and A3. Direct grants only, as
 * migration 110 explains. Idempotent and reversible.
 */
final class GrantAccountingPermissions
{
    /** @var array<string, string> */
    private const PERMISSIONS = [
        CorePermissions::ACCOUNTING_READ => 'See accounts, entries and reports',
        CorePermissions::ACCOUNTING_POST => 'Post and reverse journal entries',
        CorePermissions::ACCOUNTING_CLOSE => 'Close and reopen accounting periods',
        CorePermissions::ACCOUNTING_SETTINGS => 'Change the chart of accounts and the accounting settings',
    ];

    /** @var array<string, list<string>> */
    private const AUDIENCES = [
        CorePermissions::ACCOUNTING_READ => [CorePermissions::SETTINGS_WRITE, CorePermissions::ROLES_WRITE],
        CorePermissions::ACCOUNTING_POST => [CorePermissions::SETTINGS_WRITE],
        CorePermissions::ACCOUNTING_CLOSE => [CorePermissions::SETTINGS_WRITE],
        CorePermissions::ACCOUNTING_SETTINGS => [CorePermissions::SETTINGS_WRITE],
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
