<?php

declare(strict_types=1);

namespace Whity\Sdk\Context;

use Whity\Sdk\Inventory\Items;
use Whity\Sdk\Inventory\Locations;
use Whity\Sdk\Inventory\Lots;
use Whity\Sdk\Inventory\Operations;
use Whity\Sdk\Inventory\Orders;
use Whity\Sdk\Inventory\Stock;
use Whity\Sdk\Inventory\Units;

/**
 * Core's inventory for one plugin, in the workspace the call runs in (SDK
 * 1.127, WHIT-1232): `$ctx->inventory()`.
 *
 * `stock()` is bound to the plugin the loader loaded: the moves it posts are
 * stored under that plugin's name (`pos.sale`), never under a name the plugin
 * passes. It is the only way a plugin writes stock; the `Stock` in the
 * container reads, and refuses a write with reason `use_plugin_context`.
 *
 * Each accessor throws {@see ServiceUnavailable} on a host without inventory.
 */
interface InventoryScope
{
    public function items(): Items;

    public function locations(): Locations;

    public function stock(): Stock;

    public function units(): Units;

    /**
     * Adjustments, counts and transfers, bound to the plugin like `stock()`
     * (SDK 1.128, WHIT-1233).
     */
    public function operations(): Operations;

    /**
     * Purchase orders, bound to the plugin like `stock()` (SDK 1.129,
     * WHIT-1234).
     */
    public function orders(): Orders;

    /**
     * Batches, serial numbers and what is expiring (SDK 1.134, WHIT-1235).
     * Reads only.
     */
    public function lots(): Lots;
}
