<?php

declare(strict_types=1);

namespace Whity\Core\Inventory;

use Whity\Core\Db\DbBool;

/**
 * Places stock is kept (WHIT-1232, register IV.7): warehouses, shops and vans,
 * areas inside them, and the workspace's virtual places.
 *
 * TENANT-OWNED: every statement binds `tenant_id`. A place may belong to an
 * organisational unit (its branch); the unit must be in the same workspace.
 * Places are deactivated, never deleted, because the ledger points at them.
 */
final class LocationService
{
    public const KINDS = ['warehouse', 'area'];

    private const COLUMNS = 'id, parent_id, kind, virtual_role, name, names, code, ou_id, address, is_active, sort_order, created_at, updated_at';

    public function __construct(
        private readonly InventoryDb $db,
        private readonly InventoryWorkspace $workspace,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(int $tenantId, bool $includeVirtual = false, bool $includeInactive = false): array
    {
        $this->workspace->ensure($tenantId);
        $rows = $this->db->all(
            'SELECT ' . self::COLUMNS . ' FROM inv_locations WHERE tenant_id = :tenant_id'
                . ($includeVirtual ? '' : " AND kind <> 'virtual'")
                . ($includeInactive ? '' : ' AND is_active = TRUE')
                // In the order they were added, as TplInventory's columns are
                // (the central kitchen first, then the shops it feeds).
                . " ORDER BY CASE WHEN kind = 'virtual' THEN 1 ELSE 0 END, sort_order, id",
            [':tenant_id' => $tenantId]
        );

        return array_map(self::present(...), $rows);
    }

    /** @return array<string, mixed>|null */
    public function row(int $tenantId, int $id): ?array
    {
        return $this->db->one(
            'SELECT ' . self::COLUMNS . ' FROM inv_locations WHERE tenant_id = :tenant_id AND id = :id',
            [':tenant_id' => $tenantId, ':id' => $id]
        );
    }

    /** @return array<string, mixed> */
    public function show(int $tenantId, int $id): array
    {
        return self::present($this->row($tenantId, $id) ?? throw InventoryRejectedException::notFound('Place'));
    }

    /** @return array<string, mixed> */
    public function virtual(int $tenantId, string $role): array
    {
        return $this->show($tenantId, $this->workspace->virtualId($tenantId, $role));
    }

    /**
     * @param array<string, mixed> $input `name`, `kind`, `parent_id`, `code`, `ou_id`, `address`
     * @return array<string, mixed>
     */
    public function create(int $tenantId, array $input): array
    {
        $this->workspace->ensure($tenantId);
        $names = InventoryNames::normalize($input['name'] ?? null, 'name', 150);
        $values = $this->validate($tenantId, $input, null);
        $kind = $values['kind'] ?? 'warehouse';
        if ($kind === 'area' && ($values['parent_id'] ?? null) === null) {
            throw InventoryRejectedException::field('parent_id', 'An area is inside a place: give its parent_id');
        }
        $id = $this->db->insert(
            'INSERT INTO inv_locations (tenant_id, parent_id, kind, name, names, code, ou_id, address)
             VALUES (:tenant_id, :parent_id, :kind, :name, :names, :code, :ou_id, :address)',
            [
                ':tenant_id' => $tenantId, ':parent_id' => $values['parent_id'] ?? null, ':kind' => $kind,
                ':name' => $names['name'], ':names' => $names['names'], ':code' => $values['code'] ?? '',
                ':ou_id' => $values['ou_id'] ?? null, ':address' => $values['address'] ?? '',
            ]
        );

        return $this->show($tenantId, $id);
    }

    /**
     * @param array<string, mixed> $input fields left out keep their value; `is_active` false deactivates
     * @return array<string, mixed>
     */
    public function update(int $tenantId, int $id, array $input): array
    {
        $row = $this->row($tenantId, $id) ?? throw InventoryRejectedException::notFound('Place');
        if ($row['kind'] === 'virtual') {
            throw new InventoryRejectedException('Virtual places can\'t be changed', 409);
        }
        $sets = [];
        $params = [':tenant_id' => $tenantId, ':id' => $id];
        if (array_key_exists('name', $input)) {
            $names = InventoryNames::normalize($input['name'], 'name', 150);
            $sets[] = 'name = :name';
            $sets[] = 'names = :names';
            $params[':name'] = $names['name'];
            $params[':names'] = $names['names'];
        }
        foreach ($this->validate($tenantId, $input, $id) as $column => $value) {
            $sets[] = $column . ' = :' . $column;
            $params[':' . $column] = $value;
        }
        if (array_key_exists('is_active', $input)) {
            $sets[] = 'is_active = :is_active';
            $params[':is_active'] = is_bool($input['is_active']) ? $input['is_active'] : throw InventoryRejectedException::field('is_active', 'is_active is true or false');
        }
        if ($sets !== []) {
            $sets[] = 'updated_at = CURRENT_TIMESTAMP';
            $this->db->run('UPDATE inv_locations SET ' . implode(', ', $sets) . ' WHERE tenant_id = :tenant_id AND id = :id', $params);
        }

        return $this->show($tenantId, $id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function validate(int $tenantId, array $input, ?int $selfId): array
    {
        $out = [];
        if (array_key_exists('kind', $input)) {
            if (!is_string($input['kind']) || !in_array($input['kind'], self::KINDS, true)) {
                throw InventoryRejectedException::field('kind', 'kind must be warehouse or area');
            }
            $out['kind'] = $input['kind'];
        }
        if (array_key_exists('parent_id', $input)) {
            $parent = $input['parent_id'];
            if ($parent !== null) {
                $row = is_int($parent) ? $this->row($tenantId, $parent) : null;
                if ($row === null || $row['kind'] === 'virtual' || $parent === $selfId) {
                    throw InventoryRejectedException::field('parent_id', 'parent_id is not a place here');
                }
            }
            $out['parent_id'] = $parent;
        }
        if (array_key_exists('ou_id', $input)) {
            $ou = $input['ou_id'];
            if ($ou !== null && (!is_int($ou) || $this->db->one(
                'SELECT id FROM organizational_units WHERE tenant_id = :tenant_id AND id = :id',
                [':tenant_id' => $tenantId, ':id' => $ou]
            ) === null)) {
                throw InventoryRejectedException::field('ou_id', 'ou_id is not a unit of this workspace');
            }
            $out['ou_id'] = $ou;
        }
        foreach (['code' => 20, 'address' => 1000] as $key => $max) {
            if (array_key_exists($key, $input)) {
                $text = is_string($input[$key]) ? trim($input[$key]) : '';
                if (mb_strlen($text) > $max) {
                    throw InventoryRejectedException::field($key, $key . ' must be ' . $max . ' characters or fewer');
                }
                $out[$key] = $text;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function present(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'parent_id' => InventoryDb::int($row['parent_id']),
            'kind' => (string) $row['kind'],
            'virtual_role' => is_string($row['virtual_role']) ? $row['virtual_role'] : null,
            'name' => (string) $row['name'],
            'names' => InventoryNames::decode($row['names'], (string) $row['name']),
            'code' => (string) $row['code'],
            'ou_id' => InventoryDb::int($row['ou_id']),
            'address' => (string) $row['address'],
            'is_active' => DbBool::of($row['is_active']),
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }
}
