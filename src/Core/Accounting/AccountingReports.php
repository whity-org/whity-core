<?php

declare(strict_types=1);

namespace Whity\Core\Accounting;

use Whity\Core\Db\DbBool;
use Whity\Core\i18n\LocalizedText;

/**
 * Reports read from the ledger (WHIT-1318, design section 32 §8, register
 * AC.23). Phase A1 has the trial balance and the account statement; profit
 * and loss, the balance sheet and the aged reports arrive in A5.
 *
 * Sums over whole months read `acc_balances`; only a range's partial first
 * and last months read lines, through the `(account, date)` index.
 *
 * OPENING BALANCES: for what we own, what we owe and owners' money, everything
 * before the range. For income and costs, only what is before the range in
 * the same fiscal year, because a year starts them from zero (the year-end
 * closing entry of A5 makes that true in the books too).
 *
 * TENANT-OWNED: every statement binds `tenant_id`.
 */
final class AccountingReports
{
    public const MAX_STATEMENT_LINES = 500;

    public function __construct(
        private readonly AccountingDb $db,
        private readonly AccountingWorkspace $workspace,
        private readonly Periods $periods,
    ) {
    }

    /**
     * Every account with lines or a balance: opening, debits and credits in
     * the range, closing. Headings carry their children's sums.
     *
     * @return array{from: string, to: string, currency: string, ou_id: ?int, rows: list<array<string, mixed>>, totals: array{opening_debit_minor: int, opening_credit_minor: int, debit_minor: int, credit_minor: int, closing_debit_minor: int, closing_credit_minor: int}, balanced: bool}
     */
    public function trialBalance(int $tenantId, string $from, string $to, ?int $ouId = null): array
    {
        $from = Periods::date($from, 'from');
        $to = Periods::date($to, 'to');
        if ($from > $to) {
            throw AccountingRejectedException::field('from', 'from is on or before to');
        }
        $this->workspace->ensure($tenantId);
        $yearStart = $this->periods->yearStart($tenantId, $from);
        $before = $this->sums($tenantId, '0001-01-01', self::dayBefore($from), $ouId);
        $beforeInYear = $yearStart < $from ? $this->sums($tenantId, $yearStart, self::dayBefore($from), $ouId) : [];
        $during = $this->sums($tenantId, $from, $to, $ouId);

        $accounts = $this->db->all(
            'SELECT id, parent_id, code, name, names, type, is_group, archived_at, sort_order FROM acc_accounts
              WHERE tenant_id = :tenant_id ORDER BY sort_order, code',
            [':tenant_id' => $tenantId]
        );
        $rows = [];
        $totals = ['opening_debit_minor' => 0, 'opening_credit_minor' => 0, 'debit_minor' => 0, 'credit_minor' => 0, 'closing_debit_minor' => 0, 'closing_credit_minor' => 0];
        foreach ($accounts as $a) {
            if (DbBool::of($a['is_group'])) {
                continue;
            }
            $id = (int) $a['id'];
            $resets = in_array($a['type'], ['income', 'expense'], true);
            $opening = $resets ? ($beforeInYear[$id] ?? [0, 0]) : ($before[$id] ?? [0, 0]);
            $openingNet = $opening[0] - $opening[1];
            [$debit, $credit] = $during[$id] ?? [0, 0];
            $closingNet = $openingNet + $debit - $credit;
            if ($openingNet === 0 && $debit === 0 && $credit === 0) {
                continue;
            }
            $row = [
                'account_id' => $id,
                'code' => (string) $a['code'],
                'name' => (string) $a['name'],
                'names' => LocalizedText::decode($a['names']),
                'type' => (string) $a['type'],
                'parent_id' => AccountingDb::int($a['parent_id']),
                'opening_debit_minor' => max(0, $openingNet),
                'opening_credit_minor' => max(0, -$openingNet),
                'debit_minor' => $debit,
                'credit_minor' => $credit,
                'closing_debit_minor' => max(0, $closingNet),
                'closing_credit_minor' => max(0, -$closingNet),
            ];
            foreach (array_keys($totals) as $k) {
                $totals[$k] += $row[$k];
            }
            $rows[] = $row;
        }

        return [
            'from' => $from,
            'to' => $to,
            'currency' => $this->workspace->books($tenantId)['base_currency'],
            'ou_id' => $ouId,
            'rows' => $rows,
            'totals' => $totals,
            'balanced' => $totals['debit_minor'] === $totals['credit_minor']
                && $totals['closing_debit_minor'] === $totals['closing_credit_minor'],
        ];
    }

    /**
     * One account's lines in a range, oldest first, with a running balance.
     * `after_line` pages on from the last line of the page before (its id and
     * date go together: pass both).
     *
     * @return array{account: array<string, mixed>, from: string, to: string, currency: string, opening_minor: int, closing_minor: int, lines: list<array<string, mixed>>, next: ?array{after_date: string, after_line: int}}
     */
    public function accountStatement(int $tenantId, int $accountId, string $from, string $to, ?string $afterDate = null, ?int $afterLine = null, int $limit = 200): array
    {
        $from = Periods::date($from, 'from');
        $to = Periods::date($to, 'to');
        if ($from > $to) {
            throw AccountingRejectedException::field('from', 'from is on or before to');
        }
        $this->workspace->ensure($tenantId);
        $account = $this->db->one(
            'SELECT id, code, name, names, type, is_group FROM acc_accounts WHERE tenant_id = :tenant_id AND id = :id',
            [':tenant_id' => $tenantId, ':id' => $accountId]
        ) ?? throw AccountingRejectedException::notFound('Account');
        $limit = max(1, min(self::MAX_STATEMENT_LINES, $limit));

        $resets = in_array($account['type'], ['income', 'expense'], true);
        $openingFrom = $resets ? $this->periods->yearStart($tenantId, $from) : '0001-01-01';
        $opening = $this->net($tenantId, $accountId, $openingFrom, self::dayBefore($from));
        $closing = $opening + $this->net($tenantId, $accountId, $from, $to);

        $params = [':tenant_id' => $tenantId, ':account_id' => $accountId, ':from' => $from, ':to' => $to];
        $cursor = '';
        $running = $opening;
        if ($afterDate !== null && $afterLine !== null) {
            $afterDate = Periods::date($afterDate, 'after_date');
            $cursor = ' AND (l.entry_date > :after_date OR (l.entry_date = :after_date AND l.id > :after_line))';
            $params[':after_date'] = $afterDate;
            $params[':after_line'] = $afterLine;
            $earlier = $this->db->one(
                'SELECT COALESCE(SUM(debit_minor), 0) - COALESCE(SUM(credit_minor), 0) AS net FROM acc_lines
                  WHERE tenant_id = :tenant_id AND account_id = :account_id AND entry_date >= :from
                    AND (entry_date < :after_date OR (entry_date = :after_date AND id <= :after_line))',
                [':tenant_id' => $tenantId, ':account_id' => $accountId, ':from' => $from, ':after_date' => $afterDate, ':after_line' => $afterLine]
            );
            $running += (int) ($earlier['net'] ?? 0);
        }
        $rows = $this->db->all(
            'SELECT l.id, l.entry_id, e.number, l.entry_date, e.memo, l.description, l.debit_minor, l.credit_minor, l.contact_id, l.ou_id,
                    e.source_type, e.source_id
               FROM acc_lines l JOIN acc_entries e ON e.id = l.entry_id AND e.tenant_id = l.tenant_id
              WHERE l.tenant_id = :tenant_id AND l.account_id = :account_id AND l.entry_date >= :from AND l.entry_date <= :to' . $cursor . '
              ORDER BY l.entry_date, l.id
              LIMIT ' . ($limit + 1),
            $params
        );
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $lines = [];
        foreach ($rows as $r) {
            $running += (int) $r['debit_minor'] - (int) $r['credit_minor'];
            $lines[] = [
                'line_id' => (int) $r['id'],
                'entry_id' => (int) $r['entry_id'],
                'number' => (string) $r['number'],
                'date' => substr((string) $r['entry_date'], 0, 10),
                'memo' => (string) $r['memo'],
                'description' => (string) $r['description'],
                'debit_minor' => (int) $r['debit_minor'],
                'credit_minor' => (int) $r['credit_minor'],
                'balance_minor' => $running,
                'contact_id' => AccountingDb::int($r['contact_id']),
                'ou_id' => AccountingDb::int($r['ou_id']),
                'source_type' => (string) $r['source_type'],
                'source_id' => AccountingDb::int($r['source_id']),
            ];
        }
        $last = $lines === [] ? null : $lines[count($lines) - 1];

        return [
            'account' => [
                'id' => (int) $account['id'], 'code' => (string) $account['code'], 'name' => (string) $account['name'],
                'names' => LocalizedText::decode($account['names']), 'type' => (string) $account['type'],
            ],
            'from' => $from,
            'to' => $to,
            'currency' => $this->workspace->books($tenantId)['base_currency'],
            'opening_minor' => $opening,
            'closing_minor' => $closing,
            'lines' => $lines,
            'next' => $more && $last !== null ? ['after_date' => $last['date'], 'after_line' => $last['line_id']] : null,
        ];
    }

    /**
     * Debits and credits per account in a date range.
     *
     * @return array<int, array{0: int, 1: int}>
     */
    private function sums(int $tenantId, string $from, string $to, ?int $ouId): array
    {
        if ($from > $to) {
            return [];
        }
        $out = [];
        $add = static function (array $rows) use (&$out): void {
            foreach ($rows as $r) {
                $id = (int) $r['account_id'];
                $out[$id] = [($out[$id][0] ?? 0) + (int) $r['debit'], ($out[$id][1] ?? 0) + (int) $r['credit']];
            }
        };
        $ou = $ouId === null ? '' : ' AND b.ou_id = :ou_id';
        $params = [':tenant_id' => $tenantId, ':from' => $from, ':to' => $to];
        if ($ouId !== null) {
            $params[':ou_id'] = $ouId;
        }
        // Whole months inside the range, from the balances.
        $add($this->db->all(
            'SELECT b.account_id, SUM(b.debit_minor) AS debit, SUM(b.credit_minor) AS credit
               FROM acc_balances b JOIN acc_periods p ON p.id = b.period_id AND p.tenant_id = b.tenant_id
              WHERE b.tenant_id = :tenant_id AND p.starts_on >= :from AND p.ends_on <= :to' . $ou . '
              GROUP BY b.account_id',
            $params
        ));
        // The parts of months the range cuts, from the lines.
        $lineOu = str_replace('b.ou_id', 'l.ou_id', $ou);
        $add($this->db->all(
            'SELECT l.account_id, SUM(l.debit_minor) AS debit, SUM(l.credit_minor) AS credit
               FROM acc_lines l JOIN acc_periods p ON p.id = l.period_id AND p.tenant_id = l.tenant_id
              WHERE l.tenant_id = :tenant_id AND l.entry_date >= :from AND l.entry_date <= :to
                AND (p.starts_on < :from OR p.ends_on > :to)' . $lineOu . '
              GROUP BY l.account_id',
            $params
        ));

        return $out;
    }

    private function net(int $tenantId, int $accountId, string $from, string $to): int
    {
        $sums = $this->sums($tenantId, $from, $to, null);

        return ($sums[$accountId][0] ?? 0) - ($sums[$accountId][1] ?? 0);
    }

    private static function dayBefore(string $date): string
    {
        return (new \DateTimeImmutable($date, new \DateTimeZone('UTC')))->modify('-1 day')->format('Y-m-d');
    }
}
