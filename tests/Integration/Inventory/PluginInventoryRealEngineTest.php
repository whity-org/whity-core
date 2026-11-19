<?php

declare(strict_types=1);

namespace Tests\Integration\Inventory;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Inventory\Host\HostInventoryGuard;
use Whity\Core\Inventory\Host\HostItems;
use Whity\Core\Inventory\Host\HostLocations;
use Whity\Core\Inventory\Host\HostStock;
use Whity\Core\Inventory\Host\HostUnits;
use Whity\Core\Inventory\InventoryDb;
use Whity\Core\Inventory\InventoryWorkspace;
use Whity\Core\Inventory\ItemService;
use Whity\Core\Inventory\LocationService;
use Whity\Core\Inventory\StockLedger;
use Whity\Core\Inventory\UnitCatalog;
use Whity\Core\Plugin\HostPluginScope;
use Whity\Core\Plugin\PluginIdentity;
use Whity\Core\RBAC\CorePermissions;
use Whity\Sdk\Inventory\InventoryRejected;
use Whity\Sdk\Inventory\MoveLine;
use Whity\Sdk\Inventory\MoveRequest;
use Whity\Sdk\PluginInterface;

/**
 * A plugin sells from the till through the SDK (1.127, WHIT-1232): it finds
 * the item by barcode, posts the sale as a move to the virtual customer place
 * under its OWN name, retries safely with an idempotency key, undoes it, and
 * is refused for a person without the permission or a plugin object the
 * loader never loaded.
 *
 * Example Bakery is tenant 1: Amro Saleh (11) holds stock:adjust and
 * inventory:write, Awss Haddad (12) only reads. Tenant 2 is another workspace.
 */
final class PluginInventoryRealEngineTest extends TestCase
{
    private PDO $pdo;
    private HostItems $items;
    private HostLocations $locations;
    private HostStock $stock;
    private HostUnits $units;

    /** The workspace the plugin's call runs in, as TenantContext would say. */
    private ?int $workspace = 1;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Other Bakery', 'other-bakery')");
        $this->pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES (901, 'staff', '', NULL, CURRENT_TIMESTAMP)");
        foreach ([11 => 1, 12 => 1, 21 => 2] as $profile => $tenant) {
            $this->pdo->exec(
                "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                 VALUES ({$profile}, 'p{$profile}', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
            );
            $this->pdo->exec(
                "INSERT INTO memberships (profile_id, tenant_id, role_id, status, kind, created_at)
                 VALUES ({$profile}, {$tenant}, 901, 'active', 'member', CURRENT_TIMESTAMP)"
            );
        }
        $db = new InventoryDb($this->pdo);
        $workspace = new InventoryWorkspace($db);
        $units = new UnitCatalog($db, $workspace);
        $items = new ItemService($db, $workspace, $units, static fn (int $t): string => 'USD');
        $ledger = new StockLedger($db, $workspace, $items, $units);
        $guard = new HostInventoryGuard($db, static fn (int $profile, string $permission, int $tenant): bool => $profile === 11
            || ($profile === 21 && $tenant === 2)
            || $permission === CorePermissions::INVENTORY_READ, fn (): ?int => $this->workspace);
        $this->items = new HostItems($items, $guard);
        $this->locations = new HostLocations(new LocationService($db, $workspace), $guard);
        $this->stock = new HostStock($ledger, $guard);
        $this->units = new HostUnits($units, $items, $guard);
    }

    public function testATillSellsUnderItsOwnNameRetriesSafelyAndUndoes(): void
    {
        $pos = self::plugin('Pos');
        PluginIdentity::bind($pos, 'Pos');

        $maamoul = $this->items->create(['name' => ['en' => 'Maamoul, box of 12', 'ar' => 'معمول، علبة ١٢'], 'barcodes' => ['6251000000012']], 11);
        $shop = $this->locations->create(['name' => 'Abdoun'], 11);
        $customer = $this->locations->virtual('customer');
        self::assertTrue($customer->isVirtual());

        $match = $this->items->findByBarcode('6251000000012');
        self::assertNotNull($match);
        self::assertSame('معمول، علبة ١٢', $match['item']->names['ar']);
        $variant = $match['variant_id'];

        $opening = $this->locations->virtual('opening');
        $till = $this->stock->for($pos);
        $till->move(new MoveRequest(11, [new MoveLine($variant, '20', $opening->id, $shop->id)], 'restock'));

        $sale = new MoveRequest(11, [new MoveLine($variant, '3', $shop->id, $customer->id)], 'sale', 4411, idempotencyKey: 'receipt-4411');
        $moves = $till->move($sale);
        $again = $till->move($sale);

        self::assertSame('pos.sale', $moves[0]->sourceType, 'the host names the source after the plugin');
        self::assertSame(4411, $moves[0]->sourceId);
        self::assertSame($moves[0]->id, $again[0]->id, 'the same key posts once');
        self::assertSame(17, $this->stock->available($variant, $shop->id)->steps);
        self::assertCount(1, $this->stock->history(['source_type' => 'pos.sale']));

        $undo = $till->reverse($moves[0]->batchKey, 11, 'Customer changed their mind');
        self::assertSame('pos.reversal', $undo[0]->sourceType);
        self::assertSame($moves[0]->id, $undo[0]->reversesMoveId);
        self::assertSame(20, $this->stock->available($variant, $shop->id)->steps);

        $levels = $this->stock->levels(['location_id' => $shop->id]);
        self::assertCount(1, $levels);
        self::assertSame('20', $levels[0]->onHand->toDecimal());
        $this->workspace = 2;
        self::assertSame([], $this->stock->levels());
    }

    public function testThePluginContextPostsAsTheLoadedPlugin(): void
    {
        $before = $GLOBALS['whity_services'] ?? [];
        try {
            \Whity\register_service(\Whity\Sdk\Inventory\Items::class, $this->items);
            \Whity\register_service(\Whity\Sdk\Inventory\Locations::class, $this->locations);
            \Whity\register_service(\Whity\Sdk\Inventory\Stock::class, $this->stock);
            \Whity\register_service(\Whity\Sdk\Inventory\Units::class, $this->units);
            $salon = self::plugin('Salons');
            PluginIdentity::bind($salon, 'Salons');
            $inventory = (new HostPluginScope($salon))->inventory();

            $dye = $inventory->items()->create(['name' => 'Hair dye', 'unit' => 'ml', 'kind' => 'consumable'], 11);
            $shop = $inventory->locations()->create(['name' => 'Abdoun'], 11);
            $consumed = $inventory->locations()->virtual('consumed');
            $moves = $inventory->stock()->move(new MoveRequest(11, [new MoveLine($dye->variants[0]->id, '30', $shop->id, $consumed->id)], 'booking', 77));

            self::assertSame('salons.booking', $moves[0]->sourceType);
            self::assertSame(77, $moves[0]->sourceId);
            self::assertSame('-30', $inventory->stock()->available($dye->variants[0]->id, $shop->id)->toDecimal());
            self::assertContains('ml', array_map(static fn ($u): string => $u->code, $inventory->units()->list()));
        } finally {
            $GLOBALS['whity_services'] = $before;
        }
    }

    public function testUnitsConvertExactly(): void
    {
        $flour = $this->items->create(['name' => 'Flour', 'unit' => 'kg', 'units' => [['unit' => 'bag', 'per' => '25']]], 11);
        self::assertSame('2500', $this->units->convert('2.5', 'kg', 'g'));
        self::assertSame('75.000', $this->units->convert('3', 'bag', 'kg', $flour->id));
        self::assertContains('kg', array_map(static fn ($u): string => $u->code, $this->units->list()));

        $this->expectException(InventoryRejected::class);
        $this->units->convert('1', 'kg', 'l');
    }

    public function testWritesNeedAPersonInTheWorkspaceWithThePermission(): void
    {
        $pos = self::plugin('Pos');
        PluginIdentity::bind($pos, 'Pos');
        $item = $this->items->create(['name' => 'Butter'], 11);
        $shop = $this->locations->create(['name' => 'Abdoun'], 11);
        $loss = $this->locations->virtual('loss');
        $line = [new MoveLine($item->variants[0]->id, '1', $shop->id, $loss->id)];

        foreach ([
            'a person who only reads' => fn () => $this->stock->for($pos)->move(new MoveRequest(12, $line, 'waste')),
            'a person from another workspace' => fn () => $this->stock->for($pos)->move(new MoveRequest(21, $line, 'waste')),
            'adding an item without inventory:write' => fn () => $this->items->create(['name' => 'Cap'], 12),
        ] as $what => $attempt) {
            try {
                $attempt();
                self::fail('allowed: ' . $what);
            } catch (InventoryRejected $e) {
                self::assertSame(403, $e->status, $what);
                self::assertSame(InventoryRejected::FORBIDDEN, $e->reason, $what);
            }
        }

        try {
            $this->stock->for(self::plugin('Pos'));
            self::fail('a plugin object the loader never loaded got a stock to post with');
        } catch (InventoryRejected $e) {
            self::assertSame(InventoryRejected::FORBIDDEN, $e->reason);
        }

        try {
            $this->stock->move(new MoveRequest(11, $line, 'waste'));
            self::fail('the container\'s stock posted, so any caller could name itself');
        } catch (InventoryRejected $e) {
            self::assertSame(InventoryRejected::USE_PLUGIN_CONTEXT, $e->reason);
        }
        self::assertSame([], $this->stock->history(['source_type' => 'pos.waste']), 'nothing was posted');

        try {
            $this->stock->for($pos)->move(new MoveRequest(11, $line, 'Not A Kind!'));
            self::fail('a source type is a plain word');
        } catch (InventoryRejected $e) {
            self::assertSame('sourceType', $e->field);
        }

        $this->workspace = 2;
        self::assertNull($this->items->get($item->id), 'another workspace cannot read it');
        self::assertNull($this->items->findBySku($item->variants[0]->sku));

        foreach ([null, 0] as $none) {
            $this->workspace = $none;
            try {
                $this->items->list();
                self::fail('a call with no workspace read items');
            } catch (InventoryRejected $e) {
                self::assertSame(InventoryRejected::NO_WORKSPACE, $e->reason);
            }
        }
    }

    private static function plugin(string $name): PluginInterface
    {
        return new class ($name) implements PluginInterface {
            public function __construct(private string $name)
            {
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function getVersion(): string
            {
                return '1.0.0';
            }

            public function getRoutes(): array
            {
                return [];
            }

            public function getPermissions(): array
            {
                return [];
            }

            public function getHooks(): array
            {
                return [];
            }

            public function getMigrations(): array
            {
                return [];
            }
        };
    }
}
