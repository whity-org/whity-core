<?php

declare(strict_types=1);

namespace Whity\Core\Accounting;

/**
 * What customers owe (WHIT-1318 A2b, design section 32 §8, register AC.23):
 * aged receivables and a customer's statement.
 *
 * AGED RECEIVABLES read the open sales invoices as on a date: each invoice's
 * total less what payments and credit notes settled on or before that date,
 * in a column by how many days past its due date. A customer's credit (money
 * received or credited that settles nothing yet) is shown beside, so the
 * balance matches Customers owe us for that customer.
 *
 * A CUSTOMER'S STATEMENT reads the ledger itself: every line on Customers owe
 * us with the customer's contact, an opening balance, a running balance and
 * the closing balance. It is what the books say, whatever the documents say.
 *
 * TENANT-OWNED: every statement binds `tenant_id`.
 */
final class Receivables
{
    /** Columns by days past due: not yet due, 1–30, 31–60, 61–90, over 90. */
    public const BUCKETS = ['not_due', 'days_1_30', 'days_31_60', 'days_61_90', 'days_over_90'];

    public function __construct(
        private readonly AccountingDb $db,
        private readonly AccountingWorkspace $workspace,
    ) {
    }

    /**
     * @return array{as_of: string, currency: string, rows: list<array<string, mixed>>, totals: array<string, int>}
     */
    public function aged(int $tenantId, string $asOf, ?int $contactId = null): array
    {
        $asOf = Periods::date($asOf, 'as_of');
        $this->workspace->ensure($tenantId);
        $params = [':tenant_id' => $tenantId, ':as_of' => $asOf, ':after' => self::dayAfter($asOf)];
        $onlyOne = '';
        if ($contactId !== null) {
            $onlyOne = ' AND i.contact_id = :contact_id';
            $params[':contact_id'] = $contactId;
        }
        $invoices = $this->db->all(
            'SELECT i.id, i.contact_id, c.name AS contact_name, i.due_date, i.issue_date,
                    i.total_minor - COALESCE((SELECT SUM(a.amount_minor) FROM acc_allocations a
                       WHERE a.tenant_id = i.tenant_id AND a.invoice_id = i.id AND a.allocated_on <= :as_of
                         AND (a.reversed_at IS NULL OR a.reversed_at >= :after)), 0) AS owed
               FROM acc_invoices i JOIN contacts c ON c.id = i.contact_id AND c.tenant_id = i.tenant_id
              WHERE i.tenant_id = :tenant_id AND i.kind = \'sale\' AND i.issued_at IS NOT NULL
                AND i.status IN (\'issued\', \'partly_paid\', \'paid\', \'credited\') AND i.issue_date <= :as_of3' . $onlyOne,
            $params + [':as_of3' => $asOf]
        );
        $rows = [];
        foreach ($invoices as $inv) {
            $owed = (int) $inv['owed'];
            if ($owed <= 0) {
                continue;
            }
            $cid = (int) $inv['contact_id'];
            $rows[$cid] ??= ['contact_id' => $cid, 'contact_name' => (string) $inv['contact_name']] + array_fill_keys(self::BUCKETS, 0) + ['credit_minor' => 0];
            $due = is_string($inv['due_date']) && $inv['due_date'] !== '' ? substr($inv['due_date'], 0, 10) : substr((string) $inv['issue_date'], 0, 10);
            $late = (int) (new \DateTimeImmutable($due))->diff(new \DateTimeImmutable($asOf))->format('%r%a');
            $bucket = match (true) {
                $late <= 0 => 'not_due',
                $late <= 30 => 'days_1_30',
                $late <= 60 => 'days_31_60',
                $late <= 90 => 'days_61_90',
                default => 'days_over_90',
            };
            $rows[$cid][$bucket] += $owed;
        }
        foreach ($this->credits($tenantId, $asOf, $contactId) as $cid => [$name, $credit]) {
            $rows[$cid] ??= ['contact_id' => $cid, 'contact_name' => $name] + array_fill_keys(self::BUCKETS, 0) + ['credit_minor' => 0];
            $rows[$cid]['credit_minor'] = $credit;
        }
        $totals = array_fill_keys(self::BUCKETS, 0) + ['credit_minor' => 0, 'balance_minor' => 0];
        foreach ($rows as &$row) {
            $row['balance_minor'] = array_sum(array_intersect_key($row, array_flip(self::BUCKETS))) - $row['credit_minor'];
            foreach ($totals as $k => $_) {
                $totals[$k] += (int) $row[$k];
            }
        }
        unset($row);
        $list = array_values($rows);
        usort($list, static fn (array $a, array $b): int => [$b['balance_minor'], $a['contact_name']] <=> [$a['balance_minor'], $b['contact_name']]);

        return ['as_of' => $asOf, 'currency' => $this->workspace->books($tenantId)['base_currency'], 'rows' => $list, 'totals' => $totals];
    }

    /**
     * Money a customer paid or was credited that settles nothing yet, as on a date.
     *
     * @return array<int, array{0: string, 1: int}> contact => [name, credit]
     */
    private function credits(int $tenantId, string $asOf, ?int $contactId): array
    {
        $params = [':tenant_id' => $tenantId, ':as_of' => $asOf, ':after' => self::dayAfter($asOf), ':as_of3' => $asOf];
        $onlyOne = '';
        if ($contactId !== null) {
            $onlyOne = ' AND p.contact_id = :contact_id';
            $params[':contact_id'] = $contactId;
        }
        $out = [];
        foreach ($this->db->all(
            'SELECT p.contact_id, c.name AS contact_name,
                    SUM(p.amount_minor - COALESCE((SELECT SUM(a.amount_minor) FROM acc_allocations a
                       WHERE a.tenant_id = p.tenant_id AND a.payment_id = p.id AND a.allocated_on <= :as_of
                         AND (a.reversed_at IS NULL OR a.reversed_at >= :after)), 0)) AS credit
               FROM acc_payments p JOIN contacts c ON c.id = p.contact_id AND c.tenant_id = p.tenant_id
              WHERE p.tenant_id = :tenant_id AND p.direction = \'in\' AND p.status = \'posted\' AND p.paid_on <= :as_of3' . $onlyOne . '
              GROUP BY p.contact_id, c.name',
            $params
        ) as $r) {
            $out[(int) $r['contact_id']] = [(string) $r['contact_name'], (int) $r['credit']];
        }
        $onlyOne = str_replace('p.contact_id', 'n.contact_id', $onlyOne);
        foreach ($this->db->all(
            'SELECT n.contact_id, c.name AS contact_name,
                    SUM(n.total_minor - COALESCE((SELECT SUM(a.amount_minor) FROM acc_allocations a
                       WHERE a.tenant_id = n.tenant_id AND a.credit_invoice_id = n.id AND a.allocated_on <= :as_of
                         AND (a.reversed_at IS NULL OR a.reversed_at >= :after)), 0)) AS credit
               FROM acc_invoices n JOIN contacts c ON c.id = n.contact_id AND c.tenant_id = n.tenant_id
              WHERE n.tenant_id = :tenant_id AND n.kind = \'sale_credit\' AND n.issued_at IS NOT NULL AND n.issue_date <= :as_of3' . $onlyOne . '
              GROUP BY n.contact_id, c.name',
            $params
        ) as $r) {
            $cid = (int) $r['contact_id'];
            $out[$cid] = [(string) $r['contact_name'], ($out[$cid][1] ?? 0) + (int) $r['credit']];
        }

        return array_filter($out, static fn (array $v): bool => $v[1] > 0);
    }

    /** The first moment after a day, for comparing timestamps on both engines. */
    private static function dayAfter(string $date): string
    {
        return (new \DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
    }

    /**
     * A customer's statement from the ledger: what they owed before `$from`,
     * every line on Customers owe us from `$from` to `$to`, and what they owe.
     *
     * @return array<string, mixed>
     */
    public function statement(int $tenantId, int $contactId, string $from, string $to): array
    {
        $from = Periods::date($from, 'from');
        $to = Periods::date($to, 'to');
        if ($to < $from) {
            throw AccountingRejectedException::field('to', 'to cannot be before from');
        }
        $contact = $this->db->one(
            'SELECT id, name FROM contacts WHERE tenant_id = :tenant_id AND id = :id',
            [':tenant_id' => $tenantId, ':id' => $contactId]
        ) ?? throw AccountingRejectedException::notFound('Customer');
        $receivable = $this->workspace->roleAccountId($tenantId, 'receivable');
        $opening = (int) ($this->db->one(
            'SELECT COALESCE(SUM(debit_minor - credit_minor), 0) AS bal FROM acc_lines
              WHERE tenant_id = :tenant_id AND account_id = :account_id AND contact_id = :contact_id AND entry_date < :from',
            [':tenant_id' => $tenantId, ':account_id' => $receivable, ':contact_id' => $contactId, ':from' => $from]
        )['bal'] ?? 0);
        $lines = $this->db->all(
            'SELECT l.id, l.entry_date, l.debit_minor, l.credit_minor, l.description, e.id AS entry_id, e.number, e.memo,
                    e.source_type, e.source_id
               FROM acc_lines l JOIN acc_entries e ON e.id = l.entry_id AND e.tenant_id = l.tenant_id
              WHERE l.tenant_id = :tenant_id AND l.account_id = :account_id AND l.contact_id = :contact_id
                AND l.entry_date >= :from AND l.entry_date <= :to
              ORDER BY l.entry_date, l.id
              LIMIT 2000',
            [':tenant_id' => $tenantId, ':account_id' => $receivable, ':contact_id' => $contactId, ':from' => $from, ':to' => $to]
        );
        $balance = $opening;
        $out = [];
        foreach ($lines as $l) {
            $balance += (int) $l['debit_minor'] - (int) $l['credit_minor'];
            $out[] = [
                'line_id' => (int) $l['id'],
                'entry_id' => (int) $l['entry_id'],
                'number' => (string) $l['number'],
                'date' => substr((string) $l['entry_date'], 0, 10),
                'memo' => (string) $l['memo'],
                'source_type' => (string) $l['source_type'],
                'source_id' => AccountingDb::int($l['source_id']),
                'debit_minor' => (int) $l['debit_minor'],
                'credit_minor' => (int) $l['credit_minor'],
                'balance_minor' => $balance,
            ];
        }

        return [
            'contact' => ['id' => (int) $contact['id'], 'name' => (string) $contact['name']],
            'from' => $from,
            'to' => $to,
            'currency' => $this->workspace->books($tenantId)['base_currency'],
            'opening_minor' => $opening,
            'closing_minor' => $balance,
            'lines' => $out,
        ];
    }
}
