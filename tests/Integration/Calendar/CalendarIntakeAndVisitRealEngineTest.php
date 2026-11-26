<?php

declare(strict_types=1);

namespace Tests\Integration\Calendar;

use Database\Migrations\CreateCalendarIntakeForms;
use Database\Migrations\CreateCalendarVisitRecords;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\CalendarApiHandler;
use Whity\Api\PublicBookingApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Audit\AuditLoggerInterface;
use Whity\Core\Branding\TenantHostRepository;
use Whity\Core\Calendar\BookingRepository;
use Whity\Core\Calendar\BookingService;
use Whity\Core\Calendar\CalendarRejectedException;
use Whity\Core\Calendar\CalendarRepository;
use Whity\Core\Calendar\EmailBookingMessenger;
use Whity\Core\Calendar\IntakeForms;
use Whity\Core\Calendar\ServiceRepository;
use Whity\Core\Calendar\VisitRecords;
use Whity\Core\Mail\EmailLayout;
use Whity\Core\Mail\Mailer;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Mcp\Auth\McpPrincipal;
use Whity\Storage\LocalStorageDriver;

/**
 * Intake forms and the visit record (WHIT-1443, design section 39 §7.2 and
 * §7.3, AP.20 and AP.21). Intake: a service's forms, the booking's done / not
 * done / not needed, the customer's link that records the booking. The visit
 * record: only `calendar:visit_notes` or the treating person (an agent needs
 * the permission), never a customer or a public route; Finish visit means the
 * notes never change and only dated corrections are added; every read and
 * change audited; files in the tenant's own storage. Real engine.
 */
final class CalendarIntakeAndVisitRealEngineTest extends TestCase
{
    private const NOW = '2026-11-16 05:00:00';

    private PDO $pdo;
    private BookingService $service;
    private SettingsService $settings;
    private int $cut;
    /** @var list<array{to: string, subject: string, text: string}> */
    private array $sent = [];
    /** @var list<array{action: string, options: array<string, mixed>}> */
    private array $audited = [];
    private string $storageRoot;

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Other Bakery', 'other-bakery')");
        $this->pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES (901, 'staff', '', NULL, CURRENT_TIMESTAMP)");
        foreach ([11 => 'Amro Saleh', 12 => 'Awss Haddad', 13 => 'Ahmad Khalil'] as $profile => $name) {
            $this->pdo->exec("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                VALUES ({$profile}, '{$name}', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
            $this->pdo->exec("INSERT INTO memberships (profile_id, tenant_id, role_id, status, kind, created_at) VALUES ({$profile}, 1, 901, 'active', 'member', CURRENT_TIMESTAMP)");
        }
        $this->settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        $this->settings->setTenant(1, 'timezone', 'Asia/Amman');
        $this->settings->setTenant(1, 'calendar.min_notice_minutes', '0');
        $calendar = new CalendarRepository($this->pdo);
        $services = new ServiceRepository($this->pdo);
        $this->service = new BookingService($this->pdo, $calendar, $services, new BookingRepository($this->pdo), $this->settings, null,
            clock: fn (): DateTimeImmutable => new DateTimeImmutable(self::NOW, new DateTimeZone('UTC')));
        $sent = &$this->sent;
        $mailer = new class (static function (string $to, string $subject, string $text) use (&$sent): void {
            $sent[] = ['to' => $to, 'subject' => $subject, 'text' => $text];
        }) implements Mailer {
            public function __construct(private readonly \Closure $record)
            {
            }

            public function send(string $toEmail, string $subject, string $textBody, ?string $htmlBody = null): void
            {
                ($this->record)($toEmail, $subject, $textBody);
            }
        };
        $this->service->setMessenger(new EmailBookingMessenger(new \Whity\Core\Mail\BrandedEmailSender($mailer, new EmailLayout(), $this->settings), $this->settings, new TenantHostRepository($this->pdo), 'https://app.example.com/booking'));
        $this->service->setManageUrlBase('https://app.example.com/booking');
        $audited = &$this->audited;
        $this->service->setAudit(new class (static function (string $action, array $options) use (&$audited): void {
            $audited[] = ['action' => $action, 'options' => $options];
        }) implements AuditLoggerInterface {
            public function __construct(private readonly \Closure $record)
            {
            }

            public function record(string $action, array $options = []): void
            {
                ($this->record)($action, $options);
            }
        });
        $this->storageRoot = sys_get_temp_dir() . '/whity-visit-' . bin2hex(random_bytes(4));
        $this->service->setStorage(new LocalStorageDriver($this->storageRoot));

        $this->cut = $services->insert(1, [
            'name' => ['en' => 'Haircut'], 'description' => [], 'duration_minutes' => 30, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
            'price_minor' => null, 'currency' => 'JOD', 'bookable_online' => true, 'active' => true, 'sort_order' => 0,
        ]);
        $services->replaceStaff(1, $this->cut, [11, 12]);
        foreach ([11, 12] as $person) {
            $calendar->ensureStaff(1, $person);
        }
        $this->pdo->exec("INSERT INTO forms (id, tenant_id, form_key, name, status, public_enabled, public_slug, created_at, updated_at)
            VALUES (1, 1, 'health', 'Health questions', 'published', TRUE, '" . str_repeat('a', 64) . "', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                   (2, 1, 'consent', 'Photo consent', 'published', TRUE, '" . str_repeat('b', 64) . "', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                   (3, 2, 'theirs', 'Another workspace', 'published', TRUE, '" . str_repeat('c', 64) . "', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        if (is_dir($this->storageRoot)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->storageRoot, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->storageRoot);
        }
    }

    // ── intake forms ────────────────────────────────────────────────────

    public function testAServiceAsksForFormsAndTheBookingSaysWhetherTheyAreDone(): void
    {
        $intake = $this->service->intake();
        $intake->replaceForService(1, $this->cut, [
            ['form_id' => 1, 'fill_when' => 'on_booking', 'who' => 'every', 'required' => true],
            ['form_id' => 2, 'fill_when' => 'before_visit', 'who' => 'new', 'required' => false],
        ]);
        self::assertSame([1, 2], array_column($intake->forService(1, $this->cut), 'form_id'));
        foreach ([[['form_id' => 3]], [['form_id' => 1, 'fill_when' => 'whenever']]] as $bad) {
            try {
                $intake->replaceForService(1, $this->cut, $bad);
                self::fail('Refused: ' . json_encode($bad));
            } catch (CalendarRejectedException $e) {
                self::assertContains($e->status, [404, 422]);
            }
        }

        $this->sent = [];
        $first = $this->service->book(1, $this->request(11, '2026-11-16 07:00:00', 'Lina Haddad', 'lina@example.com'));
        // The confirmation links to both forms, each with its own token.
        self::assertSame(['Health questions', 'Photo consent'], array_column($first['intake_forms'], 'name'));
        self::assertMatchesRegularExpression('#^https://app\.example\.com/f/a{64}\?intake=[a-f0-9]{64}$#', $first['intake_forms'][0]['url']);
        self::assertStringContainsString('Before your visit, please fill in:', $this->mails()[0]['text'] ?? '');
        self::assertSame(IntakeForms::NOT_DONE, $this->service->bookingOrFail(1, (int) $first['id'])['intake']['status']);

        // Sent from the customer's link: it belongs to the booking and the customer.
        $token = substr((string) $first['intake_forms'][0]['url'], -64);
        self::assertNull($intake->resolve($token, 1, 2), 'a token is for its own form');
        self::assertNull($intake->resolve($token, 2, 1), 'and its own workspace');
        $resolved = $intake->resolve($token, 1, 1);
        self::assertSame(['Lina Haddad', 'lina@example.com', (int) $first['id']], [$resolved['name'] ?? null, $resolved['email'] ?? null, $resolved['booking_id'] ?? null]);
        $this->pdo->exec("INSERT INTO form_submissions (id, tenant_id, form_id, form_version, submitted_at, created_at) VALUES (50, 1, 1, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $intake->attach(1, 50, $resolved['booking_id'] ?? 0, $resolved['contact_id'] ?? null);
        $done = $this->service->bookingOrFail(1, (int) $first['id'])['intake'];
        self::assertSame(IntakeForms::DONE, $done['status'], 'the photo consent is optional');
        self::assertSame([true, false], array_column($done['forms'], 'done'));
        self::assertNotNull($this->scalar('SELECT contact_id FROM form_submissions WHERE id = 50'));

        // Their next visit: the new-customers-only form is no longer asked for.
        $this->pdo->exec('UPDATE calendar_bookings SET attendance = \'attended\' WHERE id = ' . (int) $first['id']);
        $second = $this->service->book(1, $this->request(11, '2026-11-17 07:00:00', 'Lina Haddad', 'lina@example.com'));
        self::assertSame(['Health questions'], array_column($second['intake_forms'], 'name'));

        // A service with no forms needs none.
        $this->service->intake()->replaceForService(1, $this->cut, []);
        self::assertSame(IntakeForms::NOT_NEEDED, $this->service->bookingOrFail(1, (int) $second['id'])['intake']['status']);
    }

    public function testSendAgainAndFillInAtTheDesk(): void
    {
        $this->service->intake()->replaceForService(1, $this->cut, [['form_id' => 1]]);
        $booking = $this->service->book(1, $this->request(11, '2026-11-16 07:00:00', 'Lina Haddad', 'lina@example.com'));
        $handler = $this->handler([]);
        $this->sent = [];
        self::assertSame(201, $handler->intakeLink(self::staff(['send' => true], 11), ['id' => (string) $booking['id'], 'formId' => '1'])->getStatusCode());
        self::assertSame(['Please fill in: Health questions'], array_column($this->mails(), 'subject'));
        $desk = self::data($handler->intakeLink(self::staff([], 11), ['id' => (string) $booking['id'], 'formId' => '1']), 201);
        self::assertStringEndsWith('&kiosk=1', (string) $desk['url']);
        self::assertSame(422, $handler->intakeLink(self::staff([], 11), ['id' => (string) $booking['id'], 'formId' => '2'])->getStatusCode(), 'not a form this booking asks for');
    }

    // ── the visit record ────────────────────────────────────────────────

    public function testOnlyThePermissionOrTheTreatingPersonOpensIt(): void
    {
        $booking = $this->book(11);
        self::assertTrue(VisitRecords::may(['profile_id' => 11], 11, false, false), 'the treating person');
        self::assertFalse(VisitRecords::may(['profile_id' => 11], 12, false, false), 'another staff member');
        self::assertTrue(VisitRecords::may(['profile_id' => 11], 12, true, false), 'granted calendar:visit_notes');
        self::assertFalse(VisitRecords::may(['profile_id' => 11], 11, false, true), 'an agent, even as the treating person');
        self::assertTrue(VisitRecords::may(['profile_id' => 11], 11, true, true), 'an agent granted it');

        $nobody = $this->handler([]);
        self::assertSame(200, $nobody->visit(self::staff([], 11), ['id' => (string) $booking])->getStatusCode());
        self::assertSame(403, $nobody->visit(self::staff([], 12), ['id' => (string) $booking])->getStatusCode());
        self::assertSame(403, $nobody->putVisit(self::staff(['body' => 'x'], 12), ['id' => (string) $booking])->getStatusCode());
        self::assertSame(403, $nobody->visit(self::agent(11), ['id' => (string) $booking])->getStatusCode(), 'an MCP tool needs the permission');
        $granted = $this->handler([CorePermissions::CALENDAR_VISIT_NOTES]);
        self::assertSame(200, $granted->visit(self::staff([], 12), ['id' => (string) $booking])->getStatusCode());
        self::assertSame(200, $granted->visit(self::agent(13), ['id' => (string) $booking])->getStatusCode());
    }

    public function testNeverOnACustomerOrPublicPath(): void
    {
        $booking = $this->book(11);
        $this->service->visits()->write(1, $booking, 'Sensitive notes', 11);
        $forCustomer = json_encode(PublicBookingApiHandler::forCustomer($this->service->bookingOrFail(1, $booking))) ?: '';
        self::assertStringNotContainsString('Sensitive notes', $forCustomer);
        self::assertStringNotContainsString('visit', $forCustomer);

        // No route outside the staff calendar names a visit, and every one there checks for it.
        $routes = (string) file_get_contents(dirname(__DIR__, 3) . '/public/index.php');
        preg_match_all("#register\\(\\s*'[A-Z]+',\\s*'([^']*visit[^']*)'#", $routes, $m);
        self::assertNotEmpty($m[1]);
        foreach ($m[1] as $path) {
            self::assertStringStartsWith('/api/calendar/', $path, $path);
        }
        $handler = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Api/CalendarApiHandler.php');
        foreach (['visit', 'putVisit', 'finishVisit', 'correctVisit', 'addVisitFile', 'visitFile'] as $method) {
            self::assertSame(1, preg_match('/public function ' . $method . '\(.*?\n    }\n/s', $handler, $body), $method);
            self::assertStringContainsString('visitAllowed(', $body[0] ?? '', $method . ' checks who may open it');
        }
        foreach (['src/Api/CustomerAreaApiHandler.php', 'src/Api/PublicBookingApiHandler.php'] as $file) {
            self::assertStringNotContainsString('visits()', (string) file_get_contents(dirname(__DIR__, 3) . '/' . $file), $file);
        }
    }

    public function testAfterFinishTheNotesNeverChangeOnlyCorrectionsAreAdded(): void
    {
        $booking = $this->book(11);
        $visits = $this->service->visits();
        $visits->write(1, $booking, 'First draft', 11);
        self::assertSame('Second draft', $visits->write(1, $booking, 'Second draft', 11)['notes']['body']);
        try {
            $visits->correct(1, $booking, 'Too early', 11);
            self::fail('Before Finish visit, the notes are changed instead');
        } catch (CalendarRejectedException $e) {
            self::assertSame(422, $e->status);
        }
        $finished = $visits->finish(1, $booking, 11);
        self::assertTrue($finished['finished']);
        self::assertSame('Amro Saleh', $finished['notes']['finished_by']['name'] ?? null);

        foreach ([fn () => $visits->write(1, $booking, 'Rewritten', 11), fn () => $visits->finish(1, $booking, 11)] as $change) {
            try {
                $change();
                self::fail('A finished visit doesn’t change');
            } catch (CalendarRejectedException $e) {
                self::assertSame(409, $e->status);
            }
        }
        $handler = $this->handler([]);
        $refused = $handler->putVisit(self::staff(['body' => 'Rewritten'], 11), ['id' => (string) $booking]);
        self::assertSame(409, $refused->getStatusCode());

        $corrected = $visits->correct(1, $booking, 'The dose was 5 ml, not 10 ml.', 11);
        self::assertSame('Second draft', $corrected['notes']['body'], 'the original stays as it was');
        self::assertSame(['The dose was 5 ml, not 10 ml.'], array_column($corrected['corrections'], 'body'));
        self::assertSame('2026-11-16T05:00:00Z', $corrected['corrections'][0]['created_at'], 'dated');
        self::assertSame('Second draft', (string) $this->scalar("SELECT body FROM calendar_visit_notes WHERE booking_id = {$booking}"));
        // There is no way to remove notes, corrections or files.
        foreach ((new \ReflectionClass(VisitRecords::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            self::assertDoesNotMatchRegularExpression('/delete|remove|forget|clear/i', $method->getName());
        }
    }

    public function testEveryReadAndChangeIsAuditedAndFilesStayInTheTenantsStorage(): void
    {
        $booking = $this->book(11);
        $visits = $this->service->visits();
        $this->audited = [];
        $visits->read(1, $booking, 11);
        $visits->write(1, $booking, 'Notes', 11);
        $file = $visits->addFile(1, $booking, "%PDF-1.4\nreport", 'application/pdf', 'x-ray.pdf', 11);
        $visits->fileBytes(1, $file['id'], 11);
        $visits->finish(1, $booking, 11);
        $visits->correct(1, $booking, 'Added later', 11);
        $actions = array_column($this->auditEntries(), 'action');
        foreach (['calendar.visit.viewed', 'calendar.visit.written', 'calendar.visit.file_added', 'calendar.visit.file_read', 'calendar.visit.finished', 'calendar.visit.corrected'] as $action) {
            self::assertContains($action, $actions);
        }
        foreach ($this->auditEntries() as $entry) {
            self::assertSame([1, 11, 'booking', $booking], [$entry['options']['tenant_id'] ?? null, $entry['options']['actor_user_id'] ?? null, $entry['options']['target_type'] ?? null, $entry['options']['target_id'] ?? null]);
        }

        self::assertSame(['x-ray.pdf', 'application/pdf', 'Amro Saleh'], [$file['filename'], $file['content_type'], $file['added_by']['name'] ?? null]);
        $key = (string) $this->scalar("SELECT f.storage_key FROM calendar_visit_files v JOIN stored_files f ON f.id = v.stored_file_id WHERE v.id = {$file['id']}");
        self::assertStringStartsWith('tenants/1/visits/' . $booking . '/', $key, 'the tenant’s own storage');
        self::assertSame('visit_file', $this->scalar("SELECT kind FROM stored_files WHERE storage_key = '{$key}'"));
        self::assertNull($visits->bookingOfFile(2, $file['id']), 'another workspace can’t find it');
        try {
            $visits->addFile(1, $booking, 'MZ not a pdf', 'application/pdf', 'tool.exe', 11);
            self::fail('Only PDFs, photos and text');
        } catch (CalendarRejectedException $e) {
            self::assertSame(422, $e->status);
        }
    }

    public function testTheMigrationsRunTwiceAndRollBack(): void
    {
        require_once dirname(__DIR__, 3) . '/database/migrations/370_create_calendar_intake_forms.php';
        require_once dirname(__DIR__, 3) . '/database/migrations/371_create_calendar_visit_records.php';
        $db = Database::withFactory(fn (): PDO => $this->pdo);
        CreateCalendarVisitRecords::up($db);
        CreateCalendarVisitRecords::up($db);
        CreateCalendarIntakeForms::up($db);
        CreateCalendarIntakeForms::up($db);
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE p.name = 'calendar:visit_notes'"), 'granted to no role');
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM permissions WHERE name = 'calendar:visit_notes'"));
        CreateCalendarVisitRecords::down($db);
        CreateCalendarIntakeForms::down($db);
        CreateCalendarIntakeForms::down($db);
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM permissions WHERE name = 'calendar:visit_notes'"));
        CreateCalendarIntakeForms::up($db);
        CreateCalendarVisitRecords::up($db);
    }

    // ---------------------------------------------------------------------

    /**
     * @return array{
     *   service_id: int, profile_id: ?int, starts_at: DateTimeImmutable,
     *   customer_profile_id: ?int, customer_name: string, customer_email: string,
     *   customer_phone: string, customer_note: string, source: string,
     *   created_by_profile_id: ?int, enforce_hours: bool, public: bool, notify: bool
     * }
     */
    private function request(int $profile, string $startsUtc, string $name, string $email): array
    {
        return [
            'service_id' => $this->cut, 'profile_id' => $profile, 'starts_at' => new DateTimeImmutable($startsUtc, new DateTimeZone('UTC')),
            'customer_profile_id' => null, 'customer_name' => $name, 'customer_email' => $email,
            'customer_phone' => '', 'customer_note' => '', 'source' => BookingRepository::SOURCE_STAFF,
            'created_by_profile_id' => 11, 'enforce_hours' => false, 'public' => false, 'notify' => true,
        ];
    }

    private function book(int $profile): int
    {
        return (int) $this->service->book(1, $this->request($profile, '2026-11-16 07:00:00', 'Lina Haddad', ''))['id'];
    }

    /** @param list<string> $granted */
    private function handler(array $granted): CalendarApiHandler
    {
        $roles = $this->createStub(RoleChecker::class);
        $roles->method('hasPermissionForProfile')->willReturnCallback(static fn (int $profile, string $permission): bool => in_array($permission, $granted, true));
        TenantContext::reset();
        TenantContext::setTenantId(1);

        return new CalendarApiHandler(new CalendarRepository($this->pdo), new ServiceRepository($this->pdo), new BookingRepository($this->pdo), $this->service, $this->settings, $roles);
    }

    /** @param array<string, mixed> $body */
    private static function staff(array $body, int $profile): Request
    {
        $r = new Request('POST', '/x', [], $body === [] ? '' : (string) json_encode($body));
        $r->user = (object) ['profile_id' => $profile];

        return $r;
    }

    private static function agent(int $profile): Request
    {
        $r = new Request('GET', '/x', [], '');
        $r->user = (object) ['profile_id' => $profile, 'principal_kind' => McpPrincipal::KIND_AGENT];

        return $r;
    }

    /** @return array<string, mixed> */
    private static function data(Response $response, int $status = 200): array
    {
        self::assertSame($status, $response->getStatusCode(), (string) $response->getBody());
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['data']);

        return $body['data'];
    }

    /** @return list<array{to: string, subject: string, text: string}> */
    private function mails(): array
    {
        return $this->sent;
    }

    /** @return list<array{action: string, options: array<string, mixed>}> */
    private function auditEntries(): array
    {
        return $this->audited;
    }

    private function scalar(string $sql): mixed
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt);

        return $stmt->fetchColumn();
    }
}
