<?php

declare(strict_types=1);

namespace Whity\Core\Inventory\Host;

use Whity\Core\Inventory\InventoryRejectedException;
use Whity\Core\Inventory\ItemService;
use Whity\Core\Inventory\UnitCatalog;
use Whity\Sdk\Inventory\Quantity;
use Whity\Sdk\Inventory\Unit;
use Whity\Sdk\Inventory\Units;

/** The workspace's units and exact conversions, for plugins (SDK 1.126, WHIT-1232). */
final class HostUnits implements Units
{
    public function __construct(
        private readonly UnitCatalog $units,
        private readonly ItemService $items,
        private readonly HostInventoryGuard $guard,
    ) {
    }

    public function list(): array
    {
        return array_map(static fn (array $row): Unit => Unit::fromArray($row), $this->units->list($this->guard->workspace()));
    }

    public function convert(string $quantity, string $fromUnit, string $toUnit, ?int $itemId = null): string
    {
        $tenantId = $this->guard->workspace();

        return HostInventoryGuard::run(function () use ($tenantId, $quantity, $fromUnit, $toUnit, $itemId): string {
            $target = $this->units->rowByCode($tenantId, $toUnit) ?? throw InventoryRejectedException::field('toUnit', 'There is no unit "' . $toUnit . '"');
            $item = $itemId === null ? null : ($this->items->row($tenantId, $itemId) ?? throw InventoryRejectedException::notFound('Item'));
            // Convert through the target unit as if it were the base: the
            // same exact fraction arithmetic posting uses.
            $converted = $this->units->toBaseSteps($tenantId, $item === null ? 0 : (int) $item['id'], $target, $quantity, $fromUnit, 'quantity');

            return (new Quantity($converted['steps'], (int) $target['decimals']))->toDecimal();
        });
    }
}
