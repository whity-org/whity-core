<?php

declare(strict_types=1);

namespace Whity\Core\Inventory;

use Whity\Core\Db\DbBool;
use Whity\Core\Hooks\HookManager;
use Whity\Sdk\Hooks\Events;
use Whity\Sdk\Hooks\HookVetoException;
use Whity\Sdk\Inventory\Quantity;

/**
 * The stock ledger and the levels it adds up to (WHIT-1232, design 31 §3.3,
 * register IV.2, IV.3, IV.8).
 *
 * POSTING is one transaction: the `stock.moving` filter hook (which may veto),
 * the moves inserted, then each level touched upserted in a fixed order
 * (variant, place, lot), so two posts touching the same levels lock them in
 * the same order and cannot deadlock. `stock.moved` fires inside the
 * transaction, after the rows are written; `stock.moved.async` after commit.
 *
 * MOVES ARE NEVER CHANGED. A trigger refuses UPDATE and DELETE on `inv_moves`
 * (migration 318). Undoing posts the same moves the other way, linked with
 * `reverses_move_id`; a batch can be reversed once.
 *
 * LEVELS are kept only for real places; virtual places (supplier, customer,
 * loss, ...) are the other end of stock entering or leaving and hold none.
 * {@see self::rebuild()} recomputes levels from the ledger.
 *
 * NEGATIVE STOCK is allowed (register IV.8, the default); the workspace
 * setting that forbids it arrives with the inventory settings screen, as a
 * `stock.moving` rule.
 *
 * TENANT-OWNED: every statement binds `tenant_id`.
 */
final class StockLedger
{
    /** Core's own source types. A plugin's are `<plugin>.<kind>`. */
    public const CORE_SOURCES = ['opening', 'adjustment', 'reversal', 'count', 'transfer'];

    public const MAX_LINES = 500;
    public const MAX_HISTORY = 200;

    private const MOVE_COLUMNS = 'm.id, m.variant_id, m.lot_id, m.from_location_id, m.to_location_id, m.quantity, m.entered_unit_id,
        m.entered_quantity, m.unit_cost_minor, m.currency, m.source_type, m.source_id, m.source_line_id, m.reason_code, m.note,
        m.batch_key, m.reverses_move_id, m.posted_by_profile_id, m.agent_id, m.on_behalf_of_profile_id, m.occurred_at, m.posted_at';

    public function __construct(
        private readonly InventoryDb $db,
        private readonly InventoryWorkspace $workspace,
        private readonly ItemService $items,
        private readonly UnitCatalog $units,
        private readonly ?HookManager $hooks = null,
        private readonly ?ReasonCatalog $reasons = null,
    ) {
    }

    // ── posting ──────────────────────────────────────────────────────────

    /**
     * Posts lines given as people and plugins write them.
     *
     * @param list<mixed> $rawLines each `variant_id`, `quantity` (positive decimal), `unit` (code or null), `from_location_id`, `to_location_id`, optional `note`, `unit_cost_minor`, `currency`
     * @param array{source_type: string, source_id?: ?int, reason_code?: string, note?: string, occurred_at?: ?string, idempotency_key?: ?string, agent_id?: ?int, on_behalf_of?: ?int} $meta
     * @return array{batch_key: string, moves: list<array<string, mixed>>, repeated: bool}
     */
    public function post(int $tenantId, ?int $actor, array $rawLines, array $meta): array
    {
        $this->workspace->ensure($tenantId);
        $key = $this->idempotencyKey($meta['idempotency_key'] ?? null);
        if ($key !== null && ($done = $this->batchByKey($tenantId, $key)) !== null) {
            return ['batch_key' => $done, 'moves' => $this->batch($tenantId, $done), 'repeated' => true];
        }
        if ($rawLines === [] || count($rawLines) > self::MAX_LINES) {
            throw InventoryRejectedException::field('lines', 'Send 1 to ' . self::MAX_LINES . ' lines');
        }
        $lines = [];
        foreach ($rawLines as $i => $raw) {
            $lines[] = $this->line($tenantId, $raw, 'lines[' . $i . ']');
        }

        return $this->write($tenantId, $actor, $lines, $meta + ['idempotency_key' => $key]);
    }

    /**
     * Opening stock or an adjustment, as a person writes it: a variant, a
     * place and a change. `opening` takes positive amounts into the place from
     * the virtual opening place; `adjustment` takes a signed change, positive
     * from `found`, negative to `loss`, with a reason.
     *
     * @param list<mixed> $rawLines each `variant_id`, `location_id`, `change` (signed decimal), `unit`, `note`
     * @return array{batch_key: string, moves: list<array<string, mixed>>, repeated: bool}
     */
    public function adjust(int $tenantId, ?int $actor, string $kind, array $rawLines, string $reason, string $note, ?string $occurredAt, ?string $idempotencyKey, ?int $agentId = null, ?int $onBehalfOf = null): array
    {
        if (!in_array($kind, ['opening', 'adjustment'], true)) {
            throw InventoryRejectedException::field('kind', 'kind must be opening or adjustment');
        }
        $this->workspace->ensure($tenantId);
        if ($rawLines === [] || count($rawLines) > self::MAX_LINES) {
            throw InventoryRejectedException::field('lines', 'Send 1 to ' . self::MAX_LINES . ' lines');
        }
        $lines = [];
        $directions = [];
        foreach ($rawLines as $i => $raw) {
            $field = 'lines[' . $i . ']';
            if (!is_array($raw)) {
                throw InventoryRejectedException::field($field, $field . ' must be an object');
            }
            $change = is_string($raw['change'] ?? null) || is_int($raw['change'] ?? null) ? trim((string) $raw['change']) : '';
            $negative = str_starts_with($change, '-');
            $amount = ltrim($change, '-+');
            if ($kind === 'opening' && $negative) {
                throw InventoryRejectedException::field($field, 'Opening stock is a positive amount');
            }
            $place = is_int($raw['location_id'] ?? null) ? $raw['location_id'] : 0;
            $other = $this->workspace->virtualId($tenantId, $kind === 'opening' ? 'opening' : ($negative ? 'loss' : 'found'));
            $directions[$negative ? 'out' : 'in'] = true;
            $lines[] = $this->line($tenantId, [
                'variant_id' => $raw['variant_id'] ?? null,
                'quantity' => $amount,
                'unit' => $raw['unit'] ?? null,
                'from_location_id' => $negative ? $place : $other,
                'to_location_id' => $negative ? $other : $place,
                'note' => $raw['note'] ?? '',
            ], $field);
        }
        if ($kind === 'adjustment') {
            $reasons = $this->reasons ?? new ReasonCatalog($this->db, $this->workspace);
            foreach (array_keys($directions) as $direction) {
                $reasons->usable($tenantId, $reason, $direction);
            }
        }
        $key = $this->idempotencyKey($idempotencyKey);
        if ($key !== null && ($done = $this->batchByKey($tenantId, $key)) !== null) {
            return ['batch_key' => $done, 'moves' => $this->batch($tenantId, $done), 'repeated' => true];
        }

        return $this->write($tenantId, $actor, $lines, [
            'source_type' => $kind, 'reason_code' => $kind === 'adjustment' ? $reason : '', 'note' => $note,
            'occurred_at' => $occurredAt, 'idempotency_key' => $key, 'agent_id' => $agentId, 'on_behalf_of' => $onBehalfOf,
        ]);
    }

    /**
     * Posts every move of a batch the other way.
     *
     * @return array{batch_key: string, moves: list<array<string, mixed>>, repeated: bool}
     */
    public function reverse(int $tenantId, string $batchKey, ?int $actor, string $note = '', string $sourceType = 'reversal'): array
    {
        $original = $this->db->all(
            'SELECT id, variant_id, lot_id, from_location_id, to_location_id, quantity, entered_unit_id, entered_quantity,
                    unit_cost_minor, currency, source_type, reverses_move_id
               FROM inv_moves WHERE tenant_id = :tenant_id AND batch_key = :batch_key ORDER BY id',
            [':tenant_id' => $tenantId, ':batch_key' => $batchKey]
        );
        if ($original === []) {
            throw InventoryRejectedException::notFound('Stock batch');
        }
        foreach ($original as $move) {
            if ($move['reverses_move_id'] !== null) {
                throw new InventoryRejectedException('This is already an undo; post the stock again instead', 409);
            }
        }
        $ids = array_map(static fn (array $m): int => (int) $m['id'], $original);
        $already = $this->db->one(
            'SELECT batch_key FROM inv_moves WHERE tenant_id = :tenant_id AND reverses_move_id = :first LIMIT 1',
            [':tenant_id' => $tenantId, ':first' => $ids[0]]
        );
        if ($already !== null) {
            throw new InventoryRejectedException('This stock change was already undone', 409, ['batch_key' => (string) $already['batch_key']]);
        }
        $lines = array_map(static fn (array $m): array => [
            'variant_id' => (int) $m['variant_id'],
            'lot_id' => InventoryDb::int($m['lot_id']),
            'from_location_id' => (int) $m['to_location_id'],
            'to_location_id' => (int) $m['from_location_id'],
            'steps' => (int) $m['quantity'],
            'entered_unit_id' => InventoryDb::int($m['entered_unit_id']),
            'entered_quantity' => InventoryDb::int($m['entered_quantity']),
            'unit_cost_minor' => InventoryDb::int($m['unit_cost_minor']),
            'currency' => is_string($m['currency']) ? $m['currency'] : null,
            'note' => '',
            'reverses_move_id' => (int) $m['id'],
        ], $original);

        return $this->write($tenantId, $actor, $lines, ['source_type' => $sourceType, 'note' => $note]);
    }

    /**
     * Posts lines already in base-unit steps, as stock operations keep them
     * (WHIT-1233). Each line may carry its own `reason_code` and the
     * `source_line_id` of the operation line it came from.
     *
     * @param list<array{variant_id: int, from_location_id: int, to_location_id: int, steps: int, entered_unit_id?: ?int, entered_quantity?: ?int, unit_cost_minor?: ?int, currency?: ?string, note?: string, reason_code?: string, source_line_id?: ?int}> $lines
     * @param array{source_type: string, source_id?: ?int, reason_code?: string, note?: string, occurred_at?: ?string, agent_id?: ?int, on_behalf_of?: ?int} $meta
     * @return array{batch_key: string, moves: list<array<string, mixed>>, repeated: bool}
     */
    public function postSteps(int $tenantId, ?int $actor, array $lines, array $meta): array
    {
        $this->workspace->ensure($tenantId);
        if ($lines === [] || count($lines) > StockOperations::MAX_LINES) {
            throw InventoryRejectedException::field('lines', 'Send 1 to ' . StockOperations::MAX_LINES . ' lines');
        }
        $ready = [];
        foreach ($lines as $line) {
            if ($line['steps'] <= 0) {
                throw InventoryRejectedException::field('lines', 'Each line moves more than 0');
            }
            $ready[] = $line + ['entered_unit_id' => null, 'entered_quantity' => null, 'unit_cost_minor' => null, 'currency' => null, 'note' => ''];
        }

        return $this->write($tenantId, $actor, $ready, $meta);
    }

    /**
     * One line as a person or plugin writes it, checked and converted to
     * base-unit steps, without posting it.
     *
     * @return array{variant_id: int, from_location_id: int, to_location_id: int, steps: int, entered_unit_id: ?int, entered_quantity: ?int, unit_cost_minor: ?int, currency: ?string, note: string}
     */
    public function prepareLine(int $tenantId, mixed $raw, string $field): array
    {
        $this->workspace->ensure($tenantId);

        return $this->line($tenantId, $raw, $field);
    }

    /** On hand now at one place, in base-unit steps, all lots together. */
    public function onHandSteps(int $tenantId, int $variantId, int $locationId): int
    {
        $row = $this->db->one(
            'SELECT SUM(on_hand) AS on_hand FROM inv_levels WHERE tenant_id = :tenant_id AND variant_id = :variant_id AND location_id = :location_id',
            [':tenant_id' => $tenantId, ':variant_id' => $variantId, ':location_id' => $locationId]
        );

        return (int) ($row['on_hand'] ?? 0);
    }

    /**
     * @param list<array{variant_id: int, lot_id?: ?int, from_location_id: int, to_location_id: int, steps: int, entered_unit_id: ?int, entered_quantity: ?int, unit_cost_minor: ?int, currency: ?string, note: string, reverses_move_id?: ?int, reason_code?: string, source_line_id?: ?int}> $lines
     * @param array<string, mixed> $meta
     * @return array{batch_key: string, moves: list<array<string, mixed>>, repeated: bool}
     */
    private function write(int $tenantId, ?int $actor, array $lines, array $meta): array
    {
        $sourceType = (string) $meta['source_type'];
        $sourceId = InventoryDb::int($meta['source_id'] ?? null);
        $occurredAt = $this->occurredAt($meta['occurred_at'] ?? null);
        $batchKey = bin2hex(random_bytes(12));
        $note = mb_substr(trim((string) ($meta['note'] ?? '')), 0, 500);
        $reason = (string) ($meta['reason_code'] ?? '');

        try {
            $ids = $this->db->transaction(function () use ($tenantId, $actor, $lines, $meta, $sourceType, $sourceId, $occurredAt, $batchKey, $note, $reason): array {
                $this->hooks?->dispatch(Events::STOCK_MOVING, [
                    'tenant_id' => $tenantId, 'batch_key' => $batchKey, 'source_type' => $sourceType, 'source_id' => $sourceId,
                    'reason_code' => $reason, 'acting_profile_id' => $actor, 'occurred_at' => $occurredAt,
                    'lines' => array_map(static fn (array $l): array => [
                        'variant_id' => $l['variant_id'], 'from_location_id' => $l['from_location_id'],
                        'to_location_id' => $l['to_location_id'], 'quantity' => $l['steps'],
                        'reason_code' => $l['reason_code'] ?? $reason,
                    ], $lines),
                ]);

                $ids = [];
                $deltas = [];
                foreach ($lines as $i => $line) {
                    $id = $this->db->insert(
                        'INSERT INTO inv_moves (tenant_id, variant_id, lot_id, from_location_id, to_location_id, quantity, entered_unit_id,
                                entered_quantity, unit_cost_minor, currency, source_type, source_id, source_line_id, reason_code, note, batch_key,
                                idempotency_key, reverses_move_id, posted_by_profile_id, agent_id, on_behalf_of_profile_id, occurred_at, posted_at)
                         VALUES (:tenant_id, :variant_id, :lot_id, :from_id, :to_id, :quantity, :entered_unit_id,
                                :entered_quantity, :unit_cost, :currency, :source_type, :source_id, :source_line_id, :reason, :note, :batch_key,
                                :idempotency_key, :reverses, :actor, :agent_id, :on_behalf_of, :occurred_at, CURRENT_TIMESTAMP)',
                        [
                            ':tenant_id' => $tenantId, ':variant_id' => $line['variant_id'], ':lot_id' => $line['lot_id'] ?? null,
                            ':from_id' => $line['from_location_id'], ':to_id' => $line['to_location_id'], ':quantity' => $line['steps'],
                            ':entered_unit_id' => $line['entered_unit_id'], ':entered_quantity' => $line['entered_quantity'],
                            ':unit_cost' => $line['unit_cost_minor'], ':currency' => $line['currency'],
                            ':source_type' => $sourceType, ':source_id' => $sourceId,
                            ':source_line_id' => $line['source_line_id'] ?? null, ':reason' => $line['reason_code'] ?? $reason,
                            ':note' => $line['note'] !== '' ? mb_substr($line['note'], 0, 500) : $note, ':batch_key' => $batchKey,
                            // The key sits on the first move only: one key, one post.
                            ':idempotency_key' => $i === 0 ? ($meta['idempotency_key'] ?? null) : null,
                            ':reverses' => $line['reverses_move_id'] ?? null, ':actor' => $actor,
                            ':agent_id' => InventoryDb::int($meta['agent_id'] ?? null), ':on_behalf_of' => InventoryDb::int($meta['on_behalf_of'] ?? null),
                            ':occurred_at' => $occurredAt,
                        ]
                    );
                    $ids[] = $id;
                    $lot = (int) ($line['lot_id'] ?? 0);
                    foreach ([[$line['from_location_id'], -$line['steps']], [$line['to_location_id'], $line['steps']]] as [$place, $delta]) {
                        $k = $line['variant_id'] . ':' . $place . ':' . $lot;
                        $deltas[$k] = ['variant_id' => $line['variant_id'], 'location_id' => $place, 'lot_id' => $lot,
                            'delta' => ($deltas[$k]['delta'] ?? 0) + $delta, 'last' => $id];
                    }
                }

                $virtual = $this->virtualIds($tenantId);
                ksort($deltas, SORT_NATURAL);
                foreach ($deltas as $d) {
                    if (isset($virtual[$d['location_id']]) || $d['delta'] === 0) {
                        continue;
                    }
                    $this->db->run(
                        'INSERT INTO inv_levels (tenant_id, variant_id, location_id, lot_id, on_hand, reserved, last_move_id, updated_at)
                         VALUES (:tenant_id, :variant_id, :location_id, :lot_id, :delta, 0, :last, CURRENT_TIMESTAMP)
                         ON CONFLICT (tenant_id, variant_id, location_id, lot_id)
                         DO UPDATE SET on_hand = inv_levels.on_hand + excluded.on_hand, last_move_id = excluded.last_move_id, updated_at = CURRENT_TIMESTAMP',
                        [':tenant_id' => $tenantId, ':variant_id' => $d['variant_id'], ':location_id' => $d['location_id'],
                            ':lot_id' => $d['lot_id'], ':delta' => $d['delta'], ':last' => $d['last']]
                    );
                }

                $this->hooks?->dispatch(Events::STOCK_MOVED, $this->movedPayload($tenantId, $actor, $batchKey, $sourceType, $sourceId, $ids));

                return $ids;
            });
        } catch (HookVetoException $e) {
            throw new InventoryRejectedException($e->reason(), 409, ['code' => 'vetoed']);
        } catch (\PDOException $e) {
            $key = $meta['idempotency_key'] ?? null;
            if (is_string($key) && InventoryDb::isUniqueViolation($e) && ($done = $this->batchByKey($tenantId, $key)) !== null) {
                return ['batch_key' => $done, 'moves' => $this->batch($tenantId, $done), 'repeated' => true];
            }
            throw $e;
        }

        try {
            $this->hooks?->dispatchAsync(Events::STOCK_MOVED_ASYNC, $this->movedPayload($tenantId, $actor, $batchKey, $sourceType, $sourceId, $ids));
        } catch (\Throwable $e) {
            error_log('[inventory] stock.moved.async could not be queued: ' . $e->getMessage());
        }

        return ['batch_key' => $batchKey, 'moves' => $this->batch($tenantId, $batchKey), 'repeated' => false];
    }

    /**
     * One raw line, checked and converted to base-unit steps.
     *
     * @return array{variant_id: int, from_location_id: int, to_location_id: int, steps: int, entered_unit_id: ?int, entered_quantity: ?int, unit_cost_minor: ?int, currency: ?string, note: string}
     */
    private function line(int $tenantId, mixed $raw, string $field): array
    {
        if (!is_array($raw)) {
            throw InventoryRejectedException::field($field, $field . ' must be an object');
        }
        $variantId = $raw['variant_id'] ?? null;
        $found = is_int($variantId) ? $this->items->variantForStock($tenantId, $variantId) : null;
        if ($found === null) {
            throw InventoryRejectedException::field($field, $field . '.variant_id is not an item here');
        }
        if ($found['variant']['archived_at'] !== null || $found['item']['archived_at'] !== null) {
            throw InventoryRejectedException::field($field, $field . ': ' . $found['item']['name'] . ' is archived');
        }
        if ($found['item']['kind'] === 'service') {
            throw InventoryRejectedException::field($field, $field . ': ' . $found['item']['name'] . ' is a service and has no stock');
        }
        $from = $this->place($tenantId, $raw['from_location_id'] ?? null, $field . '.from_location_id');
        $to = $this->place($tenantId, $raw['to_location_id'] ?? null, $field . '.to_location_id');
        if ($from['id'] === $to['id']) {
            throw InventoryRejectedException::field($field, $field . ': stock has to go somewhere else');
        }
        if ($from['kind'] === 'virtual' && $to['kind'] === 'virtual') {
            throw InventoryRejectedException::field($field, $field . ': one side must be a real place');
        }
        $quantity = is_string($raw['quantity'] ?? null) || is_int($raw['quantity'] ?? null) ? (string) $raw['quantity'] : '';
        $unit = is_string($raw['unit'] ?? null) && $raw['unit'] !== '' ? $raw['unit'] : null;
        $converted = $this->units->toBaseSteps($tenantId, (int) $found['item']['id'], $found['unit'], $quantity, $unit, $field . '.quantity');
        if ($converted['steps'] <= 0) {
            throw InventoryRejectedException::field($field, $field . '.quantity must be more than 0');
        }
        $cost = $raw['unit_cost_minor'] ?? null;
        if ($cost !== null && (!is_int($cost) || $cost < 0)) {
            throw InventoryRejectedException::field($field, $field . '.unit_cost_minor is a whole number of minor units');
        }
        $currency = $raw['currency'] ?? null;
        if ($currency !== null && (!is_string($currency) || preg_match('/^[A-Z]{3}$/', $currency) !== 1)) {
            throw InventoryRejectedException::field($field, $field . '.currency is a 3-letter code');
        }

        return [
            'variant_id' => (int) $variantId,
            'from_location_id' => $from['id'],
            'to_location_id' => $to['id'],
            'steps' => $converted['steps'],
            'entered_unit_id' => $converted['unit_id'],
            'entered_quantity' => $converted['entered_steps'],
            'unit_cost_minor' => $cost,
            'currency' => $currency,
            'note' => is_string($raw['note'] ?? null) ? trim($raw['note']) : '',
        ];
    }

    /** @return array{id: int, kind: string} */
    private function place(int $tenantId, mixed $id, string $field): array
    {
        $row = is_int($id) ? $this->db->one(
            'SELECT id, kind, is_active FROM inv_locations WHERE tenant_id = :tenant_id AND id = :id',
            [':tenant_id' => $tenantId, ':id' => $id]
        ) : null;
        if ($row === null) {
            throw InventoryRejectedException::field($field, $field . ' is not a place here');
        }
        if ($row['kind'] !== 'virtual' && !DbBool::of($row['is_active'])) {
            throw InventoryRejectedException::field($field, $field . ' is a place that is no longer in use');
        }

        return ['id' => (int) $row['id'], 'kind' => (string) $row['kind']];
    }

    // ── reading ──────────────────────────────────────────────────────────

    /**
     * Stock now at real places, with each item's base unit.
     *
     * @param array{variant_id?: ?int, item_id?: ?int, location_id?: ?int, nonzero?: bool} $filter
     * @return list<array<string, mixed>>
     */
    public function levels(int $tenantId, array $filter = []): array
    {
        $where = ['l.tenant_id = :tenant_id'];
        $params = [':tenant_id' => $tenantId];
        foreach (['variant_id' => 'l.variant_id', 'item_id' => 'v.item_id', 'location_id' => 'l.location_id'] as $key => $column) {
            if (($filter[$key] ?? null) !== null) {
                $where[] = $column . ' = :' . $key;
                $params[':' . $key] = (int) $filter[$key];
            }
        }
        if ($filter['nonzero'] ?? false) {
            $where[] = '(l.on_hand <> 0 OR l.reserved <> 0)';
        }
        $rows = $this->db->all(
            'SELECT l.variant_id, l.location_id, l.lot_id, l.on_hand, l.reserved, l.updated_at, v.item_id, v.sku, u.code AS unit, u.decimals
               FROM inv_levels l
               JOIN inv_variants v ON v.tenant_id = l.tenant_id AND v.id = l.variant_id
               JOIN inv_items i ON i.tenant_id = v.tenant_id AND i.id = v.item_id
               JOIN inv_units u ON u.tenant_id = i.tenant_id AND u.id = i.base_unit_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY v.item_id, l.variant_id, l.location_id, l.lot_id',
            $params
        );

        return array_map(static function (array $r): array {
            $decimals = (int) $r['decimals'];
            $onHand = (int) $r['on_hand'];
            $reserved = (int) $r['reserved'];

            return [
                'item_id' => (int) $r['item_id'],
                'variant_id' => (int) $r['variant_id'],
                'sku' => (string) $r['sku'],
                'location_id' => (int) $r['location_id'],
                'lot_id' => (int) $r['lot_id'] === 0 ? null : (int) $r['lot_id'],
                'on_hand' => (new Quantity($onHand, $decimals))->toDecimal(),
                'reserved' => (new Quantity($reserved, $decimals))->toDecimal(),
                'available' => (new Quantity($onHand - $reserved, $decimals))->toDecimal(),
                'unit' => (string) $r['unit'],
                'decimals' => $decimals,
                'updated_at' => (string) $r['updated_at'],
            ];
        }, $rows);
    }

    /** On hand minus reserved, in base-unit steps. */
    public function availableSteps(int $tenantId, int $variantId, int $locationId): int
    {
        $row = $this->db->one(
            'SELECT SUM(on_hand - reserved) AS available FROM inv_levels WHERE tenant_id = :tenant_id AND variant_id = :variant_id AND location_id = :location_id',
            [':tenant_id' => $tenantId, ':variant_id' => $variantId, ':location_id' => $locationId]
        );

        return (int) ($row['available'] ?? 0);
    }

    /**
     * The ledger, newest first, keyset-paged on the move id.
     *
     * @param array{variant_id?: ?int, item_id?: ?int, location_id?: ?int, source_type?: ?string, source_id?: ?int, batch_key?: ?string, before_id?: ?int} $filter
     * @return list<array<string, mixed>>
     */
    public function history(int $tenantId, array $filter = [], int $limit = 50): array
    {
        $where = ['m.tenant_id = :tenant_id'];
        $params = [':tenant_id' => $tenantId];
        foreach (['variant_id' => 'm.variant_id', 'item_id' => 'v.item_id', 'source_id' => 'm.source_id'] as $key => $column) {
            if (($filter[$key] ?? null) !== null) {
                $where[] = $column . ' = :' . $key;
                $params[':' . $key] = (int) $filter[$key];
            }
        }
        if (($filter['location_id'] ?? null) !== null) {
            $where[] = '(m.from_location_id = :location_id OR m.to_location_id = :location_id2)';
            $params[':location_id'] = (int) $filter['location_id'];
            $params[':location_id2'] = (int) $filter['location_id'];
        }
        foreach (['source_type' => 'm.source_type', 'batch_key' => 'm.batch_key'] as $key => $column) {
            if (($filter[$key] ?? null) !== null) {
                $where[] = $column . ' = :' . $key;
                $params[':' . $key] = (string) $filter[$key];
            }
        }
        if (($filter['before_id'] ?? null) !== null) {
            $where[] = 'm.id < :before_id';
            $params[':before_id'] = (int) $filter['before_id'];
        }
        $rows = $this->db->all(
            'SELECT ' . self::MOVE_COLUMNS . ', v.item_id, v.sku, u.code AS unit, u.decimals, eu.code AS entered_unit, eu.decimals AS entered_decimals,
                    p.display_name AS posted_by_name
               FROM inv_moves m
               JOIN inv_variants v ON v.tenant_id = m.tenant_id AND v.id = m.variant_id
               JOIN inv_items i ON i.tenant_id = v.tenant_id AND i.id = v.item_id
               JOIN inv_units u ON u.tenant_id = i.tenant_id AND u.id = i.base_unit_id
               LEFT JOIN inv_units eu ON eu.tenant_id = m.tenant_id AND eu.id = m.entered_unit_id
               LEFT JOIN profiles p ON p.id = m.posted_by_profile_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY m.id DESC LIMIT :limit',
            $params + [':limit' => max(1, min(self::MAX_HISTORY, $limit))]
        );

        return array_map(self::presentMove(...), $rows);
    }

    /** @return list<array<string, mixed>> */
    public function batch(int $tenantId, string $batchKey): array
    {
        return array_reverse($this->history($tenantId, ['batch_key' => $batchKey], self::MAX_LINES));
    }

    /**
     * Recomputes levels from the ledger for one workspace and reports what
     * differed. Reserved quantities are left alone (they come from
     * reservations, P5).
     *
     * @return list<array{variant_id: int, location_id: int, lot_id: int, was: int, is: int}>
     */
    public function rebuild(int $tenantId, bool $fix = true): array
    {
        $virtual = $this->virtualIds($tenantId);
        $truth = [];
        foreach ([['to_location_id', 1], ['from_location_id', -1]] as [$column, $sign]) {
            foreach ($this->db->all(
                'SELECT variant_id, ' . $column . ' AS location_id, COALESCE(lot_id, 0) AS lot_id, SUM(quantity) AS total
                   FROM inv_moves WHERE tenant_id = :tenant_id GROUP BY variant_id, ' . $column . ', COALESCE(lot_id, 0)',
                [':tenant_id' => $tenantId]
            ) as $r) {
                if (isset($virtual[(int) $r['location_id']])) {
                    continue;
                }
                $k = (int) $r['variant_id'] . ':' . (int) $r['location_id'] . ':' . (int) $r['lot_id'];
                $truth[$k] = ($truth[$k] ?? 0) + $sign * (int) $r['total'];
            }
        }
        $stored = [];
        foreach ($this->db->all(
            'SELECT variant_id, location_id, lot_id, on_hand FROM inv_levels WHERE tenant_id = :tenant_id',
            [':tenant_id' => $tenantId]
        ) as $r) {
            $stored[(int) $r['variant_id'] . ':' . (int) $r['location_id'] . ':' . (int) $r['lot_id']] = (int) $r['on_hand'];
        }
        $diffs = [];
        foreach (array_unique(array_merge(array_keys($truth), array_keys($stored))) as $k) {
            $is = $truth[$k] ?? 0;
            $was = $stored[$k] ?? 0;
            if ($is === $was) {
                continue;
            }
            [$variant, $place, $lot] = array_map('intval', explode(':', (string) $k));
            $diffs[] = ['variant_id' => $variant, 'location_id' => $place, 'lot_id' => $lot, 'was' => $was, 'is' => $is];
            if ($fix) {
                $this->db->run(
                    'INSERT INTO inv_levels (tenant_id, variant_id, location_id, lot_id, on_hand, reserved, updated_at)
                     VALUES (:tenant_id, :variant_id, :location_id, :lot_id, :on_hand, 0, CURRENT_TIMESTAMP)
                     ON CONFLICT (tenant_id, variant_id, location_id, lot_id)
                     DO UPDATE SET on_hand = excluded.on_hand, updated_at = CURRENT_TIMESTAMP',
                    [':tenant_id' => $tenantId, ':variant_id' => $variant, ':location_id' => $place, ':lot_id' => $lot, ':on_hand' => $is]
                );
            }
        }

        return $diffs;
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** @return array<int, true> */
    private function virtualIds(int $tenantId): array
    {
        $out = [];
        foreach ($this->db->all(
            "SELECT id FROM inv_locations WHERE tenant_id = :tenant_id AND kind = 'virtual'",
            [':tenant_id' => $tenantId]
        ) as $r) {
            $out[(int) $r['id']] = true;
        }

        return $out;
    }

    private function batchByKey(int $tenantId, string $key): ?string
    {
        $row = $this->db->one(
            'SELECT batch_key FROM inv_moves WHERE tenant_id = :tenant_id AND idempotency_key = :key',
            [':tenant_id' => $tenantId, ':key' => $key]
        );

        return $row === null ? null : (string) $row['batch_key'];
    }

    private function idempotencyKey(mixed $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }
        if (!is_string($key) || strlen($key) > 128) {
            throw InventoryRejectedException::field('idempotency_key', 'idempotency_key is text of up to 128 characters');
        }

        return $key;
    }

    private function occurredAt(mixed $raw): string
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($raw === null || $raw === '') {
            return $now->format('Y-m-d H:i:s');
        }
        try {
            $at = is_string($raw) ? new \DateTimeImmutable($raw) : null;
        } catch (\Exception) {
            $at = null;
        }
        if ($at === null) {
            throw InventoryRejectedException::field('occurred_at', 'occurred_at is a date and time such as 2026-11-19T08:20:00Z');
        }
        $at = $at->setTimezone(new \DateTimeZone('UTC'));
        if ($at > $now->modify('+1 day')) {
            throw InventoryRejectedException::field('occurred_at', 'occurred_at can\'t be in the future');
        }

        return $at->format('Y-m-d H:i:s');
    }

    /**
     * @param list<int> $ids
     * @return array<string, mixed>
     */
    private function movedPayload(int $tenantId, ?int $actor, string $batchKey, string $sourceType, ?int $sourceId, array $ids): array
    {
        $moves = $this->batch($tenantId, $batchKey);

        return [
            'tenant_id' => $tenantId, 'batch_key' => $batchKey, 'source_type' => $sourceType, 'source_id' => $sourceId,
            'acting_profile_id' => $actor, 'move_ids' => $ids,
            'moves' => array_map(static fn (array $m): array => [
                'id' => $m['id'], 'variant_id' => $m['variant_id'], 'from_location_id' => $m['from_location_id'],
                'to_location_id' => $m['to_location_id'], 'quantity' => $m['quantity'],
            ], $moves),
        ];
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private static function presentMove(array $r): array
    {
        $decimals = (int) $r['decimals'];
        $entered = InventoryDb::int($r['entered_quantity']);

        return [
            'id' => (int) $r['id'],
            'item_id' => (int) $r['item_id'],
            'variant_id' => (int) $r['variant_id'],
            'sku' => (string) $r['sku'],
            'lot_id' => InventoryDb::int($r['lot_id']),
            'from_location_id' => (int) $r['from_location_id'],
            'to_location_id' => (int) $r['to_location_id'],
            'quantity' => (new Quantity((int) $r['quantity'], $decimals))->toDecimal(),
            'unit' => (string) $r['unit'],
            'decimals' => $decimals,
            'entered_quantity' => $entered !== null && is_string($r['entered_unit'])
                ? (new Quantity($entered, (int) $r['entered_decimals']))->toDecimal() : null,
            'entered_unit' => is_string($r['entered_unit']) ? $r['entered_unit'] : null,
            'unit_cost_minor' => InventoryDb::int($r['unit_cost_minor']),
            'currency' => is_string($r['currency']) ? $r['currency'] : null,
            'source_type' => (string) $r['source_type'],
            'source_id' => InventoryDb::int($r['source_id']),
            'source_line_id' => InventoryDb::int($r['source_line_id']),
            'reason_code' => (string) $r['reason_code'],
            'note' => (string) $r['note'],
            'batch_key' => (string) $r['batch_key'],
            'reverses_move_id' => InventoryDb::int($r['reverses_move_id']),
            'posted_by_profile_id' => InventoryDb::int($r['posted_by_profile_id']),
            'posted_by_name' => is_string($r['posted_by_name']) ? $r['posted_by_name'] : null,
            'agent_id' => InventoryDb::int($r['agent_id']),
            'on_behalf_of_profile_id' => InventoryDb::int($r['on_behalf_of_profile_id']),
            'occurred_at' => (string) $r['occurred_at'],
            'posted_at' => (string) $r['posted_at'],
        ];
    }
}
