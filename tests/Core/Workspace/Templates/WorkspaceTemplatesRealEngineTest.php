<?php

declare(strict_types=1);

namespace Tests\Core\Workspace\Templates;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\WorkspaceCodePluginsApiHandler;
use Whity\Api\WorkspaceTemplatesApiHandler;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Plugin\Workspace\CodePluginActivation;
use Whity\Core\PluginLoader;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Core\Workspace\Templates\TemplateApplier;
use Whity\Core\Workspace\Templates\TemplateCatalogue;
use Whity\Core\Workspace\Templates\TemplateRuns;
use Whity\Core\Workspace\Templates\TemplateSetup;

/**
 * WHIT-1194 (SDK 1.116): a plugin's templates are checked one by one, listed
 * with core's Blank where the plugin is on, and offer the paid plugin's trial
 * only while it is installed and paid.
 */
final class WorkspaceTemplatesRealEngineTest extends TestCase
{
    private const BAKERY = 1;
    private const AMRO = 7;

    private static string $dir;

    private PDO $pdo;
    private CodePluginActivation $activation;
    private PluginLoader $loader;

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/whity_ws_templates_' . uniqid();
        mkdir(self::$dir . '/WsTemplates', 0755, true);
        mkdir(self::$dir . '/WsCounter', 0755, true);
        mkdir(self::$dir . '/WsBadName', 0755, true);
        file_put_contents(self::$dir . '/WsBadName/Plugin.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace WsBadName;

use Whity\Sdk\PluginDisplayNameInterface;
use Whity\Sdk\PluginInterface;

final class Plugin implements PluginInterface, PluginDisplayNameInterface
{
    public function getName(): string { return 'WsBadName'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getMigrations(): array { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array { return []; }
    public function getRoutes(): array { return []; }
    public function getDisplayName(): array { return ['en' => 'Only English']; }
}
PHP);
        file_put_contents(self::$dir . '/WsTemplates/Plugin.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace WsTemplates;

use Tests\Support\ExampleBakeryTemplate;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginWorkspaceTemplatesInterface;

final class Plugin implements PluginInterface, PluginWorkspaceTemplatesInterface
{
    public function getName(): string { return 'WsTemplates'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getMigrations(): array { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array { return []; }
    public function getRoutes(): array { return []; }
    public function getWorkspaceTemplates(): array
    {
        $bakery = ExampleBakeryTemplate::manifest();
        $bakery['offer']['plugin'] = 'WsCounter';
        $bakery['theme'] = 'templates.market';
        $broken = ['key' => 'clinic', 'version' => 1, 'name' => ['en' => 'Clinic']];
        $blank = ['key' => 'blank'] + $bakery;

        return [$bakery, $broken, $blank];
    }
}
PHP);
        file_put_contents(self::$dir . '/WsCounter/Plugin.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace WsCounter;

use Whity\Sdk\PluginAccessInterface;
use Whity\Sdk\PluginDisplayNameInterface;
use Whity\Sdk\PluginInterface;

final class Plugin implements PluginInterface, PluginAccessInterface, PluginDisplayNameInterface
{
    public function getDisplayName(): array { return ['en' => 'Whity for Counters', 'ar' => 'Whity للكاونترات']; }
    public function getName(): string { return 'WsCounter'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getMigrations(): array { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array { return []; }
    public function getRoutes(): array { return []; }
    public function getAccess(): ?array
    {
        return ['entitlement' => 'ws_counter.access', 'product' => 'plugin.ws-counter', 'trialDays' => 21];
    }
}
PHP);
    }

    private bool $themeInstalled = true;

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (0, 'system')");
        $this->pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 'example-bakery')");
        $this->pdo->prepare("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                             VALUES (?, 'Amro Saleh', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)")->execute([self::AMRO]);
        $this->activation = new CodePluginActivation($this->pdo);
        $this->loader = new PluginLoader(self::$dir, new Router(), new PermissionRegistry(), new HookManager());
        $this->loader->setWorkspaceActivation($this->activation);
        $this->loader->load();
        $this->activation->usePaidAccess(fn (): array => $this->loader->getPaidAccess());
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testValidTemplatesAreListedWithBlankAndOfferTheTrial(): void
    {
        $data = $this->list('ar');

        self::assertSame(['bakery', 'blank'], array_column($data['templates'], 'key'), 'The broken one is dropped and no plugin replaces Blank');
        $bakery = $data['templates'][0];
        self::assertSame('WsTemplates', $bakery['plugin']);
        self::assertSame('مخبز أو مقهى', $bakery['name']);
        self::assertSame(['قائمتك بالأسعار', 'نموذج طلب كيك'], $bakery['setsUp']);
        self::assertTrue($bakery['hasExamples']);
        self::assertSame('WsCounter', $bakery['offer']['plugin']);
        self::assertSame(30, $bakery['offer']['trialDays'], 'The template\'s own trial, since the plugin offers one');
        self::assertTrue($bakery['offer']['trialAvailable']);
        self::assertSame(['الطلبات عند الكاونتر برمز QR'], $bakery['offer']['paid']);
        self::assertSame('Whity للكاونترات', $bakery['offer']['pluginName'], 'The display name, never the id; Whity stays in Latin letters');
        self::assertNull($data['templates'][1]['plugin']);
        self::assertNull($data['templates'][1]['offer']);
        self::assertSame([], $data['chosen']);
        self::assertTrue($data['offerPicker'], 'A new workspace with no template goes to the picker');
    }

    public function testATemplatesThemeIsOfferedOnlyWhenTheWorkspaceHasIt(): void
    {
        self::assertSame(['id' => 'templates.market', 'name' => 'السوق'], $this->list('ar')['templates'][0]['theme']);

        $this->themeInstalled = false;
        self::assertNull($this->list('en')['templates'][0]['theme'], 'A theme the workspace does not have is not offered');
        self::assertNull($this->list('en')['templates'][1]['theme'], 'Blank has none');
    }

    /**
     * WHIT-1433. Owner, 26 Nov 2026: "a plugin(template) should be turned on
     * only for workspaces that need it". A template plugin off here is still
     * offered, and choosing its template turns it on, for someone who may.
     */
    public function testATemplateOfAPluginOffHereIsOfferedAndChoosingItTurnsThePluginOn(): void
    {
        TenantContext::setTenantId(self::BAKERY);
        $this->activation->setAccess('WsTemplates', CodePluginActivation::CHOOSE);
        $cards = $this->list('en')['templates'];
        self::assertSame(['bakery', 'blank'], array_column($cards, 'key'));
        self::assertFalse($cards[0]['pluginOn']);
        self::assertTrue($cards[1]['pluginOn']);

        $request = new Request('POST', '/api/v1/workspace/templates/bakery/apply', ['content-type' => 'application/json'], '{"examples":false}');
        $request->user = (object) ['profile_id' => self::AMRO];

        $refused = $this->handler('en', static fn (): bool => false)->apply($request, ['key' => 'bakery']);
        self::assertSame(403, $refused->getStatusCode(), 'Turning the plugin on needs plugins:enable');
        self::assertFalse($this->activation->isOn('WsTemplates', self::BAKERY));
        self::assertSame(0, $this->rows('workspace_template_runs'));

        self::assertSame(200, $this->handler('en', static fn (): bool => true)->apply($request, ['key' => 'bakery'])->getStatusCode());
        self::assertTrue($this->activation->isOn('WsTemplates', self::BAKERY), 'Choosing the template turned its plugin on here');
        self::assertTrue($this->list('en')['templates'][0]['pluginOn']);
        self::assertSame(1, $this->rows('workspace_template_runs'));
    }

    public function testATemplateOfAPluginTheOperatorKeepsOrThatCannotBeTurnedOnIsNotOffered(): void
    {
        $this->activation->setAccess('WsTemplates', CodePluginActivation::OPERATOR);

        self::assertSame(['blank'], array_column($this->list('en')['templates'], 'key'));
    }

    /**
     * WHIT-1433: a workspace that applied a template before its plugin was
     * left for each workspace to choose gets the plugin turned on; one that
     * never used it, or turned it off, keeps it off.
     */
    public function testATemplatePluginTurnsOnOnlyWhereItsTemplateWasApplied(): void
    {
        $this->activation->useTemplateKeys(fn (): array => $this->loader->getWorkspaceTemplateKeysByPlugin());
        $this->pdo->exec("INSERT INTO tenants (id, name) VALUES (2, 'example-salon'), (3, 'example-studio')");
        $runs = new TemplateRuns($this->pdo);
        $runs->start(self::BAKERY, 'bakery', 'WorkspaceTemplates', 2, self::AMRO);
        $runs->start(3, 'bakery', 'WorkspaceTemplates', 2, self::AMRO);
        $this->activation->setEnabled(3, 'WsTemplates', false, self::AMRO);
        $this->activation->setAccess('WsTemplates', CodePluginActivation::CHOOSE);

        self::assertTrue($this->activation->isOn('WsTemplates', self::BAKERY), 'It applied the bakery template');
        self::assertFalse($this->activation->isOn('WsTemplates', 2), 'It never used the template');
        self::assertFalse($this->activation->isOn('WsTemplates', 3), 'It turned the plugin off: that choice stays');
        self::assertTrue($this->activation->workspaceRows(self::BAKERY)['WsTemplates']['enabled'], 'Recorded, so it is asked once');
        self::assertArrayNotHasKey('WsTemplates', $this->activation->workspaceRows(2), 'Nothing written where it is not used');
    }

    public function testChoosingBlankIsRemembered(): void
    {
        TenantContext::setTenantId(self::BAKERY);
        $request = new Request('POST', '/api/v1/workspace/templates/blank/apply', ['content-type' => 'application/json'], '{}');
        $request->user = (object) ['profile_id' => self::AMRO];

        $response = $this->handler('en')->apply($request, ['key' => 'blank']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['blank'], $this->list('en')['chosen']);
        self::assertFalse($this->list('en')['offerPicker'], 'Once chosen, never again');
        self::assertSame(404, $this->handler('en')->apply($request, ['key' => 'nowhere'])->getStatusCode());
    }

    public function testThePreviewSavesNothingAndApplyingRecordsTheVersion(): void
    {
        TenantContext::setTenantId(self::BAKERY);
        $request = new Request('POST', '/api/v1/workspace/templates/bakery/preview', ['content-type' => 'application/json'], '{"examples":false}');
        $request->user = (object) ['profile_id' => self::AMRO];

        $response = $this->handler('en')->preview($request, ['key' => 'bakery']);

        self::assertSame(200, $response->getStatusCode());
        $plan = json_decode($response->getBody(), true)['data'];
        self::assertSame(2, $plan['version']);
        self::assertNull($plan['appliedVersion']);
        self::assertContains(['part' => 'services', 'key' => 'cake-order', 'action' => 'add'], $plan['items']);
        self::assertSame(0, $this->rows('workspace_template_runs'), 'A preview makes no run');
        self::assertSame(0, $this->rows('workspace_template_parts'));
        self::assertSame([], $this->list('en')['chosen']);
        self::assertSame(404, $this->handler('en')->preview($request, ['key' => 'nowhere'])->getStatusCode());

        self::assertSame(200, $this->handler('en')->apply($request, ['key' => 'bakery'])->getStatusCode());
        $runs = new TemplateRuns($this->pdo);
        $run = $runs->find(self::BAKERY, 'bakery');
        self::assertNotNull($run);
        self::assertSame(['template:bakery' => 2], $runs->parts(self::BAKERY, $run['id']));

        $again = json_decode($this->handler('en')->preview($request, ['key' => 'bakery'])->getBody(), true)['data'];
        self::assertSame(2, $again['appliedVersion']);
        self::assertContains(['part' => 'services', 'key' => 'cake-order', 'action' => 'done'], $again['items'], 'Made by the last run: not made again');
    }

    public function testANewVersionIsOfferedItemByItemAndNeverAppliedOnItsOwn(): void
    {
        TenantContext::setTenantId(self::BAKERY);
        $setup = new FakeSetup();
        $setup->effective['billing.default_currency'] = 'USD';
        $offered = \Tests\Support\ExampleClinicTemplates::clinic(1, '20.00');
        $runs = new TemplateRuns($this->pdo);
        $handler = new WorkspaceTemplatesApiHandler(
            new TemplateCatalogue(null, static function () use (&$offered): array {
                return [$offered + ['plugin' => 'ClinicTemplate']];
            }),
            $runs,
            static fn (): TemplateApplier => new TemplateApplier($setup, $runs, static fn (): bool => true),
            null,
            null,
            static fn (): string => 'en',
        );
        $request = new Request('POST', '/api/v1/workspace/templates/clinic/apply', ['content-type' => 'application/json'], '{}');
        $request->user = (object) ['profile_id' => self::AMRO];

        self::assertSame(200, $handler->apply($request, ['key' => 'clinic'])->getStatusCode());
        $contactsMade = $setup->count('POST /api/contacts');
        self::assertSame(1, $contactsMade, 'Examples come with the first apply');
        $setup->services = [['id' => 10, 'name' => ['en' => 'Consultation', 'ar' => 'استشارة']]];
        $card = static fn (): array => json_decode($handler->list(new Request('GET', '/api/v1/workspace/templates'))->getBody(), true)['data']['templates'][0];
        self::assertNull($card()['update'], 'Nothing new yet');

        $offered = \Tests\Support\ExampleClinicTemplates::clinic(2, '25.00');
        self::assertSame([
            'from' => 1,
            'to' => 2,
            'parts' => [['key' => 'template:clinic', 'from' => 1, 'to' => 2]],
            'changes' => [['version' => 2, 'summary' => 'Visits and an intake form']],
        ], $card()['update']);

        $plan = json_decode($handler->preview($request, ['key' => 'clinic'])->getBody(), true)['data'];
        self::assertContains(['part' => 'services', 'key' => 'consultation', 'action' => 'changed'], $plan['items']);
        self::assertSame(1, $plan['appliedVersion']);

        self::assertSame(200, $handler->apply($request, ['key' => 'clinic'])->getStatusCode());
        self::assertSame($contactsMade, $setup->count('POST /api/contacts'), 'An update adds no examples unless asked');
        self::assertSame(0, $setup->count('PATCH /api/calendar/services/10'), 'Never changed on its own');
        self::assertNull($card()['update'], 'Applied: nothing new');

        $adopt = new Request('POST', '/api/v1/workspace/templates/clinic/items/services/consultation/adopt');
        $adopt->user = (object) ['profile_id' => self::AMRO];
        $answer = json_decode($handler->adoptItem($adopt, ['key' => 'clinic', 'part' => 'services', 'item' => 'consultation'])->getBody(), true)['data'];

        self::assertSame('add', $answer['action']);
        self::assertSame(2500, $setup->body('PATCH /api/calendar/services/10')['price_minor']);
        $run = $runs->find(self::BAKERY, 'clinic');
        self::assertNotNull($run);
        $item = $runs->items(self::BAKERY, $run['id'])['service:consultation'];
        self::assertSame(\Whity\Sdk\Templates\TemplateEngine::fingerprint($offered['services'][0]), $item['fingerprint']);
    }

    public function testAChosenTemplatesSetupJoinsTheOnboardingsAndTheDashboard(): void
    {
        TenantContext::setTenantId(self::BAKERY);
        $handler = $this->handler('en');
        self::assertSame([], $handler->onboardings(self::BAKERY), 'Nothing before a template is chosen');

        $request = new Request('POST', '/api/v1/workspace/templates/bakery/apply', ['content-type' => 'application/json'], '{"examples":false}');
        $request->user = (object) ['profile_id' => self::AMRO];
        self::assertSame(200, $handler->apply($request, ['key' => 'bakery'])->getStatusCode());

        $onboardings = $handler->onboardings(self::BAKERY);
        self::assertSame(['template-bakery'], array_column($onboardings, 'plugin'));
        self::assertSame('settings:write', $onboardings[0]['permission']);
        self::assertSame(['hours', 'menu', 'logo', 'offer'], array_column($onboardings[0]['steps'], 'id'), 'The offer of the installed paid plugin comes last');

        $points = $handler->dashboardSetup(self::BAKERY);
        self::assertSame(['hours', 'menu', 'offer'], array_column($points[0]['points'], 'id'), 'A screen step has nothing to say it is done, so it is not a point');
        self::assertSame('/api/v1/workspace/templates/bakery/setup/status', $points[0]['status']);
    }

    public function testTheOfferedTrialRunsForTheTemplatesLengthOnce(): void
    {
        TenantContext::setTenantId(self::BAKERY);
        $handler = $this->handler('en');
        $plugins = new WorkspaceCodePluginsApiHandler($this->loader, $this->activation, $this->pdo);
        $handler->useTrialStarter(static fn (int $tenantId, ?int $profileId, string $plugin, ?int $days): \Whity\Core\Response => $plugins->startTrialFor($tenantId, $profileId, $plugin, $days));
        $request = new Request('POST', '/api/v1/workspace/templates/bakery/apply', ['content-type' => 'application/json'], '{"examples":false}');
        $request->user = (object) ['profile_id' => self::AMRO];

        self::assertSame(404, $handler->startOfferTrial($request, ['key' => 'bakery'])->getStatusCode(), 'Only for a template the workspace chose');
        $handler->apply($request, ['key' => 'bakery']);

        $started = $handler->startOfferTrial($request, ['key' => 'bakery']);
        self::assertSame(200, $started->getStatusCode());
        $ends = json_decode($started->getBody(), true)['data']['trialEndsAt'];
        $days = (int) round((strtotime($ends . ' UTC') - time()) / 86400);
        self::assertSame(30, $days, 'The template\'s 30 days, not the plugin\'s 21');
        self::assertSame('on', $this->activation->stateFor('WsCounter', self::BAKERY)['state']);
        self::assertSame(409, $handler->startOfferTrial($request, ['key' => 'bakery'])->getStatusCode(), 'One trial per workspace');
    }

    public function testALockedCardIsOfferedUntilTheWorkspaceHasAccess(): void
    {
        TenantContext::setTenantId(self::BAKERY);
        $handler = $this->handler('en');
        self::assertSame([], $handler->lockedOffers(self::BAKERY), 'Nothing before a template is chosen');

        $request = new Request('POST', '/api/v1/workspace/templates/bakery/apply', ['content-type' => 'application/json'], '{"examples":false}');
        $request->user = (object) ['profile_id' => self::AMRO];
        $handler->apply($request, ['key' => 'bakery']);

        $offers = $handler->lockedOffers(self::BAKERY);
        self::assertCount(1, $offers);
        self::assertSame('offer:WsCounter', $offers[0]['key'], 'Its key in the dashboard layout');
        self::assertSame('bakery', $offers[0]['template'], 'Whose trial route starts it');
        self::assertSame(['en' => 'Whity for Counters', 'ar' => 'Whity للكاونترات'], $offers[0]['pluginName']);
        self::assertSame(30, $offers[0]['trialDays'], 'The template\'s 30 days');
        self::assertNotSame([], $offers[0]['lines']);
        self::assertLessThanOrEqual(WorkspaceTemplatesApiHandler::LOCKED_CARD_LINES, count($offers[0]['lines']));
        self::assertSame([], $handler->lockedOffers(0), 'Never for the system workspace');

        // Access by a plan: no card.
        $this->activation->useEntitlements(static fn (int $tenantId, string $entitlement): bool => $entitlement === 'ws_counter.access');
        $this->activation->resetRequest();
        self::assertSame([], $handler->lockedOffers(self::BAKERY), 'A workspace that already has it');
        $this->activation->useEntitlements(static fn (): bool => false);
        $this->activation->resetRequest();

        // Offered only to the operator: no card.
        $this->activation->setAccess('WsCounter', CodePluginActivation::OPERATOR);
        self::assertSame([], $handler->lockedOffers(self::BAKERY), 'Not offered to workspaces');
        $this->activation->setAccess('WsCounter', CodePluginActivation::CHOOSE);

        // The trial started: the card goes, for good.
        $this->activation->startTrial(self::BAKERY, 'WsCounter', self::AMRO, 30);
        $this->activation->resetRequest();
        self::assertSame([], $handler->lockedOffers(self::BAKERY), 'Once the trial starts');
    }

    public function testNoLockedCardWithoutAPaidPluginToOffer(): void
    {
        TenantContext::setTenantId(self::BAKERY);
        $handler = $this->handler('en');
        $request = new Request('POST', '/api/v1/workspace/templates/blank/apply', ['content-type' => 'application/json'], '{"examples":false}');
        $request->user = (object) ['profile_id' => self::AMRO];
        $handler->apply($request, ['key' => 'blank']);
        self::assertSame([], $handler->lockedOffers(self::BAKERY), 'Blank offers nothing');

        $request = new Request('POST', '/api/v1/workspace/templates/bakery/apply', ['content-type' => 'application/json'], '{"examples":false}');
        $request->user = (object) ['profile_id' => self::AMRO];
        $handler->apply($request, ['key' => 'bakery']);
        $this->activation->usePaidAccess(static fn (): array => []);
        self::assertSame([], $handler->lockedOffers(self::BAKERY), 'The offered plugin is not installed (or not paid) here');
    }

    public function testDisplayNamesFallBackToTheId(): void
    {
        $names = $this->loader->getDisplayNames();

        self::assertSame(['en' => 'Whity for Counters', 'ar' => 'Whity للكاونترات'], $names['WsCounter']);
        self::assertSame(['en' => 'WsBadName', 'ar' => 'WsBadName'], $names['WsBadName'], 'A declaration without Arabic is ignored');
        self::assertSame(['en' => 'WsTemplates', 'ar' => 'WsTemplates'], $names['WsTemplates'], 'None declared');
    }

    public function testTheOfferStepNamesThePluginByItsDisplayName(): void
    {
        TenantContext::setTenantId(self::BAKERY);
        $handler = $this->handler('en');
        $request = new Request('POST', '/api/v1/workspace/templates/bakery/apply', ['content-type' => 'application/json'], '{"examples":false}');
        $request->user = (object) ['profile_id' => self::AMRO];
        $handler->apply($request, ['key' => 'bakery']);

        $offer = json_encode(end($handler->onboardings(self::BAKERY)[0]['steps'])['blocks'], JSON_UNESCAPED_UNICODE);
        self::assertIsString($offer);
        self::assertStringContainsString('With Whity for Counters', $offer);
        self::assertStringContainsString('مع Whity للكاونترات', $offer);
        self::assertStringNotContainsString('WsCounter', $offer);
    }

    private function rows(string $table): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE tenant_id = ?');
        $stmt->execute([self::BAKERY]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array<string, mixed> */
    private function list(string $language): array
    {
        if (TenantContext::getTenantId() === null) {
            TenantContext::setTenantId(self::BAKERY);
        }
        $response = $this->handler($language)->list(new Request('GET', '/api/v1/workspace/templates'));
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        self::assertIsArray($body);

        return $body['data'];
    }

    /** @param (\Closure(int, int): bool)|null $mayTurnOnPlugins */
    private function handler(string $language, ?\Closure $mayTurnOnPlugins = null): WorkspaceTemplatesApiHandler
    {
        $runs = new TemplateRuns($this->pdo);

        $handler = new WorkspaceTemplatesApiHandler(
            new TemplateCatalogue($this->loader),
            $runs,
            static fn (): TemplateApplier => new TemplateApplier(new FakeSetup(), $runs, static fn (): bool => true),
            $this->activation,
            null,
            static fn (): string => $language,
            static fn (): TemplateSetup => new TemplateSetup(new FakeSetup()),
            $mayTurnOnPlugins,
        );
        $handler->useDisplayNames(fn (): array => $this->loader->getDisplayNames());
        $handler->useThemes(fn (int $tenantId, string $id): ?array => $this->themeInstalled && $id === 'templates.market'
            ? ['id' => $id, 'name' => ['en' => 'Market', 'ar' => 'السوق']]
            : null);

        return $handler;
    }
}
