<?php

declare(strict_types=1);

namespace Tests\Integration\Inventory;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\RoleChecker;
use Whity\Core\Audience\ExplicitRuleResolver;
use Whity\Core\Document\Routing\DocumentRouter;
use Whity\Core\Document\Routing\RoleBelowActorRuleResolver;
use Whity\Core\Document\Routing\RoleRuleResolver;
use Whity\Core\Document\Routing\RouteAction;
use Whity\Core\Document\Routing\RouteEdgeRepository;
use Whity\Core\Document\Routing\RouteEventRepository;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\Document\Routing\RouteRepository;
use Whity\Core\Document\Routing\RouteStepRepository;
use Whity\Core\Document\Routing\RouteSubjectRegistry;
use Whity\Core\Document\Routing\RouteVerdict;
use Whity\Core\Document\Routing\RoutingRuleRegistry;
use Whity\Core\Document\RouteTemplate\RouteTemplateRepository;
use Whity\Core\Group\GroupResolver;
use Whity\Core\Group\GroupRuleResolver;
use Whity\Core\Group\UserGroupRepository;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Inventory\InventoryDb;
use Whity\Core\Inventory\InventoryRejectedException;
use Whity\Core\Inventory\InventoryWorkspace;
use Whity\Core\Inventory\ItemService;
use Whity\Core\Inventory\LocationService;
use Whity\Core\Inventory\OperationRoutes;
use Whity\Core\Inventory\OperationRouteSubject;
use Whity\Core\Inventory\ReasonCatalog;
use Whity\Core\Inventory\ReorderRules;
use Whity\Core\Inventory\StockLedger;
use Whity\Core\Inventory\StockOperations;
use Whity\Core\Inventory\UnitCatalog;
use Whity\Core\Notification\CoreTransports;
use Whity\Core\Notification\NotificationDispatcher;
use Whity\Core\Notification\NotificationRepository;
use Whity\Core\Queue\JobRepository;
use Whity\Core\Queue\QueueService;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Database\Database;
use Whity\Sdk\Hooks\Events;

/**
 * Adjustments, counts, transfers and reorder levels against the real schema
 * and the real router (WHIT-1233, design 31 §3.6, §3.9, register IV.11,
 * IV.14-IV.16).
 *
 * Example Bakery: Amro Saleh runs the inventory (inventory:settings); Ibrahim
 * Mansour works the shop floor. Butter is bought at $4.00 a kg.
 */
final class StockOperationsRealEngineTest extends TestCase
{
    private const AMRO = 10;
    private const IBRAHIM = 11;

    private PDO $pdo;
    private int $tenant;
    private ItemService $items;
    private LocationService $locations;
    private StockLedger $ledger;
    private StockOperations $operations;
    private ReasonCatalog $reasons;
    private ReorderRules $reorder;
    private DocumentRouter $router;
    private RouteRepository $routes;
    private SettingsService $settings;
    private HookManager $hooks;
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $events = [];

    protected function setUp(): void
    {
        // RoleChecker caches each role's permissions in a static, keyed by
        // role id alone: another test's role with the same id would decide who
        // may approve here, the operation would find no approver and post at
        // once (WHIT-1288).
        RoleChecker::clearCache();
        $this->pdo = SchemaFromMigrations::make(true);
        $this->seed();
        $database = Database::withFactory(fn (): PDO => $this->pdo);
        $database->setMaxLifetimeSeconds(86400);
        $database->setPingIntervalSeconds(86400);
        $database->forceConnect();

        $db = new InventoryDb($this->pdo);
        $workspace = new InventoryWorkspace($db);
        $units = new UnitCatalog($db, $workspace);
        $this->hooks = new HookManager();
        foreach ([Events::INVENTORY_OPERATION_DONE, Events::INVENTORY_COUNT_DIFFERENCES, Events::STOCK_LEVEL_BELOW_REORDER, Events::STOCK_LEVEL_RESTORED] as $event) {
            $this->hooks->listen($event, function (array $data) use ($event): array {
                $this->events[] = [$event, $data];

                return $data;
            });
        }
        $this->items = new ItemService($db, $workspace, $units, static fn (int $t): string => 'USD', null, $this->hooks);
        $this->locations = new LocationService($db, $workspace);
        $this->reasons = new ReasonCatalog($db, $workspace);
        $this->ledger = new StockLedger($db, $workspace, $this->items, $units, $this->hooks, $this->reasons);
        $this->settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        $effective = fn (int $t): array => $this->settings->effective($t);
        $this->operations = new StockOperations($db, $workspace, $this->items, $units, $this->ledger, $this->reasons, $effective, $this->hooks);
        $this->reorder = new ReorderRules($db, $this->items, $this->ledger, $this->hooks);
        $this->reorder->subscribe($this->hooks);

        $subjects = new RouteSubjectRegistry();
        $subjects->register(new OperationRouteSubject(OperationRouteSubject::ADJUSTMENT, $db, $this->operations));
        $subjects->register(new OperationRouteSubject(OperationRouteSubject::COUNT, $db, $this->operations));
        $rules = new RoutingRuleRegistry();
        $registry = $rules;
        $rules->registerCoreRoutingRules(
            new RoleRuleResolver($this->pdo),
            new RoleBelowActorRuleResolver($this->pdo),
            new ExplicitRuleResolver(),
            new GroupRuleResolver(new GroupResolver($this->pdo, new UserGroupRepository($this->pdo), static fn (): RoutingRuleRegistry => $registry)),
        );
        $this->routes = new RouteRepository($this->pdo);
        $this->router = new DocumentRouter(
            $this->pdo,
            $this->routes,
            new RouteStepRepository($this->pdo),
            new RouteEventRepository($this->pdo),
            new RouteRecipientRepository($this->pdo),
            new RouteEdgeRepository($this->pdo),
            $rules,
            $this->settings,
            null,
            $subjects,
        );
        $this->operations->useApprovals(new OperationRoutes(
            $db,
            $this->router,
            new RouteTemplateRepository($this->pdo),
            new RoleChecker($database, new PermissionRegistry()),
            $effective,
            new NotificationDispatcher(new NotificationRepository($this->pdo), CoreTransports::make(), new QueueService(new JobRepository($this->pdo))),
        ));
    }

    /**
     * WHIT-1301 (sec-rbac): Amro is the only person who runs the inventory, so
     * when he writes off more than the value himself nobody else can approve
     * it. It used to post at once; now it waits, Amro and the owner are told
     * why, and once Ibrahim may approve, sending it again routes it to him.
     */
    public function testAboveTheValueWithNobodyToApproveItKeepsWaitingAndTellsWhoCanFixIt(): void
    {
        [$butter, $shop] = $this->butterInAbdoun('20');
        $this->pdo->exec("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                          VALUES (12, 'Awss Haddad', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $this->pdo->exec("INSERT INTO workspace_owners (tenant_id, profile_id, created_at) VALUES ({$this->tenant}, 12, CURRENT_TIMESTAMP)");

        $op = $this->operations->adjust($this->tenant, self::AMRO, [
            'location_id' => $shop, 'reason_code' => 'spoiled', 'lines' => [['variant_id' => $butter, 'change' => '-8']],
        ])['operation'];

        self::assertSame('waiting_approval', $op['status'], 'fails closed: $32.00 is above $25 and nobody else can approve it');
        self::assertTrue($op['nobody_can_approve']);
        self::assertNull($op['route_id']);
        self::assertSame('20.000', $this->onHand($butter, $shop), 'nothing moves without an approval');
        self::assertSame([], array_column($this->events, 0), 'no operation-done event');
        $stmt = $this->pdo->query("SELECT recipient_profile_id, subject FROM notifications WHERE type = 'inventory.approval.nobody' ORDER BY recipient_profile_id");
        self::assertNotFalse($stmt);
        $told = $stmt->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame([self::AMRO, 12], array_map('intval', array_column($told, 'recipient_profile_id')), 'the settings holder and the owner');
        self::assertSame('Nobody can approve Stock adjustment ADJ-0001 yet', $told[0]['subject']);

        // Still nobody: sending it again keeps it waiting.
        self::assertSame('waiting_approval', $this->operations->submit($this->tenant, $op['id'], self::AMRO)['status']);
        self::assertSame('20.000', $this->onHand($butter, $shop));

        // Ibrahim may approve now: sending it again routes it to him.
        $managers = $this->scalar("SELECT id FROM roles WHERE name = 'stock-managers' AND tenant_id = {$this->tenant}");
        $this->pdo->exec("UPDATE memberships SET role_id = {$managers} WHERE profile_id = " . self::IBRAHIM);
        RoleChecker::clearCache();
        $routed = $this->operations->submit($this->tenant, $op['id'], self::AMRO);
        self::assertSame('waiting_approval', $routed['status']);
        self::assertFalse($routed['nobody_can_approve']);
        $route = $this->routes->findById((int) $routed['route_id'], $this->tenant);
        self::assertNotNull($route);
        $this->router->act($this->tenant, self::IBRAHIM, $route, RouteAction::ACKNOWLEDGED, null, RouteVerdict::APPROVED);
        self::assertSame('12.000', $this->onHand($butter, $shop));
    }

    public function testACountWithABigDifferenceAndNobodyToApproveItKeepsWaiting(): void
    {
        [$butter, $shop] = $this->butterInAbdoun('30');
        $count = $this->operations->startCount($this->tenant, self::AMRO, ['location_id' => $shop])['operation'];
        $this->operations->recordCount($this->tenant, $count['id'], self::AMRO, [['variant_id' => $butter, 'counted' => '20']]);

        $waiting = $this->operations->finishCount($this->tenant, $count['id'], self::AMRO);

        self::assertSame('waiting_approval', $waiting['status']);
        self::assertTrue($waiting['nobody_can_approve']);
        self::assertSame('30.000', $this->onHand($butter, $shop));
        self::assertSame('waiting_approval', $this->operations->finishCount($this->tenant, $count['id'], self::AMRO)['status'], 'finishing again tries the route again');
        self::assertSame('30.000', $this->onHand($butter, $shop));
    }

    public function testWithApprovalsNotWiredAChangeAboveTheValueStillWaits(): void
    {
        [$butter, $shop] = $this->butterInAbdoun('20');
        $db = new InventoryDb($this->pdo);
        $workspace = new InventoryWorkspace($db);
        $bare = new StockOperations($db, $workspace, $this->items, new UnitCatalog($db, $workspace), $this->ledger, $this->reasons, fn (int $t): array => $this->settings->effective($t));

        $op = $bare->adjust($this->tenant, self::IBRAHIM, [
            'location_id' => $shop, 'reason_code' => 'spoiled', 'lines' => [['variant_id' => $butter, 'change' => '-8']],
        ])['operation'];

        self::assertSame('waiting_approval', $op['status']);
        self::assertTrue($op['nobody_can_approve']);
        self::assertSame('20.000', $this->onHand($butter, $shop));
    }

    public function testBelowTheValueStillPostsAtOnceWithNobodyToApprove(): void
    {
        [$butter, $shop] = $this->butterInAbdoun('20');

        $op = $this->operations->adjust($this->tenant, self::AMRO, [
            'location_id' => $shop, 'reason_code' => 'unsold', 'lines' => [['variant_id' => $butter, 'change' => '-2']],
        ])['operation'];

        self::assertSame('done', $op['status'], '$8.00 is under $25 a line');
        self::assertFalse($op['nobody_can_approve']);
        self::assertSame('18.000', $this->onHand($butter, $shop));
    }

    public function testASmallWasteLinePostsAtOnceWithItsReason(): void
    {
        [$butter, $shop] = $this->butterInAbdoun('10');

        $result = $this->operations->adjust($this->tenant, self::IBRAHIM, [
            'location_id' => $shop, 'reason_code' => 'unsold',
            'lines' => [['variant_id' => $butter, 'change' => '-2.5']],
        ]);
        $op = $result['operation'];

        self::assertSame('ADJ-0001', $op['number']);
        self::assertSame('done', $op['status']);
        self::assertSame(1000, $op['value_minor'], '2.5 kg at $4.00');
        self::assertSame('-2.500', $op['lines'][0]['change']);
        self::assertSame('7.500', $this->onHand($butter, $shop));
        $moves = $this->ledger->batch($this->tenant, (string) $op['batch_key']);
        self::assertSame('unsold', $moves[0]['reason_code']);
        self::assertSame('adjustment', $moves[0]['source_type']);
        self::assertSame($op['id'], $moves[0]['source_id']);
        self::assertSame($op['lines'][0]['id'], $moves[0]['source_line_id']);
        self::assertSame([Events::INVENTORY_OPERATION_DONE], array_column($this->events, 0));
    }

    public function testAWasteLineAboveTheValueWaitsForAmroAndPostsWhenHeApproves(): void
    {
        [$butter, $shop] = $this->butterInAbdoun('20');

        $op = $this->operations->adjust($this->tenant, self::IBRAHIM, [
            'location_id' => $shop, 'reason_code' => 'spoiled', 'note' => 'Fridge failed overnight',
            'lines' => [['variant_id' => $butter, 'change' => '-8']],
        ])['operation'];

        self::assertSame('waiting_approval', $op['status'], '$32.00 is above $25 a line (N5)');
        self::assertSame(3200, $op['value_minor']);
        self::assertSame('20.000', $this->onHand($butter, $shop), 'nothing moves before approval');
        $route = $this->routes->findById((int) $op['route_id'], $this->tenant);
        self::assertNotNull($route);
        self::assertSame(OperationRouteSubject::ADJUSTMENT, $route['subject_type']);

        $this->router->act($this->tenant, self::AMRO, $route, RouteAction::ACKNOWLEDGED, null, RouteVerdict::APPROVED);

        $done = $this->operations->get($this->tenant, $op['id']);
        self::assertSame('done', $done['status']);
        self::assertSame(self::AMRO, $done['done_by_profile_id']);
        self::assertSame('12.000', $this->onHand($butter, $shop));
    }

    public function testTurnedDownItGoesBackToDraftAndCanBeCancelled(): void
    {
        [$butter, $shop] = $this->butterInAbdoun('20');
        $op = $this->operations->adjust($this->tenant, self::IBRAHIM, [
            'location_id' => $shop, 'reason_code' => 'theft', 'lines' => [['variant_id' => $butter, 'change' => '-10']],
        ])['operation'];
        $route = $this->routes->findById((int) $op['route_id'], $this->tenant);
        self::assertNotNull($route);

        $this->router->act($this->tenant, self::AMRO, $route, RouteAction::ACKNOWLEDGED, 'Check the camera first', RouteVerdict::REJECTED);

        self::assertSame('draft', $this->operations->get($this->tenant, $op['id'])['status']);
        self::assertSame('cancelled', $this->operations->cancel($this->tenant, $op['id'], self::IBRAHIM)['status']);
        self::assertSame('20.000', $this->onHand($butter, $shop));
    }

    public function testEmptyValueNeverAsksAndAnItemWithoutAPriceNeverNeedsApproval(): void
    {
        [$butter, $shop] = $this->butterInAbdoun('50');
        $this->settings->setTenant($this->tenant, SettingsRegistry::INVENTORY_APPROVE_ADJUSTMENTS_ABOVE, '');

        $op = $this->operations->adjust($this->tenant, self::IBRAHIM, [
            'location_id' => $shop, 'reason_code' => 'damaged', 'lines' => [['variant_id' => $butter, 'change' => '-40']],
        ])['operation'];
        self::assertSame('done', $op['status']);

        $this->settings->setTenant($this->tenant, SettingsRegistry::INVENTORY_APPROVE_ADJUSTMENTS_ABOVE, '25');
        $gloves = $this->items->create($this->tenant, ['name' => 'Gloves', 'unit' => 'pc', 'kind' => 'consumable'], self::AMRO)['variants'][0]['id'];
        $op = $this->operations->adjust($this->tenant, self::IBRAHIM, [
            'location_id' => $shop, 'reason_code' => 'found', 'lines' => [['variant_id' => $gloves, 'change' => '500']],
        ])['operation'];
        self::assertSame('done', $op['status']);
        self::assertNull($op['lines'][0]['value_minor']);
    }

    public function testReasonsAreTheWorkspacesOwnListAndGoTheRightWay(): void
    {
        [$butter, $shop] = $this->butterInAbdoun('10');
        try {
            $this->operations->adjust($this->tenant, self::IBRAHIM, [
                'location_id' => $shop, 'reason_code' => 'found', 'lines' => [['variant_id' => $butter, 'change' => '-1']],
            ]);
            self::fail('found brings stock in');
        } catch (InventoryRejectedException $e) {
            self::assertSame('reason_code', $e->extra['field']);
        }

        $tasting = $this->reasons->create($this->tenant, ['code' => 'tasting', 'name' => ['en' => 'Tasting', 'ar' => 'تذوق'], 'direction' => 'out', 'is_waste' => false]);
        self::assertSame(['en' => 'Tasting', 'ar' => 'تذوق'], $tasting['names']);
        $this->operations->adjust($this->tenant, self::IBRAHIM, [
            'location_id' => $shop, 'reason_code' => 'tasting', 'lines' => [['variant_id' => $butter, 'change' => '-0.2']],
        ]);
        $this->reasons->update($this->tenant, $tasting['id'], ['archived' => true]);
        $this->expectException(InventoryRejectedException::class);
        $this->operations->adjust($this->tenant, self::IBRAHIM, [
            'location_id' => $shop, 'reason_code' => 'tasting', 'lines' => [['variant_id' => $butter, 'change' => '-0.2']],
        ]);
    }

    public function testACountTakesExpectedWhenEachLineIsCountedAndPostsOnlyTheDifferences(): void
    {
        [$butter, $shop] = $this->butterInAbdoun('10');
        $milk = $this->items->create($this->tenant, ['name' => 'Milk', 'unit' => 'l', 'purchase_price_minor' => 100], self::AMRO)['variants'][0]['id'];
        $this->ledger->adjust($this->tenant, self::AMRO, 'opening', [['variant_id' => $milk, 'location_id' => $shop, 'change' => '6']], '', '', null, null);

        $count = $this->operations->startCount($this->tenant, self::IBRAHIM, ['location_id' => $shop, 'is_blind' => true])['operation'];
        self::assertSame('CNT-0001', $count['number']);
        self::assertEqualsCanonicalizing([$butter, $milk], array_column($count['lines'], 'variant_id'));

        $this->operations->recordCount($this->tenant, $count['id'], self::IBRAHIM, [['variant_id' => $butter, 'counted' => '9']]);
        // A sale after the butter was counted is not a difference (IV.15).
        $this->ledger->adjust($this->tenant, self::AMRO, 'adjustment', [['variant_id' => $butter, 'location_id' => $shop, 'change' => '-1']], 'used_in_house', '', null, null);
        $this->operations->recordCount($this->tenant, $count['id'], self::IBRAHIM, [['variant_id' => $milk, 'counted' => '6']]);

        $seen = $this->operations->get($this->tenant, $count['id'], true);
        self::assertNull($seen['lines'][0]['expected'], 'a blind count hides expected from the people counting');

        $done = $this->operations->finishCount($this->tenant, $count['id'], self::IBRAHIM);
        self::assertSame('done', $done['status'], '1 kg at $4.00 is under $25');
        $butterLine = array_values(array_filter($done['lines'], static fn (array $l): bool => $l['variant_id'] === $butter))[0];
        self::assertSame('10.000', $butterLine['expected']);
        self::assertSame('-1.000', $butterLine['difference']);
        self::assertSame('8.000', $this->onHand($butter, $shop));
        self::assertSame('6.000', $this->onHand($milk, $shop));
        self::assertCount(1, $this->ledger->batch($this->tenant, (string) $done['batch_key']), 'milk matched, so one correction');

        $differences = array_values(array_filter($this->events, static fn (array $e): bool => $e[0] === Events::INVENTORY_COUNT_DIFFERENCES));
        self::assertCount(1, $differences);
        self::assertFalse($differences[0][1]['waiting_approval']);
        self::assertSame('-1.000', $differences[0][1]['lines'][0]['difference']);
    }

    public function testACountWithABigDifferenceWaitsForApproval(): void
    {
        [$butter, $shop] = $this->butterInAbdoun('30');
        $count = $this->operations->startCount($this->tenant, self::IBRAHIM, ['location_id' => $shop])['operation'];
        $this->operations->recordCount($this->tenant, $count['id'], self::IBRAHIM, [['variant_id' => $butter, 'counted' => '20']]);

        $waiting = $this->operations->finishCount($this->tenant, $count['id'], self::IBRAHIM);
        self::assertSame('waiting_approval', $waiting['status']);
        self::assertSame('30.000', $this->onHand($butter, $shop));
        $route = $this->routes->findById((int) $waiting['route_id'], $this->tenant);
        self::assertNotNull($route);
        self::assertSame(OperationRouteSubject::COUNT, $route['subject_type']);

        $this->router->act($this->tenant, self::AMRO, $route, RouteAction::ACKNOWLEDGED, null, RouteVerdict::APPROVED);
        self::assertSame('20.000', $this->onHand($butter, $shop));
    }

    public function testATransferWaitsOnTheWayAndWhatDidNotArriveIsWrittenOff(): void
    {
        [$butter, $kitchen] = $this->butterInAbdoun('20');
        $abdoun = $this->locations->create($this->tenant, ['name' => 'Sweifieh'])['id'];

        $sent = $this->operations->transfer($this->tenant, self::AMRO, [
            'from_location_id' => $kitchen, 'to_location_id' => $abdoun, 'lines' => [['variant_id' => $butter, 'quantity' => '5']],
        ])['operation'];
        self::assertSame('TRF-0001', $sent['number']);
        self::assertSame('in_transit', $sent['status']);
        self::assertSame('15.000', $this->onHand($butter, $kitchen));
        self::assertSame('0.000', $this->onHand($butter, $abdoun));

        $received = $this->operations->receive($this->tenant, $sent['id'], self::IBRAHIM, [['line_id' => $sent['lines'][0]['id'], 'received' => '4.5']]);
        self::assertSame('done', $received['status']);
        self::assertSame('4.500', $received['lines'][0]['received']);
        self::assertSame('4.500', $this->onHand($butter, $abdoun));
        $lost = array_values(array_filter($this->ledger->batch($this->tenant, (string) $received['batch_key']), static fn (array $m): bool => $m['reason_code'] === 'lost_in_transit'));
        self::assertSame('0.500', $lost[0]['quantity']);
    }

    public function testCancellingATransferOnTheWayBringsTheStockBackAndOneStepSkipsTheWay(): void
    {
        [$butter, $kitchen] = $this->butterInAbdoun('20');
        $shop = $this->locations->create($this->tenant, ['name' => 'Sweifieh'])['id'];

        $sent = $this->operations->transfer($this->tenant, self::AMRO, [
            'from_location_id' => $kitchen, 'to_location_id' => $shop, 'lines' => [['variant_id' => $butter, 'quantity' => '5']],
        ])['operation'];
        self::assertSame('cancelled', $this->operations->cancel($this->tenant, $sent['id'], self::AMRO)['status']);
        self::assertSame('20.000', $this->onHand($butter, $kitchen));

        $this->settings->setTenant($this->tenant, SettingsRegistry::INVENTORY_TRANSFERS_ONE_STEP, 'true');
        $now = $this->operations->transfer($this->tenant, self::AMRO, [
            'from_location_id' => $kitchen, 'to_location_id' => $shop, 'lines' => [['variant_id' => $butter, 'quantity' => '2']],
        ])['operation'];
        self::assertSame('done', $now['status']);
        self::assertSame('2.000', $this->onHand($butter, $shop));
    }

    public function testGoingBelowTheReorderLevelFiresOnceUntilStockComesBack(): void
    {
        [$butter, $shop] = $this->butterInAbdoun('10');
        $rule = $this->reorder->set($this->tenant, ['variant_id' => $butter, 'location_id' => $shop, 'min_quantity' => '5', 'order_up_to' => '20']);
        self::assertFalse($rule['is_below']);

        foreach (['-3', '-3', '-1'] as $change) {
            $this->operations->adjust($this->tenant, self::IBRAHIM, [
                'location_id' => $shop, 'reason_code' => 'used_in_house', 'lines' => [['variant_id' => $butter, 'change' => $change]],
            ]);
        }
        $below = array_values(array_filter($this->events, static fn (array $e): bool => $e[0] === Events::STOCK_LEVEL_BELOW_REORDER));
        self::assertCount(1, $below, 'once, not on every change');
        self::assertSame('4.000', $below[0][1]['available']);
        self::assertSame('16.000', $below[0][1]['suggested_quantity']);
        self::assertSame([$butter], array_column($this->reorder->list($this->tenant, ['below' => true]), 'variant_id'));

        $this->operations->adjust($this->tenant, self::IBRAHIM, [
            'location_id' => $shop, 'reason_code' => 'found', 'lines' => [['variant_id' => $butter, 'change' => '6']],
        ]);
        self::assertCount(1, array_filter($this->events, static fn (array $e): bool => $e[0] === Events::STOCK_LEVEL_RESTORED));
        self::assertSame([], $this->reorder->list($this->tenant, ['below' => true]));
    }

    public function testTheSameKeyMakesOneOperationAndAnotherWorkspaceSeesNone(): void
    {
        [$butter, $shop] = $this->butterInAbdoun('10');
        $data = ['location_id' => $shop, 'reason_code' => 'damaged', 'lines' => [['variant_id' => $butter, 'change' => '-1']]];
        $first = $this->operations->adjust($this->tenant, self::IBRAHIM, $data, ['idempotency_key' => 'mcp-call-7']);
        $again = $this->operations->adjust($this->tenant, self::IBRAHIM, $data, ['idempotency_key' => 'mcp-call-7']);
        self::assertFalse($first['repeated']);
        self::assertTrue($again['repeated']);
        self::assertSame($first['operation']['id'], $again['operation']['id']);
        self::assertSame('9.000', $this->onHand($butter, $shop));

        $this->pdo->exec("INSERT INTO tenants (name, slug, created_at) VALUES ('Other Bakery', 'other', CURRENT_TIMESTAMP)");
        $other = $this->scalar("SELECT id FROM tenants WHERE slug = 'other'");
        self::assertSame([], $this->operations->list($other));
        $this->expectException(InventoryRejectedException::class);
        $this->operations->get($other, $first['operation']['id']);
    }

    /** @return array{0: int, 1: int} butter's variant and the shop it is in */
    private function butterInAbdoun(string $kg): array
    {
        $butter = $this->items->create($this->tenant, ['name' => ['en' => 'Butter', 'ar' => 'زبدة'], 'unit' => 'kg', 'purchase_price_minor' => 400], self::AMRO);
        $shop = $this->locations->create($this->tenant, ['name' => 'Abdoun'])['id'];
        $variant = $butter['variants'][0]['id'];
        $this->ledger->adjust($this->tenant, self::AMRO, 'opening', [['variant_id' => $variant, 'location_id' => $shop, 'change' => $kg]], '', '', null, null);
        $this->events = [];

        return [$variant, $shop];
    }

    private function onHand(int $variant, int $place): string
    {
        $levels = $this->ledger->levels($this->tenant, ['variant_id' => $variant, 'location_id' => $place]);

        return $levels === [] ? '0.000' : $levels[0]['on_hand'];
    }

    private function scalar(string $sql): int
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt);

        return (int) $stmt->fetchColumn();
    }

    private function seed(): void
    {
        $this->pdo->exec("INSERT INTO tenants (name, slug, created_at) VALUES ('Example Bakery', 'bakery', CURRENT_TIMESTAMP)");
        $this->tenant = $this->scalar("SELECT id FROM tenants WHERE slug = 'bakery'");
        $role = function (string $name, string $permission): int {
            $this->pdo->prepare('INSERT INTO roles (name, description, tenant_id, created_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)')->execute([$name, '', $this->tenant]);
            $id = $this->scalar("SELECT id FROM roles WHERE name = '{$name}' AND tenant_id = {$this->tenant}");
            $perm = $this->scalar("SELECT id FROM permissions WHERE name = '{$permission}'");
            $this->pdo->exec("INSERT INTO role_permissions (role_id, permission_id, created_at) VALUES ({$id}, {$perm}, CURRENT_TIMESTAMP)");

            return $id;
        };
        $managers = $role('stock-managers', 'inventory:settings');
        $floor = $role('shop-floor', 'stock:adjust');
        foreach ([self::AMRO => ['Amro Saleh', $managers], self::IBRAHIM => ['Ibrahim Mansour', $floor]] as $id => [$name, $roleId]) {
            $this->pdo->exec("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                              VALUES ({$id}, '{$name}', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
            $this->pdo->exec("INSERT INTO memberships (profile_id, tenant_id, role_id, status, kind, created_at) VALUES ({$id}, {$this->tenant}, {$roleId}, 'active', 'member', CURRENT_TIMESTAMP)");
        }
        SchemaFromMigrations::syncSequences($this->pdo);
    }
}
