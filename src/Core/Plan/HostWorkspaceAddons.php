<?php

declare(strict_types=1);

namespace Whity\Core\Plan;

use PDO;
use Whity\Core\Audit\AuditLoggerInterface;
use Whity\Core\Plugin\Workspace\CodePluginActivation;
use Whity\Sdk\Billing\WorkspaceAddons;

/**
 * The host's {@see WorkspaceAddons} (SDK 1.107, WHIT-1125): a billing plugin
 * grants and revokes a workspace's add-on plans here.
 *
 * Only a plan marked `is_addon` is ever written, so a tier can't be replaced
 * this way. An add-on keyed `plugin.<slug>.<plan>` belongs to the product
 * `plugin.<slug>`: revoking it starts the 7 days of grace for the paid plugin
 * sold as that product in the workspace, and granting it ends them.
 */
final class HostWorkspaceAddons implements WorkspaceAddons
{
    public const AUDIT_GRANTED = 'plan.addon_granted';
    public const AUDIT_REVOKED = 'plan.addon_revoked';

    public function __construct(
        private readonly PDO $db,
        private readonly CodePluginActivation $activation,
        private readonly ?AuditLoggerInterface $audit = null,
    ) {
    }

    public function grant(int $tenantId, string $planKey, int $quantity = 1): bool
    {
        $plans = new PlanRepository($this->db);
        $plan = $plans->findByKey($planKey);
        if ($plan === null || !$plans->isAddon((int) $plan['id']) || $tenantId <= 0) {
            return false;
        }

        $plans->setTenantAddon($tenantId, (int) $plan['id'], max(1, $quantity), null);
        $this->activation->markLapsed($tenantId, self::productOf($planKey), false);
        $this->audit?->record(self::AUDIT_GRANTED, [
            'tenant_id' => $tenantId,
            'target_type' => 'plan',
            'target_id' => (int) $plan['id'],
            'metadata' => ['plan' => $planKey, 'quantity' => max(1, $quantity)],
        ]);

        return true;
    }

    public function revoke(int $tenantId, string $planKey): void
    {
        $plans = new PlanRepository($this->db);
        $plan = $plans->findByKey($planKey);
        if ($plan === null || !$plans->isAddon((int) $plan['id']) || $tenantId <= 0) {
            return;
        }

        $removed = $plans->removeTenantAddon($tenantId, (int) $plan['id']);
        $this->activation->markLapsed($tenantId, self::productOf($planKey), true);
        if ($removed > 0) {
            $this->audit?->record(self::AUDIT_REVOKED, [
                'tenant_id' => $tenantId,
                'target_type' => 'plan',
                'target_id' => (int) $plan['id'],
                'metadata' => ['plan' => $planKey],
            ]);
        }
    }

    /** `plugin.jofotara.monthly` is the product `plugin.jofotara`. */
    public static function productOf(string $planKey): string
    {
        $cut = strrpos($planKey, '.');

        return $cut === false ? $planKey : substr($planKey, 0, $cut);
    }
}
