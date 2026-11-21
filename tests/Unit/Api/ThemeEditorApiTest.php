<?php

declare(strict_types=1);

namespace Whity\Tests\Unit\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Whity\Api\ThemesApiHandler;
use Whity\Core\Request;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Tenant\TenantContext;
use Whity\Core\Theme\ThemeCompiler;
use Whity\Core\Theme\ThemeRepository;

/**
 * TH.25 (30-themes 11): the live editor's preview, opening a theme to edit,
 * and saving what the editor made. The themes table is SQLite in memory with
 * the columns migration 323 creates.
 */
final class ThemeEditorApiTest extends TestCase
{
    private const TENANT = 7;

    private PDO $db;
    private ThemesApiHandler $handler;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE themes (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, theme_id VARCHAR(100) NOT NULL,
            version VARCHAR(32) NOT NULL, source VARCHAR(16) NOT NULL DEFAULT \'import\', document TEXT NOT NULL,
            signature_verified BOOLEAN NOT NULL DEFAULT 0, created_by_profile_id INTEGER NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (tenant_id, theme_id))');
        // Preview, show and import read no settings.
        $settings = (new ReflectionClass(SettingsService::class))->newInstanceWithoutConstructor();
        $this->handler = new ThemesApiHandler(new ThemeRepository($this->db), $settings);
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function theme(array $extra = []): array
    {
        return array_merge(['schemaVersion' => 1, 'id' => 'workspace.sea', 'version' => '1.0.0', 'name' => ['en' => 'Sea']], $extra);
    }

    /** @return array{0: int, 1: mixed} */
    private static function answer(\Whity\Sdk\Http\Response $response): array
    {
        return [$response->getStatusCode(), json_decode($response->getBody(), true)];
    }

    public function testThePreviewHasEveryColourInBothModesAndTheValues(): void
    {
        [$status, $body] = self::answer($this->handler->preview(new Request('POST', '/api/themes/preview', [], (string) json_encode(self::theme([
            'colour' => ['seeds' => ['primary' => '#0b6e74']],
            'shape' => ['preset' => 'round'],
        ])))));

        self::assertSame(200, $status);
        $data = $body['data'];
        self::assertTrue($data['valid']);
        // Whole sets, so dark shows as dark whatever mode the page is in.
        self::assertGreaterThanOrEqual(count(ThemeCompiler::base('light')), count($data['light']));
        self::assertArrayHasKey('background', $data['dark']);
        self::assertSame('20px', $data['vars']['wy-radius-control']);
        self::assertContains('primary', $data['roles']);
    }

    public function testADraftWithProblemsListsThemAll(): void
    {
        [$status, $body] = self::answer($this->handler->preview(new Request('POST', '/api/themes/preview', [], (string) json_encode(self::theme([
            'name' => ['en' => ''],
            'shape' => ['preset' => 'blob'],
            'density' => 'tiny',
        ])))));

        self::assertSame(200, $status);
        self::assertFalse($body['data']['valid']);
        self::assertGreaterThanOrEqual(3, count($body['data']['problems']));
        self::assertArrayNotHasKey('light', $body['data']);
    }

    public function testTheEditorSavesItsThemeAsMadeHereAndOpensItForEditing(): void
    {
        $saved = $this->handler->import(new Request('POST', '/api/themes', [], (string) json_encode(['theme' => self::theme(), 'source' => 'editor'])));
        self::assertSame(201, $saved->getStatusCode());

        [$status, $body] = self::answer($this->handler->show(new Request('GET', '/api/themes/workspace.sea', []), ['themeId' => 'workspace.sea']));
        self::assertSame(200, $status);
        self::assertSame('editor', $body['data']['source']);
        self::assertTrue($body['data']['editable']);
        self::assertSame('Sea', $body['data']['theme']['name']['en']);

        // A file imported the usual way stays an import.
        $this->handler->import(new Request('POST', '/api/themes', [], (string) json_encode(self::theme(['id' => 'acme.reef']))));
        [, $body] = self::answer($this->handler->show(new Request('GET', '/api/themes/acme.reef', []), ['themeId' => 'acme.reef']));
        self::assertSame('import', $body['data']['source']);
    }

    public function testWhitysOwnThemesOpenAsCopiesAndUnknownOnesAreNotFound(): void
    {
        [$status, $body] = self::answer($this->handler->show(new Request('GET', '/api/themes/whity.harbour', []), ['themeId' => 'whity.harbour']));
        self::assertSame(200, $status);
        self::assertSame('whity', $body['data']['source']);
        self::assertFalse($body['data']['editable']);

        self::assertSame(404, $this->handler->show(new Request('GET', '/api/themes/acme.none', []), ['themeId' => 'acme.none'])->getStatusCode());
        self::assertSame(404, $this->handler->show(new Request('GET', '/api/themes/x', []), ['themeId' => '../x'])->getStatusCode());
    }
}
