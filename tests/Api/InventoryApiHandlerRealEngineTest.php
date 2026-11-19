<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\InventoryApiHandler;
use Whity\Core\Inventory\InventoryDb;
use Whity\Core\Inventory\InventoryWorkspace;
use Whity\Core\Inventory\ItemService;
use Whity\Core\Inventory\LocationService;
use Whity\Core\Inventory\StockLedger;
use Whity\Core\Inventory\UnitCatalog;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;
use Whity\OpenAPI\InventoryApiSchemas;

/**
 * The inventory routes (WHIT-1232): what they answer, that each declares the
 * permission the design says, that another workspace's ids answer 404, that
 * and that a repeated adjustment key answers the first result with 200.
 */
final class InventoryApiHandlerRealEngineTest extends TestCase
{
    private PDO $pdo;
    private InventoryApiHandler $handler;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Other Bakery', 'other-bakery')");
        $this->pdo->exec(
            "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (11, 'Amro Saleh', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $db = new InventoryDb($this->pdo);
        $workspace = new InventoryWorkspace($db);
        $units = new UnitCatalog($db, $workspace);
        $items = new ItemService($db, $workspace, $units, static fn (int $t): string => 'JOD');
        $this->handler = new InventoryApiHandler($units, $items, new LocationService($db, $workspace), new StockLedger($db, $workspace, $items, $units));
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testEveryRouteDeclaresTheDesignsPermission(): void
    {
        $byRoute = [];
        foreach (InventoryApiSchemas::routes() as $route) {
            $byRoute[$route['method'] . ' ' . $route['path']] = $route['requiredPermission'];
        }
        self::assertSame('stock:adjust', $byRoute['POST /api/inventory/stock/adjustments']);
        self::assertSame('stock:adjust', $byRoute['POST /api/inventory/stock/batches/{key:[a-f0-9]+}/undo']);
        foreach ($byRoute as $route => $permission) {
            $expected = str_starts_with($route, 'GET ') ? 'inventory:read' : (str_contains($route, '/stock/') ? 'stock:adjust' : 'inventory:write');
            self::assertSame($expected, $permission, $route);
        }

        $index = (string) file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
        foreach (array_keys($byRoute) as $route) {
            [$method, $path] = explode(' ', $route, 2);
            self::assertMatchesRegularExpression(
                '#\$router->register\(\'' . $method . '\',\s+\'' . preg_quote($path, '#') . '\',\s+\[\$inventoryHandler, \'\w+\'\],\s+null, null, CorePermissions::(INVENTORY_READ|INVENTORY_WRITE|STOCK_ADJUST)\);#',
                $index,
                $route . ' is registered with its permission'
            );
        }
    }

    public function testAnItemAndOpeningStockThroughTheRoutes(): void
    {
        TenantContext::setTenantId(1);
        $created = $this->call('createItem', 'POST', '/api/inventory/items', ['name' => 'Butter, 10 kg', 'unit' => 'kg', 'sku' => 'BTR-10', 'sale_price_minor' => 49000]);
        self::assertSame(201, $created['status']);
        self::assertSame('JOD', $created['body']['data']['currency']);
        $variant = $created['body']['data']['variants'][0]['id'];
        $place = $this->call('createLocation', 'POST', '/api/inventory/locations', ['name' => ['en' => 'Abdoun', 'ar' => 'عبدون']])['body']['data']['id'];

        $body = ['kind' => 'opening', 'lines' => [['variant_id' => $variant, 'location_id' => $place, 'change' => '6']], 'idempotency_key' => 'k1'];
        $first = $this->call('adjust', 'POST', '/api/inventory/stock/adjustments', $body);
        $again = $this->call('adjust', 'POST', '/api/inventory/stock/adjustments', $body);
        self::assertSame(201, $first['status']);
        self::assertSame(200, $again['status']);
        self::assertSame($first['body']['data']['batch_key'], $again['body']['data']['batch_key']);

        $levels = $this->call('levels', 'GET', '/api/inventory/stock?item_id=' . $created['body']['data']['id']);
        self::assertSame('6.000', $levels['body']['data'][0]['on_hand']);
        $lookup = $this->call('lookupItem', 'GET', '/api/inventory/items/lookup?code=BTR-10');
        self::assertSame($variant, $lookup['body']['data']['variant_id']);

        $undo = $this->call('undo', 'POST', '/api/inventory/stock/batches/' . $first['body']['data']['batch_key'] . '/undo', [], ['key' => $first['body']['data']['batch_key']]);
        self::assertSame(201, $undo['status']);
        self::assertSame('0.000', $this->call('levels', 'GET', '/api/inventory/stock')['body']['data'][0]['on_hand']);

        $bad = $this->call('adjust', 'POST', '/api/inventory/stock/adjustments', ['kind' => 'adjustment', 'lines' => [['variant_id' => $variant, 'location_id' => $place, 'change' => '-1']]]);
        self::assertSame(422, $bad['status'], 'an adjustment needs a reason');
        self::assertSame('reason_code', $bad['body']['details']['field'] ?? $bad['body']['field'] ?? null);

        TenantContext::reset();
        TenantContext::setTenantId(2);
        self::assertSame(404, $this->call('showItem', 'GET', '/api/inventory/items/' . $created['body']['data']['id'], null, ['id' => (string) $created['body']['data']['id']])['status']);
        self::assertSame(0, $this->call('listItems', 'GET', '/api/inventory/items')['body']['pagination']['total']);
    }

    public function testTheSystemTenantHasNoInventory(): void
    {
        TenantContext::setTenantId(0);
        self::assertSame(403, $this->call('listItems', 'GET', '/api/inventory/items')['status']);
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $params
     * @return array{status: int, body: array<string, mixed>}
     */
    private function call(string $method, string $http, string $path, ?array $body = null, array $params = []): array
    {
        $request = new Request($http, $path, ['Content-Type' => 'application/json'], $body === null ? '' : (string) json_encode($body));
        $request->user = (object) ['profile_id' => 11];
        $response = $params === [] ? $this->handler->{$method}($request) : $this->handler->{$method}($request, $params);
        $decoded = json_decode($response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
