<?php

declare(strict_types=1);

namespace Whity\Sdk\Inventory;

/**
 * Core's items, for a plugin (SDK 1.127, WHIT-1232, design section 31).
 *
 * A host service, resolved from the container:
 *
 *     $items = \Whity\app(\Whity\Sdk\Inventory\Items::class);
 *     $match = $items?->findByBarcode('6251234567890');
 *
 * WHICH WORKSPACE. The one the call runs in: a route's request, an MCP tool
 * call, a job's own workspace. There is no workspace argument, so a plugin can
 * never reach another workspace's items. Called with no workspace, every
 * method throws {@see InventoryRejected} with reason `no_workspace`.
 *
 * READS are not permission-checked: the plugin is trusted with the
 * workspace's items. Show a person only what their own permissions allow
 * (`inventory:read`).
 *
 * WRITES act as `$actingProfileId` and do exactly what `POST /api/inventory/items`
 * and `PATCH /api/inventory/items/{id}` do: the same validation, the person must
 * be in the workspace and hold `inventory:write`. The plan's item limit
 * (`inventory.items.max`, each variant counted) only warns: an item is never
 * refused for it.
 *
 * `$data` for create and update uses the route's body: `name` (text or
 * `{en, ar}`), `kind`, `unit` (a unit code, the base unit), `sku`, `barcodes`,
 * `sale_price_minor`, `purchase_price_minor`, `currency`, `category_id`,
 * `description`, `tax_category`, `fields`, and `variants` (a list of
 * `{sku, options, barcodes, sale_price_minor}`) for an item with sizes or
 * colours.
 *
 * Absent (null from the container) on a host older than 1.127; check before use.
 */
interface Items
{
    /** The most `list()` returns at once. */
    public const MAX_LIMIT = 200;

    /**
     * Live items by name; `archived: true` for archived ones instead.
     *
     * @param array{q?: string, kind?: string, category_id?: int, archived?: bool} $filter
     * @return list<Item>
     * @throws InventoryRejected
     */
    public function list(array $filter = [], int $limit = 50, int $offset = 0): array;

    /** @throws InventoryRejected */
    public function get(int $itemId): ?Item;

    /**
     * The item one of whose variants has this SKU, exactly.
     *
     * @throws InventoryRejected
     */
    public function findBySku(string $sku): ?Item;

    /**
     * What a scanned barcode means: the item, the variant, and the unit the
     * barcode stands for (a box barcode means a box). Arabic-Indic digits are
     * read as Western ones.
     *
     * @return array{item: Item, variant_id: int, unit: string}|null
     * @throws InventoryRejected
     */
    public function findByBarcode(string $code): ?array;

    /**
     * @param array<string, mixed> $data
     * @throws InventoryRejected
     */
    public function create(array $data, int $actingProfileId): Item;

    /**
     * Fields left out keep their value.
     *
     * @param array<string, mixed> $changes
     * @throws InventoryRejected
     */
    public function update(int $itemId, array $changes, int $actingProfileId): Item;

    /**
     * Archived items leave the lists and can't be moved; their history stays.
     *
     * @throws InventoryRejected
     */
    public function archive(int $itemId, int $actingProfileId): Item;
}
