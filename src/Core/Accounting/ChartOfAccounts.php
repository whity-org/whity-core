<?php

declare(strict_types=1);

namespace Whity\Core\Accounting;

use Whity\Core\Db\DbBool;
use Whity\Core\i18n\LocalizedText;
use Whity\Core\i18n\LocalizedTextRejected;

/**
 * The chart of accounts (WHIT-1318, design section 32 §4, register AC.8).
 *
 * A tree of at most five levels. Headings (`is_group`) hold no lines. An
 * account's code can change until it has lines; after that only its name,
 * place in the tree and whether it takes manual lines can. Accounts are
 * archived, never deleted; an account with a role (receivable, sales tax...)
 * can't be archived, and neither can one with a balance or open children.
 *
 * A child takes its heading's type. A plugin's accounts carry `owner_plugin`.
 *
 * TENANT-OWNED: every statement binds `tenant_id`.
 */
final class ChartOfAccounts
{
    public const TYPES = ['asset', 'liability', 'equity', 'income', 'expense'];
    public const SUBTYPES = ['bank', 'cash', 'receivable', 'payable', 'tax', 'inventory', 'cost_of_sales', 'fixed_asset',
        'current_asset', 'current_liability', 'long_term_liability', 'other'];
    public const MAX_DEPTH = 5;

    private const COLUMNS = 'a.id, a.parent_id, a.code, a.name, a.names, a.type, a.subtype, a.is_group, a.role, a.allows_manual,
        a.ou_id, a.currency, a.is_seeded, a.owner_plugin, a.sort_order, a.archived_at, a.created_at, a.updated_at';

    public function __construct(
        private readonly AccountingDb $db,
        private readonly AccountingWorkspace $workspace,
    ) {
    }

    /**
     * Every account in tree order, with its depth and its balance to date
     * (debits minus credits).
     *
     * @return list<array<string, mixed>>
     */
    public function list(int $tenantId, bool $includeArchived = false): array
    {
        $this->workspace->ensure($tenantId);
        $rows = $this->db->all(
            'SELECT ' . self::COLUMNS . ',
                    (SELECT COALESCE(SUM(b.debit_minor), 0) - COALESCE(SUM(b.credit_minor), 0) FROM acc_balances b
                      WHERE b.tenant_id = a.tenant_id AND b.account_id = a.id) AS balance_minor
               FROM acc_accounts a
              WHERE a.tenant_id = :tenant_id' . ($includeArchived ? '' : ' AND a.archived_at IS NULL') . '
              ORDER BY a.sort_order, a.code',
            [':tenant_id' => $tenantId]
        );
        $byParent = [];
        foreach ($rows as $row) {
            $byParent[AccountingDb::int($row['parent_id']) ?? 0][] = $row;
        }
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $out = [];
        $walk = function (int $parent, int $depth) use (&$walk, &$out, $byParent): void {
            foreach ($byParent[$parent] ?? [] as $row) {
                $out[] = self::shape($row) + ['depth' => $depth];
                $walk((int) $row['id'], $depth + 1);
            }
        };
        $walk(0, 0);
        // An account whose heading is archived (and so not listed) still shows, at the top level.
        foreach ($byParent as $parent => $children) {
            if ($parent !== 0 && !in_array($parent, $ids, true)) {
                foreach ($children as $row) {
                    $out[] = self::shape($row) + ['depth' => 0];
                    $walk((int) $row['id'], 1);
                }
            }
        }
        $sums = [];
        foreach (array_reverse($out) as $account) {
            $own = (int) $account['balance_minor'] + ($sums[$account['id']] ?? 0);
            $sums[$account['id']] = $own;
            if ($account['parent_id'] !== null) {
                $sums[$account['parent_id']] = ($sums[$account['parent_id']] ?? 0) + $own;
            }
        }

        return array_map(static fn (array $a): array => ['balance_minor' => $sums[$a['id']] ?? 0] + $a, $out);
    }

    /** @return array<string, mixed> */
    public function get(int $tenantId, int $accountId): array
    {
        $this->workspace->ensure($tenantId);
        $row = $this->db->one(
            'SELECT ' . self::COLUMNS . ',
                    (SELECT COALESCE(SUM(b.debit_minor), 0) - COALESCE(SUM(b.credit_minor), 0) FROM acc_balances b
                      WHERE b.tenant_id = a.tenant_id AND b.account_id = a.id) AS balance_minor
               FROM acc_accounts a WHERE a.tenant_id = :tenant_id AND a.id = :id',
            [':tenant_id' => $tenantId, ':id' => $accountId]
        );

        return $row === null ? throw AccountingRejectedException::notFound('Account') : self::shape($row) + ['has_lines' => $this->hasLines($tenantId, $accountId)];
    }

    /** @return array<string, mixed>|null */
    public function byCode(int $tenantId, string $code): ?array
    {
        $this->workspace->ensure($tenantId);
        $id = AccountingDb::int($this->db->one(
            'SELECT id FROM acc_accounts WHERE tenant_id = :tenant_id AND code = :code',
            [':tenant_id' => $tenantId, ':code' => trim($code)]
        )['id'] ?? null);

        return $id === null ? null : $this->get($tenantId, $id);
    }

    /** @return array<string, mixed>|null */
    public function byRole(int $tenantId, string $role): ?array
    {
        $this->workspace->ensure($tenantId);
        $id = AccountingDb::int($this->db->one(
            'SELECT id FROM acc_accounts WHERE tenant_id = :tenant_id AND role = :role',
            [':tenant_id' => $tenantId, ':role' => $role]
        )['id'] ?? null);

        return $id === null ? null : $this->get($tenantId, $id);
    }

    /**
     * @param array<string, mixed> $body `code`, `name` (text or {en, ar}), `parent_id`, `type` (when no parent), `subtype`, `is_group`, `allows_manual`
     * @return array<string, mixed>
     */
    public function create(int $tenantId, array $body, ?string $ownerPlugin = null): array
    {
        $this->workspace->ensure($tenantId);
        $code = self::code($body['code'] ?? null);
        [$name, $names] = self::name($body['name'] ?? null, []);
        $parent = null;
        if (($body['parent_id'] ?? null) !== null) {
            $parent = $this->parent($tenantId, $body['parent_id'], null);
        }
        $type = $parent['type'] ?? $body['type'] ?? null;
        if (!is_string($type) || !in_array($type, self::TYPES, true)) {
            throw AccountingRejectedException::field('type', 'type is one of ' . implode(', ', self::TYPES));
        }
        $subtype = $body['subtype'] ?? 'other';
        if (!is_string($subtype) || !in_array($subtype, self::SUBTYPES, true)) {
            throw AccountingRejectedException::field('subtype', 'subtype is one of ' . implode(', ', self::SUBTYPES));
        }
        $group = self::bool($body['is_group'] ?? false, 'is_group');
        $manual = self::bool($body['allows_manual'] ?? true, 'allows_manual');

        try {
            $id = $this->db->insert(
                'INSERT INTO acc_accounts (tenant_id, parent_id, code, name, names, type, subtype, is_group, allows_manual, owner_plugin, sort_order)
                 VALUES (:tenant_id, :parent_id, :code, :name, :names, :type, :subtype, :is_group, :manual, :owner_plugin, :sort)',
                [
                    ':tenant_id' => $tenantId, ':parent_id' => $parent['id'] ?? null, ':code' => $code, ':name' => $name, ':names' => $names,
                    ':type' => $type, ':subtype' => $subtype, ':is_group' => $group, ':manual' => $manual, ':owner_plugin' => $ownerPlugin,
                    ':sort' => self::sortFor($code),
                ]
            );
        } catch (\PDOException $e) {
            if (AccountingDb::isUniqueViolation($e)) {
                throw AccountingRejectedException::field('code', 'Another account already has the code ' . $code, 409);
            }
            throw $e;
        }

        return $this->get($tenantId, $id);
    }

    /**
     * @param array<string, mixed> $changes any of `code`, `name`, `parent_id`, `subtype`, `allows_manual`
     * @return array<string, mixed>
     */
    public function update(int $tenantId, int $accountId, array $changes): array
    {
        $account = $this->get($tenantId, $accountId);
        $set = [];
        $params = [':tenant_id' => $tenantId, ':id' => $accountId];
        if (array_key_exists('code', $changes)) {
            $code = self::code($changes['code']);
            if ($code !== $account['code']) {
                if ($account['has_lines']) {
                    throw AccountingRejectedException::field('code', 'This account has entries, so its code stays ' . $account['code'], 409);
                }
                $set[] = 'code = :code';
                $set[] = 'sort_order = :sort';
                $params[':code'] = $code;
                $params[':sort'] = self::sortFor($code);
            }
        }
        if (array_key_exists('name', $changes)) {
            [$name, $names] = self::name($changes['name'], $account['names']);
            $set[] = 'name = :name';
            $set[] = 'names = :names';
            $params[':name'] = $name;
            $params[':names'] = $names;
        }
        if (array_key_exists('parent_id', $changes)) {
            $parent = $changes['parent_id'] === null ? null : $this->parent($tenantId, $changes['parent_id'], $accountId);
            if ($parent !== null && $parent['type'] !== $account['type']) {
                throw AccountingRejectedException::field('parent_id', 'An account stays under a heading of its own type (' . $account['type'] . ')');
            }
            if ($parent !== null && $this->depth($tenantId, $parent['id']) + 1 + $this->height($tenantId, $accountId) > self::MAX_DEPTH) {
                throw AccountingRejectedException::field('parent_id', 'The chart is at most ' . self::MAX_DEPTH . ' levels deep');
            }
            $set[] = 'parent_id = :parent_id';
            $params[':parent_id'] = $parent['id'] ?? null;
        }
        if (array_key_exists('subtype', $changes)) {
            $subtype = $changes['subtype'];
            if (!is_string($subtype) || !in_array($subtype, self::SUBTYPES, true)) {
                throw AccountingRejectedException::field('subtype', 'subtype is one of ' . implode(', ', self::SUBTYPES));
            }
            $set[] = 'subtype = :subtype';
            $params[':subtype'] = $subtype;
        }
        if (array_key_exists('allows_manual', $changes)) {
            $set[] = 'allows_manual = :manual';
            $params[':manual'] = self::bool($changes['allows_manual'], 'allows_manual');
        }
        if ($set === []) {
            return $account;
        }
        try {
            $this->db->run('UPDATE acc_accounts SET ' . implode(', ', $set) . ', updated_at = CURRENT_TIMESTAMP WHERE tenant_id = :tenant_id AND id = :id', $params);
        } catch (\PDOException $e) {
            if (AccountingDb::isUniqueViolation($e)) {
                throw AccountingRejectedException::field('code', 'Another account already has that code', 409);
            }
            throw $e;
        }

        return $this->get($tenantId, $accountId);
    }

    /** @return array<string, mixed> */
    public function archive(int $tenantId, int $accountId): array
    {
        $account = $this->get($tenantId, $accountId);
        if ($account['archived_at'] !== null) {
            return $account;
        }
        if ($account['role'] !== null) {
            throw new AccountingRejectedException($account['code'] . ' ' . $account['name'] . ' is used by the books (' . $account['role'] . ') and stays', 409);
        }
        if ((int) $account['balance_minor'] !== 0) {
            throw new AccountingRejectedException('This account still has a balance; move it to another account first', 409);
        }
        if ($this->db->one(
            'SELECT 1 AS hit FROM acc_accounts WHERE tenant_id = :tenant_id AND parent_id = :id AND archived_at IS NULL',
            [':tenant_id' => $tenantId, ':id' => $accountId]
        ) !== null) {
            throw new AccountingRejectedException('Archive or move the accounts under this heading first', 409);
        }
        $this->db->run(
            'UPDATE acc_accounts SET archived_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE tenant_id = :tenant_id AND id = :id',
            [':tenant_id' => $tenantId, ':id' => $accountId]
        );

        return $this->get($tenantId, $accountId);
    }

    /** @return array<string, mixed> */
    public function restore(int $tenantId, int $accountId): array
    {
        $this->get($tenantId, $accountId);
        $this->db->run(
            'UPDATE acc_accounts SET archived_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE tenant_id = :tenant_id AND id = :id',
            [':tenant_id' => $tenantId, ':id' => $accountId]
        );

        return $this->get($tenantId, $accountId);
    }

    /**
     * The journals.
     *
     * @return list<array<string, mixed>>
     */
    public function journals(int $tenantId): array
    {
        $this->workspace->ensure($tenantId);

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'code' => (string) $r['code'],
            'name' => (string) $r['name'],
            'names' => LocalizedText::decode($r['names']),
            'kind' => (string) $r['kind'],
            'default_account_id' => AccountingDb::int($r['default_account_id']),
            'owner_plugin' => is_string($r['owner_plugin']) ? $r['owner_plugin'] : null,
            'archived_at' => $r['archived_at'],
        ], $this->db->all(
            'SELECT id, code, name, names, kind, default_account_id, owner_plugin, archived_at FROM acc_journals WHERE tenant_id = :tenant_id ORDER BY id',
            [':tenant_id' => $tenantId]
        ));
    }

    private function hasLines(int $tenantId, int $accountId): bool
    {
        return $this->db->one(
            'SELECT 1 AS hit FROM acc_lines WHERE tenant_id = :tenant_id AND account_id = :id LIMIT 1',
            [':tenant_id' => $tenantId, ':id' => $accountId]
        ) !== null;
    }

    /** @return array{id: int, type: string} */
    private function parent(int $tenantId, mixed $parentId, ?int $self): array
    {
        $row = is_int($parentId) ? $this->db->one(
            'SELECT id, type, is_group, archived_at FROM acc_accounts WHERE tenant_id = :tenant_id AND id = :id',
            [':tenant_id' => $tenantId, ':id' => $parentId]
        ) : null;
        if ($row === null) {
            throw AccountingRejectedException::field('parent_id', 'parent_id is not an account here');
        }
        if (!DbBool::of($row['is_group'])) {
            throw AccountingRejectedException::field('parent_id', 'Accounts go under a heading, not under another account');
        }
        if ($row['archived_at'] !== null) {
            throw AccountingRejectedException::field('parent_id', 'That heading is archived');
        }
        if ($self !== null) {
            // A heading can't move under itself or one of its own children.
            for ($at = (int) $row['id']; $at !== 0; $at = AccountingDb::int($this->db->one(
                'SELECT parent_id FROM acc_accounts WHERE tenant_id = :tenant_id AND id = :id',
                [':tenant_id' => $tenantId, ':id' => $at]
            )['parent_id'] ?? null) ?? 0) {
                if ($at === $self) {
                    throw AccountingRejectedException::field('parent_id', 'An account can\'t go under itself');
                }
            }
        }
        if ($self === null && $this->depth($tenantId, (int) $row['id']) + 1 >= self::MAX_DEPTH) {
            throw AccountingRejectedException::field('parent_id', 'The chart is at most ' . self::MAX_DEPTH . ' levels deep');
        }

        return ['id' => (int) $row['id'], 'type' => (string) $row['type']];
    }

    /** How many levels sit above an account (0 at the top). */
    private function depth(int $tenantId, int $accountId): int
    {
        $depth = 0;
        $at = $accountId;
        while ($depth <= self::MAX_DEPTH) {
            $parent = AccountingDb::int($this->db->one(
                'SELECT parent_id FROM acc_accounts WHERE tenant_id = :tenant_id AND id = :id',
                [':tenant_id' => $tenantId, ':id' => $at]
            )['parent_id'] ?? null);
            if ($parent === null) {
                break;
            }
            $depth++;
            $at = $parent;
        }

        return $depth;
    }

    /** How many levels sit below an account (0 for a leaf). */
    private function height(int $tenantId, int $accountId): int
    {
        $children = $this->db->all(
            'SELECT id FROM acc_accounts WHERE tenant_id = :tenant_id AND parent_id = :id',
            [':tenant_id' => $tenantId, ':id' => $accountId]
        );
        $height = 0;
        foreach ($children as $child) {
            $height = max($height, 1 + $this->height($tenantId, (int) $child['id']));
        }

        return $height;
    }

    private static function code(mixed $code): string
    {
        $code = is_string($code) || is_int($code) ? trim((string) $code) : '';
        if (preg_match('/^[0-9A-Za-z][0-9A-Za-z.\-]{0,19}$/', $code) !== 1) {
            throw AccountingRejectedException::field('code', 'code is up to 20 letters, digits, dots or dashes, such as 6150');
        }

        return $code;
    }

    /**
     * @param array<string, string> $existing
     * @return array{0: string, 1: string} the original text and the JSON of every language
     */
    private static function name(mixed $raw, array $existing): array
    {
        try {
            $label = LocalizedText::label($raw, 'name');
        } catch (LocalizedTextRejected $e) {
            throw AccountingRejectedException::field('name', 'Give the account a name');
        }
        if (is_string($raw)) {
            $label += $existing;
        }
        foreach ($label as $text) {
            if (mb_strlen($text) > 150) {
                throw AccountingRejectedException::field('name', 'name is 150 characters or fewer');
            }
        }
        $original = $label['en'] ?? $label['ar'] ?? '';

        return [$original, LocalizedText::encode($label)];
    }

    private static function bool(mixed $value, string $field): bool
    {
        if (!is_bool($value)) {
            throw AccountingRejectedException::field($field, $field . ' is true or false');
        }

        return $value;
    }

    /** New accounts sort by their code among the seeded ones. */
    private static function sortFor(string $code): int
    {
        return ctype_digit($code) ? (int) $code : 999999;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function shape(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'parent_id' => AccountingDb::int($row['parent_id']),
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'names' => LocalizedText::decode($row['names']),
            'type' => (string) $row['type'],
            'subtype' => (string) $row['subtype'],
            'is_group' => DbBool::of($row['is_group']),
            'role' => is_string($row['role']) ? $row['role'] : null,
            'allows_manual' => DbBool::of($row['allows_manual']),
            'ou_id' => AccountingDb::int($row['ou_id']),
            'currency' => is_string($row['currency']) ? $row['currency'] : null,
            'is_seeded' => DbBool::of($row['is_seeded']),
            'owner_plugin' => is_string($row['owner_plugin']) ? $row['owner_plugin'] : null,
            'archived_at' => $row['archived_at'],
            'balance_minor' => (int) ($row['balance_minor'] ?? 0),
        ];
    }
}
