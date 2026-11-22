<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Navigation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Branding\BrandingService;
use Whity\Core\Http\FrontendUrls;
use Whity\Core\Navigation\HomeLinks;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;

/**
 * #2386: the instance's home page and its way back to the product beside it.
 */
final class HomeLinksTest extends TestCase
{
    private SettingsService $settings;

    protected function setUp(): void
    {
        $pdo = SchemaFromMigrations::make(true);
        $pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 't1')");
        $this->settings = new SettingsService(new GlobalSettingsRepository($pdo), new TenantSettingsRepository($pdo));
    }

    protected function tearDown(): void
    {
        foreach ([HomeLinks::HOME_ENV, HomeLinks::BACK_URL_ENV, HomeLinks::BACK_LABEL_ENV] as $name) {
            unset($_ENV[$name]);
            putenv($name);
        }
    }

    /** @return iterable<string, array{string, bool}> */
    public static function paths(): iterable
    {
        yield 'dashboard' => ['/dashboard', true];
        yield 'plugin screen with query' => ['/admin/x/forms-catalog?tab=open', true];
        yield 'protocol-relative' => ['//evil.example/x', false];
        yield 'backslash host' => ['/\\evil.example', false];
        yield 'absolute url' => ['https://evil.example', false];
        yield 'relative' => ['dashboard', false];
        yield 'space' => ['/a b', false];
        yield 'newline' => ["/a\nb", false];
    }

    #[DataProvider('paths')]
    public function testOnlyAPathOnThisSiteIsAHome(string $path, bool $ok): void
    {
        self::assertSame($ok, HomeLinks::isLocalPath($path));
        self::assertSame($ok, HomeLinks::validateHomePath($path) === null);
    }

    public function testHomeIsTheSettingThenTheEnvironmentThenTheDashboard(): void
    {
        self::assertSame('/dashboard', HomeLinks::homePath($this->settings));

        $_ENV[HomeLinks::HOME_ENV] = '/tasks';
        self::assertSame('/tasks', HomeLinks::homePath($this->settings));

        $this->settings->setGlobal(SettingsRegistry::NAVIGATION_HOME_PATH, '/admin/x/forms-catalog');
        self::assertSame('/admin/x/forms-catalog', HomeLinks::homePath($this->settings));
    }

    public function testAnEnvironmentHomeOnAnotherSiteIsIgnored(): void
    {
        $_ENV[HomeLinks::HOME_ENV] = 'https://evil.example';

        self::assertSame('/dashboard', HomeLinks::homePath(null));
    }

    public function testTheBackLinkIsOffUntilItHasAnAddress(): void
    {
        self::assertNull(HomeLinks::backToProduct($this->settings));

        $_ENV[HomeLinks::BACK_URL_ENV] = '/';
        self::assertSame(['url' => '/', 'label' => 'Back'], HomeLinks::backToProduct($this->settings));

        $this->settings->setGlobal(SettingsRegistry::NAVIGATION_BACK_TO_PRODUCT_URL, 'https://bakery.example/courses');
        $this->settings->setGlobal(SettingsRegistry::NAVIGATION_BACK_TO_PRODUCT_LABEL, 'Back to Example Bakery');
        self::assertSame(
            ['url' => 'https://bakery.example/courses', 'label' => 'Back to Example Bakery'],
            HomeLinks::backToProduct($this->settings)
        );
    }

    public function testAnUnsafeBackLinkFromTheEnvironmentIsDropped(): void
    {
        $_ENV[HomeLinks::BACK_URL_ENV] = 'javascript:alert(1)';

        self::assertNull(HomeLinks::backToProduct(null));
    }

    public function testBrandingCarriesBoth(): void
    {
        $this->settings->setGlobal(SettingsRegistry::NAVIGATION_HOME_PATH, '/tasks');
        $this->settings->setGlobal(SettingsRegistry::NAVIGATION_BACK_TO_PRODUCT_URL, '/');

        $branding = (new BrandingService($this->settings))->effective(1)->toArray();

        self::assertSame('/tasks', $branding['homePath']);
        self::assertSame(['url' => '/', 'label' => 'Back'], $branding['backToProduct']);
    }

    public function testSignInAndHomeLinksFollowTheHomePath(): void
    {
        $urls = new FrontendUrls(['APP_URL' => 'https://app.example']);
        self::assertSame('https://app.example/dashboard', $urls->link(FrontendUrls::SSO_SUCCESS));
        self::assertSame('https://app.example/dashboard', $urls->link(FrontendUrls::HOME));

        $home = $urls->withHomePath('/admin/x/forms-catalog');
        self::assertSame('https://app.example/admin/x/forms-catalog', $home->link(FrontendUrls::SSO_SUCCESS));
        self::assertSame('https://app.example/admin/x/forms-catalog', $home->link(FrontendUrls::HOME));
        self::assertSame('/admin/x/forms-catalog', (new FrontendUrls([]))->withHomePath('/admin/x/forms-catalog')->linkOrPath(FrontendUrls::SSO_SUCCESS));
        // Other links keep their own paths.
        self::assertSame('https://app.example/login', $home->link(FrontendUrls::SSO_LOGIN));
        // An explicit SSO_SUCCESS_URL still wins.
        $explicit = (new FrontendUrls(['APP_URL' => 'https://app.example', 'SSO_SUCCESS_URL' => 'https://app.example/start']))->withHomePath('/tasks');
        self::assertSame('https://app.example/start', $explicit->link(FrontendUrls::SSO_SUCCESS));
        // A path that is not on this site is ignored.
        self::assertSame('https://app.example/dashboard', $urls->withHomePath('//evil.example')->link(FrontendUrls::HOME));
        // Without settings, WHITY_HOME_PATH decides.
        self::assertSame('https://app.example/tasks', (new FrontendUrls(['APP_URL' => 'https://app.example', HomeLinks::HOME_ENV => '/tasks']))->link(FrontendUrls::HOME));
    }
}
