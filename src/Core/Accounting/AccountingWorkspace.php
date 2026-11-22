<?php

declare(strict_types=1);

namespace Whity\Core\Accounting;

use Whity\Core\i18n\LocalizedText;
use Whity\Core\RBAC\CorePermissions;

/**
 * What every workspace's books start with (WHIT-1318, design section 32 §4,
 * register AC.6, AC.8, AC.9, AC.24): the books row with the base currency, the
 * tax pack's chart of accounts and journals, and the Accountant role.
 *
 * Made on first use rather than by a migration, so a workspace created
 * tomorrow gets them too and one that never opens accounting carries nothing.
 * Idempotent: a missing row is added, an existing one is left alone (a
 * workspace renames and recodes its accounts), and a race between two requests
 * is settled by the unique keys.
 *
 * THE BASE CURRENCY is the workspace's `billing.default_currency` when the
 * books are made; it is fixed for good at the first posting (AC.6, Q14).
 *
 * THE ACCOUNTANT ROLE is a role of this workspace, holding the accounting
 * slugs except the settings, plus reading contacts, so a company can invite its
 * outside accountant (AC.24, Q8). It is made once; afterwards it is the
 * workspace's to change or delete, and it is not made again.
 *
 * `$ready` remembers, for this process, which workspaces are already set up.
 */
final class AccountingWorkspace
{
    public const ACCOUNTANT_ROLE = 'Accountant';

    /** What the Accountant role holds when it is made. Later phases add their slugs by capability. */
    public const ACCOUNTANT_PERMISSIONS = [
        CorePermissions::ACCOUNTING_READ,
        CorePermissions::ACCOUNTING_POST,
        CorePermissions::ACCOUNTING_CLOSE,
        CorePermissions::INVOICES_READ,
        CorePermissions::INVOICES_WRITE,
        CorePermissions::CONTACTS_READ,
    ];

    /** @var array<int, true> */
    private array $ready = [];

    /**
     * @param \Closure(int): string $defaultCurrency the workspace's `billing.default_currency`
     */
    public function __construct(
        private readonly AccountingDb $db,
        private readonly \Closure $defaultCurrency,
    ) {
    }

    public function ensure(int $tenantId): void
    {
        if (isset($this->ready[$tenantId])) {
            return;
        }

        if ($this->db->one('SELECT id FROM acc_books WHERE tenant_id = :tenant_id', [':tenant_id' => $tenantId]) === null) {
            $currency = strtoupper(trim(($this->defaultCurrency)($tenantId)));
            if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                $currency = 'USD';
            }
            $made = $this->quietly(fn () => $this->db->run(
                'INSERT INTO acc_books (tenant_id, base_currency, tax_pack) VALUES (:tenant_id, :currency, :pack)',
                [':tenant_id' => $tenantId, ':currency' => $currency, ':pack' => JordanChart::PACK]
            ));
            if ($made) {
                $this->accountantRole($tenantId);
            }
        }

        $this->chart($tenantId);
        $this->journals($tenantId);
        $this->taxCodes($tenantId);
        $this->ready[$tenantId] = true;
    }

    /**
     * The books row.
     *
     * @return array{base_currency: string, currency_fixed: bool, tax_pack: string, year_start_month: int, lock_date: ?string, chain_entry_id: ?int, chain_hash: string}
     */
    public function books(int $tenantId): array
    {
        $this->ensure($tenantId);
        $row = $this->db->one(
            'SELECT base_currency, currency_fixed_at, tax_pack, year_start_month, lock_date, chain_entry_id, chain_hash
               FROM acc_books WHERE tenant_id = :tenant_id',
            [':tenant_id' => $tenantId]
        ) ?? throw new \RuntimeException('Books are missing for workspace ' . $tenantId);

        return [
            'base_currency' => (string) $row['base_currency'],
            'currency_fixed' => $row['currency_fixed_at'] !== null,
            'tax_pack' => (string) $row['tax_pack'],
            'year_start_month' => (int) $row['year_start_month'],
            'lock_date' => is_string($row['lock_date']) ? substr($row['lock_date'], 0, 10) : null,
            'chain_entry_id' => AccountingDb::int($row['chain_entry_id']),
            'chain_hash' => (string) $row['chain_hash'],
        ];
    }

    /** The id of the account holding a system role (AC.8). */
    public function roleAccountId(int $tenantId, string $role): int
    {
        $this->ensure($tenantId);
        $id = AccountingDb::int($this->db->one(
            'SELECT id FROM acc_accounts WHERE tenant_id = :tenant_id AND role = :role',
            [':tenant_id' => $tenantId, ':role' => $role]
        )['id'] ?? null);

        return $id ?? throw AccountingRejectedException::field('role', 'No account holds the role ' . $role);
    }

    /** The id of a journal by its code. */
    public function journalId(int $tenantId, string $code): int
    {
        $this->ensure($tenantId);
        $id = AccountingDb::int($this->db->one(
            'SELECT id FROM acc_journals WHERE tenant_id = :tenant_id AND code = :code',
            [':tenant_id' => $tenantId, ':code' => $code]
        )['id'] ?? null);

        return $id ?? throw AccountingRejectedException::field('journal', 'There is no journal ' . $code);
    }

    private function chart(int $tenantId): void
    {
        $existing = [];
        foreach ($this->db->all('SELECT id, code, role FROM acc_accounts WHERE tenant_id = :tenant_id', [':tenant_id' => $tenantId]) as $row) {
            $existing[(string) $row['code']] = (int) $row['id'];
        }
        if ($existing !== []) {
            // A workspace's chart is its own once made; only a missing system role is added back.
            $this->missingRoles($tenantId);

            return;
        }
        $ids = [];
        $order = 0;
        foreach (JordanChart::ACCOUNTS as $code => [$parent, $type, $subtype, $group, $role, $manual, $en, $ar]) {
            $order += 10;
            $this->quietly(fn () => $this->db->run(
                'INSERT INTO acc_accounts (tenant_id, parent_id, code, name, names, type, subtype, is_group, role, allows_manual, is_seeded, sort_order)
                 VALUES (:tenant_id, :parent_id, :code, :name, :names, :type, :subtype, :is_group, :role, :manual, TRUE, :sort)',
                [
                    ':tenant_id' => $tenantId, ':parent_id' => $parent === null ? null : ($ids[$parent] ?? null),
                    ':code' => (string) $code, ':name' => $en, ':names' => LocalizedText::encode(['en' => $en, 'ar' => $ar]),
                    ':type' => $type, ':subtype' => $subtype, ':is_group' => $group, ':role' => $role, ':manual' => $manual,
                    ':sort' => $order,
                ]
            ));
            $ids[(string) $code] = (int) ($this->db->one(
                'SELECT id FROM acc_accounts WHERE tenant_id = :tenant_id AND code = :code',
                [':tenant_id' => $tenantId, ':code' => (string) $code]
            )['id'] ?? 0);
        }
    }

    /** A role the template has and the workspace lost (its account was recoded away) is added as a new account. */
    private function missingRoles(int $tenantId): void
    {
        $roles = array_column(
            $this->db->all('SELECT role FROM acc_accounts WHERE tenant_id = :tenant_id AND role IS NOT NULL', [':tenant_id' => $tenantId]),
            'role'
        );
        foreach (JordanChart::ACCOUNTS as $code => [$parent, $type, $subtype, $group, $role, $manual, $en, $ar]) {
            if ($role === null || in_array($role, $roles, true)) {
                continue;
            }
            $parentId = $parent === null ? null : AccountingDb::int($this->db->one(
                'SELECT id FROM acc_accounts WHERE tenant_id = :tenant_id AND code = :code',
                [':tenant_id' => $tenantId, ':code' => $parent]
            )['id'] ?? null);
            $free = (string) $code;
            for ($n = 1; $this->db->one('SELECT 1 AS hit FROM acc_accounts WHERE tenant_id = :t AND code = :c', [':t' => $tenantId, ':c' => $free]) !== null; $n++) {
                $free = (string) $code . '-' . $n;
            }
            $this->quietly(fn () => $this->db->run(
                'INSERT INTO acc_accounts (tenant_id, parent_id, code, name, names, type, subtype, is_group, role, allows_manual, is_seeded)
                 VALUES (:tenant_id, :parent_id, :code, :name, :names, :type, :subtype, FALSE, :role, :manual, TRUE)',
                [
                    ':tenant_id' => $tenantId, ':parent_id' => $parentId, ':code' => $free, ':name' => $en,
                    ':names' => LocalizedText::encode(['en' => $en, 'ar' => $ar]), ':type' => $type, ':subtype' => $subtype,
                    ':role' => $role, ':manual' => $manual,
                ]
            ));
        }
    }

    /** The pack's tax codes a workspace lacks are added; one it changed or archived stays as it is. */
    private function taxCodes(int $tenantId): void
    {
        $codes = array_column($this->db->all('SELECT code FROM acc_tax_codes WHERE tenant_id = :tenant_id', [':tenant_id' => $tenantId]), 'code');
        foreach (JordanChart::TAX_CODES as $code => [$direction, $rate, $treatment, $category, $role, $box, $einvoice, $en, $ar]) {
            if (in_array($code, $codes, true)) {
                continue;
            }
            $accountId = AccountingDb::int($this->db->one(
                'SELECT id FROM acc_accounts WHERE tenant_id = :tenant_id AND role = :role',
                [':tenant_id' => $tenantId, ':role' => $role]
            )['id'] ?? null);
            $this->quietly(fn () => $this->db->run(
                'INSERT INTO acc_tax_codes (tenant_id, code, name, names, pack, direction, rate_bp, treatment, category, account_id,
                        return_box, einvoice_category, is_seeded)
                 VALUES (:tenant_id, :code, :name, :names, :pack, :direction, :rate, :treatment, :category, :account_id,
                        :box, :einvoice, TRUE)',
                [
                    ':tenant_id' => $tenantId, ':code' => $code, ':name' => $en, ':names' => LocalizedText::encode(['en' => $en, 'ar' => $ar]),
                    ':pack' => JordanChart::PACK, ':direction' => $direction, ':rate' => $rate, ':treatment' => $treatment,
                    ':category' => $category, ':account_id' => $accountId, ':box' => $box, ':einvoice' => $einvoice,
                ]
            ));
        }
    }

    private function journals(int $tenantId): void
    {
        $codes = array_column($this->db->all('SELECT code FROM acc_journals WHERE tenant_id = :tenant_id', [':tenant_id' => $tenantId]), 'code');
        foreach (JordanChart::JOURNALS as $code => [$kind, $accountCode, $en, $ar]) {
            if (in_array($code, $codes, true)) {
                continue;
            }
            $accountId = $accountCode === null ? null : AccountingDb::int($this->db->one(
                'SELECT id FROM acc_accounts WHERE tenant_id = :tenant_id AND code = :code',
                [':tenant_id' => $tenantId, ':code' => $accountCode]
            )['id'] ?? null);
            $this->quietly(fn () => $this->db->run(
                'INSERT INTO acc_journals (tenant_id, code, name, names, kind, default_account_id)
                 VALUES (:tenant_id, :code, :name, :names, :kind, :account_id)',
                [
                    ':tenant_id' => $tenantId, ':code' => $code, ':name' => $en,
                    ':names' => LocalizedText::encode(['en' => $en, 'ar' => $ar]), ':kind' => $kind, ':account_id' => $accountId,
                ]
            ));
        }
    }

    /** The workspace's Accountant role, made with the books and never again. */
    private function accountantRole(int $tenantId): void
    {
        if ($this->db->one(
            'SELECT id FROM roles WHERE tenant_id = :tenant_id AND name = :name',
            [':tenant_id' => $tenantId, ':name' => self::ACCOUNTANT_ROLE]
        ) !== null) {
            return;
        }
        $roleId = null;
        $this->quietly(function () use ($tenantId, &$roleId): void {
            $roleId = $this->db->insert(
                'INSERT INTO roles (name, description, tenant_id, created_at) VALUES (:name, :description, :tenant_id, CURRENT_TIMESTAMP)',
                [
                    ':name' => self::ACCOUNTANT_ROLE,
                    ':description' => "For your accountant: sees the books and reports, posts entries and closes months. Can't change the accounting settings.",
                    ':tenant_id' => $tenantId,
                ]
            );
        });
        if (!is_int($roleId)) {
            return;
        }
        foreach (self::ACCOUNTANT_PERMISSIONS as $permission) {
            $this->db->run(
                'INSERT INTO role_permissions (role_id, permission_id, created_at)
                 SELECT :role_id, id, CURRENT_TIMESTAMP FROM permissions WHERE name = :name
                 ON CONFLICT (role_id, permission_id) DO NOTHING',
                [':role_id' => $roleId, ':name' => $permission]
            );
        }
    }

    /**
     * Inserts that lose a race to another request are fine: the row is there.
     * Inside a PostgreSQL transaction a failed statement would poison it, so
     * the insert runs under a savepoint there.
     */
    private function quietly(callable $insert): bool
    {
        $savepoint = $this->db->inTransaction() && $this->db->isPostgres();
        if ($savepoint) {
            $this->db->pdo->exec('SAVEPOINT acc_seed');
        }
        try {
            $insert();
            if ($savepoint) {
                $this->db->pdo->exec('RELEASE SAVEPOINT acc_seed');
            }

            return true;
        } catch (\PDOException $e) {
            $this->undoSavepoint($savepoint);
            if (!AccountingDb::isUniqueViolation($e)) {
                throw $e;
            }

            return false;
        }
    }

    private function undoSavepoint(bool $savepoint): void
    {
        if ($savepoint) {
            $this->db->pdo->exec('ROLLBACK TO SAVEPOINT acc_seed');
        }
    }
}
