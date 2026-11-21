<?php

declare(strict_types=1);

namespace Whity\Core\Inventory\Host;

use Whity\Sdk\Context\InventoryScope;
use Whity\Sdk\Context\ServiceUnavailable;
use Whity\Sdk\Inventory\Items;
use Whity\Sdk\Inventory\Locations;
use Whity\Sdk\Inventory\Lots;
use Whity\Sdk\Inventory\Operations;
use Whity\Sdk\Inventory\Orders;
use Whity\Sdk\Inventory\Stock;
use Whity\Sdk\Inventory\Units;
use Whity\Sdk\PluginInterface;

/**
 * `$ctx->inventory()` for one plugin (SDK 1.127, WHIT-1232). Items, places
 * and units are the container's host services; `stock()` is the container's
 * {@see HostStock} bound to this plugin, so its moves carry this plugin's
 * name. Nothing here is kept per request: the services read the workspace
 * from the call each time.
 */
final class HostInventoryScope implements InventoryScope
{
    private ?Stock $stock = null;
    private ?Operations $operations = null;
    private ?Orders $orders = null;

    public function __construct(private readonly PluginInterface $plugin)
    {
    }

    public function items(): Items
    {
        return self::resolve(Items::class);
    }

    public function locations(): Locations
    {
        return self::resolve(Locations::class);
    }

    public function units(): Units
    {
        return self::resolve(Units::class);
    }

    public function lots(): Lots
    {
        return self::resolve(Lots::class);
    }

    public function stock(): Stock
    {
        if ($this->stock === null) {
            $host = self::resolve(Stock::class);
            if (!$host instanceof HostStock) {
                throw ServiceUnavailable::named(Stock::class);
            }
            $this->stock = $host->for($this->plugin);
        }

        return $this->stock;
    }

    public function operations(): Operations
    {
        if ($this->operations === null) {
            $host = self::resolve(Operations::class);
            if (!$host instanceof HostOperations) {
                throw ServiceUnavailable::named(Operations::class);
            }
            $this->operations = $host->for($this->plugin);
        }

        return $this->operations;
    }

    public function orders(): Orders
    {
        if ($this->orders === null) {
            $host = self::resolve(Orders::class);
            if (!$host instanceof HostOrders) {
                throw ServiceUnavailable::named(Orders::class);
            }
            $this->orders = $host->for($this->plugin);
        }

        return $this->orders;
    }

    /**
     * @template T of object
     * @param class-string<T> $service
     * @return T
     */
    private static function resolve(string $service): object
    {
        try {
            $found = \Whity\app($service);
        } catch (\Throwable) {
            throw ServiceUnavailable::named($service);
        }
        if (!$found instanceof $service) {
            throw ServiceUnavailable::named($service);
        }

        return $found;
    }
}
