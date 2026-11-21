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
use Whity\Core\Theme\ThemeValidator;

/** TH.25: POST /api/v1/themes/convert answers with a theme file, notes and a preview, and saves nothing. */
final class ThemesConvertApiTest extends TestCase
{
    private ThemesApiHandler $handler;

    protected function setUp(): void
    {
        // Converting reads and writes nothing: neither is ever called.
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
    private function convert(array $body): array
    {
        $response = $this->handler->convert(new Request('POST', '/api/themes/convert', [], (string) json_encode($body)));

        return [$response->getStatusCode(), json_decode($response->getBody(), true)];
    }

    public function testAPastedThemeComesBackAsAThemeFileWithAPreview(): void
    {
        [$status, $body] = $this->convert(['format' => 'tailwind', 'source' => '@theme { --color-primary: #0b6e74; --color-background: #fafafa; --radius-lg: 0.5rem; }', 'name' => 'Harbour']);

        self::assertSame(200, $status);
        self::assertSame([], ThemeValidator::validate($body['data']['theme']));
        self::assertSame('imported.harbour', $body['data']['theme']['id']);
        self::assertNotEmpty($body['data']['notes']);
        self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $body['data']['preview']['light']['primary']);
        self::assertSame('10px', $body['data']['preview']['radius']);
    }

    public function testNothingUsableIsA422ThatSaysWhatToPaste(): void
    {
        [$status, $body] = $this->convert(['format' => 'shadcn', 'source' => 'body { color: red }']);
        self::assertSame(422, $status);
        self::assertStringContainsString(':root and .dark', json_encode($body, JSON_THROW_ON_ERROR));

        self::assertSame(422, $this->convert(['format' => 'sketch', 'source' => ':root{}'])[0]);
    }

    public function testConvertingNeedsAWorkspace(): void
    {
        TenantContext::reset();
        TenantContext::setTenantId(0);
        self::assertSame(403, $this->convert(['format' => 'tailwind', 'source' => '@theme { --color-primary: #0b6e74; }'])[0]);
    }
}
