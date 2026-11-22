<?php

declare(strict_types=1);

namespace Tests\Core\Workspace\Templates;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExampleBakeryTemplate;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Workspace\Templates\TemplateApplier;
use Whity\Core\Workspace\Templates\TemplateRuns;
use Whity\Sdk\Setup\WorkspaceSetup;

/**
 * WHIT-1194 (SDK 1.116): a template is applied as the person, adds only what
 * is missing, never hands out a permission the person lacks, and its
 * examples come out in one click.
 */
final class TemplateApplierTest extends TestCase
{
    private const BAKERY = 1;
    private const AMRO = 7;

    private PDO $pdo;
    private TemplateRuns $runs;
    private FakeSetup $api;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 'example-bakery')");
        $this->pdo->prepare("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                             VALUES (?, 'Amro Saleh', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)")->execute([self::AMRO]);
        $this->runs = new TemplateRuns($this->pdo);
        $this->api = new FakeSetup();
    }

    private function applier(): TemplateApplier
    {
        $holds = static fn (int $profileId, string $permission, int $tenantId): bool => $permission !== 'roles:delete';

        return new TemplateApplier($this->api, $this->runs, $holds, static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-11-17T09:00:00+00:00'));
    }

    public function testItSetsUpTheWorkspaceAsThePerson(): void
    {
        $result = $this->applier()->apply(self::BAKERY, self::AMRO, ExampleBakeryTemplate::manifest() + ['plugin' => 'Templates'], true);

        self::assertSame(['services' => 1, 'roles' => 1, 'forms' => 1, 'documents' => 1, 'examples' => 2], $result['made']);
        // Settings: only what the workspace keeps at its default.
        self::assertSame(['settings' => ['timezone' => 'Asia/Amman']], $this->api->body('PATCH /api/settings'));
        self::assertSame(['min_notice_minutes' => 60, 'booking_page_enabled' => true], $this->api->body('PUT /api/calendar/settings'));
        self::assertSame(['bookable' => true], $this->api->body('PUT /api/calendar/staff/7'));

        // Priced in the workspace's own currency (JOD, set by the person at creation), only ticked services.
        $service = $this->api->body('POST /api/calendar/services');
        self::assertSame(20000, $service['price_minor']);
        self::assertSame('JOD', $service['currency']);
        self::assertSame([self::AMRO], $service['staff_profile_ids']);
        self::assertSame(1, $this->api->count('POST /api/calendar/services'), 'An unticked service is offered in the setup, not made');

        // A role gets only what the person holds.
        self::assertSame(['permissions' => ['calendar:write', 'contacts:write']], $this->api->body('POST /api/roles/30/permissions'));
        self::assertSame('Counter', $this->api->body('POST /api/roles')['name']);
        self::assertContains('roles', array_column($result['skipped'], 'part'));

        self::assertSame('cake-order', $this->api->body('POST /api/forms')['form_key']);
        self::assertSame('flavour', $this->api->body('PUT /api/forms/40/fields')['fields'][0]['field_key']);
        self::assertSame(1, $this->api->count('POST /api/forms/40/publish'));
        self::assertSame('tenant', $this->api->body('POST /api/document-sources')['scope']);

        $booking = $this->api->body('POST /api/calendar/bookings');
        self::assertSame('2026-11-19T10:30:00+03:00', $booking['starts_at'], 'Two days ahead, in the workspace time zone');
        self::assertFalse($booking['notify'], 'An example booking emails nobody');
        self::assertSame(2, count($this->runs->liveExamples(self::BAKERY)));
    }

    public function testApplyingAgainAddsNothingTwice(): void
    {
        $template = ExampleBakeryTemplate::manifest();
        $this->applier()->apply(self::BAKERY, self::AMRO, $template, true);
        $calls = $this->api->writes();

        $again = $this->applier()->apply(self::BAKERY, self::AMRO, $template, true);

        self::assertSame([], $again['made']);
        self::assertSame($calls, $this->api->writes(), 'No second service, role, form, document, contact or booking; booking rules kept as the workspace left them');
        self::assertCount(1, $this->runs->forTenant(self::BAKERY));
    }

    public function testWhatTheWorkspaceAlreadyHasIsKept(): void
    {
        $this->api->services = [['id' => 3, 'name' => ['en' => 'Cake Order', 'ar' => '']]];
        $this->api->hours = [['weekday' => 1, 'start_time' => '09:00', 'end_time' => '17:00']];

        $result = $this->applier()->apply(self::BAKERY, self::AMRO, ExampleBakeryTemplate::manifest(), true);

        self::assertSame(0, $this->api->count('POST /api/calendar/services'), 'Same name, any case: kept');
        self::assertSame(0, $this->api->count('PUT /api/calendar/staff/7/hours'), 'Their own week is kept');
        self::assertSame(3, $this->api->body('POST /api/calendar/bookings')['service_id'], 'The example books the service already there');
        self::assertContains('services', array_column($result['skipped'], 'part'));
    }

    public function testTheTemplatesThemeIsSetOnItsOwnAndOnlyOverTheInstallationsTheme(): void
    {
        $result = $this->applier()->apply(self::BAKERY, self::AMRO, ExampleBakeryTemplate::manifest(), false, 'templates.market');

        $patches = array_values(array_filter($this->api->calls, static fn (array $call): bool => $call[0] === 'PATCH /api/settings'));
        self::assertSame(['settings' => ['branding_theme' => 'templates.market']], $patches[1][1], 'Its own PATCH, after the template settings');
        self::assertNotContains('theme', array_column($result['skipped'], 'part'));

        // A theme the workspace chose (here, by the first run) stays.
        $this->applier()->apply(self::BAKERY, self::AMRO, ExampleBakeryTemplate::manifest(), false, 'templates.other');
        $themes = array_filter($this->api->calls, static fn (array $call): bool => isset($call[1]['settings']['branding_theme']));
        self::assertCount(1, $themes);
    }

    public function testNoThemeWhenThePersonSaysNo(): void
    {
        $this->applier()->apply(self::BAKERY, self::AMRO, ExampleBakeryTemplate::manifest(), false);

        self::assertSame([], array_filter($this->api->calls, static fn (array $call): bool => isset($call[1]['settings']['branding_theme'])));
    }

    public function testNoExamplesWhenThePersonSaysNo(): void
    {
        $this->applier()->apply(self::BAKERY, self::AMRO, ExampleBakeryTemplate::manifest(), false);

        self::assertSame(0, $this->api->count('POST /api/contacts'));
        self::assertSame(0, $this->api->count('POST /api/calendar/bookings'));
    }

    public function testExamplesComeOutInOneClickAndOnlyThem(): void
    {
        $this->applier()->apply(self::BAKERY, self::AMRO, ExampleBakeryTemplate::manifest(), true);

        $removed = $this->applier()->removeExamples(self::BAKERY);

        self::assertSame(['removed' => 2, 'failed' => 0], $removed);
        self::assertSame(1, $this->api->count('POST /api/calendar/bookings/60/cancel'));
        self::assertSame(1, $this->api->count('POST /api/contacts/50/archive'));
        self::assertSame(0, $this->api->count('POST /api/calendar/services/10/archive'), 'The menu stays');
        self::assertSame([], $this->runs->liveExamples(self::BAKERY));
        self::assertSame(['removed' => 0, 'failed' => 0], $this->applier()->removeExamples(self::BAKERY));
    }

    public function testAPartThatFailsIsReportedAndTheRestStillHappens(): void
    {
        $this->api->refuse = 'POST /api/roles';

        $result = $this->applier()->apply(self::BAKERY, self::AMRO, ExampleBakeryTemplate::manifest(), true);

        self::assertSame(['part' => 'roles', 'key' => 'counter', 'reason' => 'Forbidden'], $result['skipped'][0]);
        self::assertSame(1, $result['made']['forms']);
    }
}
