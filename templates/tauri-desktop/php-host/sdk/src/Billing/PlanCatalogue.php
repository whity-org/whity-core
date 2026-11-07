<?php

declare(strict_types=1);

namespace Whity\Sdk\Billing;

/**
 * The host's plan catalogue, read-only (SDK 1.84).
 *
 * Resolve it from the container:
 *
 *     $plans = \Whity\app(\Whity\Sdk\Billing\PlanCatalogue::class);
 *     $terms = $plans->activePrice('whity-builder.pro', 'month', 'JOD');
 *
 * A billing plugin uses it to price a plan the way the host prices it,
 * without reading the host's tables. It can only read. Plans and prices are
 * the operator's, changed through the host's own screens.
 */
interface PlanCatalogue
{
    /**
     * The ACTIVE price of an active plan for this period and currency, or null
     * when the host does not sell it on those terms.
     */
    public function activePrice(string $planKey, string $period, string $currency): ?PlanPriceTerms;

    /** A price by the host's id, active or not, for reading back what was bought. */
    public function price(int $priceId): ?PlanPriceTerms;

    /** Whether the plan is sold beside a workspace's tier rather than as one. */
    public function isAddon(string $planKey): bool;

    /**
     * Every active price of every active ADD-ON plan, ordered by plan key,
     * period and currency: what can be bought beside a tier, such as products
     * keyed `<product>.<plan>`.
     *
     * @return list<PlanPriceTerms>
     */
    public function addonPrices(): array;
}
