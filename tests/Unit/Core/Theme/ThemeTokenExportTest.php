<?php

declare(strict_types=1);

namespace Whity\Tests\Unit\Core\Theme;

use PHPUnit\Framework\TestCase;
use Whity\Core\Theme\ThemeCompiler;
use Whity\Core\Theme\ThemeFonts;
use Whity\Core\Theme\ThemeLibrary;
use Whity\Core\Theme\ThemeTokenExport;

/** TH.25 (30-themes 11, 14.7): resolved tokens as CSS, JSON, Tailwind and Flutter. */
final class ThemeTokenExportTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function harbour(): array
    {
        return ThemeLibrary::builtIn()['whity.harbour'];
    }

    /** @return array<string, mixed> */
    private static function salon(): array
    {
        return [
            'schemaVersion' => 1, 'id' => 'templates.salon', 'version' => '1.1.0', 'name' => ['en' => "Salon */ <x>\nnext"],
            'colour' => ['seeds' => ['primary' => '#a8326e'], 'scheme' => 'expressive'],
            'type' => ['family' => 'plex'],
            'shape' => ['preset' => 'round', 'components' => ['cards' => 'crisp']],
            'density' => 'comfortable',
            'motion' => ['preset' => 'expressive'],
        ];
    }

    public function testEverySizeIsResolvedWithWhitysDefaults(): void
    {
        $shape = ThemeCompiler::resolvedShape(['schemaVersion' => 1, 'id' => 'a.b', 'version' => '1.0.0', 'name' => ['en' => 'B']]);
        self::assertSame('40px', $shape['control-h']);
        self::assertSame('48px', $shape['row-h']);
        self::assertSame('10px', $shape['radius-control']);
        self::assertSame('12px', $shape['radius-card']);
        self::assertSame('round', $shape['corner-shape']);

        $salon = ThemeCompiler::resolvedShape(self::salon());
        self::assertSame('44px', $salon['control-h']);
        self::assertSame('22px', $salon['radius-control']);
        self::assertSame('6px', $salon['cards-radius-card']);
        self::assertArrayNotHasKey('buttons-radius-control', $salon);
    }

    public function testCssHasBothModesEverySizeAndFontsByName(): void
    {
        $out = ThemeTokenExport::export(self::harbour(), 'css');
        self::assertSame('whity.harbour-1.0.0.css', $out['filename']);
        self::assertSame('text/css', $out['media_type']);
        $css = $out['content'];
        self::assertMatchesRegularExpression('/:root \{\n(  --[^\n]+\n)*  --background: #[0-9a-f]{6};/', $css);
        self::assertMatchesRegularExpression('/\.dark \{\n(  --[^\n]+\n)*  --background: #[0-9a-f]{6};/', $css);
        // Harbour is Soft: a third of the 40px control.
        self::assertStringContainsString('--radius-control: 13px;', $css);
        self::assertStringContainsString('--control-h: 40px;', $css);
        self::assertStringContainsString('--font-sans: "IBM Plex Sans", "IBM Plex Sans Arabic", ui-sans-serif', $css);
        self::assertMatchesRegularExpression('/--dur-fast: \d+ms;/', $css);
        self::assertStringNotContainsString('var(--font', $css, 'No Whity variables in a file for another tool');
    }

    public function testTheNameCantBreakOutOfAComment(): void
    {
        foreach (ThemeTokenExport::FORMATS as $format) {
            $content = ThemeTokenExport::export(self::salon(), $format)['content'];
            // The line break in the name never starts a line of its own.
            self::assertStringNotContainsString("\nnext", $content, $format);
        }
        // The header's own */ is the only one: the name's was taken out.
        self::assertSame(1, substr_count(ThemeTokenExport::export(self::salon(), 'css')['content'], '*/'));
        self::assertSame(1, substr_count(ThemeTokenExport::export(self::salon(), 'tailwind')['content'], '*/'));
        self::assertStringStartsWith('// Salon <x> next (templates.salon 1.1.0)', ThemeTokenExport::export(self::salon(), 'flutter')['content']);
    }

    public function testTailwindUsesItsOwnNamespaces(): void
    {
        $out = ThemeTokenExport::export(self::salon(), 'tailwind');
        $css = $out['content'];
        self::assertSame('templates.salon-1.1.0.tailwind.css', $out['filename']);
        self::assertMatchesRegularExpression('/@theme \{\n(  --[^\n]+\n)*  --color-background: #[0-9a-f]{6};/', $css);
        self::assertStringContainsString('--radius-control: 22px;', $css);
        // A family's own radius, as rounded-cards-card.
        self::assertStringContainsString('--radius-cards-card: 6px;', $css);
        self::assertStringContainsString('--spacing-control-h: 44px;', $css);
        self::assertMatchesRegularExpression('/@layer base \{\n  \.dark \{\n(    --[^\n]+\n)*    --color-background: #[0-9a-f]{6};/', $css);
        self::assertStringContainsString('--ease-out: linear(', $css, 'The expressive spring as CSS linear()');
    }

    public function testJsonIsDesignTokens(): void
    {
        $out = ThemeTokenExport::export(self::salon(), 'json');
        self::assertSame('application/json', $out['media_type']);
        $doc = json_decode($out['content'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('color', $doc['color']['light']['primary']['$type']);
        self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $doc['color']['dark']['primary']['$value']);
        self::assertSame(['$type' => 'dimension', '$value' => '22px'], $doc['radius']['control']);
        self::assertSame('6px', $doc['radius']['cards-card']['$value']);
        self::assertSame(['$type' => 'fontFamily', '$value' => ThemeFonts::families('plex')], $doc['font']['sans']);
        self::assertSame('duration', $doc['motion']['duration']['fast']['$type']);
        self::assertArrayNotHasKey('$type', $doc['motion']['easing']['out'], 'A spring has no token type');

        $calm = json_decode(ThemeTokenExport::export(['schemaVersion' => 1, 'id' => 'a.b', 'version' => '1.0.0', 'name' => ['en' => 'B']], 'json')['content'], true, 512, JSON_THROW_ON_ERROR);
        self::assertEquals(['$type' => 'cubicBezier', '$value' => [0.2, 0, 0, 1]], $calm['motion']['easing']['out']);
        self::assertSame('120ms', $calm['motion']['duration']['fast']['$value'], 'Calm keeps Whity\'s timings');
    }

    public function testFlutterIsLikeAppTokensWithMaterialSchemes(): void
    {
        $out = ThemeTokenExport::export(self::salon(), 'flutter');
        $dart = $out['content'];
        self::assertSame('templates_salon_theme.dart', $out['filename']);
        self::assertStringContainsString('class TemplatesSalonTheme {', $dart);
        self::assertMatchesRegularExpression("/'cardForeground': Color\\(0xFF[0-9A-F]{6}\\),/", $dart);
        self::assertMatchesRegularExpression("/'surface1': Color\\(0xFF[0-9A-F]{6}\\),/", $dart);
        self::assertStringContainsString("'control': 22,", $dart);
        self::assertStringContainsString("'controlH': 44,", $dart);
        self::assertStringContainsString("static const String fontSans = 'IBM Plex Sans';", $dart);
        self::assertStringContainsString("static const String fontArabic = 'IBM Plex Sans Arabic';", $dart);
        self::assertStringContainsString("static const lightScheme = ColorScheme(\n    brightness: Brightness.light,", $dart);
        self::assertStringContainsString('static const darkScheme = ColorScheme(', $dart);
        self::assertMatchesRegularExpression('/    onSurface: Color\(0xFF[0-9A-F]{6}\),/', $dart);
        self::assertStringNotContainsString('#', (string) strstr($dart, 'class '), 'Every colour as a Dart Color');
    }

    public function testAnIdThatStartsWithADigitStillMakesADartClass(): void
    {
        $theme = ['schemaVersion' => 1, 'id' => '3d.studio', 'version' => '1.0.0', 'name' => ['en' => '3D']];
        self::assertStringContainsString('class Whity3dStudioTheme {', ThemeTokenExport::export($theme, 'flutter')['content']);
    }
}
