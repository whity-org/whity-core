<?php

declare(strict_types=1);

namespace Whity\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Whity\Api\ThemesApiHandler;
use Whity\Core\Request;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Tenant\TenantContext;
use Whity\Core\Theme\ThemeRepository;

/** TH.25: POST /api/v1/themes/tokens exports a draft for another tool and saves nothing. */
final class ThemesTokensApiTest extends TestCase
{
    private ThemesApiHandler $handler;

    protected function setUp(): void
    {
        // A draft's export reads and writes nothing.
        $repository = (new ReflectionClass(ThemeRepository::class))->newInstanceWithoutConstructor();
        $settings = (new ReflectionClass(SettingsService::class))->newInstanceWithoutConstructor();
        $this->handler = new ThemesApiHandler($repository, $settings);
        TenantContext::reset();
        TenantContext::setTenantId(7);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    /**
     * @param array<string, mixed> $body
     * @return array{0: int, 1: mixed}
     */
    private function tokens(array $body): array
    {
        $response = $this->handler->tokens(new Request('POST', '/api/themes/tokens', [], (string) json_encode($body)));

        return [$response->getStatusCode(), json_decode($response->getBody(), true)];
    }

    public function testADraftComesBackAsAFileInEachFormat(): void
    {
        $theme = ['schemaVersion' => 1, 'id' => 'workspace.sea', 'version' => '1.0.0', 'name' => ['en' => 'Sea'], 'shape' => ['preset' => 'round']];
        foreach (['css' => 'workspace.sea-1.0.0.css', 'json' => 'workspace.sea-1.0.0.tokens.json', 'tailwind' => 'workspace.sea-1.0.0.tailwind.css', 'flutter' => 'workspace_sea_theme.dart'] as $format => $filename) {
            [$status, $body] = $this->tokens(['theme' => $theme, 'format' => $format]);
            self::assertSame(200, $status, $format);
            self::assertSame($format, $body['data']['format']);
            self::assertSame($filename, $body['data']['filename']);
            self::assertNotSame('', $body['data']['content']);
        }
    }

    public function testAnUnknownFormatOrABrokenThemeIsRefusedInWords(): void
    {
        $theme = ['schemaVersion' => 1, 'id' => 'workspace.sea', 'version' => '1.0.0', 'name' => ['en' => 'Sea']];
        self::assertSame(422, $this->tokens(['theme' => $theme, 'format' => 'sketch'])[0]);
        [$status, $body] = $this->tokens(['theme' => $theme + ['density' => 'tiny'], 'format' => 'css']);
        self::assertSame(422, $status);
        self::assertStringContainsString('density must be one of', json_encode($body, JSON_THROW_ON_ERROR));
    }
}
