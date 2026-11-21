<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use DateTimeImmutable;
use PDO;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Money\Currency;
use Whity\Sdk\Calendar\CommissionClose;
use Whity\Sdk\Calendar\CommissionCloseLine;
use Whity\Sdk\Contribute\WorkspaceCommissionExpenses;
use Whity\Sdk\Hooks\Events;

/**
 * Closing a commission period by hand (WHIT-1334, design section 35 §4.3,
 * register SL.6; owner, Q3, "Accept all"), like a payroll run.
 *
 * Closing freezes each member of staff's totals for the period (only people
 * with a rate), fires `calendar.commission.closed`, and hands the close to the
 * books through {@see WorkspaceCommissionExpenses} when something implements
 * it (accounting A3). With nothing bound, or the books unable to take it now,
 * the close stands without a reference and can be sent again.
 *
 * Only a period that has ended can be closed, and closed periods never
 * overlap, so no booking is paid twice.
 */
final class CommissionCloses
{
    /** @var \Closure(): ?WorkspaceCommissionExpenses */
    private readonly \Closure $books;

    /** @param (\Closure(): ?WorkspaceCommissionExpenses)|null $books the bound implementation; the container's by default */
    public function __construct(
        private readonly PDO $db,
        private readonly Commission $commission,
        private readonly CalendarRepository $calendar,
        private readonly ?HookManager $hooks = null,
        ?\Closure $books = null,
    ) {
        $this->books = $books ?? static function (): ?WorkspaceCommissionExpenses {
            $bound = $GLOBALS['whity_services'][WorkspaceCommissionExpenses::class] ?? null;

            return $bound instanceof WorkspaceCommissionExpenses ? $bound : null;
        };
    }

    /**
     * The periods that may be closed now: ended, and touching no closed day.
     *
     * @return list<array{id: string, label: string, from: string, to: string}>
     */
    public function closable(int $tenantId, string $language = 'en'): array
    {
        $today = $this->today($tenantId);

        return array_values(array_filter(
            $this->commission->periods($tenantId, $language),
            fn (array $p): bool => $p['to'] < $today && $this->overlapping($tenantId, $p['from'], $p['to']) === null,
        ));
    }

    /**
     * Close a period.
     *
     * @return array<string, mixed> the close as {@see list()} shows it
     */
    public function close(int $tenantId, string $period, ?int $actor, string $language = 'en'): array
    {
        $found = array_values(array_filter($this->commission->periods($tenantId, $language), static fn (array $p): bool => $p['id'] === $period))[0] ?? null;
        if ($found === null) {
            throw new CalendarRejectedException('Choose a period.', 422, ['field' => 'period']);
        }
        if ($found['to'] >= $this->today($tenantId)) {
            throw new CalendarRejectedException('Close a period once it has ended.', 409, ['reason' => 'not_ended']);
        }
        $overlap = $this->overlapping($tenantId, $found['from'], $found['to']);
        if ($overlap !== null) {
            throw new CalendarRejectedException("Some of these days were closed already ({$overlap['period_start']} to {$overlap['period_end']}).", 409, ['reason' => 'already_closed']);
        }
        $lines = array_values(array_filter($this->commission->report($tenantId, $period, $language), static fn (array $r): bool => $r['rated'] === true));
        if ($lines === []) {
            throw new CalendarRejectedException('Nothing to close: no done bookings for anyone with a rate in this period.', 409, ['reason' => 'nothing_to_close']);
        }

        $now = (new DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'INSERT INTO calendar_commission_closes (tenant_id, period_start, period_end, closed_by_profile_id, closed_at)
                 VALUES (:t, :from, :to, :by, :at)'
            )->execute([':t' => $tenantId, ':from' => $found['from'], ':to' => $found['to'], ':by' => $actor, ':at' => $now]);
            $closeId = (int) $this->db->lastInsertId();
            $insert = $this->db->prepare(
                'INSERT INTO calendar_commission_close_lines (tenant_id, close_id, profile_id, currency, bookings, sales_minor, commission_minor)
                 VALUES (:t, :c, :p, :cur, :n, :sales, :commission)'
            );
            foreach ($lines as $l) {
                $insert->execute([
                    ':t' => $tenantId, ':c' => $closeId, ':p' => $l['profile_id'], ':cur' => $l['currency'],
                    ':n' => $l['bookings'], ':sales' => $l['sales_minor'], ':commission' => $l['commission_minor'],
                ]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();

            throw $e;
        }

        $snapshot = $this->snapshot($tenantId, $closeId) ?? throw new \RuntimeException('The close was not saved');
        $payload = [
            'tenant_id' => $tenantId, 'close_id' => $closeId, 'from' => $snapshot->from, 'to' => $snapshot->to,
            'acting_profile_id' => $actor,
            'lines' => array_map(static fn (CommissionCloseLine $l): array => [
                'profile_id' => $l->profileId, 'currency' => $l->currency, 'bookings' => $l->bookings,
                'sales_minor' => $l->salesMinor, 'commission_minor' => $l->commissionMinor,
            ], $snapshot->lines),
        ];
        $this->hooks?->dispatch(Events::CALENDAR_COMMISSION_CLOSED, $payload);
        try {
            $this->hooks?->dispatchAsync(Events::CALENDAR_COMMISSION_CLOSED_ASYNC, $payload);
        } catch (\Throwable $e) {
            error_log('[commission] ' . Events::CALENDAR_COMMISSION_CLOSED_ASYNC . ' could not be queued: ' . $e->getMessage());
        }
        $this->sendToBooks($snapshot);

        return $this->one($tenantId, $closeId, $language);
    }

    /**
     * Send a close the books have not taken yet, again.
     *
     * @return array<string, mixed>
     */
    public function book(int $tenantId, int $closeId, string $language = 'en'): array
    {
        $snapshot = $this->snapshot($tenantId, $closeId) ?? throw CalendarRejectedException::notFound('Close');
        if ($this->one($tenantId, $closeId, $language)['expense_ref'] !== null) {
            throw new CalendarRejectedException('This period is in the books already.', 409, ['reason' => 'already_booked']);
        }
        if (($this->books)() === null) {
            throw new CalendarRejectedException('Nothing books commission here yet. The totals stay ready for the books.', 409, ['reason' => 'no_books']);
        }
        if (!$this->sendToBooks($snapshot)) {
            throw new CalendarRejectedException('The books could not take it now. Try again later.', 409, ['reason' => 'books_refused']);
        }

        return $this->one($tenantId, $closeId, $language);
    }

    /**
     * Every close, newest first, with its lines.
     *
     * @return list<array<string, mixed>>
     */
    public function list(int $tenantId, string $language = 'en'): array
    {
        $stmt = $this->db->prepare('SELECT id FROM calendar_commission_closes WHERE tenant_id = :t ORDER BY period_start DESC, id DESC');
        $stmt->execute([':t' => $tenantId]);

        return array_values(array_map(fn (mixed $id): array => $this->one($tenantId, (int) $id, $language), $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** @return array<string, mixed> */
    private function one(int $tenantId, int $closeId, string $language): array
    {
        $stmt = $this->db->prepare('SELECT * FROM calendar_commission_closes WHERE tenant_id = :t AND id = :id');
        $stmt->execute([':t' => $tenantId, ':id' => $closeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw CalendarRejectedException::notFound('Close');
        }
        $names = [];
        foreach ($this->calendar->listStaff($tenantId) as $s) {
            $names[$s['profile_id']] = $s['display_name'];
        }
        $ar = $language === 'ar';
        $snapshot = $this->snapshot($tenantId, $closeId);
        $totals = [];
        foreach ($snapshot->lines ?? [] as $l) {
            $totals[$l->currency] = ($totals[$l->currency] ?? 0) + $l->commissionMinor;
        }
        $ref = $row['expense_ref'] !== null ? (string) $row['expense_ref'] : null;

        return [
            'id' => $closeId,
            'from' => (string) $row['period_start'],
            'to' => (string) $row['period_end'],
            'period' => substr((string) $row['period_start'], 0, 10) . ' – ' . substr((string) $row['period_end'], 0, 10),
            'closed_at' => (string) $row['closed_at'],
            'closed_by_profile_id' => $row['closed_by_profile_id'] !== null ? (int) $row['closed_by_profile_id'] : null,
            'expense_ref' => $ref,
            'books' => $ref ?? ($ar ? 'جاهز للدفاتر' : 'Ready for the books'),
            'total' => implode(', ', array_map(static fn (string $c, int $m): string => $c === '' ? (string) $m : Currency::display($m, $c), array_keys($totals), $totals)),
            'lines' => array_map(static fn (CommissionCloseLine $l): array => [
                'profile_id' => $l->profileId,
                'person' => $names[$l->profileId] ?? '',
                'currency' => $l->currency,
                'bookings' => $l->bookings,
                'sales_minor' => $l->salesMinor,
                'commission_minor' => $l->commissionMinor,
            ], $snapshot->lines ?? []),
        ];
    }

    private function snapshot(int $tenantId, int $closeId): ?CommissionClose
    {
        $stmt = $this->db->prepare('SELECT period_start, period_end, closed_by_profile_id FROM calendar_commission_closes WHERE tenant_id = :t AND id = :id');
        $stmt->execute([':t' => $tenantId, ':id' => $closeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $lines = $this->db->prepare('SELECT profile_id, currency, bookings, sales_minor, commission_minor FROM calendar_commission_close_lines WHERE tenant_id = :t AND close_id = :c ORDER BY profile_id, currency');
        $lines->execute([':t' => $tenantId, ':c' => $closeId]);

        return new CommissionClose(
            $closeId,
            $tenantId,
            substr((string) $row['period_start'], 0, 10),
            substr((string) $row['period_end'], 0, 10),
            array_map(static fn (array $l): CommissionCloseLine => new CommissionCloseLine(
                (int) $l['profile_id'],
                (string) $l['currency'],
                (int) $l['bookings'],
                (int) $l['sales_minor'],
                (int) $l['commission_minor'],
            ), array_values($lines->fetchAll(PDO::FETCH_ASSOC))),
            $row['closed_by_profile_id'] !== null ? (int) $row['closed_by_profile_id'] : null,
        );
    }

    /** Hand the close to the books; true when they gave it a reference. */
    private function sendToBooks(CommissionClose $close): bool
    {
        $books = ($this->books)();
        if ($books === null) {
            return false;
        }
        try {
            $ref = mb_substr(trim($books->record($close)), 0, 100);
        } catch (\Throwable $e) {
            error_log('[commission] the books did not take close ' . $close->id . ': ' . $e->getMessage());

            return false;
        }
        if ($ref === '') {
            return false;
        }
        $this->db->prepare('UPDATE calendar_commission_closes SET expense_ref = :ref, booked_at = CURRENT_TIMESTAMP WHERE tenant_id = :t AND id = :id AND expense_ref IS NULL')
            ->execute([':ref' => $ref, ':t' => $close->workspaceId, ':id' => $close->id]);

        return true;
    }

    /** @return array{period_start: string, period_end: string}|null a close touching [from, to] */
    private function overlapping(int $tenantId, string $from, string $to): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT period_start, period_end FROM calendar_commission_closes
              WHERE tenant_id = :t AND period_start <= :to AND period_end >= :from
              ORDER BY period_start LIMIT 1'
        );
        $stmt->execute([':t' => $tenantId, ':from' => $from, ':to' => $to]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? ['period_start' => substr((string) $row['period_start'], 0, 10), 'period_end' => substr((string) $row['period_end'], 0, 10)] : null;
    }

    /** Today's date in the workspace, as Y-m-d. */
    private function today(int $tenantId): string
    {
        [$from] = $this->commission->range($tenantId, 'last_7_days');

        return $from->modify('+6 days')->format('Y-m-d');
    }
}
