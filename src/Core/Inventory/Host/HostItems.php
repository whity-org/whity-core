<?php

declare(strict_types=1);

namespace Whity\Core\Inventory\Host;

use Whity\Core\Inventory\InventoryRejectedException;
use Whity\Core\Inventory\ItemService;
use Whity\Core\RBAC\CorePermissions;
use Whity\Sdk\Inventory\Item;
use Whity\Sdk\Inventory\Items;

/**
 * Core's items for plugins (SDK 1.127, WHIT-1232), in the workspace the call
 * runs in. Reads are trusted; writes act as a person holding
 * `inventory:write` and go through exactly what the routes do, the item limit
 * included. Core's own code calls {@see ItemService} with an explicit
 * workspace instead.
 */
final class HostItems implements Items
{
    public function __construct(
        private readonly ItemService $items,
        private readonly HostInventoryGuard $guard,
    ) {
    }

    public function list(array $filter = [], int $limit = 50, int $offset = 0): array
    {
        $tenantId = $this->guard->workspace();
        $result = HostInventoryGuard::run(fn (): array => $this->items->list($tenantId, [
            'q' => $filter['q'] ?? null,
            'kind' => $filter['kind'] ?? null,
            'category_id' => $filter['category_id'] ?? null,
            'archived' => (bool) ($filter['archived'] ?? false),
        ], max(1, min(self::MAX_LIMIT, $limit)), max(0, $offset)));

        return array_map(static fn (array $row): Item => Item::fromArray($row), $result['data']);
    }

    public function get(int $itemId): ?Item
    {
        $tenantId = $this->guard->workspace();
        if ($itemId <= 0) {
            return null;
        }
        try {
            return Item::fromArray($this->items->show($tenantId, $itemId));
        } catch (InventoryRejectedException $e) {
            if ($e->status === 404) {
                return null;
            }

            return HostInventoryGuard::run(static fn (): Item => throw $e);
        }
    }

    public function findBySku(string $sku): ?Item
    {
        $tenantId = $this->guard->workspace();
        if (trim($sku) === '') {
            return null;
        }
        $id = $this->items->itemIdBySku($tenantId, $sku);

        return $id === null ? null : $this->get($id);
    }

    public function findByBarcode(string $code): ?array
    {
        $hit = $this->items->lookup($this->guard->workspace(), $code);

        return $hit === null ? null : ['item' => Item::fromArray($hit['item']), 'variant_id' => $hit['variant_id'], 'unit' => $hit['unit']];
    }

    public function create(array $data, int $actingProfileId): Item
    {
        $tenantId = $this->guard->workspace();
        $can = $this->guard->writer($tenantId, $actingProfileId, CorePermissions::INVENTORY_WRITE);

        return Item::fromArray(HostInventoryGuard::run(fn (): array => $this->items->create($tenantId, $data, $actingProfileId, $can)));
    }

    public function update(int $itemId, array $changes, int $actingProfileId): Item
    {
        $tenantId = $this->guard->workspace();
        $can = $this->guard->writer($tenantId, $actingProfileId, CorePermissions::INVENTORY_WRITE);

        return Item::fromArray(HostInventoryGuard::run(fn (): array => $this->items->update($tenantId, $itemId, $changes, $actingProfileId, $can)));
    }

    public function archive(int $itemId, int $actingProfileId): Item
    {
        $tenantId = $this->guard->workspace();
        $this->guard->writer($tenantId, $actingProfileId, CorePermissions::INVENTORY_WRITE);

        return Item::fromArray(HostInventoryGuard::run(fn (): array => $this->items->archive($tenantId, $itemId, true, $actingProfileId)));
    }
}
