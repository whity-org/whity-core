<?php
declare(strict_types=1);
namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\BrandingApiHandler;
use Whity\Auth\JwtParser;
use Whity\Core\Branding\BrandingAssetValidator;
use Whity\Core\Branding\BrandingService;
use Whity\Core\Branding\HostResolver;
use Whity\Core\Branding\SvgSanitizer;
use Whity\Core\Branding\TenantHostRepository;
use Whity\Core\Request;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\TenantContext;
use Whity\Storage\LocalStorageDriver;

/**
 * Real-engine integration tests for GET /api/v1/branding (Task 3.1).
 *
 * Drives the REAL BrandingApiHandler + BrandingService + repositories against
 * the full migration-built schema on in-memory SQLite. Proves:
 *  - Tenant resolves by exact custom branding_host
 *  - Tenant resolves by slug subdomain of the configured base domain
 *  - Unknown host falls back to global
 *  - Authenticated TenantContext overrides host-based resolution
 *  - Only branding fields are exposed (support_email and other settings never leak)
 *  - A signed-in caller's token names the tenant even though the public route
 *    runs without TenantContext, and the answer is never cached (#1716)
 */
final class BrandingApiRealEngineTest extends TestCase
{
    private PDO $pdo;
    private const JWT_SECRET = 'branding-test-secret-0123456789abcdef';

    private BrandingApiHandler $handler;
    private BrandingService $branding;
    private HostResolver $hostResolver;
    private SettingsService $settings;
    private string $root;

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->root = sys_get_temp_dir() . '/branding-http-' . bin2hex(random_bytes(6));
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (0, 'system')");
        $this->pdo->exec("INSERT INTO tenants (id, name, slug, branding_host) VALUES (1, 'Acme', 'acme', 'app.acme.com')");
        $settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        $settings->setGlobal('site_name', 'Platform');
        $settings->setTenant(1, 'site_name', 'Acme');
        $settings->setTenant(1, 'support_email', 'secret@acme.com'); // must NOT leak
        $this->settings = $settings;
        $branding = new BrandingService($settings, new LocalStorageDriver($this->root), new BrandingAssetValidator(new SvgSanitizer()));
        $hostResolver = new HostResolver(new TenantHostRepository($this->pdo), 'whity.app');
        $this->branding = $branding;
        $this->hostResolver = $hostResolver;
        $this->handler = new BrandingApiHandler($branding, $hostResolver, null);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    /** @param array<string, string> $headers */
    private function req(string $path, array $headers = []): Request
    {
        return new Request('GET', $path, $headers, '');
    }

    public function testResolvesByCustomHostAndOmitsOtherSettings(): void
    {
        $res = $this->handler->get($this->req('/api/v1/branding', ['Host' => 'app.acme.com']));
        self::assertSame(200, $res->getStatusCode());
        $body = json_decode($res->getBody(), true);
        self::assertSame('Acme', $body['data']['siteName']);
        self::assertSame(['siteName', 'logoWideUrl', 'logoSquareUrl', 'faviconUrl', 'mainColor', 'theme', 'installable', 'builtOnWhity'], array_keys($body['data']));
        self::assertStringNotContainsString('secret@acme.com', $res->getBody());
    }

    public function testResolvesBySlugSubdomain(): void
    {
        $res = $this->handler->get($this->req('/api/v1/branding', ['Host' => 'acme.whity.app']));
        self::assertSame(200, $res->getStatusCode());
        self::assertSame('Acme', json_decode($res->getBody(), true)['data']['siteName']);
    }

    public function testUnknownHostFallsBackToGlobal(): void
    {
        $res = $this->handler->get($this->req('/api/v1/branding', ['Host' => 'whity.app']));
        self::assertSame(200, $res->getStatusCode());
        self::assertSame('Platform', json_decode($res->getBody(), true)['data']['siteName']);
    }

    public function testAuthenticatedTenantWins(): void
    {
        TenantContext::setTenantId(1);
        // Host says global, but JWT context overrides to tenant 1
        $res = $this->handler->get($this->req('/api/v1/branding', ['Host' => 'whity.app']));
        self::assertSame(200, $res->getStatusCode());
        self::assertSame('Acme', json_decode($res->getBody(), true)['data']['siteName']);
    }

    public function testResponseIsNeverCached(): void
    {
        // #1716: the answer depends on who asks, so a cached copy (the browser
        // kept one for 60s) showed the old colour after a save, and a shared
        // cache could hand one tenant's branding to another's reader.
        $res = $this->handler->get($this->req('/api/v1/branding', ['Host' => 'whity.app']));
        self::assertSame(200, $res->getStatusCode());
        $headers = $res->getHeaders();
        self::assertSame('no-store', $headers['cache-control'] ?? null);
        self::assertStringNotContainsString('public', $headers['cache-control']);
        self::assertStringContainsString('Cookie', $headers['vary'] ?? '');
    }

    public function testSignedInCallersTokenNamesTheTenantWithoutContext(): void
    {
        // #1716: /api/v1/branding is on the tenant-isolation public list, so
        // TenantContext is empty even for a signed-in admin. A shared host
        // (here the bare base domain) resolves to the global layer; the token
        // in the access_token cookie must win, or the workspace's saved main
        // colour is never returned and the app keeps the global one.
        $this->settings->setGlobal('branding_main_color', '#b74c05');
        $this->settings->setTenant(1, 'branding_main_color', '#0961BB');

        $res = $this->tokenAware()->get($this->req('/api/v1/branding', [
            'Host' => 'whity.app',
            'Cookie' => 'theme=dark; access_token=' . $this->tokenFor(1),
        ]));

        self::assertSame(200, $res->getStatusCode());
        $data = json_decode($res->getBody(), true)['data'];
        self::assertSame('Acme', $data['siteName']);
        self::assertSame('#0961bb', $data['mainColor']);
        self::assertSame('#0961bb', $data['theme']['light']['primary']);
        self::assertArrayHasKey('primary', $data['theme']['dark']);
        self::assertNotSame('#0961bb', $data['theme']['dark']['primary']);
        self::assertNull(TenantContext::getTenantId(), 'a public request must not pin the tenant context');
    }

    public function testBearerTokenAlsoNamesTheTenant(): void
    {
        $this->settings->setTenant(1, 'branding_main_color', '#00792f');

        $res = $this->tokenAware()->get($this->req('/api/v1/branding', [
            'Host' => 'whity.app',
            'Authorization' => 'Bearer ' . $this->tokenFor(1),
        ]));

        self::assertSame('#00792f', json_decode($res->getBody(), true)['data']['mainColor']);
    }

    public function testBadTokenFallsBackToTheHost(): void
    {
        $this->settings->setGlobal('branding_main_color', '#b74c05');
        $this->settings->setTenant(1, 'branding_main_color', '#00792f');
        $forged = (new JwtParser('some-other-secret-0123456789abcdef'))->create([
            'profile_id' => 1,
            'active_tenant_id' => 1,
        ]);

        $res = $this->tokenAware()->get($this->req('/api/v1/branding', [
            'Host' => 'whity.app',
            'Cookie' => 'access_token=' . $forged,
        ]));

        $data = json_decode($res->getBody(), true)['data'];
        self::assertSame('Platform', $data['siteName']);
        self::assertSame('#b74c05', $data['mainColor']);
    }

    public function testAnonymousReaderStillResolvesByHost(): void
    {
        $this->settings->setTenant(1, 'branding_main_color', '#00792f');

        $res = $this->tokenAware()->get($this->req('/api/v1/branding', ['Host' => 'app.acme.com']));

        self::assertSame('#00792f', json_decode($res->getBody(), true)['data']['mainColor']);
    }

    private function tokenAware(): BrandingApiHandler
    {
        return new BrandingApiHandler($this->branding, $this->hostResolver, null, null, null, new JwtParser(self::JWT_SECRET));
    }

    private function tokenFor(int $tenantId): string
    {
        return (new JwtParser(self::JWT_SECRET))->create([
            'profile_id' => 1,
            'active_tenant_id' => $tenantId,
        ]);
    }

    public function testNullUrlsWhenNoAssetsSet(): void
    {
        $res = $this->handler->get($this->req('/api/v1/branding', ['Host' => 'app.acme.com']));
        $data = json_decode($res->getBody(), true)['data'];
        self::assertNull($data['logoWideUrl']);
        self::assertNull($data['logoSquareUrl']);
        self::assertNull($data['faviconUrl']);
    }
}
