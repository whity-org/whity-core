<?php

declare(strict_types=1);

namespace Tests\Core\Document;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Sdk\Manifest\DocumentStarterValidatorTest;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Document\DocumentSourceRepository;
use Whity\Core\Document\DocumentSourceVersionRepository;
use Whity\Core\Document\PluginStarterTemplates;
use Whity\Core\Document\Slots\SlotBindings;
use Whity\Core\Document\Slots\TemplateSlot;
use Whity\Core\Plugin\Tenant\TenantManifestPublisher;
use Whity\Core\Plugin\Tenant\TenantPluginRepository;
use Whity\Core\PluginRoleSeeder;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Sdk\Manifest\PluginManifest;

/**
 * WHIT-995 (SDK 1.81): a workspace plugin's starter templates are seeded when
 * it goes live, published and bound to an unbound slot, and never overwrite
 * what the workspace changed. Runs on SQLite by default and on PostgreSQL when
 * PHPUNIT_PG_DSN is set.
 */
final class PluginStarterTemplatesRealEngineTest extends TestCase
{
    private const A = 1;
    private const B = 2;
    private const MANAGER = 10;

    private PDO $pdo;
    private SettingsService $settings;
    private TenantManifestPublisher $publisher;
    private TenantPluginRepository $plugins;
    private DocumentSourceRepository $sources;
    private PluginStarterTemplates $starters;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a', 'a'), (2, 'b', 'b')");
        $this->pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
            VALUES (10, 'manager', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $this->settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        $this->plugins = new TenantPluginRepository($this->pdo);
        $this->sources = new DocumentSourceRepository($this->pdo);
        $this->starters = new PluginStarterTemplates($this->pdo, $this->sources, new DocumentSourceVersionRepository($this->pdo), $this->settings);
        $this->publisher = new TenantManifestPublisher($this->pdo, $this->plugins, new PluginRoleSeeder($this->pdo), []);
        $this->publisher->useStarterTemplates($this->starters);
    }

    public function testPublishingSeedsPublishedTemplatesAndBindsTheUnboundSlot(): void
    {
        $this->publisher->publish(self::A, DocumentStarterValidatorTest::manifest(), self::MANAGER);

        $confirmation = $this->seeded(self::A, 'confirmation');
        self::assertSame('Order confirmation', $confirmation['title']);
        self::assertSame('system', $confirmation['scope']);
        self::assertSame('CakeOrders.order', $confirmation['record_type']);
        self::assertSame(1, (int) $confirmation['published_version']);

        $priceList = $this->seeded(self::A, 'price_list');
        self::assertSame('قائمة الأسعار', $priceList['title'], 'An Arabic starter takes its Arabic label as its title');
        self::assertSame('الشتاء', $priceList['own_fields'][0]['sample'], 'and its Arabic sample');
        self::assertSame('Thank you', $confirmation['own_fields'][0]['sample'], 'a plain sample is kept as it is');
        self::assertNull($priceList['record_type']);

        $bindings = $this->bindings(self::A);
        self::assertSame((int) $confirmation['id'], $bindings['CakeOrders.order_confirmation'] ?? null);
        self::assertArrayNotHasKey('CakeOrders.order_label', $bindings, 'A slot with no starter stays unbound');

        $slot = TemplateSlot::fromManifest('CakeOrders', PluginManifest::fromArray(DocumentStarterValidatorTest::manifest())->documentSlots())[0];
        self::assertNull($slot->misfit($confirmation), 'The seeded template fits its slot, so the plugin can issue at once');

        self::assertNull($this->starter(self::B, 'confirmation'), 'Seeding is per workspace');
    }

    public function testASlotTheWorkspaceAlreadyBoundKeepsItsTemplate(): void
    {
        $this->settings->setTenant(self::A, SettingsRegistry::DOCUMENTS_SLOT_BINDINGS, SlotBindings::encode(['CakeOrders.order_confirmation' => 999]));

        $this->publisher->publish(self::A, DocumentStarterValidatorTest::manifest(), self::MANAGER);

        self::assertSame(999, $this->bindings(self::A)['CakeOrders.order_confirmation']);
        self::assertNotNull($this->starter(self::A, 'confirmation'), 'The starter is still seeded, just not bound');
    }

    public function testAPluginPublishedTurnedOffSeedsOnlyWhenSwitchedOn(): void
    {
        $this->publisher->publishTurnedOff(self::A, DocumentStarterValidatorTest::manifest(), self::MANAGER);
        self::assertNull($this->starter(self::A, 'confirmation'));

        $this->plugins->setEnabled(self::A, 'CakeOrders', true);
        $this->publisher->seedStarters(self::A, 'CakeOrders');

        self::assertNotNull($this->starter(self::A, 'confirmation'));
    }

    public function testADeletedStarterIsNotBroughtBack(): void
    {
        $this->publisher->publish(self::A, DocumentStarterValidatorTest::manifest(), self::MANAGER);
        $this->sources->delete((int) $this->seeded(self::A, 'price_list')['id'], self::A);

        $this->publisher->seedStarters(self::A, 'CakeOrders');
        $this->publisher->publish(self::A, $this->version('1.1.0'), self::MANAGER);

        self::assertNull($this->starter(self::A, 'price_list'));
    }

    public function testAnUntouchedCopyFollowsThePluginAndAnEditedOneIsLeftAlone(): void
    {
        $this->publisher->publish(self::A, DocumentStarterValidatorTest::manifest(), self::MANAGER);
        $priceList = $this->seeded(self::A, 'price_list');
        $edited = $this->sources->saveDraft((int) $priceList['id'], self::A, (int) $priceList['draft_version'], ['title' => 'Our prices'], self::MANAGER);
        self::assertNotNull($edited);

        $next = $this->version('1.1.0');
        $next['documents']['starters']['confirmation']['blocks'][] = ['id' => 'b3', 'type' => 'divider'];
        $next['documents']['starters']['price_list']['blocks'][] = ['id' => 'p2', 'type' => 'divider'];
        $this->publisher->publish(self::A, $next, self::MANAGER);

        $confirmation = $this->seeded(self::A, 'confirmation');
        self::assertCount(3, $confirmation['blocks'], 'The untouched starter takes the new version');
        self::assertSame(2, (int) $confirmation['published_version'], 'and the new version is published');

        $priceList = $this->seeded(self::A, 'price_list');
        self::assertSame('Our prices', $priceList['title'], 'The edited starter keeps the workspace edit');
        self::assertCount(1, $priceList['blocks']);
    }

    public function testSeedingTwiceChangesNothing(): void
    {
        $manifest = PluginManifest::fromArray(DocumentStarterValidatorTest::manifest());
        self::assertSame(['seeded' => 2, 'refreshed' => 0, 'bound' => 1], $this->starters->seed(self::A, $manifest));
        self::assertSame(['seeded' => 0, 'refreshed' => 0, 'bound' => 0], $this->starters->seed(self::A, $manifest));
    }

    /** @return array<string, mixed>|null */
    private function starter(int $tenantId, string $name): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id FROM document_sources WHERE tenant_id = :t AND starter_key = :k');
        $stmt->execute([':t' => $tenantId, ':k' => PluginStarterTemplates::starterKey('CakeOrders', $name)]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : $this->sources->findById((int) $id, $tenantId);
    }

    /** @return array<string, mixed> */
    private function seeded(int $tenantId, string $name): array
    {
        $row = $this->starter($tenantId, $name);
        self::assertNotNull($row, "Starter {$name} was not seeded");

        return $row;
    }

    /** @return array<string, int> */
    private function bindings(int $tenantId): array
    {
        return SlotBindings::parse((string) ($this->settings->effective($tenantId)[SettingsRegistry::DOCUMENTS_SLOT_BINDINGS] ?? ''));
    }

    /** @return array<string, mixed> */
    private function version(string $version): array
    {
        $manifest = DocumentStarterValidatorTest::manifest();
        $manifest['plugin']['version'] = $version;

        return $manifest;
    }
}
