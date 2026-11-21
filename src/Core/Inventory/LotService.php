<?php

declare(strict_types=1);

namespace Whity\Core\Inventory;

use Whity\Core\Db\DbBool;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Sdk\Inventory\Quantity;

/**
 * Lots, serial numbers and expiry (WHIT-1235, design 31 §3.5, register IN.1).
 *
 * Off by default and turned on per item (`tracking` lot or serial, `expires`).
 * For a tracked item every stock line names its lot:
 *
 * - **Stock coming in** (from a supplier, opening stock, found) names a lot
 *   by id or by code. A code not seen before makes the lot, with its expiry
 *   (required when the item expires, unless its shelf life gives it) and the
 *   date it was made.
 * - **Stock leaving a place** (used, sold, lost, sent elsewhere) takes the
 *   lot it names, or, when it names none, the lots at that place that expire
 *   first (first expiring, first out); with FEFO off, the oldest. A line may
 *   become several, one per lot. Stock past what the lots hold goes from the
 *   last lot taken, as negative stock does without lots (IV.8).
 * - A **serial number** is a lot that holds exactly one piece.
 *
 * Moving between real places carries the lot with it.
 *
 * TENANT-OWNED: every statement binds `tenant_id`.
 */
final class LotService
{
    public const MAX_CODE = 64;

    /** @param (\Closure(int): array<string, mixed>)|null $settings the workspace's effective settings */
    public function __construct(
        private readonly InventoryDb $db,
        private readonly ?\Closure $settings = null,
    ) {
    }

    /**
     * One prepared ledger line for a tracked or untracked item, with its lot
     * worked out: the same line with `lot_id` set, or several, one per lot.
     * New lots are made here, so call it inside the posting transaction.
     *
     * @param array<string, mixed> $found `ItemService::variantForStock()`
     * @param array<string, mixed> $line a prepared line: `variant_id`, `from_location_id`, `to_location_id`, `steps`, ...
     * @param array<string, mixed> $lot what the line says about its lot: `lot_id`, `lot_code`, `expires_on`, `made_on`, `supplier_contact_id`
     * @param bool $fromIsVirtual stock comes in from outside (supplier, opening, found)
     * @return list<array<string, mixed>>
     */
    public function linesFor(int $tenantId, array $found, array $line, array $lot, bool $fromIsVirtual, string $field, string $occurredOn): array
    {
        $item = $found['item'];
        $tracking = (string) ($item['tracking'] ?? 'none');
        $named = ($lot['lot_id'] ?? null) !== null || trim((string) ($lot['lot_code'] ?? '')) !== '';
        if ($tracking === 'none') {
            if ($named) {
                throw InventoryRejectedException::field($field, $field . ': ' . $item['name'] . ' doesn\'t keep batches; turn batches on for it first');
            }

            return [$line + ['lot_id' => null]];
        }
        $variantId = (int) $line['variant_id'];
        $serial = $tracking === 'serial';
        if ($serial && $line['steps'] !== 10 ** (int) $found['unit']['decimals']) {
            throw InventoryRejectedException::field($field, $field . ': a serial number is one ' . $found['unit']['code'] . '; send one line for each');
        }

        if ($fromIsVirtual) {
            if (!$named) {
                throw InventoryRejectedException::field($field, $field . ': ' . $item['name'] . ' keeps ' . ($serial ? 'serial numbers' : 'batches') . '; give the ' . ($serial ? 'serial number' : 'batch code'));
            }
            $lotId = ($lot['lot_id'] ?? null) !== null
                ? $this->own($tenantId, $variantId, $lot['lot_id'], $field)['id']
                : $this->ensure($tenantId, $found, (string) $lot['lot_code'], $lot, $field, $occurredOn);
            if ($serial && $this->onHandAnywhere($tenantId, $lotId) > 0) {
                throw InventoryRejectedException::field($field, $field . ': serial number ' . $this->get($tenantId, $lotId)['code'] . ' is already in stock');
            }

            return [$line + ['lot_id' => $lotId]];
        }

        if ($named) {
            $lotId = ($lot['lot_id'] ?? null) !== null
                ? $this->own($tenantId, $variantId, $lot['lot_id'], $field)['id']
                : ($this->findByCode($tenantId, $variantId, (string) $lot['lot_code'])['id']
                    ?? throw InventoryRejectedException::field($field, $field . ': there is no batch "' . $lot['lot_code'] . '" of ' . $item['name']));

            return [$line + ['lot_id' => $lotId]];
        }

        return $this->pick($tenantId, $line, (int) $line['from_location_id'], $item, $field);
    }

    /**
     * Checks what a line says about its lot when an operation is made, so a
     * missing batch code or expiry is said then, not when it posts after an
     * approval. Makes nothing.
     *
     * @param array<string, mixed> $found
     * @param array<string, mixed> $lot
     */
    public function check(int $tenantId, array $found, array $lot, bool $fromIsVirtual, string $field): void
    {
        $item = $found['item'];
        $tracking = (string) ($item['tracking'] ?? 'none');
        $named = ($lot['lot_id'] ?? null) !== null || trim((string) ($lot['lot_code'] ?? '')) !== '';
        if ($tracking === 'none') {
            if ($named) {
                throw InventoryRejectedException::field($field, $field . ': ' . $item['name'] . ' doesn\'t keep batches; turn batches on for it first');
            }

            return;
        }
        $variantId = (int) $found['variant']['id'];
        if (($lot['lot_id'] ?? null) !== null) {
            $this->own($tenantId, $variantId, $lot['lot_id'], $field);
        }
        self::date($lot['expires_on'] ?? null, $field . '.expires_on');
        self::date($lot['made_on'] ?? null, $field . '.made_on');
        if (!$fromIsVirtual) {
            if (($lot['lot_id'] ?? null) === null && $named && $this->findByCode($tenantId, $variantId, (string) $lot['lot_code']) === null) {
                throw InventoryRejectedException::field($field, $field . ': there is no batch "' . $lot['lot_code'] . '" of ' . $item['name']);
            }

            return;
        }
        $serial = $tracking === 'serial';
        if (!$named) {
            throw InventoryRejectedException::field($field, $field . ': ' . $item['name'] . ' keeps ' . ($serial ? 'serial numbers' : 'batches') . '; give the ' . ($serial ? 'serial number' : 'batch code'));
        }
        if (($lot['lot_id'] ?? null) === null && DbBool::of($item['expires'] ?? false) && ($lot['expires_on'] ?? null) === null
            && InventoryDb::int($item['shelf_life_days'] ?? null) === null
            && $this->findByCode($tenantId, $variantId, (string) $lot['lot_code']) === null) {
            throw InventoryRejectedException::field($field, $field . ': ' . $item['name'] . ' expires; give the expiry date of batch ' . $lot['lot_code']);
        }
    }

    /**
     * First expiring, first out (or oldest first with FEFO off): the line
     * split over the lots at the place.
     *
     * @param array<string, mixed> $line
     * @param array<string, mixed> $item
     * @return list<array<string, mixed>>
     */
    private function pick(int $tenantId, array $line, int $placeId, array $item, string $field): array
    {
        $fefo = $this->setting($tenantId, SettingsRegistry::INVENTORY_FEFO) !== 'false';
        $order = $fefo ? 'CASE WHEN t.expires_on IS NULL THEN 1 ELSE 0 END, t.expires_on, t.id' : 't.id';
        $lots = $this->db->all(
            'SELECT l.lot_id, l.on_hand FROM inv_levels l JOIN inv_lots t ON t.tenant_id = l.tenant_id AND t.id = l.lot_id
              WHERE l.tenant_id = :tenant_id AND l.variant_id = :variant_id AND l.location_id = :location_id AND l.lot_id <> 0 AND l.on_hand > 0
              ORDER BY ' . $order,
            [':tenant_id' => $tenantId, ':variant_id' => $line['variant_id'], ':location_id' => $placeId]
        );
        if ($lots === []) {
            throw new InventoryRejectedException(
                $field . ': there is no batch of ' . $item['name'] . ' here to take from; choose the batch',
                409,
                ['field' => $field, 'code' => 'no_lot']
            );
        }
        $left = (int) $line['steps'];
        $out = [];
        foreach ($lots as $i => $l) {
            $take = $i === count($lots) - 1 ? $left : min($left, (int) $l['on_hand']);
            if ($take <= 0) {
                break;
            }
            $part = $line;
            $part['lot_id'] = (int) $l['lot_id'];
            $part['steps'] = $take;
            if ($take !== (int) $line['steps']) {
                // The entered quantity belonged to the whole line; each part is in base units.
                $part['entered_unit_id'] = null;
                $part['entered_quantity'] = null;
            }
            $out[] = $part;
            $left -= $take;
        }

        return $out;
    }

    /**
     * The lot with this code, made if it is new.
     *
     * @param array<string, mixed> $found
     * @param array<string, mixed> $lot `expires_on`, `made_on`, `supplier_contact_id`
     */
    private function ensure(int $tenantId, array $found, string $code, array $lot, string $field, string $occurredOn): int
    {
        $code = trim($code);
        if ($code === '' || mb_strlen($code) > self::MAX_CODE) {
            throw InventoryRejectedException::field($field, $field . ': a batch code is up to ' . self::MAX_CODE . ' characters');
        }
        $variantId = (int) $found['variant']['id'];
        $expires = self::date($lot['expires_on'] ?? null, $field . '.expires_on');
        $made = self::date($lot['made_on'] ?? null, $field . '.made_on');
        $existing = $this->findByCode($tenantId, $variantId, $code);
        if ($existing !== null) {
            if ($expires !== null && $existing['expires_on'] !== null && $existing['expires_on'] !== $expires) {
                throw InventoryRejectedException::field($field, $field . ': batch ' . $code . ' expires on ' . $existing['expires_on'] . ', not ' . $expires);
            }

            return (int) $existing['id'];
        }
        $item = $found['item'];
        if ($expires === null && DbBool::of($item['expires'] ?? false)) {
            $days = InventoryDb::int($item['shelf_life_days'] ?? null);
            if ($days === null) {
                throw InventoryRejectedException::field($field, $field . ': ' . $item['name'] . ' expires; give the expiry date of batch ' . $code);
            }
            $expires = (new \DateTimeImmutable($made ?? $occurredOn))->modify('+' . $days . ' days')->format('Y-m-d');
        }
        $supplier = InventoryDb::int($lot['supplier_contact_id'] ?? null);

        return $this->db->insert(
            'INSERT INTO inv_lots (tenant_id, variant_id, code, is_serial, made_on, expires_on, supplier_contact_id)
             VALUES (:tenant_id, :variant_id, :code, :is_serial, :made_on, :expires_on, :supplier)',
            [
                ':tenant_id' => $tenantId, ':variant_id' => $variantId, ':code' => $code,
                ':is_serial' => ($item['tracking'] ?? '') === 'serial', ':made_on' => $made, ':expires_on' => $expires, ':supplier' => $supplier,
            ]
        );
    }

    /** The batch OPENING of a variant, made if it isn't there: where stock goes when batches are turned on. */
    public function opening(int $tenantId, int $variantId): int
    {
        $found = $this->findByCode($tenantId, $variantId, 'OPENING');
        if ($found !== null) {
            return (int) $found['id'];
        }

        return $this->db->insert(
            "INSERT INTO inv_lots (tenant_id, variant_id, code, notes) VALUES (:tenant_id, :variant_id, 'OPENING', 'Stock already here when batches were turned on')",
            [':tenant_id' => $tenantId, ':variant_id' => $variantId]
        );
    }

    // ── reading ──────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function get(int $tenantId, int $lotId): array
    {
        $rows = $this->rows($tenantId, ['id' => $lotId]);

        return $rows[0] ?? throw InventoryRejectedException::notFound('Batch');
    }

    /** @return array<string, mixed>|null */
    public function findByCode(int $tenantId, int $variantId, string $code): ?array
    {
        return $this->rows($tenantId, ['variant_id' => $variantId, 'code' => trim($code)])[0] ?? null;
    }

    /**
     * The lots of an item or variant, with what each holds now.
     *
     * @param array{item_id?: ?int, variant_id?: ?int, with_empty?: bool} $filter
     * @return list<array<string, mixed>>
     */
    public function list(int $tenantId, array $filter): array
    {
        return $this->rows($tenantId, $filter);
    }

    /**
     * Lots with stock that expire within `$days` days of today, or have
     * expired, soonest first.
     *
     * @return list<array<string, mixed>>
     */
    public function expiring(int $tenantId, ?int $days = null, ?string $today = null): array
    {
        $days ??= (int) ($this->setting($tenantId, SettingsRegistry::INVENTORY_EXPIRY_WARNING_DAYS) ?? '7');
        $today ??= gmdate('Y-m-d');
        $until = (new \DateTimeImmutable($today))->modify('+' . max(0, $days) . ' days')->format('Y-m-d');

        return array_values(array_filter(
            $this->rows($tenantId, ['expires_by' => $until]),
            static fn (array $l): bool => $l['on_hand_steps'] > 0
        ));
    }

    /** On hand of one lot across real places, in base steps. */
    public function onHandAnywhere(int $tenantId, int $lotId): int
    {
        return (int) ($this->db->one(
            'SELECT SUM(on_hand) AS on_hand FROM inv_levels WHERE tenant_id = :tenant_id AND lot_id = :lot_id',
            [':tenant_id' => $tenantId, ':lot_id' => $lotId]
        )['on_hand'] ?? 0);
    }

    /**
     * Lots whose expiry should be told today: expiring within the window and
     * not yet warned, or expired and not yet told. Every workspace; for the
     * daily job.
     *
     * @return list<array{tenant_id: int, id: int, variant_id: int, code: string, expires_on: string, state: string}>
     */
    public function due(string $today): array
    {
        $out = [];
        $rows = $this->db->all(
            // Every workspace's lots: the daily expiry job reads them all, then works per tenant.
            'SELECT t.tenant_id, t.id, t.variant_id, t.code, t.expires_on, t.warned_on, t.expired_on_notice
               FROM inv_lots t
              WHERE t.expires_on IS NOT NULL AND t.expires_on <= :horizon AND t.expired_on_notice IS NULL
                AND EXISTS (SELECT 1 FROM inv_levels l WHERE l.tenant_id = t.tenant_id AND l.lot_id = t.id AND l.on_hand > 0)
              ORDER BY t.tenant_id, t.expires_on, t.id',
            [':horizon' => (new \DateTimeImmutable($today))->modify('+365 days')->format('Y-m-d')]
        );
        foreach ($rows as $r) {
            $tenantId = (int) $r['tenant_id'];
            $expires = substr((string) $r['expires_on'], 0, 10);
            $window = (int) ($this->setting($tenantId, SettingsRegistry::INVENTORY_EXPIRY_WARNING_DAYS) ?? '7');
            if ($expires < $today) {
                $state = 'expired';
            } elseif ($r['warned_on'] === null && $expires <= (new \DateTimeImmutable($today))->modify('+' . $window . ' days')->format('Y-m-d')) {
                $state = 'expiring';
            } else {
                continue;
            }
            $out[] = ['tenant_id' => $tenantId, 'id' => (int) $r['id'], 'variant_id' => (int) $r['variant_id'], 'code' => (string) $r['code'], 'expires_on' => $expires, 'state' => $state];
        }

        return $out;
    }

    public function markTold(int $tenantId, int $lotId, string $state, string $today): void
    {
        $column = $state === 'expired' ? 'expired_on_notice' : 'warned_on';
        $this->db->run(
            'UPDATE inv_lots SET ' . $column . ' = :today, updated_at = CURRENT_TIMESTAMP WHERE tenant_id = :tenant_id AND id = :id',
            [':tenant_id' => $tenantId, ':id' => $lotId, ':today' => $today]
        );
    }

    /**
     * Changes what is known about a lot: its expiry, the date it was made, notes.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(int $tenantId, int $lotId, array $input): array
    {
        $lot = $this->get($tenantId, $lotId);
        $sets = [];
        $params = [':tenant_id' => $tenantId, ':id' => $lotId];
        foreach (['expires_on', 'made_on'] as $field) {
            if (array_key_exists($field, $input)) {
                $sets[] = $field . ' = :' . $field;
                $params[':' . $field] = self::date($input[$field], $field);
            }
        }
        if (array_key_exists('expires_on', $input)) {
            // A new date may need saying again.
            $sets[] = 'warned_on = NULL';
            $sets[] = 'expired_on_notice = NULL';
        }
        if (array_key_exists('notes', $input)) {
            if (!is_string($input['notes']) || mb_strlen($input['notes']) > 500) {
                throw InventoryRejectedException::field('notes', 'notes is text of up to 500 characters');
            }
            $sets[] = 'notes = :notes';
            $params[':notes'] = trim($input['notes']);
        }
        if ($sets !== []) {
            $this->db->run('UPDATE inv_lots SET ' . implode(', ', $sets) . ', updated_at = CURRENT_TIMESTAMP WHERE tenant_id = :tenant_id AND id = :id', $params);
        }

        return $sets === [] ? $lot : $this->get($tenantId, $lotId);
    }

    /**
     * @param array{id?: int, variant_id?: ?int, item_id?: ?int, code?: string, expires_by?: string, with_empty?: bool} $filter
     * @return list<array<string, mixed>>
     */
    private function rows(int $tenantId, array $filter): array
    {
        $where = ['t.tenant_id = :tenant_id'];
        $params = [':tenant_id' => $tenantId];
        foreach (['id' => 't.id', 'variant_id' => 't.variant_id', 'item_id' => 'v.item_id'] as $key => $column) {
            if (($filter[$key] ?? null) !== null) {
                $where[] = $column . ' = :' . $key;
                $params[':' . $key] = (int) $filter[$key];
            }
        }
        if (($filter['code'] ?? null) !== null) {
            $where[] = 't.code = :code';
            $params[':code'] = $filter['code'];
        }
        if (($filter['expires_by'] ?? null) !== null) {
            $where[] = 't.expires_on IS NOT NULL AND t.expires_on <= :expires_by';
            $params[':expires_by'] = $filter['expires_by'];
        }
        $rows = $this->db->all(
            'SELECT t.id, t.variant_id, t.code, t.is_serial, t.made_on, t.expires_on, t.supplier_contact_id, t.notes, t.created_at,
                    v.item_id, v.sku, i.name AS item_name, i.names AS item_names, u.code AS unit, u.decimals,
                    c.name AS supplier_name,
                    (SELECT SUM(l.on_hand) FROM inv_levels l WHERE l.tenant_id = t.tenant_id AND l.lot_id = t.id) AS on_hand
               FROM inv_lots t
               JOIN inv_variants v ON v.tenant_id = t.tenant_id AND v.id = t.variant_id
               JOIN inv_items i ON i.tenant_id = v.tenant_id AND i.id = v.item_id
               JOIN inv_units u ON u.tenant_id = i.tenant_id AND u.id = i.base_unit_id
               LEFT JOIN contacts c ON c.tenant_id = t.tenant_id AND c.id = t.supplier_contact_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY CASE WHEN t.expires_on IS NULL THEN 1 ELSE 0 END, t.expires_on, t.id
              LIMIT 1000',
            $params
        );
        $places = $this->places($tenantId, array_map(static fn (array $r): int => (int) $r['id'], $rows));
        $out = [];
        foreach ($rows as $r) {
            $decimals = (int) $r['decimals'];
            $onHand = (int) ($r['on_hand'] ?? 0);
            if ($onHand === 0 && !($filter['with_empty'] ?? true)) {
                continue;
            }
            $out[] = [
                'id' => (int) $r['id'],
                'variant_id' => (int) $r['variant_id'],
                'item_id' => (int) $r['item_id'],
                'item_name' => (string) $r['item_name'],
                'item_names' => InventoryNames::decode($r['item_names'], (string) $r['item_name']),
                'sku' => (string) $r['sku'],
                'code' => (string) $r['code'],
                'is_serial' => DbBool::of($r['is_serial']),
                'made_on' => $r['made_on'] === null ? null : substr((string) $r['made_on'], 0, 10),
                'expires_on' => $r['expires_on'] === null ? null : substr((string) $r['expires_on'], 0, 10),
                'supplier_contact_id' => InventoryDb::int($r['supplier_contact_id']),
                'supplier_name' => is_string($r['supplier_name']) ? $r['supplier_name'] : null,
                'notes' => (string) $r['notes'],
                'on_hand' => (new Quantity($onHand, $decimals))->toDecimal(),
                'on_hand_steps' => $onHand,
                'unit' => (string) $r['unit'],
                'places' => array_map(static fn (array $p): array => [
                    'location_id' => $p['location_id'], 'on_hand' => (new Quantity($p['steps'], $decimals))->toDecimal(),
                ], $places[(int) $r['id']] ?? []),
                'created_at' => (string) $r['created_at'],
            ];
        }

        return $out;
    }

    /**
     * @param list<int> $lotIds
     * @return array<int, list<array{location_id: int, steps: int}>>
     */
    private function places(int $tenantId, array $lotIds): array
    {
        if ($lotIds === []) {
            return [];
        }
        $names = [];
        $params = [];
        foreach ($lotIds as $i => $id) {
            $names[] = ':lot' . $i;
            $params[':lot' . $i] = $id;
        }
        $out = [];
        foreach ($this->db->all(
            'SELECT lot_id, location_id, on_hand FROM inv_levels WHERE tenant_id = :tenant_id AND on_hand <> 0 AND lot_id IN (' . implode(', ', $names) . ')
              ORDER BY lot_id, location_id',
            [':tenant_id' => $tenantId] + $params
        ) as $r) {
            $out[(int) $r['lot_id']][] = ['location_id' => (int) $r['location_id'], 'steps' => (int) $r['on_hand']];
        }

        return $out;
    }

    /** @return array{id: int} */
    private function own(int $tenantId, int $variantId, mixed $lotId, string $field): array
    {
        $row = is_int($lotId) ? $this->db->one(
            'SELECT id FROM inv_lots WHERE tenant_id = :tenant_id AND id = :id AND variant_id = :variant_id',
            [':tenant_id' => $tenantId, ':id' => $lotId, ':variant_id' => $variantId]
        ) : null;
        if ($row === null) {
            throw InventoryRejectedException::field($field, $field . '.lot_id is not a batch of this item');
        }

        return ['id' => (int) $row['id']];
    }

    private static function date(mixed $raw, string $field): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_string($raw) || preg_match('/^\d{4}-\d{2}-\d{2}$/', InventoryNames::westernDigits(trim($raw))) !== 1) {
            throw InventoryRejectedException::field($field, $field . ' is a date such as 2027-03-31');
        }
        $date = InventoryNames::westernDigits(trim($raw));
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw InventoryRejectedException::field($field, $field . ' is a date such as 2027-03-31');
        }

        return $date;
    }

    private function setting(int $tenantId, string $key): ?string
    {
        $value = ($this->settings === null ? [] : ($this->settings)($tenantId))[$key] ?? SettingsRegistry::defaults()[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
