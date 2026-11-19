<?php

declare(strict_types=1);

namespace Whity\Core\Inventory;

use Whity\Core\Db\DbBool;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Sdk\Hooks\Events;
use Whity\Sdk\Hooks\HookVetoException;
use Whity\Sdk\Inventory\Quantity;
use Whity\Sdk\Sql\SequenceAllocator;

/**
 * Purchase orders (WHIT-1234, design 31 §3.7, register IV.12).
 *
 * 1. Draft: a supplier (a contact marked as one), the place it is received
 *    into, and lines in the unit they are bought in (a 25 kg bag), each with
 *    its price, filled from what the supplier sells when not given.
 * 2. Send: `inventory.order.submitting` may refuse it; then it travels the
 *    workspace's purchase route when one is chosen, or is approved at once
 *    (with no route, a person with the write permission confirms directly).
 * 3. Receive, in parts: each receipt is an operation (`GRN-0001`) posting
 *    `supplier → place` with the order's unit cost on each move. Receiving
 *    more than ordered past the workspace's tolerance (0% by default) is
 *    refused unless the person says so (`allow_over`), and is then flagged.
 *    A short line can be closed ("won't come"); when every line is received or
 *    closed the order is done.
 *
 * MONEY is minor units in the order's currency (CU.1-CU.4). A line is its
 * quantity in its unit times that unit's price, less its discount; tax is on
 * top. The cost on a move is per base unit, net of the discount.
 *
 * TENANT-OWNED: every statement binds `tenant_id`.
 */
final class PurchaseOrders
{
    public const STATUSES = ['draft', 'waiting_approval', 'approved', 'partly_done', 'done', 'cancelled'];
    public const MAX_LINES = 500;

    private const COLUMNS = 'o.id, o.kind, o.number, o.status, o.contact_id, o.location_id, o.expected_on, o.currency, o.subtotal_minor, o.tax_minor,
        o.total_minor, o.notes, o.external_ref, o.route_id, o.document_id, o.source_plugin, o.created_by_profile_id, o.agent_id,
        o.on_behalf_of_profile_id, o.approved_by_profile_id, o.submitted_at, o.approved_at, o.done_at, o.cancelled_at, o.created_at, o.updated_at';

    /**
     * @param \Closure(int): array<string, mixed> $settings the workspace's effective settings
     * @param \Closure(int): string $defaultCurrency the workspace's currency
     */
    public function __construct(
        private readonly InventoryDb $db,
        private readonly InventoryWorkspace $workspace,
        private readonly ItemService $items,
        private readonly UnitCatalog $units,
        private readonly StockOperations $operations,
        private readonly SupplierItems $suppliers,
        private readonly SequenceAllocator $sequences,
        private readonly \Closure $settings,
        private readonly \Closure $defaultCurrency,
        private readonly ?HookManager $hooks = null,
        private ?OperationApprovals $approvals = null,
    ) {
    }

    /** Wired after construction, like {@see StockOperations::useApprovals()}. */
    public function useApprovals(OperationApprovals $approvals): void
    {
        $this->approvals = $approvals;
    }

    // ── drafting ─────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $data `contact_id`, `location_id`, `expected_on`, `currency`, `notes`, `external_ref`, `lines` (each `variant_id`, `quantity`, `unit`, `price_minor`, `discount_bp`, `tax_bp`, `note`)
     * @param array{agent_id?: ?int, on_behalf_of?: ?int, idempotency_key?: ?string, source_plugin?: ?string} $meta
     * @return array{order: array<string, mixed>, repeated: bool}
     */
    public function create(int $tenantId, ?int $actor, array $data, array $meta = []): array
    {
        $this->workspace->ensure($tenantId);
        $key = $meta['idempotency_key'] ?? null;
        if ($key !== null && ($key === '' || strlen($key) > 128)) {
            throw InventoryRejectedException::field('idempotency_key', 'idempotency_key is text of up to 128 characters');
        }
        if (is_string($key) && ($done = $this->byKey($tenantId, $key)) !== null) {
            return ['order' => $done, 'repeated' => true];
        }
        $head = $this->head($tenantId, $data, null);
        $lines = $this->lines($tenantId, $data['lines'] ?? null, $head['contact_id']);

        $id = $this->db->transaction(function () use ($tenantId, $actor, $head, $lines, $meta, $key): int {
            $number = sprintf('PO-%04d', $this->sequences->next($tenantId, 'inventory:purchase_order'));
            $id = $this->db->insert(
                "INSERT INTO inv_orders (tenant_id, kind, number, status, contact_id, location_id, expected_on, currency, notes, external_ref,
                        idempotency_key, source_plugin, created_by_profile_id, agent_id, on_behalf_of_profile_id)
                 VALUES (:tenant_id, 'purchase', :number, 'draft', :contact_id, :location_id, :expected_on, :currency, :notes, :external_ref,
                        :key, :plugin, :actor, :agent_id, :on_behalf_of)",
                [
                    ':tenant_id' => $tenantId, ':number' => $number, ':contact_id' => $head['contact_id'], ':location_id' => $head['location_id'],
                    ':expected_on' => $head['expected_on'], ':currency' => $head['currency'], ':notes' => $head['notes'],
                    ':external_ref' => $head['external_ref'], ':key' => $key,
                    ':plugin' => is_string($meta['source_plugin'] ?? null) ? $meta['source_plugin'] : null, ':actor' => $actor,
                    ':agent_id' => InventoryDb::int($meta['agent_id'] ?? null), ':on_behalf_of' => InventoryDb::int($meta['on_behalf_of'] ?? null),
                ]
            );
            $this->writeLines($tenantId, $id, $lines);
            $this->event(Events::INVENTORY_ORDER_CREATED, $tenantId, $id, $actor);

            return $id;
        });

        return ['order' => $this->get($tenantId, $id), 'repeated' => false];
    }

    /**
     * Changes a draft: any of its head fields, and its lines (the whole list).
     *
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public function update(int $tenantId, int $id, array $changes): array
    {
        $order = $this->row($tenantId, $id) ?? throw InventoryRejectedException::notFound('Purchase order');
        if ($order['status'] !== 'draft') {
            throw new InventoryRejectedException('Only a draft can be changed; this one has been sent', 409);
        }
        $head = $this->head($tenantId, $changes + [
            'contact_id' => (int) $order['contact_id'], 'location_id' => (int) $order['location_id'],
            'expected_on' => $order['expected_on'], 'currency' => $order['currency'], 'notes' => $order['notes'], 'external_ref' => $order['external_ref'],
        ], $order);
        $lines = array_key_exists('lines', $changes) ? $this->lines($tenantId, $changes['lines'], $head['contact_id']) : null;

        $this->db->transaction(function () use ($tenantId, $id, $head, $lines): void {
            $this->db->run(
                'UPDATE inv_orders SET contact_id = :contact_id, location_id = :location_id, expected_on = :expected_on, currency = :currency,
                        notes = :notes, external_ref = :external_ref, updated_at = CURRENT_TIMESTAMP
                  WHERE tenant_id = :tenant_id AND id = :id',
                [
                    ':contact_id' => $head['contact_id'], ':location_id' => $head['location_id'], ':expected_on' => $head['expected_on'],
                    ':currency' => $head['currency'], ':notes' => $head['notes'], ':external_ref' => $head['external_ref'],
                    ':tenant_id' => $tenantId, ':id' => $id,
                ]
            );
            if ($lines !== null) {
                $this->db->run('DELETE FROM inv_order_lines WHERE tenant_id = :tenant_id AND order_id = :id', [':tenant_id' => $tenantId, ':id' => $id]);
                $this->writeLines($tenantId, $id, $lines);
            }
        });

        return $this->get($tenantId, $id);
    }

    // ── sending and approval ─────────────────────────────────────────────

    /**
     * Sends a draft on: to its route when the workspace chose one, otherwise
     * approved at once by the person sending it.
     *
     * @return array<string, mixed>
     */
    public function submit(int $tenantId, int $id, ?int $actor): array
    {
        $order = $this->row($tenantId, $id) ?? throw InventoryRejectedException::notFound('Purchase order');
        if ($order['status'] !== 'draft') {
            throw new InventoryRejectedException('Only a draft can be sent', 409);
        }
        $view = $this->get($tenantId, $id);
        try {
            $this->hooks?->dispatch(Events::INVENTORY_ORDER_SUBMITTING, [
                'tenant_id' => $tenantId, 'order_id' => $id, 'kind' => 'purchase', 'number' => $view['number'],
                'contact_id' => $view['contact_id'], 'location_id' => $view['location_id'], 'currency' => $view['currency'],
                'total_minor' => $view['total_minor'], 'acting_profile_id' => $actor,
                'lines' => array_map(static fn (array $l): array => [
                    'variant_id' => $l['variant_id'], 'quantity' => $l['base_quantity'], 'price_minor' => $l['price_minor'],
                    'line_total_minor' => $l['line_total_minor'],
                ], $view['lines']),
            ]);
        } catch (HookVetoException $e) {
            throw new InventoryRejectedException($e->reason(), 409, ['code' => 'vetoed']);
        }

        return $this->db->transaction(function () use ($tenantId, $id, $actor, $view): array {
            $this->db->run(
                "UPDATE inv_orders SET status = 'waiting_approval', submitted_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
                  WHERE tenant_id = :tenant_id AND id = :id AND status = 'draft'",
                [':tenant_id' => $tenantId, ':id' => $id]
            );
            $routeId = $this->approvals?->issue($tenantId, $actor, ['id' => $id, 'kind' => 'purchase', 'number' => $view['number']]);
            if ($routeId === null) {
                $this->approve($tenantId, $id, $actor);
            } else {
                $this->db->run(
                    'UPDATE inv_orders SET route_id = :route_id WHERE tenant_id = :tenant_id AND id = :id',
                    [':route_id' => $routeId, ':tenant_id' => $tenantId, ':id' => $id]
                );
            }

            return $this->get($tenantId, $id);
        });
    }

    /** The route approved it (or nobody had to). */
    public function approve(int $tenantId, int $id, ?int $by): void
    {
        $this->db->run(
            "UPDATE inv_orders SET status = 'approved', approved_at = CURRENT_TIMESTAMP, approved_by_profile_id = :by, updated_at = CURRENT_TIMESTAMP
              WHERE tenant_id = :tenant_id AND id = :id AND status = 'waiting_approval'",
            [':by' => $by, ':tenant_id' => $tenantId, ':id' => $id]
        );
        $order = $this->row($tenantId, $id);
        if ($order === null || $order['status'] !== 'approved') {
            throw new InventoryRejectedException('This purchase order has already been decided', 409);
        }
        $this->event(Events::INVENTORY_ORDER_APPROVED, $tenantId, $id, $by);
    }

    /** The route turned it down or sent it back: back to draft. */
    public function rejected(int $tenantId, int $id, ?int $by): void
    {
        $this->db->run(
            "UPDATE inv_orders SET status = 'draft', updated_at = CURRENT_TIMESTAMP WHERE tenant_id = :tenant_id AND id = :id AND status = 'waiting_approval'",
            [':tenant_id' => $tenantId, ':id' => $id]
        );
        $this->event(Events::INVENTORY_ORDER_REJECTED, $tenantId, $id, $by);
    }

    /**
     * Cancels an order nothing has been received against.
     *
     * @return array<string, mixed>
     */
    public function cancel(int $tenantId, int $id, ?int $actor): array
    {
        $order = $this->row($tenantId, $id) ?? throw InventoryRejectedException::notFound('Purchase order');
        if (in_array($order['status'], ['partly_done', 'done', 'cancelled'], true)) {
            throw new InventoryRejectedException(
                $order['status'] === 'partly_done' ? 'Part of it has arrived; close the lines that won\'t come instead' : 'This order is already ' . $order['status'],
                409
            );
        }
        $this->db->run(
            "UPDATE inv_orders SET status = 'cancelled', cancelled_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
              WHERE tenant_id = :tenant_id AND id = :id",
            [':tenant_id' => $tenantId, ':id' => $id]
        );
        $this->event(Events::INVENTORY_ORDER_CANCELLED, $tenantId, $id, $actor);

        return $this->get($tenantId, $id);
    }

    // ── receiving ────────────────────────────────────────────────────────

    /**
     * Receives what arrived against an approved order.
     *
     * @param list<mixed> $rawLines each `line_id`, `quantity` (decimal, in the line's unit unless `unit` says), `unit`, `note`
     * @param array{agent_id?: ?int, on_behalf_of?: ?int, idempotency_key?: ?string, source_plugin?: ?string} $meta
     * @return array{order: array<string, mixed>, receipt: array<string, mixed>, repeated: bool}
     */
    public function receive(int $tenantId, int $id, ?int $actor, array $rawLines, bool $allowOver = false, string $note = '', ?string $occurredAt = null, array $meta = []): array
    {
        $order = $this->row($tenantId, $id) ?? throw InventoryRejectedException::notFound('Purchase order');
        $key = $meta['idempotency_key'] ?? null;
        if (is_string($key) && $key !== '') {
            $done = $this->db->one(
                "SELECT id FROM inv_operations WHERE tenant_id = :tenant_id AND idempotency_key = :key AND kind = 'receipt'",
                [':tenant_id' => $tenantId, ':key' => $key]
            );
            if ($done !== null) {
                return ['order' => $this->get($tenantId, $id), 'receipt' => $this->operations->get($tenantId, (int) $done['id']), 'repeated' => true];
            }
        }
        if (!in_array($order['status'], ['approved', 'partly_done'], true)) {
            throw new InventoryRejectedException('Only an approved order can be received', 409);
        }
        if ($rawLines === [] || count($rawLines) > self::MAX_LINES) {
            throw InventoryRejectedException::field('lines', 'Receive 1 to ' . self::MAX_LINES . ' lines');
        }
        $tolerance = (int) ($this->setting($tenantId, SettingsRegistry::INVENTORY_OVER_RECEIPT_PERCENT) ?? '0');
        $lines = $this->lineRows($tenantId, $id);
        $byId = [];
        foreach ($lines as $l) {
            $byId[$l['id']] = $l;
        }

        $receipt = [];
        $added = [];
        foreach ($rawLines as $i => $raw) {
            $field = 'lines[' . $i . ']';
            $lineId = is_array($raw) ? ($raw['line_id'] ?? null) : null;
            $line = is_int($lineId) ? ($byId[$lineId] ?? null) : null;
            if ($line === null) {
                throw InventoryRejectedException::field($field, $field . '.line_id is not a line of this order');
            }
            if ($line['is_closed']) {
                throw InventoryRejectedException::field($field, $field . ': ' . $line['item_name'] . ' was closed as not coming');
            }
            $quantity = is_string($raw['quantity'] ?? null) || is_int($raw['quantity'] ?? null) ? trim((string) $raw['quantity']) : '';
            $unit = is_string($raw['unit'] ?? null) && $raw['unit'] !== '' ? $raw['unit'] : $line['unit'];
            $steps = $this->units->toBaseSteps($tenantId, $line['item_id'], $line['base_unit'], $quantity, $unit, $field . '.quantity');
            if ($steps['steps'] <= 0) {
                throw InventoryRejectedException::field($field, $field . '.quantity must be more than 0');
            }
            $after = $line['done'] + ($added[$line['id']] ?? 0) + $steps['steps'];
            $limit = intdiv($line['quantity'] * (100 + $tolerance), 100);
            if ($after > $limit && !$allowOver) {
                throw new InventoryRejectedException(
                    $field . ': that is more ' . $line['item_name'] . ' than ordered. Check it, and send allow_over to receive it anyway.',
                    409,
                    ['field' => $field . '.quantity', 'code' => 'over_receipt']
                );
            }
            $added[$line['id']] = ($added[$line['id']] ?? 0) + $steps['steps'];
            $receipt[] = [
                'variant_id' => $line['variant_id'], 'steps' => $steps['steps'], 'entered_unit_id' => $steps['unit_id'],
                'entered_quantity' => $steps['entered_steps'], 'order_line_id' => $line['id'],
                'unit_cost_minor' => $line['unit_cost_minor'], 'currency' => (string) $order['currency'],
                'note' => is_string($raw['note'] ?? null) ? mb_substr(trim($raw['note']), 0, 500) : '',
            ];
        }

        return $this->db->transaction(function () use ($tenantId, $id, $actor, $order, $receipt, $added, $note, $occurredAt, $meta): array {
            $op = $this->operations->receipt($tenantId, $actor, $id, (int) $order['location_id'], $receipt, $note, $occurredAt, $meta);
            foreach ($added as $lineId => $steps) {
                $this->db->run(
                    'UPDATE inv_order_lines SET done_quantity = done_quantity + :steps, updated_at = CURRENT_TIMESTAMP
                      WHERE tenant_id = :tenant_id AND order_id = :order_id AND id = :id',
                    [':steps' => $steps, ':tenant_id' => $tenantId, ':order_id' => $id, ':id' => $lineId]
                );
            }
            $this->settle($tenantId, $id, $actor, (int) $op['id']);

            return ['order' => $this->get($tenantId, $id), 'receipt' => $op, 'repeated' => false];
        });
    }

    /**
     * Closes a line short: what hasn't come won't. The order is done when
     * every line is received or closed.
     *
     * @return array<string, mixed>
     */
    public function closeLine(int $tenantId, int $id, int $lineId, ?int $actor): array
    {
        $order = $this->row($tenantId, $id) ?? throw InventoryRejectedException::notFound('Purchase order');
        if (!in_array($order['status'], ['approved', 'partly_done'], true)) {
            throw new InventoryRejectedException('Only lines of an approved order can be closed', 409);
        }
        $this->db->transaction(function () use ($tenantId, $id, $lineId, $actor): void {
            $this->db->run(
                'UPDATE inv_order_lines SET is_closed = TRUE, updated_at = CURRENT_TIMESTAMP WHERE tenant_id = :tenant_id AND order_id = :order_id AND id = :id',
                [':tenant_id' => $tenantId, ':order_id' => $id, ':id' => $lineId]
            );
            $this->settle($tenantId, $id, $actor, null);
        });

        return $this->get($tenantId, $id);
    }

    // ── reading ──────────────────────────────────────────────────────────

    /**
     * @param array{status?: ?string, contact_id?: ?int, location_id?: ?int} $filter
     * @return list<array<string, mixed>> newest first, without lines
     */
    public function list(int $tenantId, array $filter = [], int $limit = 50, int $offset = 0): array
    {
        $where = [];
        $params = [':tenant_id' => $tenantId];
        if (($filter['status'] ?? null) !== null) {
            if (!in_array($filter['status'], self::STATUSES, true)) {
                throw InventoryRejectedException::field('status', 'status is one of ' . implode(', ', self::STATUSES));
            }
            $where[] = 'o.status = :status';
            $params[':status'] = $filter['status'];
        }
        foreach (['contact_id' => 'o.contact_id', 'location_id' => 'o.location_id'] as $key => $column) {
            if (($filter[$key] ?? null) !== null) {
                $where[] = $column . ' = :' . $key;
                $params[':' . $key] = (int) $filter[$key];
            }
        }
        $rows = $this->db->all(
            "SELECT " . self::COLUMNS . ", c.name AS contact_name
               FROM inv_orders o JOIN contacts c ON c.tenant_id = o.tenant_id AND c.id = o.contact_id
              WHERE o.tenant_id = :tenant_id AND o.kind = 'purchase'" . ($where === [] ? '' : ' AND ' . implode(' AND ', $where)) . '
              ORDER BY o.id DESC LIMIT :limit OFFSET :offset',
            $params + [':limit' => max(1, min(200, $limit)), ':offset' => max(0, $offset)]
        );
        $progress = [];
        if ($rows !== []) {
            $ids = implode(',', array_map(static fn (array $r): int => (int) $r['id'], $rows));
            foreach ($this->db->all(
                'SELECT order_id, COUNT(*) AS n, SUM(quantity) AS ordered, SUM(CASE WHEN done_quantity > quantity THEN quantity ELSE done_quantity END) AS received
                   FROM inv_order_lines WHERE tenant_id = :tenant_id AND order_id IN (' . $ids . ') GROUP BY order_id',
                [':tenant_id' => $tenantId]
            ) as $p) {
                $progress[(int) $p['order_id']] = [(int) $p['n'], (int) $p['ordered'], (int) $p['received']];
            }
        }

        return array_map(function (array $r) use ($progress): array {
            [$n, $ordered, $received] = $progress[(int) $r['id']] ?? [0, 0, 0];

            return $this->present($r) + [
                'line_count' => $n,
                'received_percent' => $ordered > 0 ? (int) floor($received * 100 / $ordered) : 0,
            ];
        }, $rows);
    }

    /** @return array<string, mixed> */
    public function get(int $tenantId, int $id): array
    {
        $row = $this->db->one(
            "SELECT " . self::COLUMNS . ", c.name AS contact_name
               FROM inv_orders o JOIN contacts c ON c.tenant_id = o.tenant_id AND c.id = o.contact_id
              WHERE o.tenant_id = :tenant_id AND o.id = :id AND o.kind = 'purchase'",
            [':tenant_id' => $tenantId, ':id' => $id]
        ) ?? throw InventoryRejectedException::notFound('Purchase order');
        $order = $this->present($row);
        $order['lines'] = array_map(static function (array $l): array {
            $inUnit = static fn (int $steps): string => (new Quantity(
                Quantity::convert($steps, $l['base_decimals'], $l['den'], $l['num'], $l['unit_decimals']),
                $l['unit_decimals']
            ))->toDecimal();

            return [
                'id' => $l['id'], 'variant_id' => $l['variant_id'], 'item_id' => $l['item_id'], 'item_name' => $l['item_name'],
                'item_names' => $l['item_names'], 'sku' => $l['sku'], 'unit' => $l['unit'],
                'quantity' => (new Quantity($l['entered'], $l['unit_decimals']))->toDecimal(),
                'received' => $inUnit($l['done']),
                'base_unit' => (string) $l['base_unit']['code'],
                'base_quantity' => (new Quantity($l['quantity'], $l['base_decimals']))->toDecimal(),
                'price_minor' => $l['price_minor'], 'discount_bp' => $l['discount_bp'], 'tax_bp' => $l['tax_bp'],
                'line_total_minor' => $l['line_total_minor'], 'is_closed' => $l['is_closed'],
                'is_over' => $l['done'] > $l['quantity'], 'note' => $l['note'],
            ];
        }, $this->lineRows($tenantId, $id));
        $order['receipts'] = array_map(
            static fn (array $op): array => array_intersect_key($op, array_flip(['id', 'number', 'status', 'batch_key', 'done_at', 'created_by_name', 'line_count'])),
            $this->operations->list($tenantId, ['kind' => 'receipt', 'order_id' => $id], 200)
        );

        return $order;
    }

    /** @return array<string, mixed>|null */
    public function row(int $tenantId, int $id): ?array
    {
        return $this->db->one(
            "SELECT " . self::COLUMNS . " FROM inv_orders o WHERE o.tenant_id = :tenant_id AND o.id = :id AND o.kind = 'purchase'",
            [':tenant_id' => $tenantId, ':id' => $id]
        );
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $current
     * @return array{contact_id: int, location_id: int, expected_on: ?string, currency: string, notes: string, external_ref: string}
     */
    private function head(int $tenantId, array $data, ?array $current): array
    {
        $contact = $this->suppliers->supplier($tenantId, $data['contact_id'] ?? null, 'contact_id');
        $place = $data['location_id'] ?? null;
        $row = is_int($place) ? $this->db->one(
            'SELECT id, kind, is_active FROM inv_locations WHERE tenant_id = :tenant_id AND id = :id',
            [':tenant_id' => $tenantId, ':id' => $place]
        ) : null;
        if ($row === null || $row['kind'] === 'virtual' || !DbBool::of($row['is_active'])) {
            throw InventoryRejectedException::field('location_id', 'location_id is a place here, where it is received');
        }
        $expected = $data['expected_on'] ?? null;
        if ($expected !== null && $expected !== '') {
            if (!is_string($expected) || \DateTimeImmutable::createFromFormat('!Y-m-d', $expected) === false) {
                throw InventoryRejectedException::field('expected_on', 'expected_on is a date such as 2026-11-25');
            }
        } else {
            $expected = null;
        }
        $currency = is_string($data['currency'] ?? null) && $data['currency'] !== '' ? strtoupper($data['currency']) : ($this->defaultCurrency)($tenantId);
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw InventoryRejectedException::field('currency', 'currency is a 3-letter code');
        }

        return [
            'contact_id' => $contact['id'],
            'location_id' => (int) $row['id'],
            'expected_on' => is_string($expected) ? substr($expected, 0, 10) : null,
            'currency' => $currency,
            'notes' => is_string($data['notes'] ?? null) ? mb_substr(trim($data['notes']), 0, 2000) : '',
            'external_ref' => is_string($data['external_ref'] ?? null) ? mb_substr(trim($data['external_ref']), 0, 100) : '',
        ];
    }

    /**
     * Lines as given, checked, converted and priced.
     *
     * @return list<array<string, mixed>>
     */
    private function lines(int $tenantId, mixed $raw, int $contactId): array
    {
        if (!is_array($raw) || !array_is_list($raw) || $raw === [] || count($raw) > self::MAX_LINES) {
            throw InventoryRejectedException::field('lines', 'lines is a list of 1 to ' . self::MAX_LINES . ' {variant_id, quantity, unit, price_minor}');
        }
        $out = [];
        foreach ($raw as $i => $line) {
            $field = 'lines[' . $i . ']';
            if (!is_array($line)) {
                throw InventoryRejectedException::field($field, $field . ' must be an object');
            }
            $variantId = $line['variant_id'] ?? null;
            $found = is_int($variantId) ? $this->items->variantForStock($tenantId, $variantId) : null;
            if ($found === null || $found['item']['kind'] === 'service' || $found['item']['archived_at'] !== null || $found['variant']['archived_at'] !== null) {
                throw InventoryRejectedException::field($field, $field . '.variant_id is not an item with stock here');
            }
            $itemId = (int) $found['item']['id'];
            $unitCode = is_string($line['unit'] ?? null) && $line['unit'] !== '' ? $line['unit'] : (string) $found['unit']['code'];
            $quantity = is_string($line['quantity'] ?? null) || is_int($line['quantity'] ?? null) ? trim((string) $line['quantity']) : '';
            $steps = $this->units->toBaseSteps($tenantId, $itemId, $found['unit'], $quantity, $unitCode, $field . '.quantity');
            if ($steps['steps'] <= 0) {
                throw InventoryRejectedException::field($field, $field . '.quantity must be more than 0');
            }
            $price = $line['price_minor'] ?? null;
            if ($price === null) {
                $price = $this->suppliers->priceFor($tenantId, (int) $variantId, $contactId, $steps['unit_id'])['price_minor']
                    ?? ($steps['unit_id'] === (int) $found['unit']['id'] ? InventoryDb::int($found['item']['purchase_price_minor']) : null)
                    ?? 0;
            }
            if (!is_int($price) || $price < 0) {
                throw InventoryRejectedException::field($field, $field . '.price_minor is the price of one ' . $unitCode . ' in minor units');
            }
            foreach (['discount_bp', 'tax_bp'] as $bp) {
                $v = $line[$bp] ?? 0;
                if (!is_int($v) || $v < 0 || $v > 10000) {
                    throw InventoryRejectedException::field($field, $field . '.' . $bp . ' is basis points, 0 to 10000');
                }
            }
            $out[] = [
                'variant_id' => (int) $variantId, 'unit_id' => $steps['unit_id'], 'quantity' => $steps['steps'], 'entered' => $steps['entered_steps'],
                'unit_decimals' => (int) ($this->units->row($tenantId, $steps['unit_id'])['decimals'] ?? 0),
                'price_minor' => $price, 'discount_bp' => (int) ($line['discount_bp'] ?? 0), 'tax_bp' => (int) ($line['tax_bp'] ?? 0),
                'note' => is_string($line['note'] ?? null) ? mb_substr(trim($line['note']), 0, 500) : '',
            ];
        }

        return $out;
    }

    /** @param list<array<string, mixed>> $lines */
    private function writeLines(int $tenantId, int $orderId, array $lines): void
    {
        $subtotal = 0;
        $tax = 0;
        foreach ($lines as $i => $l) {
            $net = self::net($l['entered'], $l['unit_decimals'], $l['price_minor'], $l['discount_bp']);
            $subtotal += $net;
            $tax += self::share($net, $l['tax_bp']);
            $this->db->run(
                'INSERT INTO inv_order_lines (tenant_id, order_id, variant_id, unit_id, quantity, entered_quantity, price_minor, discount_bp, tax_bp,
                        line_total_minor, note, sort_order)
                 VALUES (:tenant_id, :order_id, :variant_id, :unit_id, :quantity, :entered, :price, :discount, :tax, :total, :note, :sort_order)',
                [
                    ':tenant_id' => $tenantId, ':order_id' => $orderId, ':variant_id' => $l['variant_id'], ':unit_id' => $l['unit_id'],
                    ':quantity' => $l['quantity'], ':entered' => $l['entered'], ':price' => $l['price_minor'], ':discount' => $l['discount_bp'],
                    ':tax' => $l['tax_bp'], ':total' => $net, ':note' => $l['note'], ':sort_order' => $i + 1,
                ]
            );
        }
        $this->db->run(
            'UPDATE inv_orders SET subtotal_minor = :subtotal, tax_minor = :tax, total_minor = :total, updated_at = CURRENT_TIMESTAMP
              WHERE tenant_id = :tenant_id AND id = :id',
            [':subtotal' => $subtotal, ':tax' => $tax, ':total' => $subtotal + $tax, ':tenant_id' => $tenantId, ':id' => $orderId]
        );
    }

    /** Partly done or done, after a receipt or a closed line. */
    private function settle(int $tenantId, int $id, ?int $actor, ?int $receiptId): void
    {
        $open = (int) ($this->db->one(
            'SELECT COUNT(*) AS n FROM inv_order_lines WHERE tenant_id = :tenant_id AND order_id = :id AND is_closed = FALSE AND done_quantity < quantity',
            [':tenant_id' => $tenantId, ':id' => $id]
        )['n'] ?? 0);
        $received = (int) ($this->db->one(
            'SELECT COUNT(*) AS n FROM inv_order_lines WHERE tenant_id = :tenant_id AND order_id = :id AND done_quantity > 0',
            [':tenant_id' => $tenantId, ':id' => $id]
        )['n'] ?? 0);
        $status = $open === 0 ? 'done' : ($received > 0 ? 'partly_done' : 'approved');
        $before = (string) ($this->row($tenantId, $id)['status'] ?? '');
        $this->db->run(
            'UPDATE inv_orders SET status = :status, done_at = ' . ($status === 'done' ? 'CURRENT_TIMESTAMP' : 'NULL') . ', updated_at = CURRENT_TIMESTAMP
              WHERE tenant_id = :tenant_id AND id = :id',
            [':status' => $status, ':tenant_id' => $tenantId, ':id' => $id]
        );
        if ($status === 'done' && $before !== 'done') {
            $this->event(Events::INVENTORY_ORDER_DONE, $tenantId, $id, $actor, ['operation_id' => $receiptId]);
        } elseif ($status === 'partly_done' && $receiptId !== null) {
            $this->event(Events::INVENTORY_ORDER_PARTLY_DONE, $tenantId, $id, $actor, ['operation_id' => $receiptId]);
        }
    }

    /** @return list<array<string, mixed>> */
    private function lineRows(int $tenantId, int $orderId): array
    {
        $rows = $this->db->all(
            'SELECT l.id, l.variant_id, l.unit_id, l.quantity, l.entered_quantity, l.done_quantity, l.price_minor, l.discount_bp, l.tax_bp,
                    l.line_total_minor, l.is_closed, l.note, v.item_id, v.sku, i.name AS item_name, i.names AS item_names, i.base_unit_id,
                    u.code AS unit, u.decimals AS unit_decimals
               FROM inv_order_lines l
               JOIN inv_variants v ON v.tenant_id = l.tenant_id AND v.id = l.variant_id
               JOIN inv_items i ON i.tenant_id = v.tenant_id AND i.id = v.item_id
               JOIN inv_units u ON u.tenant_id = l.tenant_id AND u.id = l.unit_id
              WHERE l.tenant_id = :tenant_id AND l.order_id = :order_id
              ORDER BY l.sort_order, l.id',
            [':tenant_id' => $tenantId, ':order_id' => $orderId]
        );

        return array_map(function (array $r) use ($tenantId): array {
            $base = $this->units->row($tenantId, (int) $r['base_unit_id']) ?? [];
            [$num, $den] = $this->units->factorToBase($tenantId, (int) $r['item_id'], $base, (string) $r['unit']) ?? [1, 1];
            $unitPrice = intdiv((int) $r['price_minor'] * (10000 - (int) $r['discount_bp']) + 5000, 10000);

            return [
                'id' => (int) $r['id'], 'variant_id' => (int) $r['variant_id'], 'item_id' => (int) $r['item_id'], 'sku' => (string) $r['sku'],
                'item_name' => (string) $r['item_name'], 'item_names' => InventoryNames::decode($r['item_names'], (string) $r['item_name']),
                'unit' => (string) $r['unit'], 'unit_decimals' => (int) $r['unit_decimals'], 'base_unit' => $base,
                'base_decimals' => (int) ($base['decimals'] ?? 0), 'num' => $num, 'den' => $den,
                'quantity' => (int) $r['quantity'], 'entered' => (int) $r['entered_quantity'], 'done' => (int) $r['done_quantity'],
                'price_minor' => (int) $r['price_minor'], 'discount_bp' => (int) $r['discount_bp'], 'tax_bp' => (int) $r['tax_bp'],
                'line_total_minor' => (int) $r['line_total_minor'], 'is_closed' => DbBool::of($r['is_closed']), 'note' => (string) $r['note'],
                // Cost of one base unit, net of the discount: one bought unit is num/den base units.
                'unit_cost_minor' => intdiv($unitPrice * $den * 2 + $num, $num * 2),
            ];
        }, $rows);
    }

    /** A line's value: quantity in its unit times its price, less its discount. */
    public static function net(int $enteredSteps, int $unitDecimals, int $priceMinor, int $discountBp): int
    {
        $scale = 10 ** $unitDecimals;
        $gross = intdiv($enteredSteps * $priceMinor * 2 + $scale, $scale * 2);

        return $gross - self::share($gross, $discountBp);
    }

    /** Basis points of an amount, rounded half up. */
    private static function share(int $amount, int $bp): int
    {
        return intdiv($amount * $bp + 5000, 10000);
    }

    /** @return array<string, mixed>|null */
    private function byKey(int $tenantId, string $key): ?array
    {
        $row = $this->db->one('SELECT id FROM inv_orders WHERE tenant_id = :tenant_id AND idempotency_key = :key', [':tenant_id' => $tenantId, ':key' => $key]);

        return $row === null ? null : $this->get($tenantId, (int) $row['id']);
    }

    private function setting(int $tenantId, string $key): ?string
    {
        $value = ($this->settings)($tenantId)[$key] ?? SettingsRegistry::defaults()[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /** @param array<string, mixed> $extra */
    private function event(string $event, int $tenantId, int $id, ?int $actor, array $extra = []): void
    {
        $row = $this->row($tenantId, $id);
        if ($row === null) {
            return;
        }
        $payload = [
            'tenant_id' => $tenantId, 'order_id' => $id, 'kind' => (string) $row['kind'], 'number' => (string) $row['number'],
            'contact_id' => (int) $row['contact_id'], 'status' => (string) $row['status'], 'acting_profile_id' => $actor,
        ] + $extra;
        $this->hooks?->dispatch($event, $payload);
        try {
            $this->hooks?->dispatchAsync($event . '.async', $payload);
        } catch (\Throwable $e) {
            error_log('[inventory] ' . $event . '.async could not be queued: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function present(array $r): array
    {
        $name = function (mixed $profileId): ?string {
            if (!is_numeric($profileId)) {
                return null;
            }
            $row = $this->db->one('SELECT display_name FROM profiles WHERE id = :id', [':id' => (int) $profileId]);

            return is_string($row['display_name'] ?? null) ? $row['display_name'] : null;
        };

        return [
            'id' => (int) $r['id'],
            'kind' => (string) $r['kind'],
            'number' => (string) $r['number'],
            'status' => (string) $r['status'],
            'contact_id' => (int) $r['contact_id'],
            'contact_name' => (string) ($r['contact_name'] ?? ''),
            'location_id' => (int) $r['location_id'],
            'expected_on' => is_string($r['expected_on']) ? substr($r['expected_on'], 0, 10) : null,
            'currency' => (string) $r['currency'],
            'subtotal_minor' => (int) $r['subtotal_minor'],
            'tax_minor' => (int) $r['tax_minor'],
            'total_minor' => (int) $r['total_minor'],
            'notes' => (string) $r['notes'],
            'external_ref' => (string) $r['external_ref'],
            'route_id' => InventoryDb::int($r['route_id']),
            'document_id' => InventoryDb::int($r['document_id']),
            'source_plugin' => is_string($r['source_plugin']) ? $r['source_plugin'] : null,
            'created_by_profile_id' => InventoryDb::int($r['created_by_profile_id']),
            'created_by_name' => $name($r['created_by_profile_id']),
            'agent_id' => InventoryDb::int($r['agent_id']),
            'on_behalf_of_profile_id' => InventoryDb::int($r['on_behalf_of_profile_id']),
            'approved_by_profile_id' => InventoryDb::int($r['approved_by_profile_id']),
            'approved_by_name' => $name($r['approved_by_profile_id']),
            'submitted_at' => is_string($r['submitted_at']) ? $r['submitted_at'] : null,
            'approved_at' => is_string($r['approved_at']) ? $r['approved_at'] : null,
            'done_at' => is_string($r['done_at']) ? $r['done_at'] : null,
            'cancelled_at' => is_string($r['cancelled_at']) ? $r['cancelled_at'] : null,
            'created_at' => (string) $r['created_at'],
            'updated_at' => (string) $r['updated_at'],
        ];
    }
}
