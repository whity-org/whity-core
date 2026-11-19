<?php

declare(strict_types=1);

namespace Whity\Core\Inventory\Host;

use Whity\Core\Inventory\StockOperations;
use Whity\Core\Plugin\PluginIdentity;
use Whity\Core\RBAC\CorePermissions;
use Whity\Sdk\Inventory\InventoryRejected;
use Whity\Sdk\Inventory\Operation;
use Whity\Sdk\Inventory\Operations;
use Whity\Sdk\PluginInterface;

/**
 * Adjustments, counts and transfers for plugins (SDK 1.128, WHIT-1233), in
 * the workspace the call runs in.
 *
 * Like {@see HostStock}: the container's copy reads and refuses a write with
 * `use_plugin_context`; {@see self::for()} gives a copy bound to a plugin
 * object the loader loaded, handed out as `$ctx->inventory()->operations()`.
 * What it starts carries the plugin's slug as `source_plugin`, and its
 * idempotency keys are namespaced with it.
 */
final class HostOperations implements Operations
{
    private const PERMISSION = [
        'adjustment' => CorePermissions::STOCK_ADJUST,
        'count' => CorePermissions::STOCK_COUNT,
        'transfer' => CorePermissions::STOCK_TRANSFER,
    ];

    public function __construct(
        private readonly StockOperations $operations,
        private readonly HostInventoryGuard $guard,
        /** The loaded plugin's slug this copy acts as, or null for the read-only container copy. */
        private readonly ?string $plugin = null,
    ) {
    }

    /**
     * A copy that acts as `$plugin`. Host-side only.
     *
     * @throws InventoryRejected when the loader never loaded that object
     */
    public function for(PluginInterface $plugin): self
    {
        try {
            $slug = PluginIdentity::slugOf($plugin);
        } catch (\Throwable) {
            throw new InventoryRejected('Only a loaded plugin can change stock.', 403, null, InventoryRejected::FORBIDDEN);
        }

        return new self($this->operations, $this->guard, $slug);
    }

    public function list(array $filter = [], int $limit = 50, int $offset = 0): array
    {
        $tenantId = $this->guard->workspace();
        $rows = HostInventoryGuard::run(fn (): array => $this->operations->list($tenantId, [
            'kind' => $filter['kind'] ?? null,
            'status' => $filter['status'] ?? null,
            'location_id' => $filter['location_id'] ?? null,
        ], $limit, $offset));

        return array_map(static fn (array $row): Operation => Operation::fromArray($row), $rows);
    }

    public function get(int $operationId): Operation
    {
        $tenantId = $this->guard->workspace();

        return Operation::fromArray(HostInventoryGuard::run(fn (): array => $this->operations->get($tenantId, $operationId)));
    }

    public function adjust(int $locationId, string $reasonCode, array $lines, int $actingProfileId, ?string $note = null, ?string $idempotencyKey = null): Operation
    {
        [$tenantId, $meta] = $this->writer('adjustment', $actingProfileId, $idempotencyKey);
        $result = HostInventoryGuard::run(fn (): array => $this->operations->adjust($tenantId, $actingProfileId, [
            'location_id' => $locationId, 'reason_code' => $reasonCode, 'note' => $note ?? '', 'lines' => $lines,
        ], $meta));

        return Operation::fromArray($result['operation']);
    }

    public function startCount(int $locationId, int $actingProfileId, array $options = []): Operation
    {
        [$tenantId, $meta] = $this->writer('count', $actingProfileId, $options['idempotency_key'] ?? null);
        $result = HostInventoryGuard::run(fn (): array => $this->operations->startCount($tenantId, $actingProfileId, [
            'location_id' => $locationId,
            'category_id' => $options['category_id'] ?? null,
            'variant_ids' => $options['variant_ids'] ?? null,
            'is_blind' => $options['is_blind'] ?? false,
            'note' => $options['note'] ?? '',
        ], $meta));

        return Operation::fromArray($result['operation']);
    }

    public function recordCount(int $operationId, array $lines, int $actingProfileId): Operation
    {
        $tenantId = $this->existing($operationId, 'count', $actingProfileId);

        return Operation::fromArray(HostInventoryGuard::run(fn (): array => $this->operations->recordCount($tenantId, $operationId, $actingProfileId, $lines)));
    }

    public function finishCount(int $operationId, int $actingProfileId): Operation
    {
        $tenantId = $this->existing($operationId, 'count', $actingProfileId);

        return Operation::fromArray(HostInventoryGuard::run(fn (): array => $this->operations->finishCount($tenantId, $operationId, $actingProfileId)));
    }

    public function transfer(int $fromLocationId, int $toLocationId, array $lines, int $actingProfileId, array $options = []): Operation
    {
        [$tenantId, $meta] = $this->writer('transfer', $actingProfileId, $options['idempotency_key'] ?? null);
        $data = ['from_location_id' => $fromLocationId, 'to_location_id' => $toLocationId, 'lines' => $lines, 'note' => $options['note'] ?? ''];
        foreach (['send', 'one_step'] as $flag) {
            if (array_key_exists($flag, $options)) {
                $data[$flag] = $options[$flag];
            }
        }
        $result = HostInventoryGuard::run(fn (): array => $this->operations->transfer($tenantId, $actingProfileId, $data, $meta));

        return Operation::fromArray($result['operation']);
    }

    public function receive(int $operationId, int $actingProfileId, array $lines = []): Operation
    {
        $tenantId = $this->existing($operationId, 'transfer', $actingProfileId);

        return Operation::fromArray(HostInventoryGuard::run(fn (): array => $this->operations->receive($tenantId, $operationId, $actingProfileId, $lines)));
    }

    public function cancel(int $operationId, int $actingProfileId): Operation
    {
        $tenantId = $this->existing($operationId, null, $actingProfileId);

        return Operation::fromArray(HostInventoryGuard::run(fn (): array => $this->operations->cancel($tenantId, $operationId, $actingProfileId)));
    }

    /**
     * @return array{0: int, 1: array{idempotency_key: ?string, source_plugin: string}}
     * @throws InventoryRejected
     */
    private function writer(string $kind, int $actingProfileId, mixed $idempotencyKey): array
    {
        $plugin = $this->bound();
        $tenantId = $this->guard->workspace();
        $this->guard->writer($tenantId, $actingProfileId, self::PERMISSION[$kind]);
        if ($idempotencyKey !== null && (!is_string($idempotencyKey) || $idempotencyKey === '')) {
            throw new InventoryRejected('idempotency_key is text.', 422, 'idempotency_key', InventoryRejected::INVALID);
        }

        return [$tenantId, [
            'idempotency_key' => is_string($idempotencyKey) ? $plugin . ':' . $idempotencyKey : null,
            'source_plugin' => $plugin,
        ]];
    }

    /** @throws InventoryRejected */
    private function existing(int $operationId, ?string $kind, int $actingProfileId): int
    {
        $this->bound();
        $tenantId = $this->guard->workspace();
        $row = $this->operations->row($tenantId, $operationId);
        if ($row === null || ($kind !== null && $row['kind'] !== $kind)) {
            throw new InventoryRejected(ucfirst($kind ?? 'stock operation') . ' not found', 404, null, InventoryRejected::NOT_FOUND);
        }
        $this->guard->writer($tenantId, $actingProfileId, self::PERMISSION[(string) $row['kind']]);

        return $tenantId;
    }

    private function bound(): string
    {
        if ($this->plugin === null) {
            throw new InventoryRejected(
                'Stock is changed through the plugin context: $ctx->inventory()->operations().',
                403,
                null,
                InventoryRejected::USE_PLUGIN_CONTEXT,
            );
        }

        return $this->plugin;
    }
}
