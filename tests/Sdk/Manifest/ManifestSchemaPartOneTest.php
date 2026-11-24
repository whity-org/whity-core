<?php

declare(strict_types=1);

namespace Tests\Sdk\Manifest;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Whity\Core\Plugin\PluginManifestCheck;
use Whity\Core\Settings\InvalidSettingDeclarationException;
use Whity\Core\Settings\PluginSettingsRegistry;
use Whity\Sdk\JobInterface;
use Whity\Sdk\Manifest\InvalidManifestException;
use Whity\Sdk\Manifest\ManifestPlugin;
use Whity\Sdk\Manifest\ManifestValidator;
use Whity\Sdk\Manifest\SettingsValidator;

/**
 * `plugin.json` schema part 1 (SDK 1.163, decision D4): settings,
 * notifications, jobs, dashboard, onboarding, contact fields and themes are
 * declared in the manifest, checked by ManifestValidator, answered by
 * ManifestPlugin in the shape each PHP interface returns, and checked offline
 * by `whity-cli plugin validate`.
 */
final class ManifestSchemaPartOneTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/whity_manifest_p1_' . uniqid();
        mkdir($this->dir . '/themes', 0755, true);
        $theme = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/src/Core/Theme/themes/whity.harbour.json'), true);
        self::assertIsArray($theme);
        $theme['id'] = 'cake-orders.harbour';
        file_put_contents($this->dir . '/themes/harbour.json', json_encode($theme, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    protected function tearDown(): void
    {
        foreach (['/themes/harbour.json', '/themes/broken.json', '/plugin.json'] as $file) {
            if (is_file($this->dir . $file)) {
                unlink($this->dir . $file);
            }
        }
        rmdir($this->dir . '/themes');
        rmdir($this->dir);
    }

    /** @return array<string, mixed> */
    public static function manifest(): array
    {
        return ManifestValidatorTest::bakeryManifest() + [
            'settings' => [
                'lead_days' => ['type' => 'int', 'default' => 2, 'min' => 0, 'max' => 30, 'label' => ['en' => 'Days of notice', 'ar' => 'أيام الإشعار'], 'admin' => true],
                'mode' => ['type' => 'enum', 'options' => ['off', 'live'], 'default' => 'off'],
                'note' => ['type' => 'string', 'default' => '', 'max_length' => 200],
                'deliveries' => ['type' => 'bool', 'default' => false],
            ],
            'notifications' => [
                'order_ready' => ['label' => ['en' => 'Cake order ready', 'ar' => 'طلب الكيك جاهز'], 'emailDefault' => true],
            ],
            'jobs' => [
                'orders.remind' => ['class' => ManifestRemindJob::class, 'schedule' => '0 7 * * *'],
                'orders.export' => ['class' => ManifestRemindJob::class, 'submittable' => true],
            ],
            'dashboard' => ['widgets' => [[
                'id' => 'todays-orders',
                'kind' => 'list',
                'title' => ['en' => "Today's orders", 'ar' => 'طلبات اليوم'],
                'permission' => 'cake_orders:read',
                'source' => '/api/cake-orders/today',
                'blocks' => [['type' => 'dataList', 'source' => '/api/cake-orders/today', 'itemField' => 'line']],
            ]]],
            'onboarding' => [
                'title' => ['en' => 'Set up cake orders', 'ar' => 'إعداد طلبات الكيك'],
                'status' => '/api/cake-orders/setup',
                'permission' => 'cake_orders:manage',
                'steps' => [[
                    'id' => 'flavours',
                    'title' => ['en' => 'Add your flavours', 'ar' => 'أضف النكهات'],
                    'blocks' => [['type' => 'heading', 'text' => 'Flavours', 'level' => 2]],
                ]],
            ],
            'contactFields' => [
                ['key' => 'favourite_cake', 'label' => ['en' => 'Favourite cake', 'ar' => 'الكيكة المفضلة'], 'type' => 'text'],
            ],
            'themes' => ['themes/harbour.json'],
        ];
    }

    public function testAFullDeclarationIsValid(): void
    {
        self::assertSame(['ok' => true, 'errors' => []], ManifestValidator::validate(self::manifest()));
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function broken(): array
    {
        $m = self::manifest();

        return [
            'enum without options' => [['settings' => ['mode' => ['type' => 'enum', 'default' => 'off']]] + $m, 'settings.mode.options'],
            'default outside its bounds' => [['settings' => ['lead_days' => ['type' => 'int', 'default' => 40, 'max' => 30]]] + $m, 'settings.lead_days.default: must be at most 30'],
            'a secret as a setting' => [['settings' => ['api_key' => ['type' => 'string', 'default' => '', 'secret' => true]]] + $m, 'settings.api_key.secret'],
            'pattern with delimiters' => [['settings' => ['code' => ['type' => 'string', 'default' => 'a', 'pattern' => '/^a$/']]] + $m, 'settings.code.pattern'],
            'label in English only' => [['notifications' => ['order_ready' => ['label' => ['en' => 'Ready']]]] + $m, 'notifications.order_ready.label.ar'],
            'job without a class' => [['jobs' => ['orders.remind' => ['schedule' => '0 7 * * *']]] + $m, 'jobs.orders.remind.class'],
            'cron with four fields' => [['jobs' => ['orders.remind' => ['class' => 'Acme\\Remind', 'schedule' => '0 7 * *']]] + $m, 'jobs.orders.remind.schedule'],
            'cron hour 24' => [['jobs' => ['orders.remind' => ['class' => 'Acme\\Remind', 'schedule' => '0 24 * * *']]] + $m, 'jobs.orders.remind.schedule'],
            'a job name with a colon' => [['jobs' => ['cake:remind' => ['class' => 'Acme\\Remind']]] + $m, 'jobs.cake:remind'],
            'onboarding with a permission it lacks' => [['onboarding' => ['permission' => 'cake_orders:delete'] + $m['onboarding']] + $m, 'onboarding.permission'],
            'onboarding without steps' => [['onboarding' => ['steps' => []] + $m['onboarding']] + $m, 'onboarding.steps'],
            'a widget of an unknown kind' => [['dashboard' => ['widgets' => [['kind' => 'pie'] + $m['dashboard']['widgets'][0]]]] + $m, 'dashboard.widgets[0]'],
            'a contact field type that does not exist' => [['contactFields' => [['key' => 'x', 'label' => ['en' => 'X', 'ar' => 'X'], 'type' => 'colour']]] + $m, 'contactFields[0]'],
            'a theme outside the plugin' => [['themes' => ['../themes/harbour.json']] + $m, 'themes[0]'],
            'a theme that is not JSON' => [['themes' => ['themes/harbour.css']] + $m, 'themes[0]'],
        ];
    }

    /** @param array<string, mixed> $manifest */
    #[DataProvider('broken')]
    public function testEachPartRefusesWhatTheHostWouldRefuse(array $manifest, string $path): void
    {
        $result = ManifestValidator::validate($manifest);

        self::assertFalse($result['ok']);
        self::assertNotEmpty(array_filter($result['errors'], static fn (string $e): bool => str_starts_with($e, $path)), "an error at {$path}: " . implode(' | ', $result['errors']));
    }

    public function testAManifestPluginAnswersEachPartInTheInterfacesShape(): void
    {
        $plugin = $this->pluginFrom(self::manifest());

        self::assertSame(self::manifest()['settings'], $plugin->getSettings());
        self::assertSame(self::manifest()['notifications'], $plugin->getNotificationTypes());
        self::assertSame(['orders.remind', 'orders.export'], array_keys($plugin->getJobs()));
        self::assertSame(['reminded' => true], $plugin->getJobs()['orders.remind']->handle([]));
        self::assertSame($plugin->getJobs(), $plugin->getJobs(), 'handlers are built once');
        self::assertSame(['orders.export'], $plugin->getSubmittableJobs());
        self::assertSame(['orders.remind' => '0 7 * * *'], $plugin->getScheduledJobs());
        self::assertSame(self::manifest()['dashboard'], $plugin->getDashboard());
        self::assertSame(self::manifest()['onboarding'], $plugin->getOnboarding());
        self::assertSame(self::manifest()['contactFields'], $plugin->getContactFields());
        self::assertSame('cake-orders.harbour', $plugin->getThemes()[0]['id'] ?? null);
    }

    public function testAManifestWithoutThePartsAnswersNothing(): void
    {
        $plugin = $this->pluginFrom(ManifestValidatorTest::bakeryManifest());

        self::assertSame([], $plugin->getSettings());
        self::assertSame([], $plugin->getJobs());
        self::assertSame([], $plugin->getScheduledJobs());
        self::assertSame([], $plugin->getDashboard());
        self::assertSame([], $plugin->getOnboarding());
        self::assertSame([], $plugin->getThemes());
    }

    public function testAJobClassThatIsNotAJobIsRefusedWhole(): void
    {
        $plugin = $this->pluginFrom(['jobs' => ['orders.remind' => ['class' => self::class]]] + self::manifest());

        $this->expectException(InvalidManifestException::class);
        $this->expectExceptionMessage('jobs.orders.remind.class');
        $plugin->getJobs();
    }

    /**
     * The SDK's settings check and the host's registry agree, declaration by
     * declaration: what one refuses, so does the other.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function settingDeclarations(): array
    {
        return [
            'int in bounds' => [['type' => 'int', 'default' => 5, 'min' => 1, 'max' => 9]],
            'int below min' => [['type' => 'int', 'default' => 0, 'min' => 1]],
            'min above max' => [['type' => 'int', 'default' => 5, 'min' => 9, 'max' => 1]],
            'enum' => [['type' => 'enum', 'options' => ['a', 'b'], 'default' => 'a']],
            'enum default not an option' => [['type' => 'enum', 'options' => ['a'], 'default' => 'z']],
            'options on a string' => [['type' => 'string', 'default' => '', 'options' => ['a']]],
            'string too long' => [['type' => 'string', 'default' => 'abcdef', 'max_length' => 3]],
            'string pattern' => [['type' => 'string', 'default' => 'abc', 'pattern' => '^[a-z]+$']],
            'string not matching' => [['type' => 'string', 'default' => 'ABC', 'pattern' => '^[a-z]+$']],
            'broken pattern' => [['type' => 'string', 'default' => 'a', 'pattern' => '^[a-z+$']],
            'bool' => [['type' => 'bool', 'default' => true]],
            'bool from a string' => [['type' => 'bool', 'default' => 'yes']],
            'no default' => [['type' => 'string']],
            'float default' => [['type' => 'int', 'default' => 1.5]],
            'unknown type' => [['type' => 'colour', 'default' => '']],
            'unknown field' => [['type' => 'string', 'default' => '', 'maxlength' => 3]],
            'encrypted' => [['type' => 'string', 'default' => '', 'encrypted' => true]],
            'admin as a string' => [['type' => 'string', 'default' => '', 'admin' => 'yes']],
        ];
    }

    /** @param array<string, mixed> $declaration */
    #[DataProvider('settingDeclarations')]
    public function testTheSdkAndTheHostAgreeOnASetting(array $declaration): void
    {
        $sdkAccepts = SettingsValidator::settingErrors('example', $declaration, 'cake_orders', 'settings.example') === [];
        try {
            (new PluginSettingsRegistry())->register('CakeOrders', ['example' => $declaration]);
            $hostAccepts = true;
        } catch (InvalidSettingDeclarationException) {
            $hostAccepts = false;
        }

        self::assertSame($hostAccepts, $sdkAccepts);
    }

    public function testTheCliCheckPassesAValidPluginAndNamesEachProblem(): void
    {
        file_put_contents($this->dir . '/plugin.json', json_encode(self::manifest(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $ok = PluginManifestCheck::run($this->dir);
        self::assertSame([], $ok['errors']);
        self::assertTrue($ok['ok']);
        self::assertSame('CakeOrders', $ok['name']);

        file_put_contents($this->dir . '/themes/broken.json', json_encode(['id' => 'whity.stolen'], JSON_THROW_ON_ERROR));
        $bad = ['themes' => ['themes/harbour.json', 'themes/broken.json', 'themes/missing.json']] + self::manifest();
        unset($bad['settings']['mode']['options']);
        file_put_contents($this->dir . '/plugin.json', json_encode($bad, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $result = PluginManifestCheck::run($this->dir . '/plugin.json');

        self::assertFalse($result['ok']);
        $joined = implode("\n", $result['errors']);
        self::assertStringContainsString('settings.mode.options', $joined);
        self::assertStringContainsString('themes[1] (themes/broken.json)', $joined);
        self::assertStringContainsString("themes[2]: themes/missing.json doesn't exist", $joined);
        self::assertStringNotContainsString('themes[0]', $joined);
    }

    public function testTheCliCheckSaysWhenThereIsNoManifest(): void
    {
        $result = PluginManifestCheck::run($this->dir);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('There is no plugin.json', $result['errors'][0] ?? '');
    }

    /** @param array<string, mixed> $manifest */
    private function pluginFrom(array $manifest): ManifestPlugin
    {
        file_put_contents($this->dir . '/plugin.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $dir = $this->dir;

        return new class ($dir) extends ManifestPlugin {
            public function __construct(private readonly string $base)
            {
                parent::__construct();
            }

            protected function manifestPath(): string
            {
                return $this->base . '/plugin.json';
            }
        };
    }
}

/** A job a manifest names by class: no constructor arguments. */
final class ManifestRemindJob implements JobInterface
{
    public function handle(array $payload): array
    {
        return ['reminded' => true];
    }
}
