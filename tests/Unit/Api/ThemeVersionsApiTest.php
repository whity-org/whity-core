<?php

declare(strict_types=1);

namespace Whity\Tests\Unit\Api;

use Database\Migrations\CreateThemeVersions;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Whity\Api\ThemesApiHandler;
use Whity\Core\Request;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Tenant\TenantContext;
use Whity\Core\Theme\ThemeRepository;
use Whity\Database\Database;
use Tests\Support\SchemaFromMigrations;

/**
 * Theme versions (TH.25, 30-themes 11): every save keeps its version, the
 * history lists them newest first with who and how, any version can be read
 * for the side-by-side diff, and restore brings one back as the next version.
 * Every migration's schema (SchemaFromMigrations); 349's up() runs again,
 * twice, over a theme saved before it, as an upgrade does.
 */
final class ThemeVersionsApiTest extends TestCase
{
    private const TENANT = 7;

    /** The schema of every migration, built once for the class (SchemaFromMigrations). */
    private static ?PDO $schema = null;

    private PDO $pdo;
    private ThemeRepository $repository;
    private ThemesApiHandler $handler;

    protected function setUp(): void
    {
        self::$schema ??= SchemaFromMigrations::make();
        $this->pdo = self::$schema;
        $this->pdo->exec('DELETE FROM theme_versions');
        $this->pdo->exec('DELETE FROM themes');
        $this->pdo->exec('DELETE FROM profiles WHERE id IN (901, 902)');
        $this->pdo->exec("INSERT INTO profiles (id, display_name, password_hash) VALUES (901, 'Amro Saleh', ''), (902, '', '')");
        // A theme saved before versions existed: the migration makes it the first version.
        $insert = $this->pdo->prepare("INSERT INTO themes (tenant_id, theme_id, version, source, document, created_by_profile_id) VALUES (7, 'workspace.sea', '1.0.0', 'import', ?, 901)");
        $insert->execute([json_encode(self::theme('1.0.0', 'soft'))]);
        SchemaFromMigrations::loadMigrationClasses();
        $database = Database::withFactory(fn (): PDO => $this->pdo);
        CreateThemeVersions::up($database);
        CreateThemeVersions::up($database);

        $this->repository = new ThemeRepository($this->pdo);
        $settings = (new ReflectionClass(SettingsService::class))->newInstanceWithoutConstructor();
        $this->handler = new ThemesApiHandler($this->repository, $settings);
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);
    }

    private function versionRows(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM theme_versions');
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    /** @return array<string, mixed> */
    private static function theme(string $version, string $shape): array
    {
        return ['schemaVersion' => 1, 'id' => 'workspace.sea', 'version' => $version, 'name' => ['en' => 'Sea'], 'shape' => ['preset' => $shape]];
    }

    /**
     * @param array<string, mixed> $params
     * @return array{0: int, 1: mixed}
     */
    private function call(string $method, array $params = []): array
    {
        $response = $this->handler->{$method}(new Request('GET', '/api/themes', []), $params);

        return [$response->getStatusCode(), json_decode($response->getBody(), true)];
    }

    public function testTheMigrationMadeTodaysThemeItsFirstVersionOnce(): void
    {
        self::assertSame(1, $this->versionRows());
        [$status, $body] = $this->call('versions', ['themeId' => 'workspace.sea']);
        self::assertSame(200, $status);
        self::assertSame([['version' => '1.0.0', 'source' => 'import', 'saved_at' => $body['data']['versions'][0]['saved_at'], 'saved_by' => 'Amro Saleh', 'current' => true]], $body['data']['versions']);
    }

    public function testEverySaveKeepsItsVersionNewestFirst(): void
    {
        $this->repository->save(self::TENANT, self::theme('1.0.1', 'round'), 'editor', false, 902);
        $this->repository->save(self::TENANT, self::theme('1.0.10', 'crisp'), 'editor', false, 901);
        // The same number again replaces that version, it doesn't add one.
        $this->repository->save(self::TENANT, self::theme('1.0.10', 'cut'), 'editor', false, 901);

        [, $body] = $this->call('versions', ['themeId' => 'workspace.sea']);
        self::assertSame('1.0.10', $body['data']['current']);
        self::assertSame(['1.0.10', '1.0.1', '1.0.0'], array_column($body['data']['versions'], 'version'));
        self::assertSame([true, false, false], array_column($body['data']['versions'], 'current'));
        self::assertSame(['Amro Saleh', null, 'Amro Saleh'], array_column($body['data']['versions'], 'saved_by'), 'A name, never an id; none when the profile has none');

        [$status, $one] = $this->call('version', ['themeId' => 'workspace.sea', 'version' => '1.0.10']);
        self::assertSame(200, $status);
        self::assertSame('cut', $one['data']['theme']['shape']['preset']);
    }

    public function testRestoreBringsAVersionBackAsTheNextOne(): void
    {
        $this->repository->save(self::TENANT, self::theme('1.1.0', 'round'), 'editor', false, 901);
        $response = $this->handler->restore(new Request('POST', '/api/themes/workspace.sea/versions/1.0.0/restore', []), ['themeId' => 'workspace.sea', 'version' => '1.0.0']);
        self::assertSame(201, $response->getStatusCode());
        self::assertSame(['id' => 'workspace.sea', 'version' => '1.1.1', 'restored_from' => '1.0.0'], json_decode($response->getBody(), true)['data']);

        [, $body] = $this->call('versions', ['themeId' => 'workspace.sea']);
        self::assertSame('1.1.1', $body['data']['current']);
        self::assertSame(['1.1.1', '1.1.0', '1.0.0'], array_column($body['data']['versions'], 'version'), 'Nothing is lost');
        self::assertSame('restore', $body['data']['versions'][0]['source']);
        [, $theme] = $this->call('show', ['themeId' => 'workspace.sea']);
        self::assertSame('soft', $theme['data']['theme']['shape']['preset']);
        self::assertTrue($theme['data']['editable']);
    }

    public function testOnlyTheWorkspacesOwnThemesAndRealVersions(): void
    {
        self::assertSame(404, $this->call('versions', ['themeId' => 'whity.harbour'])[0]);
        self::assertSame(404, $this->call('version', ['themeId' => 'workspace.sea', 'version' => '9.9.9'])[0]);
        self::assertSame(404, $this->call('version', ['themeId' => 'workspace.sea', 'version' => '../x'])[0]);
        self::assertSame(404, $this->call('restore', ['themeId' => 'workspace.sea', 'version' => '2.0.0'])[0]);
        // Another workspace sees nothing.
        TenantContext::reset();
        TenantContext::setTenantId(8);
        self::assertSame(404, $this->call('versions', ['themeId' => 'workspace.sea'])[0]);
    }

    public function testRemovingAThemeRemovesItsHistory(): void
    {
        self::assertTrue($this->repository->delete(self::TENANT, 'workspace.sea'));
        self::assertSame(0, $this->versionRows());
    }
}
