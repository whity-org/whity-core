<?php

declare(strict_types=1);

namespace Whity\Core\Inventory\Host;

use Whity\Core\Inventory\StockLedger;
use Whity\Core\Plugin\PluginIdentity;
use Whity\Core\RBAC\CorePermissions;
use Whity\Sdk\Inventory\InventoryRejected;
use Whity\Sdk\Inventory\Move;
use Whity\Sdk\Inventory\MoveLine;
use Whity\Sdk\Inventory\MoveRequest;
use Whity\Sdk\Inventory\Quantity;
use Whity\Sdk\Inventory\Stock;
use Whity\Sdk\Inventory\StockLevel;
use Whity\Sdk\PluginInterface;

/**
 * Core's stock for plugins (SDK 1.126, WHIT-1232), in the workspace the call
 * runs in.
 *
 * WHO POSTS. The instance in the container is unbound: it reads, and refuses
 * a write with reason `use_plugin_context`, because anyone can resolve it.
 * {@see self::for()} gives a copy bound to a plugin OBJECT the loader loaded
 * ({@see PluginIdentity}); the plugin context hands that copy out as
 * `$ctx->inventory()->stock()`. Its moves are stored as `<slug>.<kind>`, and
 * its idempotency keys are namespaced the same way, so no plugin can post as
 * core or as another plugin, nor collide with another's keys.
 */
final class HostStock implements Stock
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly HostInventoryGuard $guard,
        /** The loaded plugin's slug this copy posts as, or null for the read-only container copy. */
        private readonly ?string $source = null,
    ) {
    }

    /**
     * A copy that posts as `$plugin`. Host-side only.
     *
     * @throws InventoryRejected when the loader never loaded that object
     */
    public function for(PluginInterface $plugin): self
    {
        try {
            $slug = PluginIdentity::slugOf($plugin);
        } catch (\Throwable) {
            throw new InventoryRejected('Only a loaded plugin can move stock.', 403, null, InventoryRejected::FORBIDDEN);
        }

        return new self($this->ledger, $this->guard, $slug);
    }

    public function levels(array $filter = []): array
    {
        $tenantId = $this->guard->workspace();

        return array_map(static fn (array $row): StockLevel => StockLevel::fromArray($row), $this->ledger->levels($tenantId, [
            'variant_id' => $filter['variant_id'] ?? null,
            'item_id' => $filter['item_id'] ?? null,
            'location_id' => $filter['location_id'] ?? null,
            'nonzero' => (bool) ($filter['nonzero'] ?? false),
        ]));
    }

    public function available(int $variantId, int $locationId): Quantity
    {
        $tenantId = $this->guard->workspace();
        $decimals = 0;
        foreach ($this->ledger->levels($tenantId, ['variant_id' => $variantId]) as $level) {
            $decimals = (int) $level['decimals'];
        }

        return new Quantity($this->ledger->availableSteps($tenantId, $variantId, $locationId), $decimals);
    }

    public function history(array $filter = [], int $limit = 50): array
    {
        $tenantId = $this->guard->workspace();
        $rows = $this->ledger->history($tenantId, [
            'variant_id' => $filter['variant_id'] ?? null,
            'item_id' => $filter['item_id'] ?? null,
            'location_id' => $filter['location_id'] ?? null,
            'source_type' => $filter['source_type'] ?? null,
            'source_id' => $filter['source_id'] ?? null,
            'batch_key' => $filter['batch_key'] ?? null,
            'before_id' => $filter['before_id'] ?? null,
        ], $limit);

        return array_map(static fn (array $row): Move => Move::fromArray($row), $rows);
    }

    public function move(MoveRequest $request): array
    {
        $source = $this->sourceType($request->sourceType);
        $tenantId = $this->guard->workspace();
        $this->guard->writer($tenantId, $request->actingProfileId, CorePermissions::STOCK_ADJUST);
        $lines = array_map(static fn (MoveLine $line): array => [
            'variant_id' => $line->variantId,
            'quantity' => $line->quantity,
            'unit' => $line->unit,
            'from_location_id' => $line->fromLocationId,
            'to_location_id' => $line->toLocationId,
            'note' => $line->note,
            'unit_cost_minor' => $line->unitCostMinor,
            'currency' => $line->currency,
        ], $request->lines);

        $result = HostInventoryGuard::run(fn (): array => $this->ledger->post($tenantId, $request->actingProfileId, $lines, [
            'source_type' => $source,
            'source_id' => $request->sourceId,
            'reason_code' => $request->reasonCode,
            'note' => $request->note,
            'occurred_at' => $request->occurredAt,
            'idempotency_key' => $request->idempotencyKey === null ? null : $source . ':' . $request->idempotencyKey,
        ]));

        return array_map(static fn (array $row): Move => Move::fromArray($row), $result['moves']);
    }

    public function reverse(string $batchKey, int $actingProfileId, ?string $note = null): array
    {
        $source = $this->sourceType('reversal');
        $tenantId = $this->guard->workspace();
        $this->guard->writer($tenantId, $actingProfileId, CorePermissions::STOCK_ADJUST);
        $result = HostInventoryGuard::run(fn (): array => $this->ledger->reverse($tenantId, $batchKey, $actingProfileId, $note ?? '', $source));

        return array_map(static fn (array $row): Move => Move::fromArray($row), $result['moves']);
    }

    private function sourceType(string $kind): string
    {
        if ($this->source === null) {
            throw new InventoryRejected(
                'Stock is written through the plugin context: $ctx->inventory()->stock().',
                403,
                null,
                InventoryRejected::USE_PLUGIN_CONTEXT,
            );
        }
        $kind = strtolower(trim($kind));
        if (preg_match('/^[a-z][a-z0-9_]{0,40}$/', $kind) !== 1) {
            throw new InventoryRejected('sourceType is lower-case letters, digits and _, such as "sale".', 422, 'sourceType', InventoryRejected::INVALID);
        }

        return $this->source . '.' . $kind;
    }
}
