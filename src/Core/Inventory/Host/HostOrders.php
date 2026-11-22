<?php

declare(strict_types=1);

namespace Whity\Core\Inventory\Host;

use Whity\Core\Inventory\PurchaseOrders;
use Whity\Core\Plugin\PluginIdentity;
use Whity\Core\RBAC\CorePermissions;
use Whity\Sdk\Inventory\InventoryRejected;
use Whity\Sdk\Inventory\Operation;
use Whity\Sdk\Inventory\Order;
use Whity\Sdk\Inventory\Orders;
use Whity\Sdk\PluginInterface;

/**
 * Purchase orders for plugins (SDK 1.129, WHIT-1234), in the workspace the
 * call runs in. Bound like {@see HostOperations}: the container's copy reads;
 * {@see self::for()} gives a copy acting as a loaded plugin, handed out as
 * `$ctx->inventory()->orders()`.
 */
final class HostOrders implements Orders
{
    public function __construct(
        private readonly PurchaseOrders $orders,
        private readonly HostInventoryGuard $guard,
        /** The loaded plugin's slug this copy acts as, or null for the read-only container copy. */
        private readonly ?string $plugin = null,
    ) {
    }

    /** @throws InventoryRejected when the loader never loaded that object */
    public function for(PluginInterface $plugin): self
    {
        try {
            $slug = PluginIdentity::slugOf($plugin);
        } catch (\Throwable) {
            throw new InventoryRejected('Only a loaded plugin can order stock.', 403, null, InventoryRejected::FORBIDDEN);
        }

        return new self($this->orders, $this->guard, $slug);
    }

    public function list(array $filter = [], int $limit = 50, int $offset = 0): array
    {
        $tenantId = $this->guard->workspace();
        $rows = HostInventoryGuard::run(fn (): array => $this->orders->list($tenantId, [
            'status' => $filter['status'] ?? null,
            'contact_id' => $filter['contact_id'] ?? null,
            'location_id' => $filter['location_id'] ?? null,
        ], $limit, $offset));

        return array_map(static fn (array $row): Order => Order::fromArray($row), $rows);
    }

    public function get(int $orderId): Order
    {
        $tenantId = $this->guard->workspace();

        return Order::fromArray(HostInventoryGuard::run(fn (): array => $this->orders->get($tenantId, $orderId)));
    }

    public function createPurchase(int $supplierContactId, int $locationId, array $lines, int $actingProfileId, array $options = []): Order
    {
        [$tenantId, $plugin] = $this->writer($actingProfileId);
        $key = $options['idempotency_key'] ?? null;
        $result = HostInventoryGuard::run(fn (): array => $this->orders->create($tenantId, $actingProfileId, [
            'contact_id' => $supplierContactId,
            'location_id' => $locationId,
            'lines' => $lines,
            'expected_on' => $options['expected_on'] ?? null,
            'currency' => $options['currency'] ?? null,
            'notes' => $options['notes'] ?? '',
            'external_ref' => $options['external_ref'] ?? '',
        ], [
            'idempotency_key' => is_string($key) && $key !== '' ? $plugin . ':' . $key : null,
            'source_plugin' => $plugin,
        ]));

        return Order::fromArray($result['order']);
    }

    public function submit(int $orderId, int $actingProfileId): Order
    {
        [$tenantId] = $this->writer($actingProfileId);

        return Order::fromArray(HostInventoryGuard::run(fn (): array => $this->orders->submit($tenantId, $orderId, $actingProfileId)));
    }

    public function receive(int $orderId, array $lines, int $actingProfileId, bool $allowOver = false, array $options = []): Operation
    {
        [$tenantId, $plugin] = $this->writer($actingProfileId);
        $key = $options['idempotency_key'] ?? null;
        $result = HostInventoryGuard::run(fn (): array => $this->orders->receive(
            $tenantId,
            $orderId,
            $actingProfileId,
            $lines,
            $allowOver,
            is_string($options['note'] ?? null) ? $options['note'] : '',
            null,
            ['idempotency_key' => is_string($key) && $key !== '' ? $plugin . ':' . $key : null, 'source_plugin' => $plugin],
        ));

        return Operation::fromArray($result['receipt']);
    }

    public function cancel(int $orderId, int $actingProfileId): Order
    {
        [$tenantId] = $this->writer($actingProfileId);

        return Order::fromArray(HostInventoryGuard::run(fn (): array => $this->orders->cancel($tenantId, $orderId, $actingProfileId)));
    }

    /**
     * @return array{0: int, 1: string}
     * @throws InventoryRejected
     */
    private function writer(int $actingProfileId): array
    {
        if ($this->plugin === null) {
            throw new InventoryRejected(
                'Orders are changed through the plugin context: $ctx->inventory()->orders().',
                403,
                null,
                InventoryRejected::USE_PLUGIN_CONTEXT,
            );
        }
        $tenantId = $this->guard->workspace();
        $this->guard->writer($tenantId, $actingProfileId, CorePermissions::PURCHASES_WRITE);

        return [$tenantId, $this->plugin];
    }
}
