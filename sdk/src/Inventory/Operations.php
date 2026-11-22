<?php

declare(strict_types=1);

namespace Whity\Sdk\Inventory;

/**
 * Adjustments, counts and transfers, for a plugin (SDK 1.128, WHIT-1233,
 * register IV.14-IV.16).
 *
 * Reach it through the plugin context: `$ctx->inventory()->operations()`.
 * That one is bound to your plugin: what it starts is marked as yours
 * (`source_plugin`) and its idempotency keys are kept apart from every other
 * plugin's. The `Operations` in the container (`\Whity\app(Operations::class)`)
 * reads only, and refuses a write with {@see InventoryRejected} reason
 * `use_plugin_context`. It works in the workspace the call runs in; with none,
 * it throws `no_workspace`.
 *
 * WRITES act as `$actingProfileId`, who must be in the workspace and hold the
 * permission for the kind: `stock:adjust`, `stock:count` or `stock:transfer`.
 * An adjustment, or a count's differences, worth more than the workspace's
 * value waits for approval on its route ({@see Operation::$status}
 * `waiting_approval`) and posts once approved. Quantities are decimals in the
 * line's unit (the item's base unit when it names none), as text: "2.5".
 */
interface Operations
{
    /**
     * Newest first, without lines.
     *
     * @param array{kind?: string, status?: string, location_id?: int} $filter
     * @return list<Operation>
     * @throws InventoryRejected
     */
    public function list(array $filter = [], int $limit = 50, int $offset = 0): array;

    /** @throws InventoryRejected `not_found` */
    public function get(int $operationId): Operation;

    /**
     * Changes stock at one place, with a reason from the workspace's list.
     *
     * @param list<array{variant_id: int, change: string, unit?: string, reason_code?: string, note?: string}> $lines a negative change takes stock out
     * @throws InventoryRejected
     */
    public function adjust(int $locationId, string $reasonCode, array $lines, int $actingProfileId, ?string $note = null, ?string $idempotencyKey = null): Operation;

    /**
     * Starts a count at one place: what is there now, or a category, or the
     * chosen variants.
     *
     * @param array{category_id?: int, variant_ids?: list<int>, is_blind?: bool, note?: string, idempotency_key?: string} $options
     * @throws InventoryRejected
     */
    public function startCount(int $locationId, int $actingProfileId, array $options = []): Operation;

    /**
     * Records what was counted; each line's expected quantity is taken now.
     *
     * @param list<array{variant_id: int, counted: string, unit?: string, note?: string}> $lines
     * @throws InventoryRejected
     */
    public function recordCount(int $operationId, array $lines, int $actingProfileId): Operation;

    /**
     * Finishes a count: its differences post as count corrections, or wait for
     * approval.
     *
     * @throws InventoryRejected
     */
    public function finishCount(int $operationId, int $actingProfileId): Operation;

    /**
     * Moves stock between two places, sent at once unless `send` is false.
     *
     * @param list<array{variant_id: int, quantity: string, unit?: string, note?: string}> $lines
     * @param array{send?: bool, one_step?: bool, note?: string, idempotency_key?: string} $options
     * @throws InventoryRejected
     */
    public function transfer(int $fromLocationId, int $toLocationId, array $lines, int $actingProfileId, array $options = []): Operation;

    /**
     * Receives a transfer on the way. Lines not named arrive in full; what a
     * named line is short of is written off as lost on the way.
     *
     * @param list<array{line_id: int, received: string}> $lines
     * @throws InventoryRejected
     */
    public function receive(int $operationId, int $actingProfileId, array $lines = []): Operation;

    /**
     * Cancels one that has not posted. A transfer on the way goes back.
     *
     * @throws InventoryRejected
     */
    public function cancel(int $operationId, int $actingProfileId): Operation;
}
