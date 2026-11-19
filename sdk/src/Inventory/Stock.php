<?php

declare(strict_types=1);

namespace Whity\Sdk\Inventory;

/**
 * Core's stock, for a plugin (SDK 1.127, WHIT-1232, register IV.2).
 *
 * Reach it through the plugin context: `$ctx->inventory()->stock()`. That
 * one is bound to your plugin, so the moves it posts are stored under your
 * plugin's name and no plugin can post as another. The `Stock` in the
 * container (`\Whity\app(Stock::class)`) reads only: its `move()` and
 * `reverse()` refuse with {@see InventoryRejected} reason `use_plugin_context`.
 *
 * It works in the workspace the call runs in, like {@see Items}; with none, it
 * throws {@see InventoryRejected} `no_workspace`.
 *
 * THE LEDGER. Every change in stock is a move from one place to another, and
 * the moves are never changed: a sale is `shop → customer` (the workspace's
 * virtual customer place), waste is `shop → loss`, a delivery from a supplier
 * is `supplier → warehouse`. Levels are what the moves add up to. To undo, call
 * {@see self::reverse()}, which posts the same moves the other way.
 *
 * WRITES act as `MoveRequest::$actingProfileId`, who must be in the workspace
 * and hold `stock:adjust`, and run the `stock.moving` hook, where another
 * plugin or a workspace rule may refuse them. After posting, `stock.moved`
 * fires, and `stock.moved.async` once the transaction has committed.
 */
interface Stock
{
    /**
     * Stock now, in each item's base unit; real places only.
     *
     * @param array{variant_id?: int, item_id?: int, location_id?: int, nonzero?: bool} $filter
     * @return list<StockLevel>
     * @throws InventoryRejected
     */
    public function levels(array $filter = []): array;

    /**
     * On hand minus reserved, for one variant in one place, in its base unit.
     *
     * @throws InventoryRejected
     */
    public function available(int $variantId, int $locationId): Quantity;

    /**
     * The ledger, newest first. `before_id` pages back from a move.
     *
     * @param array{variant_id?: int, item_id?: int, location_id?: int, source_type?: string, source_id?: int, batch_key?: string, before_id?: int} $filter
     * @return list<Move>
     * @throws InventoryRejected
     */
    public function history(array $filter = [], int $limit = 50): array;

    /**
     * Posts the request's lines together, or none of them, under the bound
     * plugin's name (`sale` is stored as `<plugin>.sale`).
     *
     * @return list<Move> the moves posted (or, for a repeated idempotency key, posted the first time)
     * @throws InventoryRejected
     */
    public function move(MoveRequest $request): array;

    /**
     * Posts every move of a batch the other way, linked to the original. A
     * batch can be reversed once.
     *
     * @return list<Move> the reversing moves
     * @throws InventoryRejected
     */
    public function reverse(string $batchKey, int $actingProfileId, ?string $note = null): array;
}
