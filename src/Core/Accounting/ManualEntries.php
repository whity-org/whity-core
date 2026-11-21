<?php

declare(strict_types=1);

namespace Whity\Core\Accounting;

use Whity\Core\Money\Currency;
use Whity\Core\Money\MoneyException;

/**
 * Journal entries people write (WHIT-1318, design section 32 §5.1, register
 * AC.10).
 *
 * A draft lives in `acc_manual_entries` and may be changed or cancelled; the
 * ledger only ever receives it when it is posted. Posting needs
 * `accounting:post` (the route checks it) and goes through {@see Ledger::post()}
 * with source `manual` (or `opening` in the opening journal), so the same rules
 * hold: balanced, open month, no lines on accounts kept by documents unless it
 * is the opening journal.
 *
 * Amounts are typed in the base currency's normal units ("12.500" for JOD) and
 * kept in minor units. Approval routes for large entries and agents' entries
 * arrive with the Money screens (A1b).
 *
 * TENANT-OWNED: every statement binds `tenant_id`.
 */
final class ManualEntries
{
    public const JOURNAL_KINDS = ['general', 'opening', 'bank', 'cash'];

    public function __construct(
        private readonly AccountingDb $db,
        private readonly AccountingWorkspace $workspace,
        private readonly Ledger $ledger,
    ) {
    }

    /**
     * @param array{status?: ?string, before_id?: ?int} $filter
     * @return list<array<string, mixed>>
     */
    public function list(int $tenantId, array $filter = [], int $limit = 50): array
    {
        $this->workspace->ensure($tenantId);
        $where = ['m.tenant_id = :tenant_id'];
        $params = [':tenant_id' => $tenantId];
        if (isset($filter['status']) && $filter['status'] !== '') {
            $where[] = 'm.status = :status';
            $params[':status'] = $filter['status'];
        }
        if (isset($filter['before_id'])) {
            $where[] = 'm.id < :before_id';
            $params[':before_id'] = (int) $filter['before_id'];
        }
        $rows = $this->db->all(
            'SELECT m.id, m.journal_id, j.code AS journal_code, m.entry_date, m.memo, m.status, m.entry_id, m.created_by_profile_id,
                    m.agent_id, m.on_behalf_of_profile_id, m.created_at, m.updated_at,
                    (SELECT COALESCE(SUM(l.debit_minor), 0) FROM acc_manual_entry_lines l WHERE l.tenant_id = m.tenant_id AND l.manual_entry_id = m.id) AS debit_minor,
                    (SELECT COALESCE(SUM(l.credit_minor), 0) FROM acc_manual_entry_lines l WHERE l.tenant_id = m.tenant_id AND l.manual_entry_id = m.id) AS credit_minor
               FROM acc_manual_entries m JOIN acc_journals j ON j.id = m.journal_id AND j.tenant_id = m.tenant_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY m.id DESC LIMIT ' . max(1, min(Ledger::MAX_PAGE, $limit)),
            $params
        );

        return array_map(fn (array $r): array => $this->shape($tenantId, $r, null), $rows);
    }

    /** @return array<string, mixed> */
    public function get(int $tenantId, int $id): array
    {
        $this->workspace->ensure($tenantId);
        $row = $this->db->one(
            'SELECT m.id, m.journal_id, j.code AS journal_code, m.entry_date, m.memo, m.status, m.entry_id, m.created_by_profile_id,
                    m.agent_id, m.on_behalf_of_profile_id, m.created_at, m.updated_at
               FROM acc_manual_entries m JOIN acc_journals j ON j.id = m.journal_id AND j.tenant_id = m.tenant_id
              WHERE m.tenant_id = :tenant_id AND m.id = :id',
            [':tenant_id' => $tenantId, ':id' => $id]
        );
        if ($row === null) {
            throw AccountingRejectedException::notFound('Journal entry');
        }
        $lines = $this->db->all(
            'SELECT l.id, l.line_no, l.account_id, a.code AS account_code, a.name AS account_name, l.debit_minor, l.credit_minor,
                    l.contact_id, l.ou_id, l.description
               FROM acc_manual_entry_lines l JOIN acc_accounts a ON a.id = l.account_id AND a.tenant_id = l.tenant_id
              WHERE l.tenant_id = :tenant_id AND l.manual_entry_id = :id ORDER BY l.line_no',
            [':tenant_id' => $tenantId, ':id' => $id]
        );
        $row['debit_minor'] = array_sum(array_map(static fn (array $l): int => (int) $l['debit_minor'], $lines));
        $row['credit_minor'] = array_sum(array_map(static fn (array $l): int => (int) $l['credit_minor'], $lines));

        return $this->shape($tenantId, $row, $lines);
    }

    /**
     * @param array<string, mixed> $body `journal` (code, default GEN), `date`, `memo`, `lines` (each `account_id`, `debit` or `credit` as a decimal, `contact_id`, `ou_id`, `description`)
     * @return array<string, mixed>
     */
    public function create(int $tenantId, ?int $actor, array $body, ?int $agentId = null, ?int $onBehalfOf = null): array
    {
        $this->workspace->ensure($tenantId);
        $journalId = $this->journalId($tenantId, $body['journal'] ?? 'GEN');
        $date = Periods::date($body['date'] ?? null);
        $memo = self::memo($body['memo'] ?? '');
        $lines = $this->lines($tenantId, $body['lines'] ?? null);

        return $this->db->transaction(function () use ($tenantId, $actor, $journalId, $date, $memo, $lines, $agentId, $onBehalfOf): array {
            $id = $this->db->insert(
                'INSERT INTO acc_manual_entries (tenant_id, journal_id, entry_date, memo, created_by_profile_id, agent_id, on_behalf_of_profile_id)
                 VALUES (:tenant_id, :journal_id, :entry_date, :memo, :actor, :agent_id, :on_behalf_of)',
                [':tenant_id' => $tenantId, ':journal_id' => $journalId, ':entry_date' => $date, ':memo' => $memo, ':actor' => $actor,
                    ':agent_id' => $agentId, ':on_behalf_of' => $onBehalfOf]
            );
            $this->writeLines($tenantId, $id, $lines);

            return $this->get($tenantId, $id);
        });
    }

    /**
     * @param array<string, mixed> $changes any of `journal`, `date`, `memo`, `lines` (replaces them all)
     * @return array<string, mixed>
     */
    public function update(int $tenantId, int $id, array $changes): array
    {
        $this->draft($tenantId, $id);
        $set = [];
        $params = [':tenant_id' => $tenantId, ':id' => $id];
        if (array_key_exists('journal', $changes)) {
            $set[] = 'journal_id = :journal_id';
            $params[':journal_id'] = $this->journalId($tenantId, $changes['journal']);
        }
        if (array_key_exists('date', $changes)) {
            $set[] = 'entry_date = :entry_date';
            $params[':entry_date'] = Periods::date($changes['date']);
        }
        if (array_key_exists('memo', $changes)) {
            $set[] = 'memo = :memo';
            $params[':memo'] = self::memo($changes['memo']);
        }
        $lines = array_key_exists('lines', $changes) ? $this->lines($tenantId, $changes['lines']) : null;

        return $this->db->transaction(function () use ($tenantId, $id, $set, $params, $lines): array {
            $this->db->run(
                'UPDATE acc_manual_entries SET ' . implode(', ', [...$set, 'updated_at = CURRENT_TIMESTAMP']) . "
                  WHERE tenant_id = :tenant_id AND id = :id AND status = 'draft'",
                $params
            );
            if ($lines !== null) {
                $this->db->run('DELETE FROM acc_manual_entry_lines WHERE tenant_id = :tenant_id AND manual_entry_id = :id', [':tenant_id' => $tenantId, ':id' => $id]);
                $this->writeLines($tenantId, $id, $lines);
            }
            return $this->get($tenantId, $id);
        });
    }

    /**
     * Posts a draft to the ledger.
     *
     * @return array<string, mixed> the draft, now `posted`, with its `entry`
     */
    public function post(int $tenantId, int $id, ?int $actor): array
    {
        $draft = $this->draft($tenantId, $id);
        $lines = array_values(array_map(static fn (array $l): array => [
            'account_id' => $l['account_id'],
            'debit_minor' => $l['debit_minor'],
            'credit_minor' => $l['credit_minor'],
            'contact_id' => $l['contact_id'],
            'ou_id' => $l['ou_id'],
            'description' => $l['description'],
            'source_line_type' => 'manual_line',
            'source_line_id' => $l['id'],
        ], $draft['lines']));

        return $this->db->transaction(function () use ($tenantId, $id, $actor, $draft, $lines): array {
            // Claim the draft first, so two people posting it at once post it once.
            $claimed = $this->db->run(
                "UPDATE acc_manual_entries SET status = 'posting', updated_at = CURRENT_TIMESTAMP
                  WHERE tenant_id = :tenant_id AND id = :id AND status = 'draft'",
                [':tenant_id' => $tenantId, ':id' => $id]
            )->rowCount();
            if ($claimed !== 1) {
                throw new AccountingRejectedException('This entry was already posted or cancelled', 409);
            }
            $posted = $this->ledger->post($tenantId, $actor, [
                'journal' => $draft['journal_id'],
                'date' => $draft['date'],
                'memo' => $draft['memo'],
                'lines' => $lines,
                'source_type' => $draft['journal_kind'] === 'opening' ? 'opening' : 'manual',
                'source_id' => $id,
                'idempotency_key' => 'manual:' . $id,
                'agent_id' => $draft['agent_id'],
                'on_behalf_of' => $draft['on_behalf_of_profile_id'],
            ]);
            $this->db->run(
                "UPDATE acc_manual_entries SET status = 'posted', entry_id = :entry_id, updated_at = CURRENT_TIMESTAMP
                  WHERE tenant_id = :tenant_id AND id = :id",
                [':tenant_id' => $tenantId, ':id' => $id, ':entry_id' => (int) $posted['entry']['id']]
            );

            return $this->get($tenantId, $id);
        });
    }

    /** @return array<string, mixed> */
    public function cancel(int $tenantId, int $id): array
    {
        $this->draft($tenantId, $id);
        $this->db->run(
            "UPDATE acc_manual_entries SET status = 'cancelled', updated_at = CURRENT_TIMESTAMP WHERE tenant_id = :tenant_id AND id = :id AND status = 'draft'",
            [':tenant_id' => $tenantId, ':id' => $id]
        );

        return $this->get($tenantId, $id);
    }

    /** @return array<string, mixed> */
    private function draft(int $tenantId, int $id): array
    {
        $entry = $this->get($tenantId, $id);
        if ($entry['status'] !== 'draft') {
            throw new AccountingRejectedException('Only a draft can change; this one is ' . $entry['status'], 409);
        }

        return $entry;
    }

    private function journalId(int $tenantId, mixed $code): int
    {
        $row = is_string($code) && $code !== '' ? $this->db->one(
            'SELECT id, kind, archived_at FROM acc_journals WHERE tenant_id = :tenant_id AND code = :code',
            [':tenant_id' => $tenantId, ':code' => strtoupper(trim($code))]
        ) : null;
        if ($row === null || $row['archived_at'] !== null) {
            throw AccountingRejectedException::field('journal', 'journal is the code of a journal in use here, such as GEN');
        }
        if (!in_array($row['kind'], self::JOURNAL_KINDS, true)) {
            throw AccountingRejectedException::field('journal', 'Sales, purchases and stock are posted by their documents; write this in the general journal');
        }

        return (int) $row['id'];
    }

    /**
     * Lines as typed, checked for shape; the ledger checks the rest when posting.
     *
     * @return list<array{account_id: int, debit_minor: int, credit_minor: int, contact_id: ?int, ou_id: ?int, description: string}>
     */
    private function lines(int $tenantId, mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw) || count($raw) < 2 || count($raw) > Ledger::MAX_LINES) {
            throw AccountingRejectedException::field('lines', 'An entry has 2 to ' . Ledger::MAX_LINES . ' lines');
        }
        $currency = $this->workspace->books($tenantId)['base_currency'];
        $out = [];
        foreach ($raw as $i => $line) {
            $field = 'lines[' . $i . ']';
            if (!is_array($line)) {
                throw AccountingRejectedException::field($field, $field . ' must be an object');
            }
            $accountId = $line['account_id'] ?? null;
            if (!is_int($accountId) || $this->db->one(
                'SELECT 1 AS hit FROM acc_accounts WHERE tenant_id = :tenant_id AND id = :id',
                [':tenant_id' => $tenantId, ':id' => $accountId]
            ) === null) {
                throw AccountingRejectedException::field($field, $field . '.account_id is not an account here');
            }
            $debit = self::amount($line['debit'] ?? null, $currency, $field . '.debit');
            $credit = self::amount($line['credit'] ?? null, $currency, $field . '.credit');
            if (($debit > 0) === ($credit > 0)) {
                throw AccountingRejectedException::field($field, $field . ' is a debit or a credit above 0, not both');
            }
            $contactId = $line['contact_id'] ?? null;
            $ouId = $line['ou_id'] ?? null;
            if ($contactId !== null && !is_int($contactId)) {
                throw AccountingRejectedException::field($field, $field . '.contact_id is a contact id');
            }
            if ($ouId !== null && !is_int($ouId)) {
                throw AccountingRejectedException::field($field, $field . '.ou_id is a unit id');
            }
            $out[] = [
                'account_id' => $accountId,
                'debit_minor' => $debit,
                'credit_minor' => $credit,
                'contact_id' => $contactId,
                'ou_id' => $ouId,
                'description' => is_string($line['description'] ?? null) ? mb_substr(trim($line['description']), 0, 500) : '',
            ];
        }

        return $out;
    }

    /** @param list<array{account_id: int, debit_minor: int, credit_minor: int, contact_id: ?int, ou_id: ?int, description: string}> $lines */
    private function writeLines(int $tenantId, int $id, array $lines): void
    {
        foreach ($lines as $i => $line) {
            $this->db->run(
                'INSERT INTO acc_manual_entry_lines (tenant_id, manual_entry_id, line_no, account_id, debit_minor, credit_minor, contact_id, ou_id, description)
                 VALUES (:tenant_id, :id, :line_no, :account_id, :debit, :credit, :contact_id, :ou_id, :description)',
                [':tenant_id' => $tenantId, ':id' => $id, ':line_no' => $i + 1, ':account_id' => $line['account_id'],
                    ':debit' => $line['debit_minor'], ':credit' => $line['credit_minor'], ':contact_id' => $line['contact_id'],
                    ':ou_id' => $line['ou_id'], ':description' => $line['description']]
            );
        }
    }

    private static function amount(mixed $value, string $currency, string $field): int
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return 0;
        }
        if (!is_string($value) && !is_int($value)) {
            throw AccountingRejectedException::field($field, $field . ' is an amount written like 12.50');
        }
        try {
            $minor = Currency::parse((string) $value, $currency);
        } catch (MoneyException) {
            throw AccountingRejectedException::field($field, $field . ' is an amount in ' . $currency . ' with at most ' . Currency::exponentFor($currency) . ' decimals');
        }
        if ($minor < 0) {
            throw AccountingRejectedException::field($field, $field . ' is not negative; put it on the other side');
        }

        return $minor;
    }

    private static function memo(mixed $memo): string
    {
        if (!is_string($memo)) {
            throw AccountingRejectedException::field('memo', 'memo is text');
        }
        if (mb_strlen(trim($memo)) > 500) {
            throw AccountingRejectedException::field('memo', 'memo is 500 characters or fewer');
        }

        return trim($memo);
    }

    /**
     * @param array<string, mixed> $row
     * @param list<array<string, mixed>>|null $lines
     * @return array<string, mixed>
     */
    private function shape(int $tenantId, array $row, ?array $lines): array
    {
        $journalKind = (string) ($this->db->one(
            'SELECT kind FROM acc_journals WHERE tenant_id = :tenant_id AND id = :id',
            [':tenant_id' => $tenantId, ':id' => (int) $row['journal_id']]
        )['kind'] ?? 'general');
        $debit = (int) ($row['debit_minor'] ?? 0);
        $credit = (int) ($row['credit_minor'] ?? 0);
        $out = [
            'id' => (int) $row['id'],
            'journal_id' => (int) $row['journal_id'],
            'journal_code' => (string) $row['journal_code'],
            'journal_kind' => $journalKind,
            'date' => substr((string) $row['entry_date'], 0, 10),
            'memo' => (string) $row['memo'],
            'status' => (string) $row['status'],
            'entry_id' => AccountingDb::int($row['entry_id']),
            'currency' => $this->workspace->books($tenantId)['base_currency'],
            'debit_minor' => $debit,
            'credit_minor' => $credit,
            'balanced' => $debit === $credit && $debit > 0,
            'created_by_profile_id' => AccountingDb::int($row['created_by_profile_id']),
            'agent_id' => AccountingDb::int($row['agent_id']),
            'on_behalf_of_profile_id' => AccountingDb::int($row['on_behalf_of_profile_id']),
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
        if ($lines !== null) {
            $out['lines'] = array_map(static fn (array $l): array => [
                'id' => (int) $l['id'],
                'line_no' => (int) $l['line_no'],
                'account_id' => (int) $l['account_id'],
                'account_code' => (string) $l['account_code'],
                'account_name' => (string) $l['account_name'],
                'debit_minor' => (int) $l['debit_minor'],
                'credit_minor' => (int) $l['credit_minor'],
                'contact_id' => AccountingDb::int($l['contact_id']),
                'ou_id' => AccountingDb::int($l['ou_id']),
                'description' => (string) $l['description'],
            ], $lines);
        }

        return $out;
    }
}
