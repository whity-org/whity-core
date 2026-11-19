<?php

declare(strict_types=1);

namespace Whity\Core\Inventory;

use Whity\Core\Db\DbBool;
use Whity\Sdk\Inventory\Quantity;

/**
 * A workspace's units of measure and the exact conversions between them
 * (WHIT-1232, register IV.3, IV.5).
 *
 * Within a dimension, units convert through their `to_base` fraction (g to
 * kg). Across dimensions, and for packages (bag, box, pack), only an item's
 * own units convert ("1 bag of flour is 25 kg"). Every conversion is a
 * whole-number fraction reduced by the greatest common divisor; nothing here
 * is ever a float.
 */
final class UnitCatalog
{
    public const DIMENSIONS = ['count', 'mass', 'volume', 'length', 'area', 'time', 'package'];

    public function __construct(
        private readonly InventoryDb $db,
        private readonly InventoryWorkspace $workspace,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(int $tenantId, bool $withArchived = false): array
    {
        $this->workspace->ensure($tenantId);
        $rows = $this->db->all(
            'SELECT id, code, name, names, dimension, decimals, to_base_num, to_base_den, is_seeded, archived_at
               FROM inv_units WHERE tenant_id = :tenant_id' . ($withArchived ? '' : ' AND archived_at IS NULL') . '
              ORDER BY is_seeded DESC, id',
            [':tenant_id' => $tenantId]
        );

        return array_map(self::present(...), $rows);
    }

    /** @return array<string, mixed>|null the row */
    public function row(int $tenantId, int $unitId): ?array
    {
        return $this->db->one(
            'SELECT id, code, name, names, dimension, decimals, to_base_num, to_base_den, is_seeded, archived_at
               FROM inv_units WHERE tenant_id = :tenant_id AND id = :id',
            [':tenant_id' => $tenantId, ':id' => $unitId]
        );
    }

    /** @return array<string, mixed>|null the row */
    public function rowByCode(int $tenantId, string $code): ?array
    {
        $this->workspace->ensure($tenantId);

        return $this->db->one(
            'SELECT id, code, name, names, dimension, decimals, to_base_num, to_base_den, is_seeded, archived_at
               FROM inv_units WHERE tenant_id = :tenant_id AND code = :code',
            [':tenant_id' => $tenantId, ':code' => strtolower(trim($code))]
        );
    }

    /**
     * @param array<string, mixed> $input `code`, `name`, `dimension`, `decimals`, `to_base` (a decimal: how many of the dimension's base unit one is)
     * @return array<string, mixed>
     */
    public function create(int $tenantId, array $input): array
    {
        $this->workspace->ensure($tenantId);
        $code = is_string($input['code'] ?? null) ? strtolower(trim($input['code'])) : '';
        if (preg_match('/^[a-z][a-z0-9_]{0,19}$/u', $code) !== 1) {
            throw InventoryRejectedException::field('code', 'code is up to 20 lower-case letters, digits and _, starting with a letter');
        }
        $names = InventoryNames::normalize($input['name'] ?? $code, 'name', 100);
        $dimension = is_string($input['dimension'] ?? null) ? $input['dimension'] : 'count';
        if (!in_array($dimension, self::DIMENSIONS, true)) {
            throw InventoryRejectedException::field('dimension', 'dimension must be one of ' . implode(', ', self::DIMENSIONS));
        }
        $decimals = $input['decimals'] ?? 0;
        if (!is_int($decimals) || $decimals < 0 || $decimals > Quantity::MAX_DECIMALS) {
            throw InventoryRejectedException::field('decimals', 'decimals must be a whole number from 0 to ' . Quantity::MAX_DECIMALS);
        }
        [$num, $den] = self::fraction($input['to_base'] ?? '1', 'to_base');

        try {
            $id = $this->db->insert(
                'INSERT INTO inv_units (tenant_id, code, name, names, dimension, decimals, to_base_num, to_base_den)
                 VALUES (:tenant_id, :code, :name, :names, :dimension, :decimals, :num, :den)',
                [':tenant_id' => $tenantId, ':code' => $code, ':name' => $names['name'], ':names' => $names['names'],
                    ':dimension' => $dimension, ':decimals' => $decimals, ':num' => $num, ':den' => $den]
            );
        } catch (\PDOException $e) {
            if (InventoryDb::isUniqueViolation($e)) {
                throw new InventoryRejectedException('There is already a unit "' . $code . '"', 409, ['field' => 'code']);
            }
            throw $e;
        }

        return self::present($this->row($tenantId, $id) ?? []);
    }

    /**
     * How many of the item's base unit one `$unitCode` is, as [num, den], and
     * the unit's row. Null when they don't convert.
     *
     * @param array<string, mixed> $baseUnit the item's base unit row
     * @return array{0: int, 1: int, 2: array<string, mixed>}|null
     */
    public function factorToBase(int $tenantId, int $itemId, array $baseUnit, string $unitCode): ?array
    {
        $unit = $this->rowByCode($tenantId, $unitCode);
        if ($unit === null || $unit['archived_at'] !== null) {
            return null;
        }
        if ((int) $unit['id'] === (int) $baseUnit['id']) {
            return [1, 1, $unit];
        }
        $own = $this->db->one(
            'SELECT num, den FROM inv_item_units WHERE tenant_id = :tenant_id AND item_id = :item_id AND unit_id = :unit_id',
            [':tenant_id' => $tenantId, ':item_id' => $itemId, ':unit_id' => (int) $unit['id']]
        );
        if ($own !== null) {
            return [...self::reduce((int) $own['num'], (int) $own['den']), $unit];
        }
        if ($unit['dimension'] !== $baseUnit['dimension'] || $unit['dimension'] === 'package') {
            return null;
        }

        // (unit → dimension base) ÷ (item base → dimension base)
        return [...self::reduce(
            (int) $unit['to_base_num'] * (int) $baseUnit['to_base_den'],
            (int) $unit['to_base_den'] * (int) $baseUnit['to_base_num'],
        ), $unit];
    }

    /**
     * A decimal quantity typed in `$unitCode` as steps of the item's base unit.
     *
     * @param array<string, mixed> $baseUnit
     * @return array{steps: int, unit_id: int, entered_steps: int}
     * @throws InventoryRejectedException
     */
    public function toBaseSteps(int $tenantId, int $itemId, array $baseUnit, string $quantity, ?string $unitCode, string $field): array
    {
        $code = $unitCode ?? (string) $baseUnit['code'];
        $factor = $this->factorToBase($tenantId, $itemId, $baseUnit, $code);
        if ($factor === null) {
            throw InventoryRejectedException::field($field, '"' . $code . '" doesn\'t convert to ' . $baseUnit['code'] . ' for this item');
        }
        [$num, $den, $unit] = $factor;
        try {
            $entered = Quantity::parse($quantity, (int) $unit['decimals']);
        } catch (\InvalidArgumentException $e) {
            throw InventoryRejectedException::field($field, $e->getMessage());
        }
        $steps = Quantity::convert($entered->steps, (int) $unit['decimals'], $num, $den, (int) $baseUnit['decimals']);

        return ['steps' => $steps, 'unit_id' => (int) $unit['id'], 'entered_steps' => $entered->steps];
    }

    /**
     * A positive decimal ("25", "0.5") as an exact reduced fraction.
     *
     * @return array{0: int, 1: int}
     * @throws InventoryRejectedException
     */
    public static function fraction(mixed $raw, string $field): array
    {
        $text = is_int($raw) ? (string) $raw : (is_string($raw) ? trim($raw) : '');
        if (preg_match('/^(\d{1,12})(?:\.(\d{1,6}))?$/', $text, $m) !== 1) {
            throw InventoryRejectedException::field($field, $field . ' must be a positive number such as 25 or 0.5');
        }
        $decimals = strlen($m[2] ?? '');
        $num = (int) ($m[1] . ($m[2] ?? ''));
        if ($num <= 0) {
            throw InventoryRejectedException::field($field, $field . ' must be more than 0');
        }

        return self::reduce($num, 10 ** $decimals);
    }

    /** @return array{0: int, 1: int} */
    public static function reduce(int $num, int $den): array
    {
        $a = abs($num);
        $b = abs($den);
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }
        $gcd = max(1, $a);

        return [intdiv($num, $gcd), intdiv($den, $gcd)];
    }

    /** "25" or "0.5" for a fraction that ends, else "num/den". */
    public static function fractionText(int $num, int $den): string
    {
        for ($decimals = 0; $decimals <= Quantity::MAX_DECIMALS; $decimals++) {
            $scaled = $num * 10 ** $decimals;
            if ($scaled % $den === 0) {
                return (new Quantity(intdiv($scaled, $den), $decimals))->toDecimal();
            }
        }

        return $num . '/' . $den;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function present(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'code' => (string) ($row['code'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'names' => InventoryNames::decode($row['names'] ?? null, (string) ($row['name'] ?? '')),
            'dimension' => (string) ($row['dimension'] ?? 'count'),
            'decimals' => (int) ($row['decimals'] ?? 0),
            'to_base_num' => (int) ($row['to_base_num'] ?? 1),
            'to_base_den' => (int) ($row['to_base_den'] ?? 1),
            'seeded' => DbBool::of($row['is_seeded'] ?? false),
            'archived' => ($row['archived_at'] ?? null) !== null,
        ];
    }
}
