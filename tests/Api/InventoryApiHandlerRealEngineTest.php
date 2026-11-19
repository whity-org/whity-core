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
use Whity\Core\Inventory\ReasonCatalog;
use Whity\Core\Inventory\ReorderRules;
use Whity\Core\Inventory\StockLedger;
use Whity\Core\Inventory\StockOperations;
use Whity\Core\Inventory\UnitCatalog;
use Whity\Core\Request;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
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
        $reasons = new ReasonCatalog($db, $workspace);
        $ledger = new StockLedger($db, $workspace, $items, $units, null, $reasons);
        $settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        $operations = new StockOperations($db, $workspace, $items, $units, $ledger, $reasons, static fn (int $t): array => $settings->effective($t));
        $this->handler = new InventoryApiHandler(
            $units,
            $items,
            new LocationService($db, $workspace),
            $ledger,
            null,
            $operations,
            $reasons,
            new ReorderRules($db, $items, $ledger),
            $settings,
        );
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
            // Design 31 §4.1: reading is inventory:read; each kind of stock
            // change has its own permission (WHIT-1233).
            $expected = match (true) {
                str_starts_with($route, 'GET ') => 'inventory:read',
                str_contains($route, '/stock/'), str_contains($route, '/adjustments') => 'stock:adjust',
                str_contains($route, '/counts') => 'stock:count',
                str_contains($route, '/transfers') => 'stock:transfer',
                str_contains($route, '/reasons'), str_contains($route, '/settings') => 'inventory:settings',
                default => 'inventory:write',
            };
            self::assertSame($expected, $permission, $route);
        }

        $index = (string) file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
        foreach (array_keys($byRoute) as $route) {
            [$method, $path] = explode(' ', $route, 2);
            self::assertMatchesRegularExpression(
                '#\$router->register\(\'' . $method . '\',\s+\'' . preg_quote($path, '#') . '\',\s+\[\$inventoryHandler, \'\w+\'\],\s+null, null, CorePermissions::(INVENTORY_READ|INVENTORY_WRITE|STOCK_ADJUST|STOCK_COUNT|STOCK_TRANSFER|INVENTORY_SETTINGS)\);#',
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

    public function testAdjustmentsCountsTransfersAndSettingsThroughTheRoutes(): void
    {
        TenantContext::setTenantId(1);
        $variant = $this->call('createItem', 'POST', '/api/inventory/items', ['name' => 'Butter', 'unit' => 'kg', 'purchase_price_minor' => 4000])['body']['data']['variants'][0]['id'];
        $abdoun = $this->call('createLocation', 'POST', '/api/inventory/locations', ['name' => 'Abdoun'])['body']['data']['id'];
        $sweifieh = $this->call('createLocation', 'POST', '/api/inventory/locations', ['name' => 'Sweifieh'])['body']['data']['id'];
        $this->call('adjust', 'POST', '/api/inventory/stock/adjustments', ['kind' => 'opening', 'lines' => [['variant_id' => $variant, 'location_id' => $abdoun, 'change' => '10']]]);

        // The P1 route now makes an operation, and still answers with the batch.
        $waste = $this->call('adjust', 'POST', '/api/inventory/stock/adjustments', [
            'reason_code' => 'damaged', 'lines' => [['variant_id' => $variant, 'location_id' => $abdoun, 'change' => '-1']],
        ]);
        self::assertSame(201, $waste['status']);
        self::assertSame('ADJ-0001', $waste['body']['data']['operation']['number']);
        self::assertSame('done', $waste['body']['data']['operation']['status'], 'JOD 4.000 is under 25');
        self::assertCount(1, $waste['body']['data']['moves']);
        $twoPlaces = $this->call('adjust', 'POST', '/api/inventory/stock/adjustments', ['reason_code' => 'damaged', 'lines' => [
            ['variant_id' => $variant, 'location_id' => $abdoun, 'change' => '-1'],
            ['variant_id' => $variant, 'location_id' => $sweifieh, 'change' => '-1'],
        ]]);
        self::assertSame(422, $twoPlaces['status']);

        $sent = $this->call('createTransfer', 'POST', '/api/inventory/transfers', [
            'from_location_id' => $abdoun, 'to_location_id' => $sweifieh, 'lines' => [['variant_id' => $variant, 'quantity' => '2']],
        ]);
        self::assertSame(201, $sent['status']);
        self::assertSame('in_transit', $sent['body']['data']['status']);
        $id = (string) $sent['body']['data']['id'];
        self::assertSame(404, $this->call('finishCount', 'POST', '/api/inventory/counts/' . $id . '/finish', [], ['id' => $id])['status'], 'a transfer is not a count');
        self::assertSame('done', $this->call('receiveTransfer', 'POST', '/api/inventory/transfers/' . $id . '/receive', [], ['id' => $id])['body']['data']['status']);

        $count = $this->call('startCount', 'POST', '/api/inventory/counts', ['location_id' => $sweifieh]);
        $countId = (string) $count['body']['data']['id'];
        $this->call('recordCount', 'PUT', '/api/inventory/counts/' . $countId . '/lines', ['lines' => [['variant_id' => $variant, 'counted' => '2']]], ['id' => $countId]);
        self::assertSame('done', $this->call('finishCount', 'POST', '/api/inventory/counts/' . $countId . '/finish', [], ['id' => $countId])['body']['data']['status']);
        self::assertCount(3, $this->call('listOperations', 'GET', '/api/inventory/operations')['body']['data'], 'opening stock is not an operation');

        self::assertSame('25', $this->call('showSettings', 'GET', '/api/inventory/settings')['body']['data']['approve_adjustments_above']);
        $changed = $this->call('updateSettings', 'PATCH', '/api/inventory/settings', ['approve_adjustments_above' => null, 'transfers_one_step' => true]);
        self::assertNull($changed['body']['data']['approve_adjustments_above']);
        self::assertTrue($changed['body']['data']['transfers_one_step']);
        self::assertSame(422, $this->call('updateSettings', 'PATCH', '/api/inventory/settings', ['approve_counts_above' => 'lots'])['status']);
        self::assertSame(422, $this->call('updateSettings', 'PATCH', '/api/inventory/settings', ['currency' => 'USD'])['status']);

        $rule = $this->call('setReorderRule', 'PUT', '/api/inventory/reorder-rules', ['variant_id' => $variant, 'location_id' => $sweifieh, 'min_quantity' => '5']);
        self::assertTrue($rule['body']['data']['is_below']);
        self::assertSame('3.000', $rule['body']['data']['suggested_quantity']);
        self::assertSame('unsold', $this->call('listReasons', 'GET', '/api/inventory/reasons')['body']['data'][3]['code']);

        TenantContext::reset();
        TenantContext::setTenantId(2);
        self::assertSame([], $this->call('listOperations', 'GET', '/api/inventory/operations')['body']['data']);
        self::assertSame(404, $this->call('showOperation', 'GET', '/api/inventory/operations/' . $id, null, ['id' => $id])['status']);
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
