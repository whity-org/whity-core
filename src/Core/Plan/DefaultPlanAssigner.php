<?php

declare(strict_types=1);

namespace Whity\Core\Plan;

use PDO;
use Whity\Core\Entitlement\EntitlementService;
use Whity\Core\Entitlement\TenantEntitlementRepository;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;

/**
 * Puts a new workspace on the plan named by `billing.default_plan`.
 *
 * With the setting empty, which is the default, this reads one row and does
 * nothing, so an install that does not sell plans behaves as it always has.
 *
 * IT RUNS AFTER THE CREATING TRANSACTION HAS COMMITTED, and is therefore called
 * from each creation path rather than being a
 * {@see \Whity\Core\Tenant\TenantProvisioningStep}. Those steps run inside the
 * creator's transaction, where {@see PlanService::applyToTenant()} cannot open
 * its own, and a step would also run again on every `seed` against a tenant
 * that already exists.
 *
 * A WORKSPACE THAT ALREADY HAS A `tenant_plan` ROW IS LEFT ALONE. That row is
 * how a closed payment gate is written, and the upsert behind applyToTenant
 * would otherwise attach a plan to a workspace that was meant to pay first.
 *
 * IT NEVER THROWS. A retired plan or a mistyped key is the operator's to fix and
 * must not stop somebody creating a workspace, so every failure is logged and
 * the workspace carries on with no plan, as all of them do today.
 */
final class DefaultPlanAssigner
{
    public function __construct(
        private readonly GlobalSettingsRepository $settings,
        private readonly PlanRepository $plans,
        private readonly PlanService $service,
    ) {
    }

    public static function forDatabase(PDO $db): self
    {
        $plans = new PlanRepository($db);

        return new self(
            new GlobalSettingsRepository($db),
            $plans,
            new PlanService($plans, new EntitlementService(new TenantEntitlementRepository($db)), $db),
        );
    }

    public function assign(int $tenantId): void
    {
        try {
            $planKey = trim((string) $this->settings->get(SettingsRegistry::BILLING_DEFAULT_PLAN));
            if ($planKey === '' || $this->plans->getTenantPlan($tenantId) !== null) {
                return;
            }

            $plan = $this->plans->findByKey($planKey);
            if ($plan === null || !$plan['is_active']) {
                error_log(sprintf(
                    '[default-plan] billing.default_plan names "%s", which is %s; tenant %d has no plan',
                    $planKey,
                    $plan === null ? 'not in the catalogue' : 'retired',
                    $tenantId
                ));

                return;
            }

            $this->service->applyToTenant((int) $plan['id'], $tenantId);
        } catch (\Throwable $e) {
            error_log(sprintf('[default-plan] tenant %d was not given a plan: %s', $tenantId, $e->getMessage()));
        }
    }
}
