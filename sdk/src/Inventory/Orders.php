<?php

declare(strict_types=1);

namespace Whity\Sdk\Inventory;

/**
 * Purchase orders, for a plugin (SDK 1.129, WHIT-1234, register IV.12); sales
 * orders join with core's sales.
 *
 * Reach it through the plugin context: `$ctx->inventory()->orders()`, bound
 * to your plugin like `operations()`: what it drafts carries your plugin's
 * name (`source_plugin`) and its idempotency keys are kept apart from every
 * other plugin's. The `Orders` in the container reads only, and refuses a
 * write with {@see InventoryRejected} reason `use_plugin_context`.
 *
 * WRITES act as `$actingProfileId`, who must be in the workspace and hold
 * `purchases:write`. The supplier is a contact marked as one. Quantities are
 * decimals as text in the line's unit; prices are minor units of one of that
 * unit in the order's currency. Sending runs `inventory.order.submitting`,
 * then the workspace's purchase route if it chose one (the order waits,
 * `status` `waiting_approval`), otherwise approves it at once.
 */
interface Orders
{
    /**
     * Newest first, without lines.
     *
     * @param array{status?: string, contact_id?: int, location_id?: int} $filter
     * @return list<Order>
     * @throws InventoryRejected
     */
    public function list(array $filter = [], int $limit = 50, int $offset = 0): array;

    /** @throws InventoryRejected `not_found` */
    public function get(int $orderId): Order;

    /**
     * Drafts a purchase order.
     *
     * @param list<array{variant_id: int, quantity: string, unit?: string, price_minor?: int, discount_bp?: int, tax_bp?: int, note?: string}> $lines a line without a price takes what the supplier sells it for
     * @param array{expected_on?: string, currency?: string, notes?: string, external_ref?: string, idempotency_key?: string} $options
     * @throws InventoryRejected
     */
    public function createPurchase(int $supplierContactId, int $locationId, array $lines, int $actingProfileId, array $options = []): Order;

    /**
     * Sends a draft on: to its route, or approved at once.
     *
     * @throws InventoryRejected `vetoed` when a rule refused it
     */
    public function submit(int $orderId, int $actingProfileId): Order;

    /**
     * Receives what arrived. More than ordered past the workspace's tolerance
     * is refused (`conflict`) unless `$allowOver`.
     *
     * @param list<array{line_id: int, quantity: string, unit?: string, note?: string}> $lines
     * @param array{note?: string, idempotency_key?: string} $options
     * @return Operation the receipt
     * @throws InventoryRejected
     */
    public function receive(int $orderId, array $lines, int $actingProfileId, bool $allowOver = false, array $options = []): Operation;

    /**
     * Cancels an order nothing has arrived against.
     *
     * @throws InventoryRejected
     */
    public function cancel(int $orderId, int $actingProfileId): Order;
}
