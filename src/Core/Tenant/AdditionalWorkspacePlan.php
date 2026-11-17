<?php

declare(strict_types=1);

namespace Whity\Core\Tenant;

use PDO;
use Whity\Core\Plan\PlanPriceRepository;
use Whity\Core\Plan\PlanRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Subscription\SubscriptionService;

/**
 * "Each new workspace pays" (WHIT-1203, owner 2026-11-17, Slack-style).
 *
 * Owning more than one workspace is what is paid. A person's FIRST owned
 * workspace starts on `billing.default_plan` (Free on app.whity.dev); every
 * further one they create starts on `workspaces.additional_plan` (Team there)
 * in a trial of `workspaces.additional_trial_days`, and when the trial ends
 * unpaid it is behind the payment gate (`block_all`), the same gate sign-up
 * uses when payment is required. Being invited into workspaces is never
 * counted: only {@see WorkspaceOwners} rows are, so a co-owned workspace
 * counts for each of its owners.
 *
 * With the setting empty (the default) nothing here applies and every
 * workspace starts on the default plan, so a self-hosted install, which pays
 * only for paid plugins (BM.3), is unchanged. A plan key that is missing,
 * retired or an add-on falls back to the default plan, logged: a mistyped
 * setting must never stop somebody creating a workspace, nor quietly bill it.
 */
final class AdditionalWorkspacePlan
{
    /** The price the create screen quotes: flat, by the month (CU.1-4). */
    private const QUOTED_PERIOD = PlanPriceRepository::PERIOD_MONTH;

    /** @var callable(): int */
    private $clock;

    /**
     * @param (callable(): int)|null $clock Unix-timestamp source (for tests); defaults to time().
     */
    public function __construct(
        private readonly PDO $db,
        private readonly SettingsService $settings,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    private function now(): int
    {
        return ($this->clock)();
    }

    /**
     * The paid plan a workspace starts on when its creator already owns
     * $ownedCount workspaces, or null when it starts on the default plan.
     *
     * @return array{plan_id: int, plan_key: string, plan_name: string, trial_days: int}|null
     */
    public function forOwnedCount(int $ownedCount): ?array
    {
        if ($ownedCount < 1) {
            return null;
        }
        $global = $this->settings->getGlobal();
        $key = trim((string) ($global[SettingsRegistry::WORKSPACES_ADDITIONAL_PLAN] ?? ''));
        if ($key === '') {
            return null;
        }

        $plans = new PlanRepository($this->db);
        $plan = $plans->findByKey($key);
        if ($plan === null || !$plan['is_active'] || $plans->isAddon((int) $plan['id'])) {
            error_log(sprintf(
                '[workspace-create] workspaces.additional_plan names "%s", which is %s; the workspace starts on the default plan',
                $key,
                $plan === null ? 'not in the catalogue' : (!$plan['is_active'] ? 'retired' : 'an add-on'),
            ));
            return null;
        }

        return [
            'plan_id'    => (int) $plan['id'],
            'plan_key'   => (string) $plan['plan_key'],
            'plan_name'  => (string) $plan['name'],
            'trial_days' => self::trialDays($global),
        ];
    }

    /**
     * What the create screen tells the person before they create it: null for
     * the default plan, else the plan, the trial and its monthly price in the
     * installation's currency (null when the plan has no such price yet).
     *
     * @return array{plan_key: string, plan_name: string, trial_days: int,
     *               price: array{unit_amount: int, currency: string, billing_period: string}|null}|null
     */
    public function noticeFor(int $ownedCount): ?array
    {
        $plan = $this->forOwnedCount($ownedCount);
        if ($plan === null) {
            return null;
        }
        $currency = trim((string) ($this->settings->getGlobal()[SettingsRegistry::BILLING_DEFAULT_CURRENCY] ?? ''));
        $price = null;
        if ($currency !== '') {
            $row = (new PlanPriceRepository($this->db))->findActive($plan['plan_id'], $currency, self::QUOTED_PERIOD);
            if ($row !== null) {
                $price = [
                    'unit_amount'    => (int) $row['unit_amount'],
                    'currency'       => (string) $row['currency'],
                    'billing_period' => (string) $row['billing_period'],
                ];
            }
        }

        return [
            'plan_key'   => $plan['plan_key'],
            'plan_name'  => $plan['plan_name'],
            'trial_days' => $plan['trial_days'],
            'price'      => $price,
        ];
    }

    /**
     * Put a just-founded workspace on the paid plan: in a trial that ends
     * $trialDays from now, or, with no trial, straight behind the payment gate.
     * Either way enforcement is `block_all`, so an unpaid workspace is closed
     * once the trial is over. Must run inside the founder's transaction, so the
     * workspace and its bill are one act or neither.
     *
     * @param array{plan_id: int, plan_key: string, plan_name: string, trial_days: int} $plan
     */
    public function start(int $tenantId, array $plan, int $assignedBy): void
    {
        if (!$this->db->inTransaction()) {
            throw new \LogicException('AdditionalWorkspacePlan::start() must run inside the founder\'s transaction.');
        }
        $trialDays = $plan['trial_days'];
        $periodEnd = $trialDays > 0
            ? date('Y-m-d H:i:s', $this->now() + $trialDays * 86400)
            : null;

        // A brand-new tenant has no per-tenant entitlement copies to reset, so
        // writing plan_id here is what PlanService::applyToTenant() would do.
        $this->db->prepare(
            'INSERT INTO tenant_plan (tenant_id, plan_id, assigned_by, assigned_at, status, current_period_end, enforcement_mode)
             VALUES (:tenant_id, :plan_id, :assigned_by, NOW(), :status, :period_end, :mode)'
        )->execute([
            ':tenant_id'   => $tenantId,
            ':plan_id'     => $plan['plan_id'],
            ':assigned_by' => $assignedBy,
            ':status'      => $trialDays > 0 ? SubscriptionService::STATUS_TRIALING : SubscriptionService::STATUS_EXPIRED,
            ':period_end'  => $periodEnd,
            ':mode'        => SubscriptionService::MODE_BLOCK_ALL,
        ]);
    }

    /** @param array<string, mixed> $global */
    private static function trialDays(array $global): int
    {
        $raw = (string) ($global[SettingsRegistry::WORKSPACES_ADDITIONAL_TRIAL_DAYS] ?? '14');
        return preg_match('/^\d+$/', $raw) === 1 ? min(90, (int) $raw) : 14;
    }
}
