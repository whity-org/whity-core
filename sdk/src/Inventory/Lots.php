<?php

declare(strict_types=1);

namespace Whity\Sdk\Inventory;

/**
 * Core's batches and serial numbers, for a plugin (SDK 1.134, WHIT-1235,
 * design 31 §3.5). Reach it through `$ctx->inventory()->lots()`, or the copy
 * in the container (`\Whity\app(Lots::class)`); both only read. It works in
 * the workspace the call runs in; with none, it throws
 * {@see InventoryRejected} `no_workspace`.
 *
 * An item keeps batches or serial numbers when its `tracking` is `lot` or
 * `serial`. Stock that comes in names its lot on the {@see MoveLine}
 * (`lotCode`, with `expiresOn` when the item expires); stock that leaves may
 * name one, or core takes the lots that expire first (the workspace's FEFO
 * setting, on by default). The daily job fires `stock.lot.expiring` within
 * the workspace's warning window and `stock.lot.expired` the day after a lot
 * expires, each once.
 */
interface Lots
{
    /** @throws InventoryRejected `not_found` when it isn't a lot of this workspace */
    public function get(int $lotId): Lot;

    /** @throws InventoryRejected */
    public function findByCode(int $variantId, string $code): ?Lot;

    /**
     * Lots with stock that expire within `$days` days (the workspace's
     * warning window when null), or already have, soonest first.
     *
     * @return list<Lot>
     * @throws InventoryRejected
     */
    public function expiring(?int $days = null): array;
}
