<?php

declare(strict_types=1);

namespace Whity\Core\Accounting;

use Whity\Core\Hooks\HookManager;
use Whity\Sdk\Hooks\Events;

/**
 * Fiscal years and their months (WHIT-1318, design section 32 §3.4, register
 * AC.7).
 *
 * A fiscal year is twelve months from the books' `year_start_month` (January
 * by default). Years are made when a date in them is first needed, so a
 * workspace posting in 2027 gets 2027 without a job.
 *
 * A period is `open` (takes postings), `closed` (refuses them; a person with
 * `accounting:close` reopens it with a reason, which is kept) or `locked`
 * (after a filed tax return or a closed year, phases A5 and A6). The books'
 * lock date refuses anything dated on or before it, whatever the period says.
 *
 * TENANT-OWNED: every statement binds `tenant_id`.
 */
final class Periods
{
    public const EARLIEST_YEAR = 2000;
    public const YEARS_AHEAD = 2;

    public function __construct(
        private readonly AccountingDb $db,
        private readonly AccountingWorkspace $workspace,
        private readonly ?HookManager $hooks = null,
    ) {
    }

    /**
     * The period a date falls in, making its year when needed.
     *
     * @return array{id: int, starts_on: string, ends_on: string, status: string, fiscal_year_id: int}
     */
    public function periodFor(int $tenantId, string $date, string $field = 'date'): array
    {
        $date = self::date($date, $field);
        $year = (int) substr($date, 0, 4);
        if ($year < self::EARLIEST_YEAR || $year > (int) gmdate('Y') + self::YEARS_AHEAD) {
            throw AccountingRejectedException::field($field, $field . ' must be between ' . self::EARLIEST_YEAR . ' and ' . ((int) gmdate('Y') + self::YEARS_AHEAD));
        }
        $row = $this->find($tenantId, $date);
        if ($row === null) {
            $this->makeYear($tenantId, $date);
            $row = $this->find($tenantId, $date) ?? throw new \RuntimeException('No period for ' . $date);
        }

        return $row;
    }

    /**
     * Refuses a posting dated in a period that is not open, or on or before
     * the lock date.
     *
     * @return array{id: int, starts_on: string, ends_on: string, status: string, fiscal_year_id: int}
     */
    public function postable(int $tenantId, string $date, string $field = 'date'): array
    {
        $period = $this->periodFor($tenantId, $date, $field);
        $lock = $this->workspace->books($tenantId)['lock_date'];
        if ($lock !== null && $date <= $lock) {
            throw AccountingRejectedException::coded('period_closed', 'The books are locked up to ' . $lock . '. Date it after that.', 409, $field);
        }
        if ($period['status'] !== 'open') {
            throw AccountingRejectedException::coded(
                'period_closed',
                'The month starting ' . $period['starts_on'] . ' is ' . $period['status'] . '. Reopen it, or date this in an open month.',
                409,
                $field
            );
        }

        return $period;
    }

    /** Whether a posting dated `$date` would be accepted. */
    public function isOpen(int $tenantId, string $date): bool
    {
        try {
            $this->postable($tenantId, $date);

            return true;
        } catch (AccountingRejectedException) {
            return false;
        }
    }

    /**
     * Months, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function list(int $tenantId, ?int $year = null): array
    {
        $this->workspace->ensure($tenantId);
        $this->periodFor($tenantId, gmdate('Y-m-d'));
        $params = [':tenant_id' => $tenantId];
        $where = '';
        if ($year !== null) {
            $where = ' AND p.starts_on >= :from AND p.starts_on <= :to';
            $params[':from'] = sprintf('%04d-01-01', $year);
            $params[':to'] = sprintf('%04d-12-31', $year);
        }

        return array_map(self::shape(...), $this->db->all(
            'SELECT p.id, p.fiscal_year_id, p.starts_on, p.ends_on, p.status, p.closed_at, p.closed_by_profile_id, p.reopened_at,
                    p.reopened_by_profile_id, p.reopen_reason, y.starts_on AS year_starts_on, y.ends_on AS year_ends_on
               FROM acc_periods p JOIN acc_fiscal_years y ON y.id = p.fiscal_year_id AND y.tenant_id = p.tenant_id
              WHERE p.tenant_id = :tenant_id' . $where . '
              ORDER BY p.starts_on DESC',
            $params
        ));
    }

    /** @return array<string, mixed> */
    public function get(int $tenantId, int $periodId): array
    {
        $row = $this->db->one(
            'SELECT p.id, p.fiscal_year_id, p.starts_on, p.ends_on, p.status, p.closed_at, p.closed_by_profile_id, p.reopened_at,
                    p.reopened_by_profile_id, p.reopen_reason, y.starts_on AS year_starts_on, y.ends_on AS year_ends_on
               FROM acc_periods p JOIN acc_fiscal_years y ON y.id = p.fiscal_year_id AND y.tenant_id = p.tenant_id
              WHERE p.tenant_id = :tenant_id AND p.id = :id',
            [':tenant_id' => $tenantId, ':id' => $periodId]
        );

        return $row === null ? throw AccountingRejectedException::notFound('Period') : self::shape($row);
    }

    /** @return array<string, mixed> */
    public function close(int $tenantId, int $periodId, ?int $actor): array
    {
        $period = $this->get($tenantId, $periodId);
        if ($period['status'] !== 'open') {
            throw new AccountingRejectedException('This month is already ' . $period['status'], 409);
        }
        $this->db->run(
            "UPDATE acc_periods SET status = 'closed', closed_at = CURRENT_TIMESTAMP, closed_by_profile_id = :actor
              WHERE tenant_id = :tenant_id AND id = :id AND status = 'open'",
            [':tenant_id' => $tenantId, ':id' => $periodId, ':actor' => $actor]
        );
        $this->notify(Events::ACCOUNTING_PERIOD_CLOSED, Events::ACCOUNTING_PERIOD_CLOSED_ASYNC, [
            'tenant_id' => $tenantId, 'period_id' => $periodId, 'starts_on' => $period['starts_on'], 'ends_on' => $period['ends_on'],
            'acting_profile_id' => $actor,
        ]);

        return $this->get($tenantId, $periodId);
    }

    /** @return array<string, mixed> */
    public function reopen(int $tenantId, int $periodId, ?int $actor, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw AccountingRejectedException::field('reason', 'Say why the month is reopened, in up to 500 characters');
        }
        $period = $this->get($tenantId, $periodId);
        if ($period['status'] === 'locked') {
            throw new AccountingRejectedException('This month is locked by a filed tax return or a closed year; an admin reopens it through its approval', 409);
        }
        if ($period['status'] === 'open') {
            throw new AccountingRejectedException('This month is already open', 409);
        }
        $lock = $this->workspace->books($tenantId)['lock_date'];
        if ($lock !== null && $period['starts_on'] <= $lock) {
            throw new AccountingRejectedException('This month is before the lock date ' . $lock . '; move the lock date first', 409);
        }
        $this->db->run(
            "UPDATE acc_periods SET status = 'open', reopened_at = CURRENT_TIMESTAMP, reopened_by_profile_id = :actor, reopen_reason = :reason
              WHERE tenant_id = :tenant_id AND id = :id AND status = 'closed'",
            [':tenant_id' => $tenantId, ':id' => $periodId, ':actor' => $actor, ':reason' => $reason]
        );
        $this->notify(Events::ACCOUNTING_PERIOD_REOPENED, Events::ACCOUNTING_PERIOD_REOPENED_ASYNC, [
            'tenant_id' => $tenantId, 'period_id' => $periodId, 'starts_on' => $period['starts_on'], 'ends_on' => $period['ends_on'],
            'reason' => $reason, 'acting_profile_id' => $actor,
        ]);

        return $this->get($tenantId, $periodId);
    }

    /** The first day of the fiscal year `$date` falls in. */
    public function yearStart(int $tenantId, string $date): string
    {
        $period = $this->periodFor($tenantId, $date);
        $row = $this->db->one(
            'SELECT starts_on FROM acc_fiscal_years WHERE tenant_id = :tenant_id AND id = :id',
            [':tenant_id' => $tenantId, ':id' => $period['fiscal_year_id']]
        );

        return substr((string) ($row['starts_on'] ?? $period['starts_on']), 0, 10);
    }

    /** A calendar date, `YYYY-MM-DD`. */
    public static function date(mixed $value, string $field = 'date'): string
    {
        if (!is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($value), $m) !== 1
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw AccountingRejectedException::field($field, $field . ' is a date written YYYY-MM-DD');
        }

        return trim($value);
    }

    /** @return array{id: int, starts_on: string, ends_on: string, status: string, fiscal_year_id: int}|null */
    private function find(int $tenantId, string $date): ?array
    {
        $row = $this->db->one(
            'SELECT id, fiscal_year_id, starts_on, ends_on, status FROM acc_periods
              WHERE tenant_id = :tenant_id AND starts_on <= :date AND ends_on >= :date',
            [':tenant_id' => $tenantId, ':date' => $date]
        );

        return $row === null ? null : [
            'id' => (int) $row['id'],
            'starts_on' => substr((string) $row['starts_on'], 0, 10),
            'ends_on' => substr((string) $row['ends_on'], 0, 10),
            'status' => (string) $row['status'],
            'fiscal_year_id' => (int) $row['fiscal_year_id'],
        ];
    }

    private function makeYear(int $tenantId, string $date): void
    {
        $startMonth = $this->workspace->books($tenantId)['year_start_month'];
        [$y, $m] = [(int) substr($date, 0, 4), (int) substr($date, 5, 2)];
        $startYear = $m >= $startMonth ? $y : $y - 1;
        $start = new \DateTimeImmutable(sprintf('%04d-%02d-01', $startYear, $startMonth), new \DateTimeZone('UTC'));
        $end = $start->modify('+1 year')->modify('-1 day');

        $this->db->transaction(function () use ($tenantId, $start, $end): void {
            // Two requests making the same year: the second inserts nothing and uses the first's.
            $this->db->run(
                'INSERT INTO acc_fiscal_years (tenant_id, starts_on, ends_on) VALUES (:tenant_id, :starts_on, :ends_on)
                 ON CONFLICT (tenant_id, starts_on) DO NOTHING',
                [':tenant_id' => $tenantId, ':starts_on' => $start->format('Y-m-d'), ':ends_on' => $end->format('Y-m-d')]
            );
            $yearId = (int) ($this->db->one(
                'SELECT id FROM acc_fiscal_years WHERE tenant_id = :tenant_id AND starts_on = :starts_on',
                [':tenant_id' => $tenantId, ':starts_on' => $start->format('Y-m-d')]
            )['id'] ?? 0);
            for ($i = 0; $i < 12; $i++) {
                $from = $start->modify('+' . $i . ' month');
                $this->db->run(
                    'INSERT INTO acc_periods (tenant_id, fiscal_year_id, starts_on, ends_on) VALUES (:tenant_id, :year_id, :starts_on, :ends_on)
                     ON CONFLICT (tenant_id, starts_on) DO NOTHING',
                    [
                        ':tenant_id' => $tenantId, ':year_id' => $yearId,
                        ':starts_on' => $from->format('Y-m-d'), ':ends_on' => $from->modify('+1 month')->modify('-1 day')->format('Y-m-d'),
                    ]
                );
            }
        });
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function shape(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'fiscal_year_id' => (int) $row['fiscal_year_id'],
            'starts_on' => substr((string) $row['starts_on'], 0, 10),
            'ends_on' => substr((string) $row['ends_on'], 0, 10),
            'status' => (string) $row['status'],
            'closed_at' => $row['closed_at'],
            'closed_by_profile_id' => AccountingDb::int($row['closed_by_profile_id']),
            'reopened_at' => $row['reopened_at'],
            'reopened_by_profile_id' => AccountingDb::int($row['reopened_by_profile_id']),
            'reopen_reason' => (string) $row['reopen_reason'],
            'year_starts_on' => substr((string) $row['year_starts_on'], 0, 10),
            'year_ends_on' => substr((string) $row['year_ends_on'], 0, 10),
        ];
    }

    /** @param array<string, mixed> $payload */
    private function notify(string $event, string $async, array $payload): void
    {
        $this->hooks?->dispatch($event, $payload);
        try {
            $this->hooks?->dispatchAsync($async, $payload);
        } catch (\Throwable $e) {
            error_log('[accounting] ' . $async . ' could not be queued: ' . $e->getMessage());
        }
    }
}
