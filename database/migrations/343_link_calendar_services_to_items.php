<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Core\Calendar\ServiceItems;
use Whity\Core\Calendar\ServiceRepository;
use Whity\Core\Inventory\InventoryDb;
use Whity\Core\Inventory\InventoryWorkspace;
use Whity\Core\Inventory\ItemService;
use Whity\Core\Inventory\UnitCatalog;
use Whity\Database\Database;

/**
 * LinkCalendarServicesToItems — every calendar service becomes an inventory
 * service item (WHIT-1334, design section 35 §5, register SL.8): the item
 * holds the name and the price, the calendar keeps a copy.
 *
 * Adds `calendar_services.item_id` (an `inv_items` row of kind `service`;
 * SET NULL if the item is ever deleted, so a service never breaks), then links
 * every existing service of every workspace: one service item each, with the
 * service's name, price and currency, archived when the service is off.
 * Idempotent: a service already linked is left as it is.
 *
 * down() drops the column and leaves the items: they are the workspace's now.
 */
final class LinkCalendarServicesToItems
{
    public static function up(Database $db): void
    {
        $db->exec('ALTER TABLE calendar_services ADD COLUMN IF NOT EXISTS item_id INTEGER REFERENCES inv_items(id) ON DELETE SET NULL');
        // migration-lint: allow index-not-concurrent (calendar_services holds a workspace's handful of services; the column is new and empty when the index is built)
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_calendar_services_item ON calendar_services(tenant_id, item_id) WHERE item_id IS NOT NULL');

        $pdo = $db->getPdo();
        $inventory = new InventoryDb($pdo);
        $workspace = new InventoryWorkspace($inventory);
        $items = new ItemService($inventory, $workspace, new UnitCatalog($inventory, $workspace), static fn (int $tenantId): string => 'USD');
        $links = new ServiceItems($pdo, new ServiceRepository($pdo), $items);

        // Every workspace, once: a migration runs for the whole installation.
        $tenants = $pdo->query('SELECT DISTINCT tenant_id FROM calendar_services WHERE item_id IS NULL ORDER BY tenant_id');
        foreach ($tenants === false ? [] : $tenants->fetchAll(\PDO::FETCH_COLUMN) as $tenantId) {
            $links->linkAll((int) $tenantId);
        }
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP INDEX IF EXISTS uq_calendar_services_item');
        $db->exec('ALTER TABLE calendar_services DROP COLUMN IF EXISTS item_id');
    }
}
