<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * AddAccountingExpenseClaims — expense claims and petty cash (WHIT-1318 A3b,
 * design section 32 §5.3, register MO.1, Q5).
 *
 * An expense claim is a supplier bill whose supplier is the member of staff
 * who paid, through their own contact (`contacts.profile_id`), so it posts to
 * We owe suppliers under their name and is reimbursed as a payment made.
 * `acc_invoices.claimant_profile_id` marks it as a claim and says whose it
 * is; `receipt_stored_file_id` keeps the photo of the receipt. A petty cash
 * spend is a bill paid at once from the Petty cash account, so neither needs a
 * table of its own.
 *
 * Permission `expenses:submit` (a person files their own claims), granted by
 * capability to the roles holding `settings:write` or `accounting:post`;
 * admins hand it to staff roles on the roles page. Idempotent and reversible.
 */
final class AddAccountingExpenseClaims
{
    /** @var array<string, string> */
    private const PERMISSIONS = [
        CorePermissions::EXPENSES_SUBMIT => 'Claim back money spent for the workspace',
    ];

    /** @var array<string, list<string>> */
    private const AUDIENCES = [
        CorePermissions::EXPENSES_SUBMIT => [CorePermissions::SETTINGS_WRITE, CorePermissions::ACCOUNTING_POST],
    ];

    public static function up(Database $db): void
    {
        $db->exec('ALTER TABLE acc_invoices ADD COLUMN IF NOT EXISTS claimant_profile_id INTEGER REFERENCES profiles(id) ON DELETE SET NULL');
        $db->exec('ALTER TABLE acc_invoices ADD COLUMN IF NOT EXISTS receipt_stored_file_id BIGINT REFERENCES stored_files(id) ON DELETE SET NULL');
        // migration-lint: allow index-not-concurrent acc_invoices is a few days old and holds no real customers' data yet (no real customers, owner 16 Nov 2026)
        $db->exec('CREATE INDEX IF NOT EXISTS idx_acc_invoices_claimant ON acc_invoices(tenant_id, claimant_profile_id) WHERE claimant_profile_id IS NOT NULL');

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
        $db->exec('DROP INDEX IF EXISTS idx_acc_invoices_claimant');
        $db->exec('ALTER TABLE acc_invoices DROP COLUMN IF EXISTS receipt_stored_file_id');
        $db->exec('ALTER TABLE acc_invoices DROP COLUMN IF EXISTS claimant_profile_id');
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
