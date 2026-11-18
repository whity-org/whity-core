<?php

declare(strict_types=1);

namespace Whity\Core\Billing;

use PDO;
use Whity\Core\Plan\PlanPriceRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Subscription\SubscriptionService;

/**
 * Where a workspace stands with its plan's trial and payment (WHIT-1207).
 *
 * Owner 2026-11-18: a further workspace a person creates (WHIT-1203) asks for a
 * card straight away, keeping the 30-day trial free and charging on day 30;
 * "Not now" keeps the trial and the workspace turns read-only when it ends
 * unpaid. Read-only and nearly-ended workspaces show a banner with Pay, and a
 * reminder from 7 days before the trial ends.
 *
 * One reader for the three places that need it: the payment step after
 * creating a workspace, the banner across the app, and checkout, which uses
 * {@see trialDaysForCheckout()} so adding a card never cuts a trial short.
 * Everything comes from this deployment's own `tenant_plan` row, never from
 * the caller.
 */
final class WorkspaceTrial
{
    /** The reminder shows from this many days before the trial ends (owner 2026-11-18). */
    public const REMINDER_DAYS = 7;

    /** The price shown is by the month (CU.1-4), as on the create screen. */
    private const QUOTED_PERIOD = PlanPriceRepository::PERIOD_MONTH;

    /** @var callable(): int */
    private $clock;

    /**
     * @param (callable(): int)|null $clock Unix-timestamp source (for tests); defaults to time().
     */
    public function __construct(
        private readonly PDO $db,
        private readonly SubscriptionService $subscriptions,
        private readonly SettingsService $settings,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Null when the workspace has no billed plan (the default plan, self-hosted).
     *
     * @return array{
     *   plan_key: string, plan_name: string, status: string,
     *   trial_ends_at: string|null, days_left: int|null,
     *   read_only: bool, card_added: bool,
     *   price: array{unit_amount: int, currency: string, billing_period: string}|null
     * }|null
     */
    public function stateFor(int $tenantId): ?array
    {
        $row = $this->row($tenantId);
        if ($row === null) {
            return null;
        }

        $trialEnd = $this->trialEnd($row);

        return [
            'plan_key'      => (string) $row['plan_key'],
            'plan_name'     => (string) $row['plan_name'],
            'status'        => (string) $row['status'],
            'trial_ends_at' => $trialEnd === null ? null : gmdate('Y-m-d\TH:i:s\Z', $trialEnd),
            'days_left'     => $trialEnd === null ? null : self::daysUntil($trialEnd, $this->now()),
            'read_only'     => !$this->subscriptions->decide($tenantId, true)->allowed,
            'card_added'    => self::hasExternalRef($row),
            'price'         => $this->monthlyPrice((int) $row['plan_id']),
        ];
    }

    /**
     * How many days of trial a checkout for $planKey should keep, or null for
     * an ordinary checkout charged now.
     *
     * Only for the workspace's OWN plan, while its trial is still running and
     * before a card is on file: a workspace buying another plan, or paying
     * after its trial ended, is charged now. Never less than 1.
     */
    public function trialDaysForCheckout(int $tenantId, string $planKey): ?int
    {
        $row = $this->row($tenantId);
        if ($row === null || (string) $row['plan_key'] !== $planKey || self::hasExternalRef($row)) {
            return null;
        }
        $trialEnd = $this->trialEnd($row);
        if ($trialEnd === null || $trialEnd <= $this->now()) {
            return null;
        }

        return max(1, self::daysUntil($trialEnd, $this->now()));
    }

    /** Whole days left, rounded up, so "ends tomorrow evening" is 2 and never 0 while it runs. */
    private static function daysUntil(int $end, int $now): int
    {
        return max(0, (int) ceil(($end - $now) / 86400));
    }

    /** @param array<string, mixed> $row */
    private function trialEnd(array $row): ?int
    {
        if ((string) $row['status'] !== SubscriptionService::STATUS_TRIALING) {
            return null;
        }
        $ends = $row['current_period_end'] ?? null;
        if (!is_string($ends) || $ends === '') {
            return null;
        }
        $at = strtotime($ends);

        return $at === false ? null : $at;
    }

    /** @param array<string, mixed> $row */
    private static function hasExternalRef(array $row): bool
    {
        $ref = $row['external_ref'] ?? null;

        return is_string($ref) && $ref !== '';
    }

    /** @return array<string, mixed>|null */
    private function row(int $tenantId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT tp.plan_id, tp.status, tp.current_period_end, tp.external_ref, p.plan_key, p.name AS plan_name
               FROM tenant_plan tp
               JOIN plans p ON p.id = tp.plan_id
              WHERE tp.tenant_id = :tenant_id'
        );
        $statement->execute([':tenant_id' => $tenantId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !is_string($row['status'] ?? null) || $row['status'] === '') {
            return null;
        }

        return $row;
    }

    /** @return array{unit_amount: int, currency: string, billing_period: string}|null */
    private function monthlyPrice(int $planId): ?array
    {
        $currency = trim((string) ($this->settings->getGlobal()[SettingsRegistry::BILLING_DEFAULT_CURRENCY] ?? ''));
        if ($currency === '') {
            return null;
        }
        $row = (new PlanPriceRepository($this->db))->findActive($planId, $currency, self::QUOTED_PERIOD);
        if ($row === null) {
            return null;
        }

        return [
            'unit_amount'    => (int) $row['unit_amount'],
            'currency'       => (string) $row['currency'],
            'billing_period' => (string) $row['billing_period'],
        ];
    }

    private function now(): int
    {
        return ($this->clock)();
    }
}
