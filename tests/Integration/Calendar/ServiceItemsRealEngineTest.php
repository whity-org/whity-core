<?php

declare(strict_types=1);

namespace Tests\Integration\Calendar;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Calendar\PriceList;
use Whity\Core\Calendar\ServiceItems;
use Whity\Core\Calendar\ServiceRepository;
use Whity\Core\Inventory\InventoryDb;
use Whity\Core\Inventory\InventoryWorkspace;
use Whity\Core\Inventory\ItemService;
use Whity\Core\Inventory\UnitCatalog;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Usage\PlanLimitsReport;

/**
 * Calendar services as inventory service items (WHIT-1334, design section 35
 * §5, SL.8): linked on save, one price both ways, archived together, off the
 * items meter, and the printed price list.
 *
 * Example Bakery is tenant 1.
 */
final class ServiceItemsRealEngineTest extends TestCase
{
    private PDO $pdo;
    private ServiceRepository $services;
    private ItemService $items;
    private ServiceItems $links;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Other Bakery', 'other-bakery')");
        $this->services = new ServiceRepository($this->pdo);
        $db = new InventoryDb($this->pdo);
        $workspace = new InventoryWorkspace($db);
        $this->items = new ItemService($db, $workspace, new UnitCatalog($db, $workspace), static fn (int $t): string => 'JOD');
        $this->links = new ServiceItems($this->pdo, $this->services, $this->items);
    }

    public function testASavedServiceGetsItsServiceItemAndKeepsItInStep(): void
    {
        $id = $this->service(1, ['en' => 'Cake tasting', 'ar' => 'تذوق الكعك'], 12500);
        $itemId = $this->links->sync(1, $id);

        $item = $this->items->row(1, $itemId);
        self::assertNotNull($item);
        self::assertSame('service', $item['kind']);
        self::assertSame(12500, (int) $item['sale_price_minor']);
        self::assertSame('JOD', trim((string) $item['currency']));
        self::assertSame($itemId, $this->services->find(1, $id)['item_id'] ?? null);

        // The calendar changes the price: the item follows, still one item.
        $this->pdo->exec("UPDATE calendar_services SET price_minor = 15000 WHERE id = {$id}");
        self::assertSame($itemId, $this->links->sync(1, $id));
        self::assertSame(15000, (int) ($this->items->row(1, $itemId)['sale_price_minor'] ?? 0));

        // Inventory changes the price and the name: the service follows.
        $this->items->update(1, $itemId, ['sale_price_minor' => 17500, 'name' => ['en' => 'Tasting box', 'ar' => 'علبة تذوق']], null);
        $this->links->fromItem(1, $itemId);
        $service = $this->services->find(1, $id);
        self::assertSame(17500, $service['price_minor'] ?? null);
        self::assertSame('Tasting box', $service['name']['en'] ?? null);

        // Archived in inventory: the service is off. On again from the calendar: the item is back.
        $this->items->archive(1, $itemId, true, null);
        $this->links->fromItem(1, $itemId);
        self::assertFalse($this->services->find(1, $id)['active'] ?? true);
        $this->pdo->exec("UPDATE calendar_services SET active = true WHERE id = {$id}");
        $this->links->sync(1, $id);
        $back = $this->items->row(1, $itemId);
        self::assertNotNull($back);
        self::assertNull($back['archived_at']);
    }

    public function testLinkAllLinksEveryServiceOnceAndServicesAreNotOnTheItemsMeter(): void
    {
        $this->service(1, ['en' => 'Cake tasting'], 12500);
        $this->service(1, ['en' => 'Decorating'], null);
        $this->service(2, ['en' => 'Other'], 100);

        self::assertSame(2, $this->links->linkAll(1));
        self::assertSame(0, $this->links->linkAll(1), 'linked already');
        $linked = $this->pdo->query('SELECT COUNT(*) FROM calendar_services WHERE tenant_id = 1 AND item_id IS NOT NULL');
        self::assertNotFalse($linked);
        self::assertSame(2, (int) $linked->fetchColumn());
        self::assertSame(0, PlanLimitsReport::liveItems($this->pdo, 1));
        self::assertSame(0, $this->items->countLiveVariants(1));

        $this->items->create(1, ['name' => 'Flour 25 kg', 'kind' => 'stocked'], null);
        self::assertSame(1, PlanLimitsReport::liveItems($this->pdo, 1), 'stock still counts');
    }

    public function testThePriceListReadsTheItemsPrice(): void
    {
        $id = $this->service(1, ['en' => 'Cake tasting', 'ar' => 'تذوق الكعك'], 12500);
        $itemId = $this->links->sync(1, $id);
        $this->items->update(1, $itemId, ['sale_price_minor' => 13000], null);
        $off = $this->service(1, ['en' => 'Old service'], 100, false);
        $this->links->sync(1, $off);

        $settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        $list = new PriceList($this->services, $this->items, $settings);
        self::assertSame([['en' => 'Cake tasting', 'ar' => 'تذوق الكعك', 'minutes' => 30, 'price' => 'JOD 13.000']], $list->lines(1));

        $page = $list->page(1, 'ar');
        self::assertStringContainsString('dir="rtl"', $page['html']);
        self::assertStringContainsString('JOD 13.000', $page['html']);
        self::assertStringContainsString('nonce="' . $page['nonce'] . '"', $page['html']);
        self::assertStringNotContainsString('Old service', $page['html']);
    }

    /** @param array<string, string> $name */
    private function service(int $tenantId, array $name, ?int $price, bool $active = true): int
    {
        return $this->services->insert($tenantId, [
            'name' => $name, 'description' => [],
            'duration_minutes' => 30, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
            'price_minor' => $price, 'currency' => 'JOD', 'bookable_online' => true, 'active' => $active, 'sort_order' => 0,
        ]);
    }
}
