<?php

declare(strict_types=1);

namespace Whity\Core\Inventory;

use Whity\Core\i18n\LocalizedText;

/**
 * What every workspace's inventory starts with (WHIT-1232, design 31 §3.2):
 * the common units and the virtual places at the other end of stock coming in
 * and going out.
 *
 * Made on first use rather than by a migration, so a workspace created
 * tomorrow gets them too and one that never opens Inventory gets nothing.
 * Idempotent: a missing row is added, an existing one is left alone (a
 * workspace may rename a unit), and a race between two requests is settled by
 * the unique keys.
 *
 * `$ready` remembers, for this process, which workspaces are already set up.
 * It holds facts that never stop being true (rows are archived, not deleted),
 * so it is safe to keep across requests on a worker.
 */
final class InventoryWorkspace
{
    /**
     * code => [dimension, decimals, to_base_num, to_base_den, en, ar]
     *
     * `bag`, `box` and `pack` are packages: how much one holds depends on the
     * item, so they convert only through an item's own units.
     */
    public const SEEDED_UNITS = [
        'pc' => ['count', 0, 1, 1, 'piece', 'قطعة'],
        'kg' => ['mass', 3, 1, 1, 'kg', 'كغ'],
        'g' => ['mass', 0, 1, 1000, 'g', 'غ'],
        'l' => ['volume', 3, 1, 1, 'litre', 'لتر'],
        'ml' => ['volume', 0, 1, 1000, 'ml', 'مل'],
        'm' => ['length', 3, 1, 1, 'metre', 'م'],
        'box' => ['package', 0, 1, 1, 'box', 'علبة'],
        'pack' => ['package', 0, 1, 1, 'pack', 'عبوة'],
        'bag' => ['package', 0, 1, 1, 'bag', 'كيس'],
    ];

    /** role => [en, ar] */
    public const VIRTUAL_PLACES = [
        'supplier' => ['Suppliers', 'الموردون'],
        'customer' => ['Customers', 'العملاء'],
        'loss' => ['Loss and waste', 'هالك وتالف'],
        'found' => ['Found stock', 'مخزون عُثر عليه'],
        'production' => ['Production', 'الإنتاج'],
        'consumed' => ['Used in services', 'مستهلك في الخدمات'],
        'opening' => ['Opening stock', 'رصيد افتتاحي'],
        'transit' => ['On the way', 'في الطريق'],
    ];

    /** @var array<int, true> */
    private array $ready = [];

    public function __construct(private readonly InventoryDb $db)
    {
    }

    public function ensure(int $tenantId): void
    {
        if (isset($this->ready[$tenantId])) {
            return;
        }

        $units = array_column(
            $this->db->all('SELECT code FROM inv_units WHERE tenant_id = :tenant_id', [':tenant_id' => $tenantId]),
            'code'
        );
        foreach (self::SEEDED_UNITS as $code => [$dimension, $decimals, $num, $den, $en, $ar]) {
            if (in_array($code, $units, true)) {
                continue;
            }
            $this->quietly(fn () => $this->db->run(
                'INSERT INTO inv_units (tenant_id, code, name, names, dimension, decimals, to_base_num, to_base_den, is_seeded)
                 VALUES (:tenant_id, :code, :name, :names, :dimension, :decimals, :num, :den, TRUE)',
                [
                    ':tenant_id' => $tenantId, ':code' => $code, ':name' => $en,
                    ':names' => LocalizedText::encode(['en' => $en, 'ar' => $ar]),
                    ':dimension' => $dimension, ':decimals' => $decimals, ':num' => $num, ':den' => $den,
                ]
            ));
        }

        $roles = array_column(
            $this->db->all(
                "SELECT virtual_role FROM inv_locations WHERE tenant_id = :tenant_id AND kind = 'virtual'",
                [':tenant_id' => $tenantId]
            ),
            'virtual_role'
        );
        foreach (self::VIRTUAL_PLACES as $role => [$en, $ar]) {
            if (in_array($role, $roles, true)) {
                continue;
            }
            $this->quietly(fn () => $this->db->run(
                "INSERT INTO inv_locations (tenant_id, kind, virtual_role, name, names)
                 VALUES (:tenant_id, 'virtual', :role, :name, :names)",
                [':tenant_id' => $tenantId, ':role' => $role, ':name' => $en, ':names' => LocalizedText::encode(['en' => $en, 'ar' => $ar])]
            ));
        }

        $this->ready[$tenantId] = true;
    }

    /** The id of a virtual place, making the set on first use. */
    public function virtualId(int $tenantId, string $role): int
    {
        if (!array_key_exists($role, self::VIRTUAL_PLACES)) {
            throw InventoryRejectedException::field('role', 'role must be one of ' . implode(', ', array_keys(self::VIRTUAL_PLACES)));
        }
        $this->ensure($tenantId);
        $id = InventoryDb::int($this->db->one(
            "SELECT id FROM inv_locations WHERE tenant_id = :tenant_id AND kind = 'virtual' AND virtual_role = :role",
            [':tenant_id' => $tenantId, ':role' => $role]
        )['id'] ?? null);
        if ($id === null) {
            throw new \RuntimeException('Virtual place ' . $role . ' is missing for workspace ' . $tenantId);
        }

        return $id;
    }

    /** Inserts that lose a race to another request are fine: the row is there. */
    private function quietly(callable $insert): void
    {
        try {
            $insert();
        } catch (\PDOException $e) {
            if (!InventoryDb::isUniqueViolation($e)) {
                throw $e;
            }
        }
    }
}
