<?php

declare(strict_types=1);

namespace Whity\Core\Accounting;

use Whity\Core\Db\DbBool;
use Whity\Core\Hooks\HookManager;
use Whity\Core\i18n\LocalizedText;
use Whity\Sdk\Sql\SequenceAllocator;
use Whity\Sdk\Hooks\Events;
use Whity\Sdk\Hooks\HookVetoException;

/**
 * The double-entry ledger and the monthly balances it adds up to (WHIT-1318,
 * design section 32 §3.3–§3.5, register AC.3–AC.5).
 *
 * POSTING is one transaction:
 *  1. the `accounting.entry.posting` filter hook, which may veto;
 *  2. the books row locked (`FOR UPDATE` on PostgreSQL; SQLite writes one at a
 *     time anyway), which puts every posting in the workspace in one line, so
 *     the hash chain never forks and numbers follow each other;
 *  3. the base currency fixed if this is the first posting (AC.6);
 *  4. a number from the journal's counter for the year (`GEN-2026-0001`);
 *  5. the entry and its lines inserted, with the entry's hash over the previous
 *     entry's hash and its own content (AC.4);
 *  6. each balance touched upserted in a fixed order (account, period, branch);
 *  7. the chain head moved; `accounting.entry.posted` fired.
 * `accounting.entry.posted.async` fires after commit.
 *
 * ENTRIES ARE NEVER CHANGED. Triggers refuse UPDATE and DELETE on
 * `acc_entries` and `acc_lines` (migration 345). {@see self::reverse()} posts
 * the mirror, linked with `reverses_entry_id`; an entry is reversed once.
 *
 * BALANCED BY CONSTRUCTION. An entry has 2 to 500 lines, each a debit or a
 * credit above zero, debits equal to credits, on accounts that are not
 * headings and not archived. On PostgreSQL a deferred trigger checks the sum
 * again at commit.
 *
 * MANUAL ENTRIES (`source_type` `manual`) can't touch accounts that refuse
 * manual lines (receivables, payables, stock and tax by default), so the
 * documents that own them stay true; the opening journal can, for opening
 * balances. Plugins post under `<plugin>.<kind>` and may use any account.
 *
 * TENANT-OWNED: every statement binds `tenant_id`.
 */
final class Ledger
{
    public const MAX_LINES = 500;
    public const MAX_PAGE = 200;

    /** Core's own source types. A plugin's are `<plugin>.<kind>`. */
    public const CORE_SOURCES = ['manual', 'opening', 'reversal', 'invoice', 'credit_note'];

    public function __construct(
        private readonly AccountingDb $db,
        private readonly AccountingWorkspace $workspace,
        private readonly Periods $periods,
        private readonly SequenceAllocator $sequences,
        private readonly ?HookManager $hooks = null,
    ) {
    }

    // ── posting ──────────────────────────────────────────────────────────

    /**
     * Posts one entry.
     *
     * @param array{journal: string|int, date: string, memo?: string, lines: list<mixed>, source_type: string, source_id?: ?int,
     *     idempotency_key?: ?string, agent_id?: ?int, on_behalf_of?: ?int} $request each line: `account_id` (or `account_code`),
     *     `debit_minor` or `credit_minor` (a whole number above 0), optional `contact_id`, `ou_id`, `description`,
     *     `source_line_type`, `source_line_id`
     * @return array{entry: array<string, mixed>, repeated: bool}
     */
    public function post(int $tenantId, ?int $actor, array $request): array
    {
        $this->workspace->ensure($tenantId);
        $key = self::idempotencyKey($request['idempotency_key'] ?? null);
        if ($key !== null && ($done = $this->entryIdByKey($tenantId, $key)) !== null) {
            return ['entry' => $this->entry($tenantId, $done), 'repeated' => true];
        }

        $journal = $this->journal($tenantId, $request['journal']);
        $sourceType = self::sourceType($request['source_type']);
        $date = Periods::date($request['date']);
        $memo = mb_substr(trim((string) ($request['memo'] ?? '')), 0, 500);
        $raw = $request['lines'];
        if (count($raw) < 2 || count($raw) > self::MAX_LINES) {
            throw AccountingRejectedException::field('lines', 'An entry has 2 to ' . self::MAX_LINES . ' lines');
        }
        $manual = $sourceType === 'manual' && $journal['kind'] !== 'opening';
        $lines = [];
        foreach ($raw as $i => $line) {
            $lines[] = $this->line($tenantId, $line, 'lines[' . $i . ']', $manual);
        }
        $debits = array_sum(array_column($lines, 'debit_minor'));
        $credits = array_sum(array_column($lines, 'credit_minor'));
        if ($debits !== $credits) {
            throw AccountingRejectedException::coded(
                'unbalanced',
                'Debits and credits must be equal; they differ by ' . abs($debits - $credits) . ' minor units',
                422,
                'lines'
            );
        }

        return $this->write($tenantId, $actor, $journal, $date, $memo, $lines, [
            'source_type' => $sourceType,
            'source_id' => AccountingDb::int($request['source_id'] ?? null),
            'idempotency_key' => $key,
            'agent_id' => AccountingDb::int($request['agent_id'] ?? null),
            'on_behalf_of' => AccountingDb::int($request['on_behalf_of'] ?? null),
            'reverses_entry_id' => null,
        ]);
    }

    /**
     * Posts the mirror of an entry: every debit a credit and every credit a
     * debit, in the same journal. Dated `$date`, else the original's date when
     * that is still open, else today.
     *
     * @return array{entry: array<string, mixed>, repeated: bool}
     */
    public function reverse(int $tenantId, int $entryId, ?int $actor, string $note = '', ?string $date = null, string $sourceType = 'reversal'): array
    {
        $original = $this->entry($tenantId, $entryId);
        if ($original['reverses_entry_id'] !== null) {
            throw new AccountingRejectedException('This entry is itself a reversal; post the entry again instead', 409);
        }
        $already = $this->db->one(
            'SELECT id FROM acc_entries WHERE tenant_id = :tenant_id AND reverses_entry_id = :id',
            [':tenant_id' => $tenantId, ':id' => $entryId]
        );
        if ($already !== null) {
            throw new AccountingRejectedException('This entry was already reversed', 409, ['entry_id' => (int) $already['id']]);
        }
        if ($date === null) {
            $date = $this->periods->isOpen($tenantId, (string) $original['date']) ? (string) $original['date'] : gmdate('Y-m-d');
        }
        $date = Periods::date($date);
        $lines = [];
        foreach ($original['lines'] as $line) {
            $lines[] = [
                'account_id' => (int) $line['account_id'],
                'debit_minor' => (int) $line['credit_minor'],
                'credit_minor' => (int) $line['debit_minor'],
                'contact_id' => AccountingDb::int($line['contact_id']),
                'ou_id' => AccountingDb::int($line['ou_id']),
                'description' => (string) $line['description'],
                'source_line_type' => is_string($line['source_line_type']) ? $line['source_line_type'] : null,
                'source_line_id' => AccountingDb::int($line['source_line_id']),
                'tax_code_id' => AccountingDb::int($line['tax_code_id']),
                'tax_base_minor' => ($base = AccountingDb::int($line['tax_base_minor'])) === null ? null : -$base,
            ];
        }
        $memo = trim($note) !== '' ? mb_substr(trim($note), 0, 500) : 'Reverses ' . $original['number'];
        $journal = $this->journal($tenantId, (int) $original['journal_id']);

        try {
            return $this->write($tenantId, $actor, $journal, $date, $memo, $lines, [
                'source_type' => $sourceType,
                'source_id' => $original['source_id'],
                'idempotency_key' => null,
                'agent_id' => null,
                'on_behalf_of' => null,
                'reverses_entry_id' => $entryId,
            ]);
        } catch (\PDOException $e) {
            // Two people reversing at once: the second finds the first's.
            if (AccountingDb::isUniqueViolation($e)) {
                throw new AccountingRejectedException('This entry was already reversed', 409);
            }
            throw $e;
        }
    }

    /**
     * @param array{id: int, code: string, kind: string} $journal
     * @param list<array{account_id: int, debit_minor: int, credit_minor: int, contact_id: ?int, ou_id: ?int, description: string, source_line_type: ?string, source_line_id: ?int, tax_code_id?: ?int, tax_base_minor?: ?int}> $lines
     * @param array{source_type: string, source_id: ?int, idempotency_key: ?string, agent_id: ?int, on_behalf_of: ?int, reverses_entry_id: ?int} $meta
     * @return array{entry: array<string, mixed>, repeated: bool}
     */
    private function write(int $tenantId, ?int $actor, array $journal, string $date, string $memo, array $lines, array $meta): array
    {
        // Checked here for a quick answer, and again under the books lock in case the month closed meanwhile.
        $this->periods->postable($tenantId, $date);
        $total = array_sum(array_column($lines, 'debit_minor'));
        if ($total <= 0) {
            throw AccountingRejectedException::field('lines', 'An entry moves more than 0');
        }

        try {
            $entryId = $this->db->transaction(function () use ($tenantId, $actor, $journal, $date, $memo, $lines, $meta, $total): int {
                $this->hooks?->dispatch(Events::ACCOUNTING_ENTRY_POSTING, [
                    'tenant_id' => $tenantId, 'journal' => $journal['code'], 'date' => $date, 'memo' => $memo,
                    'source_type' => $meta['source_type'], 'source_id' => $meta['source_id'], 'acting_profile_id' => $actor,
                    'reverses_entry_id' => $meta['reverses_entry_id'],
                    'lines' => array_map(static fn (array $l): array => [
                        'account_id' => $l['account_id'], 'debit_minor' => $l['debit_minor'], 'credit_minor' => $l['credit_minor'],
                        'contact_id' => $l['contact_id'], 'ou_id' => $l['ou_id'],
                    ], $lines),
                ]);

                $books = $this->lockBooks($tenantId);
                $period = $this->periods->postable($tenantId, $date);
                $currency = $books['base_currency'];
                if ($books['currency_fixed_at'] === null) {
                    $this->db->run(
                        'UPDATE acc_books SET currency_fixed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE tenant_id = :tenant_id',
                        [':tenant_id' => $tenantId]
                    );
                }
                $year = substr($date, 0, 4);
                $sequence = $this->sequences->next($tenantId, 'acc_entries:' . strtolower($journal['code']) . ':' . $year);
                $number = $journal['code'] . '-' . $year . '-' . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
                $prev = $books['chain_hash'];
                $hash = self::hash($prev, [
                    'tenant_id' => $tenantId, 'journal_id' => $journal['id'], 'number' => $number, 'date' => $date, 'memo' => $memo,
                    'source_type' => $meta['source_type'], 'source_id' => $meta['source_id'], 'reverses_entry_id' => $meta['reverses_entry_id'],
                    'currency' => $currency, 'total_minor' => $total, 'posted_by' => $actor, 'agent_id' => $meta['agent_id'],
                    'on_behalf_of' => $meta['on_behalf_of'], 'lines' => self::canonicalLines($lines),
                ]);

                $entryId = $this->db->insert(
                    'INSERT INTO acc_entries (tenant_id, journal_id, period_id, number, entry_date, memo, source_type, source_id, reverses_entry_id,
                            currency, total_minor, idempotency_key, posted_by_profile_id, agent_id, on_behalf_of_profile_id, prev_hash, hash, posted_at)
                     VALUES (:tenant_id, :journal_id, :period_id, :number, :entry_date, :memo, :source_type, :source_id, :reverses,
                            :currency, :total, :idempotency_key, :actor, :agent_id, :on_behalf_of, :prev_hash, :hash, CURRENT_TIMESTAMP)',
                    [
                        ':tenant_id' => $tenantId, ':journal_id' => $journal['id'], ':period_id' => $period['id'], ':number' => $number,
                        ':entry_date' => $date, ':memo' => $memo, ':source_type' => $meta['source_type'], ':source_id' => $meta['source_id'],
                        ':reverses' => $meta['reverses_entry_id'], ':currency' => $currency, ':total' => $total,
                        ':idempotency_key' => $meta['idempotency_key'], ':actor' => $actor, ':agent_id' => $meta['agent_id'],
                        ':on_behalf_of' => $meta['on_behalf_of'], ':prev_hash' => $prev, ':hash' => $hash,
                    ]
                );

                $sums = [];
                foreach ($lines as $i => $line) {
                    $amount = $line['debit_minor'] - $line['credit_minor'];
                    $lineId = $this->db->insert(
                        'INSERT INTO acc_lines (tenant_id, entry_id, line_no, account_id, period_id, entry_date, debit_minor, credit_minor,
                                currency, amount_minor, rate_num, rate_den, contact_id, ou_id, description, source_line_type, source_line_id,
                                tax_code_id, tax_base_minor)
                         VALUES (:tenant_id, :entry_id, :line_no, :account_id, :period_id, :entry_date, :debit, :credit,
                                :currency, :amount, 1, 1, :contact_id, :ou_id, :description, :source_line_type, :source_line_id,
                                :tax_code_id, :tax_base)',
                        [
                            ':tenant_id' => $tenantId, ':entry_id' => $entryId, ':line_no' => $i + 1, ':account_id' => $line['account_id'],
                            ':period_id' => $period['id'], ':entry_date' => $date, ':debit' => $line['debit_minor'], ':credit' => $line['credit_minor'],
                            ':currency' => $currency, ':amount' => $amount, ':contact_id' => $line['contact_id'], ':ou_id' => $line['ou_id'],
                            ':description' => $line['description'], ':source_line_type' => $line['source_line_type'],
                            ':source_line_id' => $line['source_line_id'], ':tax_code_id' => $line['tax_code_id'] ?? null,
                            ':tax_base' => $line['tax_base_minor'] ?? null,
                        ]
                    );
                    $k = sprintf('%010d:%010d', $line['account_id'], $line['ou_id'] ?? 0);
                    $sums[$k] = [
                        'account_id' => $line['account_id'], 'ou_id' => $line['ou_id'] ?? 0,
                        'debit' => ($sums[$k]['debit'] ?? 0) + $line['debit_minor'],
                        'credit' => ($sums[$k]['credit'] ?? 0) + $line['credit_minor'],
                        'last' => $lineId,
                    ];
                }
                ksort($sums);
                foreach ($sums as $s) {
                    $this->db->run(
                        'INSERT INTO acc_balances (tenant_id, account_id, period_id, ou_id, debit_minor, credit_minor, last_line_id, updated_at)
                         VALUES (:tenant_id, :account_id, :period_id, :ou_id, :debit, :credit, :last, CURRENT_TIMESTAMP)
                         ON CONFLICT (tenant_id, account_id, period_id, ou_id)
                         DO UPDATE SET debit_minor = acc_balances.debit_minor + excluded.debit_minor,
                                       credit_minor = acc_balances.credit_minor + excluded.credit_minor,
                                       last_line_id = excluded.last_line_id, updated_at = CURRENT_TIMESTAMP',
                        [':tenant_id' => $tenantId, ':account_id' => $s['account_id'], ':period_id' => $period['id'], ':ou_id' => $s['ou_id'],
                            ':debit' => $s['debit'], ':credit' => $s['credit'], ':last' => $s['last']]
                    );
                }
                $this->db->run(
                    'UPDATE acc_books SET chain_entry_id = :entry_id, chain_hash = :hash, updated_at = CURRENT_TIMESTAMP WHERE tenant_id = :tenant_id',
                    [':tenant_id' => $tenantId, ':entry_id' => $entryId, ':hash' => $hash]
                );

                $this->hooks?->dispatch(Events::ACCOUNTING_ENTRY_POSTED, $this->postedPayload($tenantId, $actor, $entryId, $number, $journal['code'], $date, $meta, $total, $currency));
                if ($meta['reverses_entry_id'] !== null) {
                    $this->hooks?->dispatch(Events::ACCOUNTING_ENTRY_REVERSED, [
                        'tenant_id' => $tenantId, 'entry_id' => $meta['reverses_entry_id'], 'reversal_entry_id' => $entryId, 'acting_profile_id' => $actor,
                    ]);
                }

                return $entryId;
            });
        } catch (HookVetoException $e) {
            throw AccountingRejectedException::coded('vetoed', $e->reason(), 409);
        } catch (\PDOException $e) {
            $key = $meta['idempotency_key'];
            if ($key !== null && AccountingDb::isUniqueViolation($e) && ($done = $this->entryIdByKey($tenantId, $key)) !== null) {
                return ['entry' => $this->entry($tenantId, $done), 'repeated' => true];
            }
            throw $e;
        }

        $entry = $this->entry($tenantId, $entryId);
        try {
            $this->hooks?->dispatchAsync(Events::ACCOUNTING_ENTRY_POSTED_ASYNC, $this->postedPayload(
                $tenantId, $actor, $entryId, (string) $entry['number'], $journal['code'], $date, $meta, $total, (string) $entry['currency']
            ));
            if ($meta['reverses_entry_id'] !== null) {
                $this->hooks?->dispatchAsync(Events::ACCOUNTING_ENTRY_REVERSED_ASYNC, [
                    'tenant_id' => $tenantId, 'entry_id' => $meta['reverses_entry_id'], 'reversal_entry_id' => $entryId, 'acting_profile_id' => $actor,
                ]);
            }
        } catch (\Throwable $e) {
            error_log('[accounting] entry events could not be queued: ' . $e->getMessage());
        }

        return ['entry' => $entry, 'repeated' => false];
    }

    /**
     * One raw line, checked.
     *
     * @return array{account_id: int, debit_minor: int, credit_minor: int, contact_id: ?int, ou_id: ?int, description: string, source_line_type: ?string, source_line_id: ?int}
     */
    private function line(int $tenantId, mixed $raw, string $field, bool $manual): array
    {
        if (!is_array($raw)) {
            throw AccountingRejectedException::field($field, $field . ' must be an object');
        }
        $account = $this->account($tenantId, $raw['account_id'] ?? null, $raw['account_code'] ?? null, $field);
        if ($manual && !$account['allows_manual']) {
            throw AccountingRejectedException::field(
                $field,
                $field . ': ' . $account['code'] . ' ' . $account['name'] . ' is kept by its documents (invoices, bills, stock or tax) and takes no manual lines'
            );
        }
        $debit = $raw['debit_minor'] ?? 0;
        $credit = $raw['credit_minor'] ?? 0;
        if (!is_int($debit) || !is_int($credit) || $debit < 0 || $credit < 0) {
            throw AccountingRejectedException::field($field, $field . ': debit_minor and credit_minor are whole numbers of minor units, 0 or more');
        }
        if (($debit > 0) === ($credit > 0)) {
            throw AccountingRejectedException::field($field, $field . ' is a debit or a credit above 0, not both');
        }
        $contactId = $raw['contact_id'] ?? null;
        if ($contactId !== null) {
            if (!is_int($contactId) || $this->db->one(
                'SELECT 1 AS hit FROM contacts WHERE tenant_id = :tenant_id AND id = :id',
                [':tenant_id' => $tenantId, ':id' => $contactId]
            ) === null) {
                throw AccountingRejectedException::field($field, $field . '.contact_id is not a contact here');
            }
        }
        $ouId = $raw['ou_id'] ?? null;
        if ($ouId !== null) {
            if (!is_int($ouId) || $this->db->one(
                'SELECT 1 AS hit FROM organizational_units WHERE tenant_id = :tenant_id AND id = :id',
                [':tenant_id' => $tenantId, ':id' => $ouId]
            ) === null) {
                throw AccountingRejectedException::field($field, $field . '.ou_id is not a unit here');
            }
        }
        $sourceLineType = $raw['source_line_type'] ?? null;
        $sourceLineId = $raw['source_line_id'] ?? null;
        $taxCodeId = $raw['tax_code_id'] ?? null;
        $taxBase = $raw['tax_base_minor'] ?? null;
        if ($taxCodeId !== null) {
            if (!is_int($taxCodeId) || $this->db->one(
                'SELECT 1 AS hit FROM acc_tax_codes WHERE tenant_id = :tenant_id AND id = :id',
                [':tenant_id' => $tenantId, ':id' => $taxCodeId]
            ) === null) {
                throw AccountingRejectedException::field($field, $field . '.tax_code_id is not a tax code here');
            }
        }
        if ($taxBase !== null && ($taxCodeId === null || !is_int($taxBase))) {
            throw AccountingRejectedException::field($field, $field . '.tax_base_minor is a whole number of minor units, with a tax_code_id');
        }

        return [
            'account_id' => $account['id'],
            'debit_minor' => $debit,
            'credit_minor' => $credit,
            'contact_id' => $contactId,
            'ou_id' => $ouId,
            'description' => is_string($raw['description'] ?? null) ? mb_substr(trim($raw['description']), 0, 500) : '',
            'source_line_type' => is_string($sourceLineType) && $sourceLineType !== '' ? mb_substr($sourceLineType, 0, 60) : null,
            'source_line_id' => is_int($sourceLineId) ? $sourceLineId : null,
            'tax_code_id' => $taxCodeId,
            'tax_base_minor' => $taxBase,
        ];
    }

    /** @return array{id: int, code: string, name: string, allows_manual: bool} */
    private function account(int $tenantId, mixed $id, mixed $code, string $field): array
    {
        $row = null;
        if (is_int($id)) {
            $row = $this->db->one(
                'SELECT id, code, name, is_group, allows_manual, archived_at FROM acc_accounts WHERE tenant_id = :tenant_id AND id = :id',
                [':tenant_id' => $tenantId, ':id' => $id]
            );
        } elseif (is_string($code) && trim($code) !== '') {
            $row = $this->db->one(
                'SELECT id, code, name, is_group, allows_manual, archived_at FROM acc_accounts WHERE tenant_id = :tenant_id AND code = :code',
                [':tenant_id' => $tenantId, ':code' => trim($code)]
            );
        }
        if ($row === null) {
            throw AccountingRejectedException::field($field, $field . ': that account is not in this chart');
        }
        if (DbBool::of($row['is_group'])) {
            throw AccountingRejectedException::field($field, $field . ': ' . $row['code'] . ' ' . $row['name'] . ' is a heading; choose an account under it');
        }
        if ($row['archived_at'] !== null) {
            throw AccountingRejectedException::field($field, $field . ': ' . $row['code'] . ' ' . $row['name'] . ' is archived');
        }

        return ['id' => (int) $row['id'], 'code' => (string) $row['code'], 'name' => (string) $row['name'], 'allows_manual' => DbBool::of($row['allows_manual'])];
    }

    /** @return array{id: int, code: string, kind: string} */
    private function journal(int $tenantId, mixed $journal): array
    {
        $row = null;
        if (is_int($journal)) {
            $row = $this->db->one(
                'SELECT id, code, kind, archived_at FROM acc_journals WHERE tenant_id = :tenant_id AND id = :id',
                [':tenant_id' => $tenantId, ':id' => $journal]
            );
        } elseif (is_string($journal) && $journal !== '') {
            $row = $this->db->one(
                'SELECT id, code, kind, archived_at FROM acc_journals WHERE tenant_id = :tenant_id AND code = :code',
                [':tenant_id' => $tenantId, ':code' => strtoupper(trim($journal))]
            );
        }
        if ($row === null || $row['archived_at'] !== null) {
            throw AccountingRejectedException::field('journal', 'That journal is not in use here');
        }

        return ['id' => (int) $row['id'], 'code' => (string) $row['code'], 'kind' => (string) $row['kind']];
    }

    /** @return array{base_currency: string, currency_fixed_at: mixed, chain_hash: string} */
    private function lockBooks(int $tenantId): array
    {
        $row = $this->db->one(
            'SELECT base_currency, currency_fixed_at, chain_hash FROM acc_books WHERE tenant_id = :tenant_id' . ($this->db->isPostgres() ? ' FOR UPDATE' : ''),
            [':tenant_id' => $tenantId]
        ) ?? throw new \RuntimeException('Books are missing for workspace ' . $tenantId);

        return ['base_currency' => (string) $row['base_currency'], 'currency_fixed_at' => $row['currency_fixed_at'], 'chain_hash' => (string) $row['chain_hash']];
    }

    // ── reading ──────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function entry(int $tenantId, int $entryId): array
    {
        $row = $this->db->one(
            'SELECT e.id, e.journal_id, j.code AS journal_code, e.period_id, e.number, e.entry_date, e.memo, e.source_type, e.source_id,
                    e.reverses_entry_id, e.currency, e.total_minor, e.posted_by_profile_id, e.agent_id, e.on_behalf_of_profile_id,
                    e.prev_hash, e.hash, e.posted_at,
                    (SELECT r.id FROM acc_entries r WHERE r.tenant_id = e.tenant_id AND r.reverses_entry_id = e.id) AS reversed_by_entry_id
               FROM acc_entries e JOIN acc_journals j ON j.id = e.journal_id AND j.tenant_id = e.tenant_id
              WHERE e.tenant_id = :tenant_id AND e.id = :id',
            [':tenant_id' => $tenantId, ':id' => $entryId]
        );
        if ($row === null) {
            throw AccountingRejectedException::notFound('Entry');
        }
        $lines = $this->db->all(
            'SELECT l.id, l.line_no, l.account_id, a.code AS account_code, a.name AS account_name, a.names AS account_names,
                    l.debit_minor, l.credit_minor, l.contact_id, l.ou_id, l.description, l.source_line_type, l.source_line_id,
                    l.tax_code_id, l.tax_base_minor
               FROM acc_lines l JOIN acc_accounts a ON a.id = l.account_id AND a.tenant_id = l.tenant_id
              WHERE l.tenant_id = :tenant_id AND l.entry_id = :entry_id
              ORDER BY l.line_no',
            [':tenant_id' => $tenantId, ':entry_id' => $entryId]
        );

        return self::shapeEntry($row) + ['lines' => array_map(static fn (array $l): array => [
            'id' => (int) $l['id'],
            'line_no' => (int) $l['line_no'],
            'account_id' => (int) $l['account_id'],
            'account_code' => (string) $l['account_code'],
            'account_name' => (string) $l['account_name'],
            'account_names' => LocalizedText::decode($l['account_names']),
            'debit_minor' => (int) $l['debit_minor'],
            'credit_minor' => (int) $l['credit_minor'],
            'contact_id' => AccountingDb::int($l['contact_id']),
            'ou_id' => AccountingDb::int($l['ou_id']),
            'description' => (string) $l['description'],
            'source_line_type' => is_string($l['source_line_type']) ? $l['source_line_type'] : null,
            'source_line_id' => AccountingDb::int($l['source_line_id']),
            'tax_code_id' => AccountingDb::int($l['tax_code_id']),
            'tax_base_minor' => AccountingDb::int($l['tax_base_minor']),
        ], $lines)];
    }

    /**
     * Entries, newest first, without their lines. `before_id` pages back.
     *
     * @param array{journal_id?: ?int, account_id?: ?int, source_type?: ?string, source_id?: ?int, from?: ?string, to?: ?string, before_id?: ?int} $filter
     * @return list<array<string, mixed>>
     */
    public function entries(int $tenantId, array $filter, int $limit = 50): array
    {
        $this->workspace->ensure($tenantId);
        $limit = max(1, min(self::MAX_PAGE, $limit));
        $where = ['e.tenant_id = :tenant_id'];
        $params = [':tenant_id' => $tenantId];
        foreach (['journal_id' => 'e.journal_id', 'source_id' => 'e.source_id'] as $key => $column) {
            if (isset($filter[$key])) {
                $where[] = $column . ' = :' . $key;
                $params[':' . $key] = (int) $filter[$key];
            }
        }
        if (isset($filter['source_type']) && $filter['source_type'] !== '') {
            $where[] = 'e.source_type = :source_type';
            $params[':source_type'] = $filter['source_type'];
        }
        if (isset($filter['account_id'])) {
            $where[] = 'EXISTS (SELECT 1 FROM acc_lines l WHERE l.tenant_id = e.tenant_id AND l.entry_id = e.id AND l.account_id = :account_id)';
            $params[':account_id'] = (int) $filter['account_id'];
        }
        if (isset($filter['from'])) {
            $where[] = 'e.entry_date >= :from';
            $params[':from'] = Periods::date($filter['from'], 'from');
        }
        if (isset($filter['to'])) {
            $where[] = 'e.entry_date <= :to';
            $params[':to'] = Periods::date($filter['to'], 'to');
        }
        if (isset($filter['before_id'])) {
            $where[] = 'e.id < :before_id';
            $params[':before_id'] = (int) $filter['before_id'];
        }

        return array_map(self::shapeEntry(...), $this->db->all(
            'SELECT e.id, e.journal_id, j.code AS journal_code, e.period_id, e.number, e.entry_date, e.memo, e.source_type, e.source_id,
                    e.reverses_entry_id, e.currency, e.total_minor, e.posted_by_profile_id, e.agent_id, e.on_behalf_of_profile_id,
                    e.prev_hash, e.hash, e.posted_at,
                    (SELECT r.id FROM acc_entries r WHERE r.tenant_id = e.tenant_id AND r.reverses_entry_id = e.id) AS reversed_by_entry_id
               FROM acc_entries e JOIN acc_journals j ON j.id = e.journal_id AND j.tenant_id = e.tenant_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY e.id DESC
              LIMIT ' . $limit,
            $params
        ));
    }

    /**
     * An account's balance at the end of `$asOf` (debits minus credits), from
     * the monthly balances and the lines of `$asOf`'s own month.
     */
    public function balance(int $tenantId, int $accountId, string $asOf, ?int $ouId = null): int
    {
        $asOf = Periods::date($asOf, 'as_of');
        $period = $this->periods->periodFor($tenantId, $asOf, 'as_of');
        $ou = $ouId === null ? '' : ' AND ou_id = :ou_id';
        $params = [':tenant_id' => $tenantId, ':account_id' => $accountId, ':starts_on' => $period['starts_on']];
        if ($ouId !== null) {
            $params[':ou_id'] = $ouId;
        }
        $before = $this->db->one(
            'SELECT COALESCE(SUM(b.debit_minor), 0) - COALESCE(SUM(b.credit_minor), 0) AS net
               FROM acc_balances b JOIN acc_periods p ON p.id = b.period_id AND p.tenant_id = b.tenant_id
              WHERE b.tenant_id = :tenant_id AND b.account_id = :account_id AND p.starts_on < :starts_on' . str_replace('ou_id', 'b.ou_id', $ou),
            $params
        );
        $params[':as_of'] = $asOf;
        $inMonth = $this->db->one(
            'SELECT COALESCE(SUM(debit_minor), 0) - COALESCE(SUM(credit_minor), 0) AS net FROM acc_lines
              WHERE tenant_id = :tenant_id AND account_id = :account_id AND entry_date >= :starts_on AND entry_date <= :as_of' . $ou,
            $params
        );

        return (int) ($before['net'] ?? 0) + (int) ($inMonth['net'] ?? 0);
    }

    // ── keeping it true ──────────────────────────────────────────────────

    /**
     * Recomputes the monthly balances from the lines and says what differed.
     *
     * @return array{rows: int, differences: list<array{account_id: int, period_id: int, ou_id: int, was: array{int, int}, now: array{int, int}}>}
     */
    public function rebuildBalances(int $tenantId): array
    {
        return $this->db->transaction(function () use ($tenantId): array {
            $was = [];
            foreach ($this->db->all('SELECT account_id, period_id, ou_id, debit_minor, credit_minor FROM acc_balances WHERE tenant_id = :tenant_id', [':tenant_id' => $tenantId]) as $r) {
                $was[$r['account_id'] . ':' . $r['period_id'] . ':' . $r['ou_id']] = [(int) $r['debit_minor'], (int) $r['credit_minor']];
            }
            $now = [];
            $rows = $this->db->all(
                'SELECT account_id, period_id, COALESCE(ou_id, 0) AS ou_id, SUM(debit_minor) AS debit, SUM(credit_minor) AS credit, MAX(id) AS last_line_id
                   FROM acc_lines WHERE tenant_id = :tenant_id
                  GROUP BY account_id, period_id, COALESCE(ou_id, 0)',
                [':tenant_id' => $tenantId]
            );
            $this->db->run('DELETE FROM acc_balances WHERE tenant_id = :tenant_id', [':tenant_id' => $tenantId]);
            foreach ($rows as $r) {
                $now[$r['account_id'] . ':' . $r['period_id'] . ':' . $r['ou_id']] = [(int) $r['debit'], (int) $r['credit']];
                $this->db->run(
                    'INSERT INTO acc_balances (tenant_id, account_id, period_id, ou_id, debit_minor, credit_minor, last_line_id)
                     VALUES (:tenant_id, :account_id, :period_id, :ou_id, :debit, :credit, :last)',
                    [':tenant_id' => $tenantId, ':account_id' => (int) $r['account_id'], ':period_id' => (int) $r['period_id'],
                        ':ou_id' => (int) $r['ou_id'], ':debit' => (int) $r['debit'], ':credit' => (int) $r['credit'], ':last' => (int) $r['last_line_id']]
                );
            }
            $differences = [];
            foreach (array_unique(array_merge(array_keys($was), array_keys($now))) as $key) {
                $a = $was[$key] ?? [0, 0];
                $b = $now[$key] ?? [0, 0];
                if ($a !== $b) {
                    [$account, $period, $ou] = array_map('intval', explode(':', (string) $key));
                    $differences[] = ['account_id' => $account, 'period_id' => $period, 'ou_id' => $ou, 'was' => $a, 'now' => $b];
                }
            }

            return ['rows' => count($rows), 'differences' => $differences];
        });
    }

    /**
     * Walks the hash chain from the first entry and says where it breaks.
     *
     * @return array{entries: int, intact: bool, broken_at: ?int, reason: ?string}
     */
    public function verifyChain(int $tenantId): array
    {
        $prev = '';
        $count = 0;
        $after = 0;
        do {
            $batch = $this->db->all(
                'SELECT e.id, e.journal_id, e.number, e.entry_date, e.memo, e.source_type, e.source_id, e.reverses_entry_id, e.currency,
                        e.total_minor, e.posted_by_profile_id, e.agent_id, e.on_behalf_of_profile_id, e.prev_hash, e.hash
                   FROM acc_entries e WHERE e.tenant_id = :tenant_id AND e.id > :after ORDER BY e.id LIMIT 500',
                [':tenant_id' => $tenantId, ':after' => $after]
            );
            foreach ($batch as $e) {
                $count++;
                $after = (int) $e['id'];
                if ((string) $e['prev_hash'] !== $prev) {
                    return ['entries' => $count, 'intact' => false, 'broken_at' => $after, 'reason' => 'prev_hash does not match the entry before'];
                }
                $lines = $this->db->all(
                    'SELECT account_id, debit_minor, credit_minor, contact_id, ou_id, description, source_line_type, source_line_id,
                            tax_code_id, tax_base_minor
                       FROM acc_lines WHERE tenant_id = :tenant_id AND entry_id = :entry_id ORDER BY line_no',
                    [':tenant_id' => $tenantId, ':entry_id' => $after]
                );
                $expected = self::hash($prev, [
                    'tenant_id' => $tenantId, 'journal_id' => (int) $e['journal_id'], 'number' => (string) $e['number'],
                    'date' => substr((string) $e['entry_date'], 0, 10), 'memo' => (string) $e['memo'], 'source_type' => (string) $e['source_type'],
                    'source_id' => AccountingDb::int($e['source_id']), 'reverses_entry_id' => AccountingDb::int($e['reverses_entry_id']),
                    'currency' => (string) $e['currency'], 'total_minor' => (int) $e['total_minor'],
                    'posted_by' => AccountingDb::int($e['posted_by_profile_id']), 'agent_id' => AccountingDb::int($e['agent_id']),
                    'on_behalf_of' => AccountingDb::int($e['on_behalf_of_profile_id']),
                    'lines' => self::canonicalLines(array_map(static fn (array $l): array => [
                        'account_id' => (int) $l['account_id'], 'debit_minor' => (int) $l['debit_minor'], 'credit_minor' => (int) $l['credit_minor'],
                        'contact_id' => AccountingDb::int($l['contact_id']), 'ou_id' => AccountingDb::int($l['ou_id']),
                        'description' => (string) $l['description'],
                        'source_line_type' => is_string($l['source_line_type']) ? $l['source_line_type'] : null,
                        'source_line_id' => AccountingDb::int($l['source_line_id']),
                        'tax_code_id' => AccountingDb::int($l['tax_code_id']), 'tax_base_minor' => AccountingDb::int($l['tax_base_minor']),
                    ], $lines)),
                ]);
                if (!hash_equals($expected, (string) $e['hash'])) {
                    return ['entries' => $count, 'intact' => false, 'broken_at' => $after, 'reason' => 'the entry or its lines changed after posting'];
                }
                $prev = (string) $e['hash'];
            }
        } while (count($batch) === 500);

        return ['entries' => $count, 'intact' => true, 'broken_at' => null, 'reason' => null];
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /**
     * The chain's hash: SHA-256 over the previous hash and the entry's content
     * as canonical JSON. Anything that changes a posted figure, account,
     * contact, date or who posted it changes the hash.
     *
     * @param array<string, mixed> $content
     */
    public static function hash(string $prev, array $content): string
    {
        return hash('sha256', $prev . "\n" . json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<array{account_id: int, debit_minor: int, credit_minor: int, contact_id: ?int, ou_id: ?int, description: string, source_line_type: ?string, source_line_id: ?int, tax_code_id?: ?int, tax_base_minor?: ?int}> $lines
     * @return list<array<int, int|string|null>>
     */
    private static function canonicalLines(array $lines): array
    {
        // The tax fields join the hash only when set, so entries posted before
        // lines carried tax (A1) keep the hash they were chained with.
        return array_map(static fn (array $l): array => [
            $l['account_id'], $l['debit_minor'], $l['credit_minor'], $l['contact_id'], $l['ou_id'], $l['description'],
            $l['source_line_type'], $l['source_line_id'],
        ] + (($l['tax_code_id'] ?? null) !== null ? [8 => $l['tax_code_id'], 9 => $l['tax_base_minor'] ?? null] : []), $lines);
    }

    private function entryIdByKey(int $tenantId, string $key): ?int
    {
        return AccountingDb::int($this->db->one(
            'SELECT id FROM acc_entries WHERE tenant_id = :tenant_id AND idempotency_key = :key',
            [':tenant_id' => $tenantId, ':key' => $key]
        )['id'] ?? null);
    }

    private static function idempotencyKey(mixed $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }
        if (!is_string($key) || mb_strlen($key) > 160) {
            throw AccountingRejectedException::field('idempotency_key', 'idempotency_key is text of up to 160 characters');
        }

        return $key;
    }

    private static function sourceType(mixed $type): string
    {
        if (!is_string($type) || preg_match('/^[a-z][a-z0-9_-]{0,40}(\.[a-z][a-z0-9_]{0,40})?$/', $type) !== 1) {
            throw AccountingRejectedException::field('source_type', 'source_type is lower-case letters, digits and _, such as "manual"');
        }

        return $type;
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function postedPayload(int $tenantId, ?int $actor, int $entryId, string $number, string $journal, string $date, array $meta, int $total, string $currency): array
    {
        return [
            'tenant_id' => $tenantId, 'entry_id' => $entryId, 'number' => $number, 'journal' => $journal, 'date' => $date,
            'source_type' => $meta['source_type'], 'source_id' => $meta['source_id'], 'reverses_entry_id' => $meta['reverses_entry_id'],
            'total_minor' => $total, 'currency' => $currency, 'acting_profile_id' => $actor,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function shapeEntry(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'journal_id' => (int) $row['journal_id'],
            'journal_code' => (string) $row['journal_code'],
            'period_id' => (int) $row['period_id'],
            'number' => (string) $row['number'],
            'date' => substr((string) $row['entry_date'], 0, 10),
            'memo' => (string) $row['memo'],
            'source_type' => (string) $row['source_type'],
            'source_id' => AccountingDb::int($row['source_id']),
            'reverses_entry_id' => AccountingDb::int($row['reverses_entry_id']),
            'reversed_by_entry_id' => AccountingDb::int($row['reversed_by_entry_id']),
            'currency' => (string) $row['currency'],
            'total_minor' => (int) $row['total_minor'],
            'posted_by_profile_id' => AccountingDb::int($row['posted_by_profile_id']),
            'agent_id' => AccountingDb::int($row['agent_id']),
            'on_behalf_of_profile_id' => AccountingDb::int($row['on_behalf_of_profile_id']),
            'hash' => (string) $row['hash'],
            'prev_hash' => (string) $row['prev_hash'],
            'posted_at' => $row['posted_at'],
        ];
    }
}
