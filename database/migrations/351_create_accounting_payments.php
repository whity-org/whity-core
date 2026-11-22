<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * CreateAccountingPayments — money received, cheques, and transfers between
 * the workspace's own accounts (WHIT-1318 A2b, design section 32 §5.4, §5.5,
 * register AC.13, AC.14, AC.17).
 *
 * - `acc_payments`: one row per payment. `direction` is `in` (from a
 *   customer), `out` (to a supplier, phase A3) or `transfer` (between the
 *   workspace's own accounts, no contact). Posting a payment posts its entry
 *   (`entry_id`); a cheque received with a later date waits on Cheques to
 *   collect until it is deposited (`deposit_entry_id`) or bounces
 *   (`cheque_status`). What a payment settles is in `acc_allocations`
 *   (migration 350), whose `payment_id` points here; money not yet allocated
 *   is the customer's credit. `external_ref` makes a payment idempotent: Whity
 *   Pay passes its own payment id.
 *
 * Amounts are minor units of `currency`, with `rate_num`/`rate_den` (1/1 in
 * the base currency until multi-currency, A7).
 *
 * Permissions `payments:read` and `payments:write`, granted by capability:
 * read to the roles that run the workspace or post to the books, write to
 * those holding `settings:write` or `accounting:post` (so each workspace's
 * Accountant role gets both).
 *
 * Tenant-owned and named literally for TenantOwnedTablesTest and
 * CoreTablesTest. Idempotent and reversible.
 */
final class CreateAccountingPayments
{
    /** @var array<string, string> */
    private const PERMISSIONS = [
        CorePermissions::PAYMENTS_READ => 'See payments and what they settle',
        CorePermissions::PAYMENTS_WRITE => 'Record payments, allocate them and handle cheques',
    ];

    /** @var array<string, list<string>> */
    private const AUDIENCES = [
        CorePermissions::PAYMENTS_READ => [CorePermissions::SETTINGS_WRITE, CorePermissions::ROLES_WRITE, CorePermissions::ACCOUNTING_POST],
        CorePermissions::PAYMENTS_WRITE => [CorePermissions::SETTINGS_WRITE, CorePermissions::ACCOUNTING_POST],
    ];

    public static function up(Database $db): void
    {
        $db->exec('
            CREATE TABLE IF NOT EXISTS acc_payments (
                id                    SERIAL PRIMARY KEY,
                tenant_id             INTEGER       NOT NULL REFERENCES tenants(id)      ON DELETE CASCADE,
                direction             VARCHAR(10)   NOT NULL,
                number                VARCHAR(40)   NOT NULL,
                contact_id            INTEGER                REFERENCES contacts(id),
                paid_on               DATE          NOT NULL,
                amount_minor          BIGINT        NOT NULL,
                currency              VARCHAR(3)    NOT NULL,
                rate_num              BIGINT        NOT NULL DEFAULT 1,
                rate_den              BIGINT        NOT NULL DEFAULT 1,
                method                VARCHAR(20)   NOT NULL,
                account_id            INTEGER       NOT NULL REFERENCES acc_accounts(id),
                from_account_id       INTEGER                REFERENCES acc_accounts(id),
                reference             VARCHAR(200)  NOT NULL DEFAULT \'\',
                cheque_number         VARCHAR(60),
                cheque_bank           VARCHAR(120),
                cheque_due_on         DATE,
                cheque_status         VARCHAR(20),
                fee_minor             BIGINT        NOT NULL DEFAULT 0,
                status                VARCHAR(20)   NOT NULL DEFAULT \'posted\',
                notes                 VARCHAR(2000) NOT NULL DEFAULT \'\',
                -- @reference-lint-ignore: the posted entry; entries live forever and are never deleted
                entry_id              BIGINT,
                -- @reference-lint-ignore: the cheque\'s deposit entry; entries live forever
                deposit_entry_id      BIGINT,
                -- @reference-lint-ignore: the entry that undid a bounced cheque; entries live forever
                bounce_entry_id       BIGINT,
                external_ref          VARCHAR(160),
                created_by_profile_id INTEGER                REFERENCES profiles(id) ON DELETE SET NULL,
                -- @reference-lint-ignore: the agent that proposed the payment, kept for the trail even after the agent is removed
                agent_id              INTEGER,
                on_behalf_of_profile_id INTEGER              REFERENCES profiles(id) ON DELETE SET NULL,
                owner_plugin          VARCHAR(100),
                created_at            TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at            TIMESTAMP     NOT NULL DEFAULT NOW(),
                CONSTRAINT ck_acc_payments_amount CHECK (amount_minor > 0 AND fee_minor >= 0 AND fee_minor <= amount_minor)
            )
        ');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_acc_payments_number ON acc_payments(tenant_id, direction, number)');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_acc_payments_external ON acc_payments(tenant_id, external_ref) WHERE external_ref IS NOT NULL');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_acc_payments_date ON acc_payments(tenant_id, direction, paid_on)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_acc_payments_contact ON acc_payments(tenant_id, contact_id) WHERE contact_id IS NOT NULL');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_acc_payments_cheques ON acc_payments(tenant_id, cheque_status, cheque_due_on) WHERE cheque_status IS NOT NULL');
        // migration-lint: allow index-not-concurrent acc_allocations was made by migration 350 and holds only credit-note settlements, a handful of rows
        $db->exec('CREATE INDEX IF NOT EXISTS idx_acc_allocations_payment ON acc_allocations(tenant_id, payment_id) WHERE payment_id IS NOT NULL');

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
        $db->exec('DROP INDEX IF EXISTS idx_acc_allocations_payment');
        $db->exec('DROP TABLE IF EXISTS acc_payments');
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
