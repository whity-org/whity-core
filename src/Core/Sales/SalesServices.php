<?php

declare(strict_types=1);

namespace Whity\Core\Sales;

use PDO;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Inventory\InventoryDb;
use Whity\Core\Inventory\InventoryWorkspace;
use Whity\Core\Inventory\ItemService;
use Whity\Core\Inventory\UnitCatalog;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Database\SequenceCounters;

/**
 * Builds {@see Quotes} where the request kernel's wiring isn't there: the
 * queue worker's expiry job (WHIT-1321).
 */
final class SalesServices
{
    public static function quotes(PDO $pdo, SettingsService $settings, ?HookManager $hooks, ?QuoteConversions $conversions = null): Quotes
    {
        $db = new InventoryDb($pdo);
        $workspace = new InventoryWorkspace($db);
        $units = new UnitCatalog($db, $workspace);
        $currency = static function (int $tenantId) use ($settings): string {
            $value = $settings->effective($tenantId)[SettingsRegistry::BILLING_DEFAULT_CURRENCY] ?? null;

            return is_string($value) && preg_match('/^[A-Z]{3}$/', $value) === 1 ? $value : 'USD';
        };

        return new Quotes(
            $db,
            $workspace,
            new ItemService($db, $workspace, $units, $currency, null, $hooks),
            $units,
            new SequenceCounters($pdo),
            $conversions ?? new QuoteConversions(),
            static fn (int $tenantId): array => $settings->effective($tenantId),
            $currency,
            $hooks,
        );
    }
}
