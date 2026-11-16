<?php

declare(strict_types=1);

namespace Whity\Core\Plugin\Workspace;

use PDO;
use Whity\Core\Entitlement\EntitlementRegistry;

/**
 * A paid plugin's add-on plan lifts the workspace's limits WHILE THE PLUGIN IS
 * ON there, however it is on: bought online, paid in cash, on its free trial,
 * in grace, or free for every workspace (WHIT-1156).
 *
 * Owner, 2026-11-16, on Whity for Salons: "no need for the 5 limit if they pay
 * by chair". Salons is priced per chair, so its add-on plan rows on the Plans
 * screen carry `members.max = -1`. Bought online, that row is an add-on pack
 * the workspace holds, and {@see \Whity\Core\Entitlement\EntitlementService}
 * already stacks it. A trial or a cash payment holds no add-on row, so this
 * gives the same values as a pack of one for as long as the plugin is on.
 * Nothing here knows about salons: any paid plugin's add-on plan works the
 * same way, and the operator decides what it lifts.
 *
 * - ONLY NUMBER LIMITS. A flag on the plan (the plugin's own access
 *   entitlement above all) is never granted this way: a plugin that is on
 *   because of a trial must not become "entitled" by its own trial and stay on
 *   for good.
 * - NO DOUBLE COUNT. A workspace that holds the add-on already has its pack.
 * - WHEN THE PLUGIN GOES READ-ONLY OR OFF, the lift ends. Nobody is removed:
 *   seat limits only ever refuse the next addition.
 */
final class PluginLimitPacks
{
    /**
     * @param \Closure(): array<string, array{entitlement: string, product: string, trialDays: int}> $paidAccess
     */
    public function __construct(
        private readonly PDO $db,
        private readonly CodePluginActivation $activation,
        private readonly \Closure $paidAccess,
    ) {
    }

    /**
     * The packs to stack for this workspace now.
     *
     * @return list<array{quantity: int, values: array<string, string>}>
     */
    public function packsFor(int $tenantId): array
    {
        $packs = [];
        foreach (($this->paidAccess)() as $plugin => $access) {
            $product = $access['product'];
            if ($product === '' || $this->activation->stateFor($plugin, $tenantId)['state'] !== CodePluginActivation::STATE_ON) {
                continue;
            }
            if ($this->holdsAddon($tenantId, $product)) {
                continue;
            }
            $values = $this->limitsOf($product);
            if ($values !== []) {
                $packs[] = ['quantity' => 1, 'values' => $values];
            }
        }

        return $packs;
    }

    private function holdsAddon(int $tenantId, string $product): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM tenant_plan_addons ta JOIN plans p ON p.id = ta.plan_id
              WHERE ta.tenant_id = :tenant_id AND ta.quantity > 0 AND p.plan_key LIKE :product LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':product' => self::like($product)]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * The number limits the product's active add-on plans set, the most
     * generous of each when its monthly and yearly rows differ.
     *
     * @return array<string, string>
     */
    private function limitsOf(string $product): array
    {
        $stmt = $this->db->prepare(
            'SELECT pe.entitlement_key, pe.value
               FROM plans p JOIN plan_entitlements pe ON pe.plan_id = p.id
              WHERE p.plan_key LIKE :product AND p.is_active = :active AND p.is_addon = :addon'
        );
        $stmt->bindValue(':product', self::like($product));
        $stmt->bindValue(':active', true, PDO::PARAM_BOOL);
        $stmt->bindValue(':addon', true, PDO::PARAM_BOOL);
        $stmt->execute();

        $best = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = (string) $row['entitlement_key'];
            $raw = (string) $row['value'];
            try {
                if (EntitlementRegistry::typeFor($key) !== 'int' || EntitlementRegistry::validate($key, $raw) !== null) {
                    continue;
                }
            } catch (\InvalidArgumentException) {
                continue; // a key nobody declares any more
            }
            $value = (int) $raw;
            $current = isset($best[$key]) ? (int) $best[$key] : null;
            if ($current === null || $current !== EntitlementRegistry::UNLIMITED && ($value === EntitlementRegistry::UNLIMITED || $value > $current)) {
                $best[$key] = (string) $value;
            }
        }

        return $best;
    }

    /** `plugin.salons` → `plugin.salons.%`: its plans, by the add-on key convention. */
    private static function like(string $product): string
    {
        return $product . '.%';
    }
}
