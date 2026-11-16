<?php

declare(strict_types=1);

namespace Whity\Core\Billing\Cash;

use PDO;
use Whity\Core\Db\DbBool;

/**
 * Cash payments for paid plugin plans (WHIT-1153): the operator records one,
 * and while it covers now the plugin is on in that workspace exactly as if it
 * had been bought online. See migration 296.
 *
 * A new payment starts on the day it was paid, or when the workspace's current
 * cash cover for that plugin ends, whichever is later, and runs for the plan's
 * period (a month, 6 months, a year). Nothing is deleted; a mistake is voided
 * with a reason.
 */
final class CashPayments
{
    /** @var array<string, int> billing period => months */
    public const MONTHS = ['month' => 1, 'quarter' => 3, 'half_year' => 6, 'year' => 12];

    /** @var \Closure(): int */
    private \Closure $clock;

    /** @param (\Closure(): int)|null $clock */
    public function __construct(private readonly PDO $db, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * @return array<string, mixed> the payment as stored
     * @throws CashPaymentRejected naming what is wrong
     */
    public function record(
        int $tenantId,
        string $planKey,
        int $quantity,
        int $amountMinor,
        string $currency,
        string $receiptNumber,
        ?string $note,
        string $paidOn,
        ?int $profileId,
    ): array {
        if ($tenantId <= 0) {
            throw new CashPaymentRejected('Choose the workspace that paid.');
        }
        $months = $this->monthsOf($planKey);
        if ($quantity < 1 || $quantity > 1000) {
            throw new CashPaymentRejected('Quantity: a number from 1 to 1000.');
        }
        if ($amountMinor < 0) {
            throw new CashPaymentRejected('The amount can\'t be negative.');
        }
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new CashPaymentRejected('Currency: a three-letter code such as JOD.');
        }
        $receiptNumber = trim($receiptNumber);
        if ($receiptNumber === '' || mb_strlen($receiptNumber) > 64) {
            throw new CashPaymentRejected('Write the receipt number (at most 64 characters).');
        }
        $paid = \DateTimeImmutable::createFromFormat('!Y-m-d', $paidOn, new \DateTimeZone('UTC'));
        if ($paid === false || $paid->format('Y-m-d') !== $paidOn) {
            throw new CashPaymentRejected('The date paid must be a date like 2026-11-16.');
        }
        if ($this->receiptExists($tenantId, $receiptNumber)) {
            throw new CashPaymentRejected('That receipt number is already recorded for this workspace.');
        }

        // Starts when paid, or when the current cash cover for this plugin ends.
        $start = $paid;
        $coveredUntil = $this->coverOf($tenantId, self::productOf($planKey))['until'] ?? null;
        if ($coveredUntil !== null) {
            $until = new \DateTimeImmutable($coveredUntil, new \DateTimeZone('UTC'));
            if ($until > $start) {
                $start = $until;
            }
        }
        $end = $start->modify('+' . $months . ' months');

        $stmt = $this->db->prepare(
            'INSERT INTO plugin_cash_payments
                (tenant_id, plan_key, quantity, amount_minor, currency, receipt_number, note, paid_on, period_start, period_end, recorded_by_profile_id, recorded_at)
             VALUES (:tenant_id, :plan_key, :quantity, :amount, :currency, :receipt, :note, :paid_on, :start, :end, :profile, CURRENT_TIMESTAMP)'
        );
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':plan_key', $planKey);
        $stmt->bindValue(':quantity', $quantity, PDO::PARAM_INT);
        $stmt->bindValue(':amount', $amountMinor, PDO::PARAM_INT);
        $stmt->bindValue(':currency', $currency);
        $stmt->bindValue(':receipt', $receiptNumber);
        $stmt->bindValue(':note', $note !== null && trim($note) !== '' ? trim($note) : null, $note !== null && trim($note) !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':paid_on', $paidOn);
        $stmt->bindValue(':start', $start->format('Y-m-d H:i:s'));
        $stmt->bindValue(':end', $end->format('Y-m-d H:i:s'));
        $stmt->bindValue(':profile', $profileId, $profileId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->execute();

        return $this->find($tenantId, $receiptNumber) ?? [];
    }

    /**
     * @return array<string, mixed>
     * @throws CashPaymentRejected when it doesn't exist, is voided already, or no reason is given
     */
    public function void(int $id, string $reason, ?int $profileId): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new CashPaymentRejected('Say why it is voided.');
        }
        $row = $this->byId($id);
        if ($row === null) {
            throw new CashPaymentRejected('No such cash payment.');
        }
        if ($row['voided_at'] !== null) {
            throw new CashPaymentRejected('It is already voided.');
        }
        $stmt = $this->db->prepare(
            'UPDATE plugin_cash_payments SET voided_at = CURRENT_TIMESTAMP, voided_by_profile_id = :profile, void_reason = :reason
              WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->bindValue(':profile', $profileId, $profileId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':reason', $reason);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', (int) $row['tenant_id'], PDO::PARAM_INT);
        $stmt->execute();

        return $this->byId($id) ?? [];
    }

    /**
     * Whether cash covers a workspace's use of a plugin product now, and until
     * when the last cash payment reaches.
     *
     * @return array{active: bool, until: string, quantity: int}|null null when it never paid cash for it
     */
    public function coverOf(int $tenantId, string $product): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT period_start, period_end, quantity FROM plugin_cash_payments
              WHERE tenant_id = :tenant_id AND plan_key LIKE :product AND voided_at IS NULL
              ORDER BY period_end DESC'
        );
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':product', $product . '.%');
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return null;
        }
        $now = gmdate('Y-m-d H:i:s', ($this->clock)());
        $active = false;
        $quantity = (int) $rows[0]['quantity'];
        foreach ($rows as $row) {
            if ((string) $row['period_start'] <= $now && $now < (string) $row['period_end']) {
                $active = true;
                $quantity = (int) $row['quantity'];
                break;
            }
        }

        return ['active' => $active, 'until' => self::stamp((string) $rows[0]['period_end']), 'quantity' => $quantity];
    }

    /**
     * A workspace's cash payments, newest first, for its billing history and
     * the operator's list.
     *
     * @return list<array<string, mixed>>
     */
    public function forWorkspace(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT c.*, p.display_name AS recorded_by_name FROM plugin_cash_payments c
               LEFT JOIN profiles p ON p.id = c.recorded_by_profile_id
              WHERE c.tenant_id = :tenant_id ORDER BY c.paid_on DESC, c.id DESC'
        );
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->execute();

        return array_values(array_map(static fn (array $row): array => self::shape($row), $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * Payments whose cover ends within $days, with no later payment for the
     * same plugin, whose reminder hasn't gone out yet.
     *
     * @return list<array<string, mixed>>
     */
    public function dueReminders(int $days = 7): array
    {
        $now = ($this->clock)();
        // @tenant-guard-ignore: the operator's reminder sweep reads every workspace's cash payments.
        $stmt = $this->db->prepare(
            'SELECT c.* FROM plugin_cash_payments c
              WHERE c.voided_at IS NULL AND c.reminder_sent_at IS NULL
                AND c.period_end > :now AND c.period_end <= :soon'
        );
        $stmt->bindValue(':now', gmdate('Y-m-d H:i:s', $now));
        $stmt->bindValue(':soon', gmdate('Y-m-d H:i:s', $now + $days * 86400));
        $stmt->execute();
        $due = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cover = $this->coverOf((int) $row['tenant_id'], self::productOf((string) $row['plan_key']));
            if ($cover !== null && $cover['until'] === self::stamp((string) $row['period_end'])) {
                $due[] = self::shape($row);
            }
        }

        return $due;
    }

    public function markReminded(int $tenantId, int $id): void
    {
        $stmt = $this->db->prepare('UPDATE plugin_cash_payments SET reminder_sent_at = CURRENT_TIMESTAMP WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->execute();
    }

    /** `plugin.salons.half_year` belongs to `plugin.salons`. */
    public static function productOf(string $planKey): string
    {
        $cut = strrpos($planKey, '.');

        return $cut === false ? $planKey : substr($planKey, 0, $cut);
    }

    /** @return array<string, mixed>|null */
    public function byId(int $id): ?array
    {
        // @tenant-guard-ignore: the operator voids by id, from the system workspace.
        $stmt = $this->db->prepare('SELECT * FROM plugin_cash_payments WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::shape($row) : null;
    }

    private function monthsOf(string $planKey): int
    {
        $stmt = $this->db->prepare(
            'SELECT p.is_addon, pp.billing_period FROM plans p
               LEFT JOIN plan_prices pp ON pp.plan_id = p.id AND pp.is_active = :on
              WHERE p.plan_key = :plan_key'
        );
        $stmt->bindValue(':on', true, PDO::PARAM_BOOL);
        $stmt->bindValue(':plan_key', $planKey);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === [] || !DbBool::of($rows[0]['is_addon']) || !str_starts_with($planKey, 'plugin.')) {
            throw new CashPaymentRejected('Choose one of the paid plugins\' plans.');
        }
        foreach ($rows as $row) {
            $months = self::MONTHS[(string) ($row['billing_period'] ?? '')] ?? null;
            if ($months !== null) {
                return $months;
            }
        }
        throw new CashPaymentRejected('That plan has no monthly, 6-month or yearly price.');
    }

    private function receiptExists(int $tenantId, string $receipt): bool
    {
        return $this->find($tenantId, $receipt) !== null;
    }

    /** @return array<string, mixed>|null */
    private function find(int $tenantId, string $receipt): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM plugin_cash_payments WHERE tenant_id = :tenant_id AND receipt_number = :receipt');
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':receipt', $receipt);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::shape($row) : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function shape(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'tenant_id' => (int) $row['tenant_id'],
            'plan_key' => (string) $row['plan_key'],
            'quantity' => (int) $row['quantity'],
            'amount_minor' => (int) $row['amount_minor'],
            'currency' => (string) $row['currency'],
            'receipt_number' => (string) $row['receipt_number'],
            'note' => $row['note'] !== null ? (string) $row['note'] : null,
            'paid_on' => substr((string) $row['paid_on'], 0, 10),
            'period_start' => self::stamp((string) $row['period_start']),
            'period_end' => self::stamp((string) $row['period_end']),
            'recorded_by' => isset($row['recorded_by_name']) ? (string) $row['recorded_by_name'] : null,
            'recorded_at' => (string) $row['recorded_at'],
            'voided_at' => $row['voided_at'] !== null ? (string) $row['voided_at'] : null,
            'void_reason' => $row['void_reason'] !== null ? (string) $row['void_reason'] : null,
        ];
    }

    /** Postgres and SQLite give timestamps differently; one shape for both. */
    private static function stamp(string $value): string
    {
        return substr(str_replace('T', ' ', $value), 0, 19);
    }
}
