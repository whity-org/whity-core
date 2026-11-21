<?php

declare(strict_types=1);

namespace Tests\Integration\Sales;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Inventory\InventoryDb;
use Whity\Core\Inventory\InventoryWorkspace;
use Whity\Core\Inventory\ItemService;
use Whity\Core\Inventory\UnitCatalog;
use Whity\Core\Sales\QuoteConversions;
use Whity\Core\Sales\Quotes;
use Whity\Core\Sales\SalesRejectedException;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Database\SequenceCounters;
use Whity\Sdk\Contribute\WorkspaceQuoteConversion;
use Whity\Sdk\Hooks\Events;
use Whity\Sdk\Hooks\HookVetoException;
use Whity\Sdk\Sales\ConversionCheck;
use Whity\Sdk\Sales\ConversionResult;
use Whity\Sdk\Sales\QuoteSnapshot;

/**
 * Quotes against the real schema (WHIT-1321, design section 34 §3, register
 * QT.1-QT.12): drafting with item and free lines, totals with tax added or
 * included, the number on first send, the customer's link, accepting and
 * declining, expiry, revisions, turning into a target once, and tenant
 * isolation.
 *
 * Example Bakery (tenant 1) quotes Example Café for a birthday cake and an
 * hour of decorating, on 21 November 2026.
 */
final class QuotesRealEngineTest extends TestCase
{
    private const AMRO = 11;

    private PDO $pdo;
    private Quotes $quotes;
    private QuoteConversions $conversions;
    private SettingsService $settings;
    private HookManager $hooks;
    private \DateTimeImmutable $today;
    private int $cake;
    /** @var list<string> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Other Bakery', 'other-bakery')");
        $this->pdo->exec(
            "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (11, 'Amro Saleh', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $this->pdo->exec(
            "INSERT INTO contacts (id, tenant_id, name, is_customer, is_supplier) VALUES
             (41, 1, 'Example Café', true, false), (42, 1, 'Jordan Flour Mills', false, true), (43, 2, 'Other Café', true, false)"
        );
        SchemaFromMigrations::syncSequences($this->pdo);

        $db = new InventoryDb($this->pdo);
        $workspace = new InventoryWorkspace($db);
        $units = new UnitCatalog($db, $workspace);
        $this->hooks = new HookManager();
        foreach ([Events::SALES_QUOTE_CREATED, Events::SALES_QUOTE_SENT, Events::SALES_QUOTE_ACCEPTED, Events::SALES_QUOTE_DECLINED, Events::SALES_QUOTE_EXPIRED, Events::SALES_QUOTE_CONVERTED, Events::SALES_QUOTE_CANCELLED] as $event) {
            $this->hooks->listen($event, function (array $data) use ($event): array {
                $this->events[] = $event;

                return $data;
            });
        }
        $items = new ItemService($db, $workspace, $units, static fn (int $t): string => 'USD', null, $this->hooks);
        $this->settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        $this->conversions = new QuoteConversions();
        $this->today = new \DateTimeImmutable('2026-11-21');
        $this->quotes = new Quotes(
            $db, $workspace, $items, $units, new SequenceCounters($this->pdo), $this->conversions,
            fn (int $t): array => $this->settings->effective($t), static fn (int $t): string => 'USD', $this->hooks, null,
            fn (): \DateTimeImmutable => $this->today,
        );
        $this->cake = $items->create(1, [
            'name' => ['en' => 'Birthday cake', 'ar' => 'كعكة عيد ميلاد'], 'unit' => 'pc', 'sku' => 'CAKE-B', 'sale_price_minor' => 4500, 'tax_category' => 'standard',
        ], self::AMRO)['variants'][0]['id'];
    }

    public function testAnItemLineTakesTheItemsPriceAndNameAndTheTotalsAddUp(): void
    {
        $quote = $this->draft();

        self::assertNull($quote['number'], 'no number until it is sent');
        self::assertSame('draft', $quote['status']);
        self::assertSame('2026-12-21', $quote['valid_until'], '30 days by default');
        self::assertSame('Birthday cake', $quote['lines'][0]['description']);
        self::assertSame(4500, $quote['lines'][0]['price_minor']);
        self::assertSame('standard', $quote['lines'][0]['tax_category']);
        self::assertSame('2', $quote['lines'][0]['quantity']);
        self::assertSame('1.500', $quote['lines'][1]['quantity']);
        self::assertSame(9000, $quote['lines'][0]['line_total_minor']);
        self::assertSame(3000, $quote['lines'][1]['line_total_minor'], '1.5 hours at $20.00');
        self::assertSame(12000, $quote['subtotal_minor']);
        self::assertSame(1920, $quote['tax_minor'], '16% on $120.00');
        self::assertSame(13920, $quote['total_minor']);
        self::assertSame([Events::SALES_QUOTE_CREATED], $this->events);
    }

    public function testPricesThatIncludeTaxAreSplitOncePerRate(): void
    {
        $quote = $this->quotes->create(1, self::AMRO, [
            'contact_id' => 41, 'prices_include_tax' => true,
            'lines' => [['description' => 'Coffee', 'quantity' => '1', 'price_minor' => 825, 'tax_bp' => 1600]],
        ])['quote'];

        self::assertSame(711, $quote['subtotal_minor'], '8.25 / 1.16 = 7.112, rounded to the cent');
        self::assertSame(114, $quote['tax_minor']);
        self::assertSame(825, $quote['total_minor'], 'the total is what was typed');
    }

    public function testOnlyACustomerCanBeQuoted(): void
    {
        $this->expectException(SalesRejectedException::class);
        $this->expectExceptionMessage('Jordan Flour Mills is not marked as a customer');
        $this->quotes->create(1, self::AMRO, ['contact_id' => 42, 'lines' => [['description' => 'Flour', 'price_minor' => 100]]]);
    }

    public function testSendingNumbersItGivesALinkAndTheCustomerAcceptsOnline(): void
    {
        $sent = $this->quotes->send(1, $this->draft()['id'], self::AMRO);
        self::assertSame('QUO-0001', $sent['quote']['number']);
        self::assertSame('sent', $sent['quote']['status']);
        self::assertSame('2026-11-21', $sent['quote']['issue_date']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $sent['token']);
        self::assertStringNotContainsString($sent['token'], (string) json_encode($this->rows('SELECT * FROM sales_quotes')), 'only the hash is kept');

        $found = $this->quotes->byToken($sent['token']);
        self::assertSame(['tenant_id' => 1, 'quote_id' => $sent['quote']['id'], 'replaced' => false], $found);
        $view = $this->quotes->publicView(1, $sent['quote']['id']);
        self::assertArrayNotHasKey('notes', $view, 'the team\'s notes are not the customer\'s');
        self::assertSame('Example Café', $view['customer']);

        $accepted = $this->quotes->decide(1, $sent['quote']['id'], true, '  Ahmad   Khalil ', '', null, true);
        self::assertSame('accepted', $accepted['status']);
        self::assertSame('Ahmad Khalil', $accepted['decided_by_name']);
        self::assertTrue($accepted['decided_online']);
        self::assertSame([Events::SALES_QUOTE_CREATED, Events::SALES_QUOTE_SENT, Events::SALES_QUOTE_ACCEPTED], $this->events);

        $this->expectException(SalesRejectedException::class);
        $this->expectExceptionMessage('already been accepted');
        $this->quotes->decide(1, $sent['quote']['id'], false, '', 'Changed our minds', null, true);
    }

    public function testAcceptingNeedsAName(): void
    {
        $id = $this->quotes->send(1, $this->draft()['id'], self::AMRO)['quote']['id'];

        $this->expectException(SalesRejectedException::class);
        $this->quotes->decide(1, $id, true, '   ', '', null, true);
    }

    public function testDecliningKeepsTheReason(): void
    {
        $id = $this->quotes->send(1, $this->draft()['id'], self::AMRO)['quote']['id'];
        $declined = $this->quotes->decide(1, $id, false, '', 'Too expensive', self::AMRO, false);

        self::assertSame('declined', $declined['status']);
        self::assertSame('Too expensive', $declined['decline_reason']);
        self::assertSame('Amro Saleh', $declined['decided_by_profile_name']);
    }

    public function testAQuotePastItsDateExpiresAndCannotBeAccepted(): void
    {
        $id = $this->quotes->send(1, $this->draft()['id'], self::AMRO)['quote']['id'];
        $this->today = new \DateTimeImmutable('2026-12-22');

        self::assertSame(1, $this->quotes->expireDue());
        self::assertSame('expired', $this->quotes->get(1, $id)['status']);
        self::assertSame(0, $this->quotes->expireDue(), 'only once');
        self::assertContains(Events::SALES_QUOTE_EXPIRED, $this->events);

        $this->expectException(SalesRejectedException::class);
        $this->expectExceptionMessage('expired');
        $this->quotes->decide(1, $id, true, 'Ahmad Khalil', '', null, true);
    }

    public function testARevisionKeepsTheNumberAndReplacesTheOneBeforeWhenSent(): void
    {
        $first = $this->quotes->send(1, $this->draft()['id'], self::AMRO);
        $revision = $this->quotes->revise(1, $first['quote']['id'], self::AMRO);
        self::assertSame('QUO-0001', $revision['number']);
        self::assertSame(2, $revision['revision']);
        self::assertSame('draft', $revision['status']);
        self::assertSame($revision['id'], $this->quotes->revise(1, $first['quote']['id'], self::AMRO)['id'], 'one draft revision at a time');

        $this->quotes->update(1, $revision['id'], ['lines' => [['variant_id' => $this->cake, 'quantity' => '3', 'tax_bp' => 1600]]]);
        $second = $this->quotes->send(1, $revision['id'], self::AMRO);
        self::assertSame('QUO-0001', $second['quote']['number'], 'no new number for a revision');
        self::assertSame('replaced', $this->quotes->get(1, $first['quote']['id'])['status']);

        $old = $this->quotes->byToken($first['token']);
        self::assertSame(['tenant_id' => 1, 'quote_id' => $revision['id'], 'replaced' => true], $old, 'the old link opens the newest revision');
        self::assertCount(1, $this->quotes->list(1), 'one row per quote in the list');
        self::assertSame(2, $this->quotes->list(1)[0]['revision']);
    }

    public function testWithARouteADraftMustBeApprovedBeforeItGoesOut(): void
    {
        $this->settings->setTenant(1, SettingsRegistry::SALES_QUOTE_ROUTE_TEMPLATE_ID, '7');

        try {
            $this->quotes->send(1, $this->draft()['id'], self::AMRO);
            self::fail('a draft went out past its route');
        } catch (SalesRejectedException $e) {
            self::assertSame('route_needed', $e->extra['code'] ?? null);
        }
    }

    public function testWithNoRouteSubmittingMakesItReady(): void
    {
        $ready = $this->quotes->submit(1, $this->draft()['id'], self::AMRO);

        self::assertSame('ready', $ready['status']);
        self::assertSame('Amro Saleh', $ready['approved_by_name']);
    }

    public function testARuleCanRefuseSending(): void
    {
        $this->hooks->listen(Events::SALES_QUOTE_SENDING, static function (array $data): array {
            throw new HookVetoException(Events::SALES_QUOTE_SENDING, 'Example Café is over its credit limit');
        });

        try {
            $this->quotes->send(1, $this->draft()['id'], self::AMRO);
            self::fail('the veto was ignored');
        } catch (SalesRejectedException $e) {
            self::assertSame('Example Café is over its credit limit', $e->clientMessage);
            self::assertSame('vetoed', $e->extra['code'] ?? null);
        }
    }

    public function testAnAcceptedQuoteTurnsIntoATargetOnlyOnce(): void
    {
        $target = $this->target();
        $this->conversions->register($target);
        $id = $this->quotes->send(1, $this->draft()['id'], self::AMRO)['quote']['id'];

        self::assertFalse($this->quotes->targets(1, $id, self::AMRO)[0]['possible'], 'not before the customer accepts');
        $this->quotes->decide(1, $id, true, 'Ahmad Khalil', '', null, true);
        self::assertTrue($this->quotes->targets(1, $id, self::AMRO)[0]['possible']);

        $first = $this->quotes->convert(1, $id, 'invoice', self::AMRO);
        self::assertFalse($first['repeated']);
        self::assertSame(['key' => 'invoice', 'id' => 1042, 'label' => 'INV-1042', 'link' => '/money/invoices/1042'], $first['target']);
        self::assertSame('converted', $first['quote']['status']);
        self::assertSame([['target' => 'invoice', 'id' => 1042, 'label' => 'INV-1042']], $first['quote']['conversions']);

        $again = $this->quotes->convert(1, $id, 'invoice', self::AMRO);
        self::assertTrue($again['repeated']);
        self::assertSame(1, $target->made, 'a retried click makes nothing new');
        $seen = $target->seen;
        self::assertNotNull($seen);
        self::assertCount(2, $seen->lines);
        self::assertSame('2', $seen->lines[0]->quantity);
        self::assertSame('2', $seen->lines[0]->baseQuantity, 'an item line carries its base quantity');
        self::assertNull($seen->lines[1]->variantId, 'a free line has no item');
        self::assertContains(Events::SALES_QUOTE_CONVERTED, $this->events);
    }

    public function testATargetsRefusalIsShownAndNothingIsWritten(): void
    {
        $target = $this->target(ConversionCheck::no('Add a place to ship from first'));
        $this->conversions->register($target);
        $id = $this->quotes->send(1, $this->draft()['id'], self::AMRO)['quote']['id'];
        $this->quotes->decide(1, $id, true, 'Ahmad Khalil', '', null, true);

        self::assertSame('Add a place to ship from first', $this->quotes->targets(1, $id, self::AMRO)[0]['reason']);
        try {
            $this->quotes->convert(1, $id, 'invoice', self::AMRO);
            self::fail('converted past a refusal');
        } catch (SalesRejectedException $e) {
            self::assertSame('Add a place to ship from first', $e->clientMessage);
        }
        self::assertSame(0, count($this->rows('SELECT id FROM sales_quote_conversions')));
    }

    public function testATakenKeyIsDropped(): void
    {
        self::assertTrue($this->conversions->register($this->target()));
        self::assertFalse($this->conversions->register($this->target(), 'a plugin'));
    }

    public function testCancellingStopsTheLink(): void
    {
        $sent = $this->quotes->send(1, $this->draft()['id'], self::AMRO);
        $this->quotes->cancel(1, $sent['quote']['id'], self::AMRO);

        self::assertNull($this->quotes->byToken($sent['token']));
    }

    public function testAnotherWorkspaceSeesNothing(): void
    {
        $id = $this->draft()['id'];

        self::assertSame([], $this->quotes->list(2));
        self::assertNull($this->quotes->row(2, $id));
        $this->expectException(SalesRejectedException::class);
        $this->quotes->get(2, $id);
    }

    public function testAnotherWorkspacesCustomerCannotBeQuoted(): void
    {
        $this->expectException(SalesRejectedException::class);
        $this->quotes->create(1, self::AMRO, ['contact_id' => 43, 'lines' => [['description' => 'Cake', 'price_minor' => 100]]]);
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql): array
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt);

        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed> */
    private function draft(): array
    {
        return $this->quotes->create(1, self::AMRO, [
            'contact_id' => 41,
            'terms' => 'Half on order, half on delivery.',
            'notes' => 'Ask about the nut allergy.',
            'lines' => [
                ['variant_id' => $this->cake, 'quantity' => '2', 'tax_bp' => 1600],
                ['description' => 'Decorating, per hour', 'quantity' => '1.5', 'price_minor' => 2000, 'tax_bp' => 1600],
            ],
        ])['quote'];
    }

    private function target(?ConversionCheck $check = null): RecordingTarget
    {
        return new RecordingTarget($check ?? ConversionCheck::yes());
    }
}

/** A target that records what it was given, standing in for accounting's invoices. */
final class RecordingTarget implements WorkspaceQuoteConversion
{
    public int $made = 0;
    public ?QuoteSnapshot $seen = null;

    public function __construct(private readonly ConversionCheck $check)
    {
    }

    public function key(): string
    {
        return 'invoice';
    }

    public function label(): array
    {
        return ['en' => 'Invoice', 'ar' => 'فاتورة'];
    }

    public function canConvert(QuoteSnapshot $quote, int $actingProfileId): ConversionCheck
    {
        return $this->check;
    }

    public function convert(QuoteSnapshot $quote, int $actingProfileId): ConversionResult
    {
        $this->made++;
        $this->seen = $quote;

        return new ConversionResult(1042, 'INV-1042', '/money/invoices/1042');
    }
}
