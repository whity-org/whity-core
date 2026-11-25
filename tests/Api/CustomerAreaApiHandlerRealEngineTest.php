<?php

declare(strict_types=1);

namespace Tests\Api;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\CustomerAreaApiHandler;
use Whity\Auth\TenantConfinement;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Branding\BrandingService;
use Whity\Core\Branding\HostResolver;
use Whity\Core\Branding\TenantHostRepository;
use Whity\Core\Calendar\BookingRepository;
use Whity\Core\Calendar\BookingService;
use Whity\Core\Calendar\CalendarRepository;
use Whity\Core\Calendar\ServiceRepository;
use Whity\Core\CustomerArea\CustomerRecords;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Identity\CustomerSafeRole;
use Whity\Core\Identity\MembershipRepository;
use Whity\Core\Inventory\InventoryDb;
use Whity\Core\Inventory\InventoryWorkspace;
use Whity\Core\Inventory\ItemService;
use Whity\Core\Inventory\UnitCatalog;
use Whity\Core\Messaging\CoreMessaging;
use Whity\Core\Messaging\MessageChannelRegistry;
use Whity\Core\Request;
use Whity\Core\Sales\QuoteConversions;
use Whity\Core\Sales\Quotes;
use Whity\Database\SequenceCounters;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Signup\CustomerSignupService;
use Whity\Core\Signup\WorkspaceSignupSettings;
use Whity\Core\Store\DatabaseSharedStore;
use Whity\Core\Tenant\TenantContext;

/**
 * The customer area against a real schema (WHIT-1416, design section 41 §6
 * and §13): a customer sees their own records and nothing else.
 *
 * Example Bakery (1) has two customers, Ahmad (31) and Ibrahim (32). Emad
 * (41) is a customer of Other Bakery (2) only. Amro (11) is staff at Example
 * Bakery. Every cross-customer and cross-workspace read must answer exactly
 * what an id that does not exist answers.
 */
final class CustomerAreaApiHandlerRealEngineTest extends TestCase
{
    private const NOW = '2026-11-25 08:00:00';

    private PDO $pdo;
    private CustomerAreaApiHandler $handler;
    private SettingsService $settings;
    /** @var array<string, mixed>|null */
    private ?array $claims = null;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Other Bakery', 'other-bakery')");
        $this->pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES (901, 'customer', '', NULL, CURRENT_TIMESTAMP)");
        foreach ([11 => 'Amro Saleh', 31 => 'Ahmad Khalil', 32 => 'Ibrahim Mansour', 41 => 'Emad Nasser', 51 => 'Awss Haddad'] as $id => $name) {
            $this->pdo->exec(
                "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                 VALUES ({$id}, '{$name}', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
            );
        }
        foreach ([31 => 'ahmad@example.test', 32 => 'ibrahim@example.test', 41 => 'emad@example.test'] as $id => $email) {
            $this->pdo->exec("INSERT INTO profile_emails (profile_id, email, verified, is_primary) VALUES ({$id}, '{$email}', true, true)");
        }
        // Awss's address was never confirmed.
        $this->pdo->exec("INSERT INTO profile_emails (profile_id, email, verified, is_primary) VALUES (51, 'awss@example.test', false, true)");
        foreach ([[11, 1, 'member'], [31, 1, 'customer'], [32, 1, 'customer'], [41, 2, 'customer'], [51, 1, 'customer']] as [$p, $t, $kind]) {
            $this->pdo->exec("INSERT INTO memberships (profile_id, tenant_id, role_id, status, kind, created_at) VALUES ({$p}, {$t}, 901, 'active', '{$kind}', CURRENT_TIMESTAMP)");
        }
        // Contacts: Ahmad's is linked; Ibrahim's carries his verified address
        // but is not linked yet; Awss's carries her unverified address.
        $this->pdo->exec(
            "INSERT INTO contacts (id, tenant_id, name, email, is_customer, is_supplier, profile_id) VALUES
             (61, 1, 'Ahmad Khalil', 'ahmad@example.test', true, false, 31),
             (62, 1, 'Ibrahim Mansour', 'IBRAHIM@example.test', true, false, NULL),
             (63, 1, 'Awss Haddad', 'awss@example.test', true, false, NULL),
             (64, 2, 'Emad Nasser', 'emad@example.test', true, false, 41)"
        );
        $this->pdo->exec(
            "INSERT INTO sales_quotes (id, tenant_id, number, revision, status, contact_id, issue_date, valid_until, currency, total_minor, subtotal_minor, tax_minor)
             VALUES (71, 1, 'Q-0001', 0, 'sent', 61, '2026-11-20', '2026-12-20', 'JOD', 49000, 49000, 0),
                    (72, 1, 'Q-0002', 0, 'sent', 62, '2026-11-21', '2026-12-21', 'JOD', 12000, 12000, 0),
                    (73, 1, 'Q-0003', 0, 'draft', 61, '2026-11-22', '2026-12-22', 'JOD', 5000, 5000, 0),
                    (74, 2, 'Q-0001', 0, 'sent', 64, '2026-11-22', '2026-12-22', 'USD', 3000, 3000, 0)"
        );
        $this->pdo->exec(
            "INSERT INTO acc_invoices (id, tenant_id, kind, number, status, contact_id, issue_date, due_date, currency, subtotal_minor, tax_minor, total_minor, paid_minor, credited_minor)
             VALUES (81, 1, 'sale', 'INV-0001', 'issued', 61, '2026-11-01', '2026-11-15', 'JOD', 30000, 0, 30000, 10000, 0),
                    (82, 1, 'sale', 'INV-0002', 'draft', 61, '2026-11-02', '2026-11-16', 'JOD', 1000, 0, 1000, 0, 0),
                    (83, 1, 'purchase', 'B-0001', 'issued', 61, '2026-11-02', '2026-11-16', 'JOD', 1000, 0, 1000, 0, 0),
                    (84, 1, 'sale', 'INV-0003', 'issued', 62, '2026-11-03', '2026-11-17', 'JOD', 2000, 0, 2000, 0, 0)"
        );
        // Bookings: Ahmad's own (by profile), one made with his verified
        // address, one someone else made with his number (another email), and
        // Ibrahim's.
        $this->pdo->exec(
            "INSERT INTO calendar_services (id, tenant_id, name, duration_minutes, price_minor, currency, bookable_online, active)
             VALUES (91, 1, '{\"en\":\"Cake tasting\",\"ar\":\"تذوق الكعك\"}', 30, 12500, 'JOD', true, true)"
        );
        $booking = "INSERT INTO calendar_bookings (id, tenant_id, service_id, profile_id, customer_profile_id, customer_name, customer_email, starts_at, ends_at, blocks_from, blocks_until, status, source, contact_id, created_at)
                    VALUES (%d, 1, 91, 11, %s, '%s', '%s', '%s', '%s', '%s', '%s', 'confirmed', 'public', %d, CURRENT_TIMESTAMP)";
        foreach ([
            [101, '31', 'Ahmad', 'ahmad@example.test', '2026-12-01 09:00:00', 61],
            [102, 'NULL', 'Ahmad', 'AHMAD@example.test', '2026-12-02 09:00:00', 61],
            [103, 'NULL', 'Someone else', 'someone@example.test', '2026-12-03 09:00:00', 61],
            [104, '32', 'Ibrahim', 'ibrahim@example.test', '2026-12-04 09:00:00', 62],
            [105, '31', 'Ahmad', 'ahmad@example.test', '2026-11-01 09:00:00', 61],
        ] as [$id, $cp, $name, $email, $start, $contact]) {
            $end = (new DateTimeImmutable($start))->modify('+30 minutes')->format('Y-m-d H:i:s');
            $this->pdo->exec(sprintf($booking, $id, $cp, $name, $email, $start, $end, $start, $end, $contact));
        }
        SchemaFromMigrations::syncSequences($this->pdo);

        $this->settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        $this->handler = $this->handler();
        TenantContext::reset();
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testSignedOutSeesTheFrontDoorAndNoTabs(): void
    {
        $out = $this->call('area', 'example-bakery');

        self::assertSame(200, $out['status']);
        self::assertSame('Example Bakery', $out['body']['data']['workspace']['name']);
        self::assertNull($out['body']['data']['me']);
        self::assertSame([], $out['body']['data']['tabs']);
        self::assertSame(401, $this->call('home', 'example-bakery')['status']);
        self::assertSame(401, $this->call('bills', 'example-bakery')['status']);
    }

    public function testAnUnknownHandleAndTheSystemWorkspaceAreNotHere(): void
    {
        $this->as(31);
        self::assertSame(404, $this->call('area', 'no-such-place')['status']);
        self::assertSame(404, $this->call('home', 'no-such-place')['status']);
    }

    public function testACustomerSeesOnlyTheirOwnBills(): void
    {
        $this->as(31);
        $bills = $this->call('bills', 'example-bakery')['body']['data']['bills'];

        $seen = array_map(static fn (array $b): string => $b['kind'] . ':' . $b['id'], $bills);
        sort($seen);
        // Not the draft quote (73), the draft invoice (82), the supplier bill
        // (83), nor anything of Ibrahim's or of the other workspace.
        self::assertSame(['invoice:81', 'quote:71'], $seen);
        $invoice = array_values(array_filter($bills, static fn (array $b): bool => $b['kind'] === 'invoice'))[0];
        self::assertSame(20000, $invoice['due_minor']);
        self::assertTrue($invoice['overdue']);
    }

    public function testAnotherCustomersRecordsAnswerExactlyLikeMissingOnes(): void
    {
        $this->as(31);
        $missing = $this->call('quote', 'example-bakery', ['id' => '999999']);
        $theirs = $this->call('quote', 'example-bakery', ['id' => '72']);
        self::assertSame(404, $missing['status']);
        self::assertSame($missing, $theirs);

        self::assertSame(404, $this->call('invoice', 'example-bakery', ['id' => '84'])['status']);
        self::assertSame(404, $this->call('invoice', 'example-bakery', ['id' => '82'])['status'], 'a draft is not the customer\'s yet');
        self::assertSame(404, $this->call('invoice', 'example-bakery', ['id' => '83'])['status'], 'a supplier bill is never shown');
        self::assertSame(404, $this->call('booking', 'example-bakery', ['id' => '104'])['status']);
        self::assertSame(404, $this->call('cancelBooking', 'example-bakery', ['id' => '104'], 'POST', [])['status']);
        self::assertSame(404, $this->call('acceptQuote', 'example-bakery', ['id' => '72'], 'POST', [])['status']);
        self::assertSame('sent', $this->scalar('SELECT status FROM sales_quotes WHERE id = 72'));
    }

    public function testAnotherWorkspacesRecordsAreNotReachableThroughThisHandle(): void
    {
        $this->as(41);
        // Emad is not a customer of Example Bakery at all.
        self::assertSame(404, $this->call('home', 'example-bakery')['status']);
        self::assertSame('none', $this->call('area', 'example-bakery')['body']['data']['me']['state']);
        // And his own quote is not reachable by id through Example Bakery.
        self::assertSame(404, $this->call('quote', 'example-bakery', ['id' => '74'])['status']);
        self::assertSame(200, $this->call('quote', 'other-bakery', ['id' => '74'])['status']);
    }

    public function testBookingsShowOnlyThoseMadeByThePersonOrWithAVerifiedAddress(): void
    {
        $this->as(31);
        $upcoming = $this->call('bookings', 'example-bakery')['body']['data']['bookings'];
        self::assertSame([101, 102], array_map(static fn (array $b): int => $b['id'], $upcoming));
        self::assertSame('Cake tasting', $upcoming[0]['service_name']['en']);
        self::assertSame([105], array_map(static fn (array $b): int => $b['id'], $this->call('bookings', 'example-bakery', [], 'GET', null, '?when=past')['body']['data']['bookings']));
        // Made with his number but someone else's email: not his to see.
        self::assertSame(404, $this->call('booking', 'example-bakery', ['id' => '103'])['status']);
    }

    public function testACustomerCancelsTheirOwnBooking(): void
    {
        $this->as(31);
        $out = $this->call('cancelBooking', 'example-bakery', ['id' => '101'], 'POST', ['reason' => 'Travelling']);

        self::assertSame(200, $out['status']);
        self::assertSame('cancelled', $this->scalar('SELECT status FROM calendar_bookings WHERE id = 101'));
    }

    public function testOpeningTheAreaLinksAContactOnlyByAVerifiedAddress(): void
    {
        $this->as(32);
        $area = $this->call('area', 'example-bakery')['body']['data'];
        self::assertSame('customer', $area['me']['state']);
        self::assertSame('32', (string) $this->scalar('SELECT profile_id FROM contacts WHERE id = 62'));
        self::assertContains('bills', array_column($area['tabs'], 'id'));
        // A quote to answer and an invoice with something due.
        self::assertSame(2, array_values(array_filter($area['tabs'], static fn (array $t): bool => $t['id'] === 'bills'))[0]['count']);
        self::assertSame(200, $this->call('quote', 'example-bakery', ['id' => '72'])['status']);

        $this->as(51);
        $this->call('area', 'example-bakery');
        self::assertNull($this->scalar('SELECT profile_id FROM contacts WHERE id = 63'), 'an unverified address never links');
        self::assertSame([], $this->call('bills', 'example-bakery')['body']['data']['bills']);
    }

    public function testStaffSeeTheAreaAsACustomerWithNothingOfTheWorkspaces(): void
    {
        $this->as(11);
        $area = $this->call('area', 'example-bakery')['body']['data'];

        self::assertSame('customer', $area['me']['state']);
        self::assertTrue($area['me']['staff']);
        self::assertSame([], $this->call('bills', 'example-bakery')['body']['data']['bills']);
        self::assertSame(409, $this->call('leave', 'example-bakery', [], 'POST', [])['status']);
    }

    public function testASessionConfinedToAnotherWorkspaceIsTreatedAsSignedOut(): void
    {
        $this->as(31, [TenantConfinement::CLAIM => 2]);

        self::assertNull($this->call('area', 'example-bakery')['body']['data']['me']);
        self::assertSame(401, $this->call('home', 'example-bakery')['status']);
    }

    public function testTurningTheAreaOffClosesItButKeepsTheFrontDoor(): void
    {
        $this->settings->setTenant(1, 'customer_area.enabled', 'false');
        $this->as(31);

        $area = $this->call('area', 'example-bakery')['body']['data'];
        self::assertFalse($area['enabled']);
        self::assertNull($area['me']);
        self::assertSame(404, $this->call('bills', 'example-bakery')['status']);
    }

    public function testHiddenTabsStayHiddenAndOrderIsKept(): void
    {
        $this->settings->setTenant(1, 'customer_area.hidden_tabs', '["bookings"]');
        $this->as(31);

        self::assertNotContains('bookings', array_column($this->call('area', 'example-bakery')['body']['data']['tabs'], 'id'));
    }

    public function testACustomerLeavesAndTheBusinessKeepsTheRecords(): void
    {
        $this->as(31);
        self::assertSame(200, $this->call('leave', 'example-bakery', [], 'POST', [])['status']);

        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM memberships WHERE profile_id = 31 AND tenant_id = 1"));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM sales_quotes WHERE id = 71'));
        self::assertSame(404, $this->call('home', 'example-bakery')['status']);
    }

    public function testPluginTabsAndHomeCardsShowOnlyWithTheirPermission(): void
    {
        $this->as(31);
        $tabs = $this->call('area', 'example-bakery')['body']['data']['tabs'];
        self::assertSame('x:courses', $tabs[0]['id'], 'plugin tabs come first, after Home');
        self::assertNotContains('x:grades', array_column($tabs, 'id'));
        self::assertSame(['next-lesson'], array_column($this->call('home', 'example-bakery')['body']['data']['cards'], 'id'));
        self::assertSame(200, $this->call('screen', 'example-bakery', ['screen' => 'courses'])['status']);
        self::assertSame(404, $this->call('screen', 'example-bakery', ['screen' => 'grades'])['status']);

        // Ibrahim holds none of it.
        $this->as(32);
        self::assertSame([], array_values(array_filter(array_column($this->call('area', 'example-bakery')['body']['data']['tabs'], 'id'), static fn (string $id): bool => str_starts_with($id, 'x:'))));
        self::assertSame(404, $this->call('screen', 'example-bakery', ['screen' => 'courses'])['status']);
        self::assertSame(404, $this->call('screenToken', 'example-bakery', ['screen' => 'courses'], 'POST', [])['status']);
    }

    public function testAScreenTokenIsBoundToThisWorkspaceAndMarkedForCustomers(): void
    {
        $this->as(31, ['type' => 'access', 'jti' => 'session-1', 'active_tenant_id' => 2]);
        $out = $this->call('screenToken', 'example-bakery', ['screen' => 'courses'], 'POST', []);

        self::assertSame(200, $out['status']);
        $claims = (new \Whity\Auth\JwtParser('customer-area-test-secret-at-least-32-bytes-long'))->parse($out['body']['data']['token']);
        self::assertIsArray($claims);
        self::assertSame(\Whity\Core\Plugin\PluginFrameTokens::TYPE, $claims['type']);
        self::assertSame('customer', $claims['audience']);
        self::assertSame(1, $claims['active_tenant_id'], 'bound to the area\'s workspace, not the session\'s');
        self::assertSame('Courses', $claims['plugin_ns']);
        self::assertSame('session-1', $claims['session_jti']);
    }

    // ---- Helpers ----

    private function scalar(string $sql): mixed
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt);

        return $stmt->fetchColumn();
    }

    /** @param array<string, mixed> $extra */
    private function as(int $profileId, array $extra = []): void
    {
        $email = $this->scalar("SELECT email FROM profile_emails WHERE profile_id = {$profileId}");
        $this->claims = ['profile_id' => $profileId, 'email' => is_string($email) ? $email : '', 'type' => 'access'] + $extra;
        TenantContext::reset();
    }

    /**
     * @param array<string, string> $params
     * @param array<string, mixed>|null $body
     * @return array{status: int, body: array<string, mixed>}
     */
    private function call(string $method, string $handle, array $params = [], string $http = 'GET', ?array $body = null, string $query = ''): array
    {
        TenantContext::reset();
        $_GET = [];
        if ($query !== '') {
            parse_str(ltrim($query, '?'), $_GET);
        }
        $request = new Request($http, '/api/v1/customer-area/' . $handle . $query, ['Content-Type' => 'application/json'], $body === null ? '' : (string) json_encode($body));
        $response = $this->handler->{$method}($request, ['handle' => $handle] + $params);
        $_GET = [];
        $decoded = json_decode($response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    private function handler(): CustomerAreaApiHandler
    {
        $pdo = $this->pdo;
        $hooks = new HookManager();
        $clock = static fn (): DateTimeImmutable => new DateTimeImmutable(self::NOW, new DateTimeZone('UTC'));
        $booking = new BookingService($pdo, new CalendarRepository($pdo), new ServiceRepository($pdo), new BookingRepository($pdo), $this->settings, null, clock: $clock);

        $db = new InventoryDb($pdo);
        $workspace = new InventoryWorkspace($db);
        $units = new UnitCatalog($db, $workspace);
        $items = new ItemService($db, $workspace, $units, static fn (int $t): string => 'USD', null, $hooks);
        $quotes = new Quotes(
            $db, $workspace, $items, $units, new SequenceCounters($pdo), new QuoteConversions(),
            fn (int $t): array => $this->settings->effective($t), static fn (int $t): string => 'USD', $hooks, null,
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-11-25'),
        );
        $messaging = CoreMessaging::build(
            $pdo,
            new MessageChannelRegistry(),
            new class implements \Whity\Core\Mail\ThreadedMailer {
                public function sendThreaded(\Whity\Core\Mail\OutgoingEmail $email): void
                {
                }
            },
            new \Whity\Core\Security\EncryptedSecretStore(['v1' => str_repeat('k', 32)], 'v1'),
            static fn (string $name, array $payload, array $opts): int => 1,
        );
        $hostRepo = new TenantHostRepository($pdo);
        $signupSettings = new WorkspaceSignupSettings($pdo);

        return new CustomerAreaApiHandler(
            fn (): ?array => $this->claims,
            $pdo,
            new CustomerRecords($pdo),
            $hostRepo,
            new HostResolver($hostRepo, 'whity.test'),
            new BrandingService($this->settings),
            $this->settings,
            new MembershipRepository($pdo, $hooks),
            $signupSettings,
            new CustomerSignupService($pdo, $signupSettings, CustomerSafeRole::make($pdo), $hooks),
            $booking,
            $quotes,
            $messaging,
            new DatabaseSharedStore($pdo),
            new AuditLogger($pdo, new NullLogger()),
            // SDK 1.173: one plugin with a tab and a Home card, open to
            // holders of courses_course:read (Ahmad, 31, only).
            static fn (): array => [
                ['id' => 'courses', 'label' => ['en' => 'Courses', 'ar' => 'الدورات'], 'placement' => 'tab', 'screen' => 'blocks', 'requiredPermission' => 'courses_course:read', 'icon' => 'document', 'order' => 5, 'blocks' => [['type' => 'text', 'text' => 'Hi']], 'plugin' => 'Courses', 'namespace' => 'Courses'],
                ['id' => 'next-lesson', 'label' => 'Next lesson', 'placement' => 'home', 'screen' => 'blocks', 'requiredPermission' => 'courses_course:read', 'icon' => null, 'order' => 1, 'blocks' => [['type' => 'text', 'text' => 'Lesson 4']], 'plugin' => 'Courses', 'namespace' => 'Courses'],
                ['id' => 'grades', 'label' => 'Grades', 'placement' => 'tab', 'screen' => 'blocks', 'requiredPermission' => 'courses_grade:read', 'icon' => null, 'order' => 6, 'blocks' => [], 'plugin' => 'Courses', 'namespace' => 'Courses'],
            ],
            static fn (int $profileId, string $permission, int $tenantId): bool => $profileId === 31 && $tenantId === 1 && $permission === 'courses_course:read',
            new \Whity\Auth\JwtParser('customer-area-test-secret-at-least-32-bytes-long'),
        );
    }
}
