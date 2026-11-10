<?php

declare(strict_types=1);

namespace Tests\Core\Document;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\RoleChecker;
use Whity\Core\Audit\AuditContext;
use Whity\Core\Document\DocumentAccessPolicy;
use Whity\Core\Document\DocumentSourceRepository;
use Whity\Core\Document\DocumentSourceVersionRepository;
use Whity\Core\Document\Issuing\SdkTemplates;
use Whity\Core\Document\Slots\BackgroundIssuing;
use Whity\Core\Document\Slots\SlotBindings;
use Whity\Core\Document\Slots\TemplateSlot;
use Whity\Core\Document\Slots\TemplateSlotRegistry;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\RBAC\ResourceTypeRegistry;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Sdk\Documents\TemplateField;

/**
 * WHIT-996 (SDK 1.82): plugins read the published templates their caller can
 * see, described from the published version, with their merge fields. Runs on
 * SQLite by default and on PostgreSQL when PHPUNIT_PG_DSN is set.
 */
final class SdkTemplatesRealEngineTest extends TestCase
{
    private const A = 1;
    private const B = 2;
    private const OWNER = 10;
    private const OTHER = 20;

    private PDO $pdo;
    private SettingsService $settings;
    private DocumentSourceRepository $sources;
    private DocumentSourceVersionRepository $versions;
    private TemplateSlotRegistry $slots;
    private SdkTemplates $templates;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a', 'a'), (2, 'b', 'b')");
        $this->pdo->exec("INSERT OR IGNORE INTO roles (id, name, description, tenant_id, created_at) VALUES (1, 'admin', '', NULL, datetime('now'))");
        $this->pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at) VALUES
                (10, 'owner', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (20, 'other', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $this->pdo->exec("
            INSERT INTO memberships (id, profile_id, tenant_id, role_id, status, created_at) VALUES
                (1000, 10, 1, 1, 'active', datetime('now')),
                (1001, 20, 1, 1, 'active', datetime('now'))
        ");
        $this->settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        $this->sources = new DocumentSourceRepository($this->pdo);
        $this->versions = new DocumentSourceVersionRepository($this->pdo);
        $this->slots = new TemplateSlotRegistry();
        $this->slots->register('Courier', [new TemplateSlot('Courier.label', 'Courier', ['en' => 'Label', 'ar' => 'ملصق'], 'Courier.parcel')]);
        $this->slots->declareBackground('Courier', true);

        $db = Database::withFactory(fn (): PDO => $this->pdo);
        $this->templates = SdkTemplates::build(
            $this->pdo,
            new RoleChecker($db, new PermissionRegistry()),
            new ResourceTypeRegistry(),
            $this->settings,
            $this->slots,
            static fn (string $job): ?array => str_starts_with($job, 'courier:') ? ['plugin' => 'Courier', 'permissions' => []] : null,
        );
    }

    protected function tearDown(): void
    {
        AuditContext::reset();
        TenantContext::reset();
        RoleChecker::clearCache();
    }

    public function testAPersonReadsThePublishedTemplatesTheyCanSeeWithTheirFields(): void
    {
        $invoice = $this->template('Invoice', 'system', null, publish: true);
        $this->template('Draft only', 'system', null, publish: false);
        $this->template('Someone else\'s', 'personal', self::OTHER, publish: true);
        $this->template('Other workspace', 'system', null, publish: true, tenantId: self::B);
        $this->bind(['Courier.label' => $invoice]);

        $this->as(self::OWNER);
        self::assertTrue($this->templates->canRead());
        $list = $this->templates->list();
        self::assertSame(['Invoice'], array_map(static fn ($t) => $t->title, $list));

        $t = $list[0];
        self::assertSame($invoice, $t->id);
        self::assertSame(1, $t->publishedVersion);
        self::assertSame('Courier.parcel', $t->recordType);
        self::assertSame(['Courier.label'], $t->slots);
        self::assertSame(
            [
                ['record.customer', 'Customer', TemplateField::SOURCE_RECORD, false, null],
                ['template.note', 'Note', TemplateField::SOURCE_TEMPLATE, true, 'Thank you'],
                ['company.name', 'Company name', TemplateField::SOURCE_HOST, false, null],
                ['template.footer', 'Footer', TemplateField::SOURCE_TEMPLATE, false, null],
            ],
            array_map(static fn (TemplateField $f): array => [$f->path, $f->label, $f->source, $f->required, $f->sample], $t->fields),
        );
        self::assertSame(['template.note'], $t->requiredValues());

        self::assertSame($invoice, $this->templates->find($invoice)?->id);
        self::assertSame($invoice, $this->templates->forSlot('Courier.label')?->id);
        self::assertSame([], $this->templates->list(kind: 'label'));
        self::assertSame([], $this->templates->list(recordType: 'Other.thing'));
    }

    public function testItDescribesThePublishedVersionNotTheDraft(): void
    {
        $id = $this->template('Invoice', 'system', null, publish: true);
        self::assertNotNull($this->sources->saveDraft($id, self::A, 1, ['title' => 'Invoice (draft)', 'blocks' => []], self::OWNER));

        $this->as(self::OWNER);
        $t = $this->templates->find($id);
        self::assertNotNull($t);
        self::assertSame('Invoice', $t->title);
        self::assertCount(4, $t->fields);
    }

    public function testNothingIsVisibleThatTheCallerCannotSee(): void
    {
        $hidden = $this->template('Someone else\'s', 'personal', self::OTHER, publish: true);
        $draft = $this->template('Draft only', 'system', null, publish: false);
        $elsewhere = $this->template('Other workspace', 'system', null, publish: true, tenantId: self::B);

        $this->as(self::OWNER);
        self::assertNull($this->templates->find($hidden));
        self::assertNull($this->templates->find($draft));
        self::assertNull($this->templates->find($elsewhere));
        self::assertNull($this->templates->forSlot('Courier.label'));
    }

    public function testWithNobodyThereIsNothingToRead(): void
    {
        $id = $this->template('Invoice', 'system', null, publish: true);
        TenantContext::setTenantId(self::A);

        self::assertFalse($this->templates->canRead());
        self::assertSame([], $this->templates->list());
        self::assertNull($this->templates->find($id));
    }

    public function testABackgroundPluginReadsOnlyItsOwnBoundTemplatesWhereAllowed(): void
    {
        $label = $this->template('Parcel label', 'system', null, publish: true);
        $other = $this->template('Invoice', 'system', null, publish: true);
        $this->bind(['Courier.label' => $label, 'Billing.invoice' => $other]);

        TenantContext::setTenantId(self::A);
        AuditContext::setJob('courier:send_labels');
        self::assertFalse($this->templates->canRead(), 'not allowed by the workspace yet');
        self::assertSame([], $this->templates->list());

        $this->settings->setTenant(self::A, SettingsRegistry::DOCUMENTS_BACKGROUND_ISSUING, BackgroundIssuing::encode(['Courier']));
        self::assertTrue($this->templates->canRead());
        self::assertSame([$label], array_map(static fn ($t) => $t->id, $this->templates->list()));
        self::assertNull($this->templates->find($other), "another plugin's template");
        self::assertNull($this->templates->forSlot('Billing.invoice'));
        self::assertSame($label, $this->templates->forSlot('Courier.label')?->id);

        AuditContext::setJob('other:thing');
        self::assertFalse($this->templates->canRead(), 'a plugin that declares nothing');
    }

    private function as(int $profileId): void
    {
        TenantContext::setTenantId(self::A);
        AuditContext::set($profileId, null);
    }

    /** @param array<string, int> $bindings */
    private function bind(array $bindings): void
    {
        $this->settings->setTenant(self::A, SettingsRegistry::DOCUMENTS_SLOT_BINDINGS, SlotBindings::encode($bindings));
    }

    private function template(string $title, string $scope, ?int $createdBy, bool $publish, int $tenantId = self::A): int
    {
        $field = static fn (string $path, string $label, bool $required = false): array
            => ['type' => 'field', 'path' => $path, 'label' => $label] + ($required ? ['required' => true] : []);
        $id = $this->sources->create($tenantId, [
            'kind' => 'template',
            'title' => $title,
            'lang' => 'en',
            'default_view' => 'page',
            'page' => ['size' => 'A4'],
            'record_type' => 'Courier.parcel',
            'blocks' => [
                ['id' => 'b1', 'type' => 'paragraph', 'content' => [$field('record.customer', 'Customer'), $field('template.note', 'Note', true)]],
                ['id' => 'b2', 'type' => 'paragraph', 'content' => [$field('company.name', 'Company name'), $field('record.customer', 'Customer')]],
            ],
            'own_fields' => [
                ['key' => 'note', 'label' => 'Note', 'sample' => 'Thank you'],
                ['key' => 'footer', 'label' => 'Footer'],
            ],
            'scope' => $scope,
            'created_by' => $createdBy,
        ]);
        if ($publish) {
            self::assertSame(1, $this->versions->publish($id, $tenantId, 1, self::OWNER));
        }

        return $id;
    }
}
