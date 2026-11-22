<?php

declare(strict_types=1);

namespace Whity\Core\Inventory;

use Whity\Core\Db\DbBool;

/**
 * Why stock changed outside a sale, a delivery or a transfer (WHIT-1233,
 * design 31 §3.2 `inv_reasons`, register N5, IV.14).
 *
 * Each workspace starts with the seeded list ({@see InventoryWorkspace::SEEDED_REASONS})
 * and may add its own. A reason says whether it takes stock out, in, or both,
 * and whether it is waste (the waste figures add those up). Reasons are
 * archived, never deleted, because moves keep their code forever; seeded ones
 * keep their code but can be renamed or archived.
 *
 * TENANT-OWNED: every statement binds `tenant_id`.
 */
final class ReasonCatalog
{
    public const DIRECTIONS = ['out', 'in', 'both'];

    private const COLUMNS = 'id, code, name, names, direction, is_waste, is_seeded, sort_order, archived_at';

    public function __construct(
        private readonly InventoryDb $db,
        private readonly InventoryWorkspace $workspace,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(int $tenantId, bool $includeArchived = false): array
    {
        $this->workspace->ensure($tenantId);

        return array_map(self::present(...), $this->db->all(
            'SELECT ' . self::COLUMNS . ' FROM inv_reasons WHERE tenant_id = :tenant_id'
                . ($includeArchived ? '' : ' AND archived_at IS NULL')
                . ' ORDER BY sort_order, id',
            [':tenant_id' => $tenantId]
        ));
    }

    /**
     * A reason that can be used now for stock going this way.
     *
     * @param 'out'|'in' $direction
     * @return array<string, mixed>
     */
    public function usable(int $tenantId, string $code, string $direction, string $field = 'reason_code'): array
    {
        $this->workspace->ensure($tenantId);
        $row = $code === '' ? null : $this->db->one(
            'SELECT ' . self::COLUMNS . ' FROM inv_reasons WHERE tenant_id = :tenant_id AND code = :code AND archived_at IS NULL',
            [':tenant_id' => $tenantId, ':code' => $code]
        );
        if ($row === null || ((string) $row['direction'] !== 'both' && (string) $row['direction'] !== $direction)) {
            $codes = array_map(
                static fn (array $r): string => (string) $r['code'],
                array_filter($this->list($tenantId), static fn (array $r): bool => $r['direction'] === 'both' || $r['direction'] === $direction)
            );
            throw InventoryRejectedException::field($field, $field . ' must be one of ' . implode(', ', $codes) . ' for stock going ' . $direction);
        }

        return self::present($row);
    }

    /**
     * @param array<string, mixed> $input `code`, `name` (text or {en, ar}), `direction`, `is_waste`
     * @return array<string, mixed>
     */
    public function create(int $tenantId, array $input): array
    {
        $this->workspace->ensure($tenantId);
        $code = is_string($input['code'] ?? null) ? trim($input['code']) : '';
        if (preg_match('/^[a-z][a-z0-9_]{0,39}$/', $code) !== 1) {
            throw InventoryRejectedException::field('code', 'code is lower-case letters, digits and _, starting with a letter, up to 40');
        }
        $names = InventoryNames::normalize($input['name'] ?? null, 'name', 150);
        $direction = $this->direction($input['direction'] ?? 'out');
        $order = (int) ($this->db->one('SELECT MAX(sort_order) AS m FROM inv_reasons WHERE tenant_id = :tenant_id', [':tenant_id' => $tenantId])['m'] ?? 0) + 10;
        try {
            $id = $this->db->insert(
                'INSERT INTO inv_reasons (tenant_id, code, name, names, direction, is_waste, sort_order)
                 VALUES (:tenant_id, :code, :name, :names, :direction, :waste, :sort_order)',
                [
                    ':tenant_id' => $tenantId, ':code' => $code, ':name' => $names['name'], ':names' => $names['names'],
                    ':direction' => $direction, ':waste' => ($input['is_waste'] ?? false) === true, ':sort_order' => $order,
                ]
            );
        } catch (\PDOException $e) {
            if (InventoryDb::isUniqueViolation($e)) {
                throw InventoryRejectedException::field('code', 'There is already a reason with the code ' . $code, 409);
            }
            throw $e;
        }

        return $this->show($tenantId, $id);
    }

    /**
     * @param array<string, mixed> $changes `name`, `direction`, `is_waste`, `archived` (bool)
     * @return array<string, mixed>
     */
    public function update(int $tenantId, int $id, array $changes): array
    {
        $row = $this->row($tenantId, $id) ?? throw InventoryRejectedException::notFound('Reason');
        $sets = [];
        $params = [':tenant_id' => $tenantId, ':id' => $id];
        if (array_key_exists('name', $changes)) {
            $names = InventoryNames::normalize($changes['name'], 'name', 150);
            $sets[] = 'name = :name, names = :names';
            $params[':name'] = $names['name'];
            $params[':names'] = $names['names'];
        }
        if (array_key_exists('direction', $changes)) {
            if (DbBool::of($row['is_seeded']) && $changes['direction'] !== $row['direction']) {
                throw InventoryRejectedException::field('direction', 'A reason Whity added keeps its direction; add your own instead');
            }
            $sets[] = 'direction = :direction';
            $params[':direction'] = $this->direction($changes['direction']);
        }
        if (array_key_exists('is_waste', $changes)) {
            if (!is_bool($changes['is_waste'])) {
                throw InventoryRejectedException::field('is_waste', 'is_waste is true or false');
            }
            $sets[] = 'is_waste = :waste';
            $params[':waste'] = $changes['is_waste'];
        }
        if (array_key_exists('archived', $changes)) {
            if (!is_bool($changes['archived'])) {
                throw InventoryRejectedException::field('archived', 'archived is true or false');
            }
            if ($changes['archived'] && in_array((string) $row['code'], ['count_correction'], true)) {
                throw InventoryRejectedException::field('archived', 'Counts need the count difference reason, so it stays');
            }
            $sets[] = $changes['archived'] ? 'archived_at = COALESCE(archived_at, CURRENT_TIMESTAMP)' : 'archived_at = NULL';
        }
        if ($sets !== []) {
            $this->db->run(
                'UPDATE inv_reasons SET ' . implode(', ', $sets) . ', updated_at = CURRENT_TIMESTAMP WHERE tenant_id = :tenant_id AND id = :id',
                $params
            );
        }

        return $this->show($tenantId, $id);
    }

    /** @return array<string, mixed> */
    public function show(int $tenantId, int $id): array
    {
        return self::present($this->row($tenantId, $id) ?? throw InventoryRejectedException::notFound('Reason'));
    }

    /** @return array<string, true> the codes that count as waste */
    public function wasteCodes(int $tenantId): array
    {
        $out = [];
        foreach ($this->list($tenantId, true) as $r) {
            if ($r['is_waste']) {
                $out[(string) $r['code']] = true;
            }
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private function row(int $tenantId, int $id): ?array
    {
        return $this->db->one(
            'SELECT ' . self::COLUMNS . ' FROM inv_reasons WHERE tenant_id = :tenant_id AND id = :id',
            [':tenant_id' => $tenantId, ':id' => $id]
        );
    }

    private function direction(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, self::DIRECTIONS, true)) {
            throw InventoryRejectedException::field('direction', 'direction is out, in or both');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private static function present(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'code' => (string) $r['code'],
            'name' => (string) $r['name'],
            'names' => InventoryNames::decode($r['names'], (string) $r['name']),
            'direction' => (string) $r['direction'],
            'is_waste' => DbBool::of($r['is_waste']),
            'is_seeded' => DbBool::of($r['is_seeded']),
            'archived' => $r['archived_at'] !== null,
        ];
    }
}
