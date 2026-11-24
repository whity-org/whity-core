<?php

declare(strict_types=1);

namespace Whity\Core\Inventory;

/**
 * Core's cost of stock: a moving average per variant (WHIT-1318 A4a, design
 * section 31 D1, register IV.18), in the workspace currency.
 *
 * `inv_costs` keeps, per variant, the quantity across real places and what it
 * is worth. The stock ledger asks {@see self::value()} for each batch before
 * it writes the moves, in the same transaction:
 *
 * - IN (a virtual place to a real one: supplier, opening, found, a customer's
 *   return): worth the move's own cost when it has one in the workspace
 *   currency, else the current average; added to the variant.
 * - OUT (a real place to a virtual one: customer, loss, consumed): worth the
 *   average, `value × quantity / on hand`, rounded half up; taken off. Taking
 *   the last of it takes all the value, so nothing is left over.
 * - A REVERSAL is worth exactly what the move it undoes was worth.
 * - Between real places: worth the average, and the variant doesn't change.
 *
 * A variant's cost starts the first time a batch touches it (or when stock is
 * valued for the books): its stock then, times its purchase price when that
 * price is in the workspace currency. Without one its value is unknown (NULL),
 * and so is what its moves out are worth, until its stock returns to zero.
 *
 * TENANT-OWNED: every statement binds `tenant_id`.
 */
final class InventoryCosts
{
    /** @var array<string, int> steps per base unit, for one call (a long-lived worker must not keep them) */
    private array $scales = [];

    /** @param \Closure(int): string $currency the workspace's currency */
    public function __construct(
        private readonly InventoryDb $db,
        private readonly \Closure $currency,
    ) {
    }

    /**
     * What each line of a batch is worth, and the variants' costs moved on.
     * Runs inside the ledger's transaction, before the moves are written.
     *
     * @param list<array<string, mixed>> $lines each `variant_id`, `from_location_id`, `to_location_id`, `steps`,
     *     `unit_cost_minor`, `currency`, optional `value_minor` (a reversal's)
     * @param array<int, true> $virtual the workspace's virtual places
     * @return list<?int> per line
     */
    public function value(int $tenantId, array $lines, array $virtual): array
    {
        $this->scales = [];
        $base = $this->base($tenantId);
        $variants = array_values(array_unique(array_map(static fn (array $l): int => (int) $l['variant_id'], $lines)));
        sort($variants);
        $rows = [];
        foreach ($variants as $variantId) {
            $rows[$variantId] = $this->row($tenantId, $variantId, $base, true);
        }
        $out = [];
        foreach ($lines as $line) {
            $variantId = (int) $line['variant_id'];
            $steps = (int) $line['steps'];
            $fromReal = !isset($virtual[(int) $line['from_location_id']]);
            $toReal = !isset($virtual[(int) $line['to_location_id']]);
            $row = &$rows[$variantId];
            $given = array_key_exists('value_minor', $line) ? $line['value_minor'] : false;
            if (!$fromReal && $toReal) {
                $value = $given !== false ? (is_int($given) ? $given : null) : $this->incoming($tenantId, $line, $steps, $row, $base);
                // Empty (or short) stock takes the new value as it is; known
                // stock adds it; an unknown part makes the whole unknown.
                $row['value'] = match (true) {
                    $row['quantity'] <= 0 => $value,
                    $row['value'] === null, $value === null => null,
                    default => $row['value'] + $value,
                };
                $row['quantity'] += $steps;
            } elseif ($fromReal && !$toReal) {
                $value = $given !== false ? (is_int($given) ? $given : null) : self::average($row, $steps);
                $row['quantity'] -= $steps;
                $row['value'] = match (true) {
                    $row['quantity'] === 0 => 0,
                    $row['quantity'] < 0, $row['value'] === null, $value === null => null,
                    default => max(0, $row['value'] - $value),
                };
            } elseif ($fromReal) {
                $value = $given !== false ? (is_int($given) ? $given : null) : self::average($row, $steps);
            } else {
                $value = null;
            }
            unset($row);
            $out[] = $value;
        }
        foreach ($rows as $variantId => $row) {
            $this->db->run(
                'UPDATE inv_costs SET quantity = :quantity, value_minor = :value, updated_at = CURRENT_TIMESTAMP
                  WHERE tenant_id = :tenant_id AND variant_id = :variant_id',
                [':quantity' => $row['quantity'], ':value' => $row['value'], ':tenant_id' => $tenantId, ':variant_id' => $variantId]
            );
        }

        return $out;
    }

    /**
     * The stock's value now: every variant with stock, its quantity and value.
     * Variants not yet costed are costed first.
     *
     * @return array{currency: string, value_minor: int, unknown: list<int>}
     */
    public function stockValue(int $tenantId): array
    {
        $base = $this->base($tenantId);
        foreach ($this->db->all(
            "SELECT DISTINCT l.variant_id FROM inv_levels l
              WHERE l.tenant_id = :tenant_id AND l.on_hand <> 0
                AND NOT EXISTS (SELECT 1 FROM inv_costs c WHERE c.tenant_id = l.tenant_id AND c.variant_id = l.variant_id)
              ORDER BY l.variant_id",
            [':tenant_id' => $tenantId]
        ) as $r) {
            $this->row($tenantId, (int) $r['variant_id'], $base, false);
        }
        $value = 0;
        $unknown = [];
        foreach ($this->db->all(
            'SELECT variant_id, quantity, value_minor FROM inv_costs WHERE tenant_id = :tenant_id AND quantity <> 0 ORDER BY variant_id',
            [':tenant_id' => $tenantId]
        ) as $r) {
            if ($r['value_minor'] === null) {
                $unknown[] = (int) $r['variant_id'];
            } else {
                $value += (int) $r['value_minor'];
            }
        }

        return ['currency' => $base, 'value_minor' => $value, 'unknown' => $unknown];
    }

    /**
     * A variant's quantity, value and average cost of one base step (rounded).
     *
     * @return array{quantity: int, value_minor: ?int, average_minor: ?int, currency: string}
     */
    public function of(int $tenantId, int $variantId): array
    {
        $base = $this->base($tenantId);
        $row = $this->row($tenantId, $variantId, $base, false);

        return [
            'quantity' => $row['quantity'],
            'value_minor' => $row['value'],
            'average_minor' => $row['value'] !== null && $row['quantity'] > 0 ? self::divRound($row['value'], $row['quantity']) : null,
            'currency' => $base,
        ];
    }

    /**
     * The variant's cost row, made from its stock and purchase price the
     * first time.
     *
     * @return array{quantity: int, value: ?int}
     */
    private function row(int $tenantId, int $variantId, string $base, bool $lock): array
    {
        $found = $this->db->one(
            'SELECT quantity, value_minor FROM inv_costs WHERE tenant_id = :tenant_id AND variant_id = :variant_id' . ($lock && $this->db->isPostgres() ? ' FOR UPDATE' : ''),
            [':tenant_id' => $tenantId, ':variant_id' => $variantId]
        );
        if ($found !== null) {
            return ['quantity' => (int) $found['quantity'], 'value' => InventoryDb::int($found['value_minor'])];
        }
        $quantity = (int) ($this->db->one(
            'SELECT COALESCE(SUM(on_hand), 0) AS q FROM inv_levels WHERE tenant_id = :tenant_id AND variant_id = :variant_id',
            [':tenant_id' => $tenantId, ':variant_id' => $variantId]
        )['q'] ?? 0);
        $price = $this->db->one(
            'SELECT v.purchase_price_minor AS variant_price, i.purchase_price_minor AS item_price, i.currency, u.decimals
               FROM inv_variants v JOIN inv_items i ON i.id = v.item_id AND i.tenant_id = v.tenant_id
               LEFT JOIN inv_units u ON u.id = i.base_unit_id AND u.tenant_id = i.tenant_id
              WHERE v.tenant_id = :tenant_id AND v.id = :variant_id',
            [':tenant_id' => $tenantId, ':variant_id' => $variantId]
        );
        $unit = InventoryDb::int($price['variant_price'] ?? null) ?? InventoryDb::int($price['item_price'] ?? null);
        $currency = is_string($price['currency'] ?? null) && $price['currency'] !== '' ? strtoupper($price['currency']) : $base;
        // The price is per base unit (a kilogram); the quantity is in its steps (grams).
        $scale = 10 ** max(0, (int) ($price['decimals'] ?? 0));
        $value = $quantity === 0 ? 0 : (($unit !== null && $currency === $base) ? self::divRound($unit * $quantity, $scale) : null);
        $this->db->run(
            'INSERT INTO inv_costs (tenant_id, variant_id, quantity, value_minor, currency) VALUES (:tenant_id, :variant_id, :quantity, :value, :currency)
             ON CONFLICT (tenant_id, variant_id) DO NOTHING',
            [':tenant_id' => $tenantId, ':variant_id' => $variantId, ':quantity' => $quantity, ':value' => $value, ':currency' => $base]
        );

        return $lock ? $this->row($tenantId, $variantId, $base, true) : ['quantity' => $quantity, 'value' => $value];
    }

    /**
     * @param array<string, mixed> $line
     * @param array{quantity: int, value: ?int} $row
     */
    private function incoming(int $tenantId, array $line, int $steps, array $row, string $base): ?int
    {
        $unit = $line['unit_cost_minor'] ?? null;
        $currency = is_string($line['currency'] ?? null) && $line['currency'] !== '' ? strtoupper($line['currency']) : $base;
        if (is_int($unit) && $currency === $base) {
            // The cost is of one base unit (a kilogram); the quantity is in its steps (grams).
            return self::divRound($unit * $steps, $this->scale($tenantId, (int) $line['variant_id']));
        }
        // No price on the line: the average, or, into empty stock, the item's purchase price.
        $average = self::average($row, $steps);
        if ($average !== null || $row['quantity'] > 0) {
            return $average;
        }
        $price = $this->purchasePrice($tenantId, (int) $line['variant_id'], $base);

        return $price === null ? null : self::divRound($price * $steps, $this->scale($tenantId, (int) $line['variant_id']));
    }

    /** The variant's (or its item's) purchase price for one base unit, when it is in the workspace's currency. */
    private function purchasePrice(int $tenantId, int $variantId, string $base): ?int
    {
        $price = $this->db->one(
            'SELECT v.purchase_price_minor AS variant_price, i.purchase_price_minor AS item_price, i.currency
               FROM inv_variants v JOIN inv_items i ON i.id = v.item_id AND i.tenant_id = v.tenant_id
              WHERE v.tenant_id = :tenant_id AND v.id = :variant_id',
            [':tenant_id' => $tenantId, ':variant_id' => $variantId]
        );
        $unit = InventoryDb::int($price['variant_price'] ?? null) ?? InventoryDb::int($price['item_price'] ?? null);
        $currency = is_string($price['currency'] ?? null) && $price['currency'] !== '' ? strtoupper($price['currency']) : $base;

        return $unit !== null && $currency === $base ? $unit : null;
    }

    /** @param array{quantity: int, value: ?int} $row */
    private static function average(array $row, int $steps): ?int
    {
        if ($row['value'] === null || $row['quantity'] <= 0) {
            return null;
        }
        if ($steps >= $row['quantity']) {
            return $row['value'];
        }

        return self::divRound($row['value'] * $steps, $row['quantity']);
    }

    /**
     * Gives stock whose cost is unknown a cost: `unitCostMinor` for one base
     * unit, in the workspace's currency. Answers the stock's value now, or
     * null when its cost was already known (or there is none on hand).
     */
    public function setUnknown(int $tenantId, int $variantId, int $unitCostMinor): ?int
    {
        $this->scales = [];
        $row = $this->row($tenantId, $variantId, $this->base($tenantId), true);
        if ($row['value'] !== null || $row['quantity'] <= 0) {
            return null;
        }
        $value = self::divRound($unitCostMinor * $row['quantity'], $this->scale($tenantId, $variantId));
        $this->db->run(
            'UPDATE inv_costs SET value_minor = :value, updated_at = CURRENT_TIMESTAMP
              WHERE tenant_id = :tenant_id AND variant_id = :variant_id AND value_minor IS NULL',
            [':value' => $value, ':tenant_id' => $tenantId, ':variant_id' => $variantId]
        );

        return $value;
    }

    /** Steps in one base unit: 1000 for a kilogram kept in grams. */
    private function scale(int $tenantId, int $variantId): int
    {
        if (isset($this->scales[$tenantId . ':' . $variantId])) {
            return $this->scales[$tenantId . ':' . $variantId];
        }
        $decimals = $this->db->one(
            'SELECT u.decimals FROM inv_variants v JOIN inv_items i ON i.id = v.item_id AND i.tenant_id = v.tenant_id
               LEFT JOIN inv_units u ON u.id = i.base_unit_id AND u.tenant_id = i.tenant_id
              WHERE v.tenant_id = :tenant_id AND v.id = :variant_id',
            [':tenant_id' => $tenantId, ':variant_id' => $variantId]
        )['decimals'] ?? 0;

        return $this->scales[$tenantId . ':' . $variantId] = 10 ** max(0, (int) $decimals);
    }

    private static function divRound(int $n, int $d): int
    {
        return intdiv($n * 2 + $d, $d * 2);
    }

    private function base(int $tenantId): string
    {
        $currency = strtoupper(trim(($this->currency)($tenantId)));

        return preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : 'USD';
    }
}
