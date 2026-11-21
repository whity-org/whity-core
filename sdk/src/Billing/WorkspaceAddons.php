<?php

declare(strict_types=1);

namespace Whity\Sdk\Billing;

/**
 * Add-on plans a workspace holds beside its tier (SDK 1.107, WHIT-1125).
 *
 * A host service: resolve it with `\Whity\app(\Whity\Sdk\Billing\WorkspaceAddons::class)`.
 *
 * A billing plugin (Whity Pay) calls it when a workspace's subscription to an
 * add-on starts, renews or ends. An add-on's entitlements stack on the tier's,
 * so a paid plugin's `<plugin>.access` comes from its add-on plan, e.g.
 * `plugin.jofotara.monthly`.
 *
 * Only plans marked as add-ons can be granted: a tier can never be replaced
 * through here. Revoking starts the plugin's 7 days of grace in that
 * workspace; granting again ends it.
 */
interface WorkspaceAddons
{
    /**
     * @param string $planKey An add-on plan's key, e.g. `plugin.jofotara.monthly`.
     * @return bool false when no add-on plan has that key
     */
    public function grant(int $tenantId, string $planKey, int $quantity = 1): bool;

    public function revoke(int $tenantId, string $planKey): void;
}
