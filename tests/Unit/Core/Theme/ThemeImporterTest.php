<?php

declare(strict_types=1);

namespace Whity\Tests\Unit\Core\Theme;

use PHPUnit\Framework\TestCase;
use Whity\Core\Theme\ThemeCompiler;
use Whity\Core\Theme\ThemeImporter;
use Whity\Core\Theme\ThemeImportProblem;
use Whity\Core\Theme\ThemeValidator;

/** TH.25 (30-themes 11, 14.7): themes from shadcn, Tailwind and Material Theme Builder. */
final class ThemeImporterTest extends TestCase
{
    /** shadcn v4's own output: oklch on :root and .dark, inside @layer base. */
    private const SHADCN_V4 = <<<'CSS'
        @layer base {
          :root {
            --radius: 0.625rem;
            --background: oklch(1 0 0);
            --foreground: oklch(0.145 0 0);
            --card: oklch(1 0 0);
            --primary: oklch(0.488 0.243 264.376);
            --primary-foreground: oklch(0.985 0 0);
            --muted-foreground: oklch(0.556 0 0);
            --border: oklch(0.922 0 0);
            --destructive: oklch(0.577 0.245 27.325);
            --chart-1: oklch(0.646 0.222 41.116);
            --font-sans: "Cairo", ui-sans-serif, sans-serif;
          }
          .dark {
            --background: oklch(0.145 0 0);
            --foreground: oklch(0.985 0 0);
            --primary: oklch(0.623 0.214 259.815);
            --border: oklch(1 0 0 / 10%);
          }
        }
        CSS;

    public function testShadcnV4CssBecomesAThemeFile(): void
    {
        $result = ThemeImporter::convert('shadcn', self::SHADCN_V4, 'Acme blue');
        $theme = $result['theme'];

        self::assertSame([], ThemeValidator::validate($theme));
        self::assertSame('imported.acme-blue', $theme['id']);
        self::assertSame(['en' => 'Acme blue'], $theme['name']);
        $primary = $theme['colour']['seeds']['primary'];
        self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $primary);
        self::assertSame($primary, $theme['colour']['overrides']['light']['primary']);
        self::assertSame('#ffffff', $theme['colour']['overrides']['light']['background']);
        // Alpha is dropped; status colours never come through (TH.7).
        self::assertSame('#ffffff', $theme['colour']['overrides']['dark']['border']);
        self::assertArrayNotHasKey('destructive', $theme['colour']['overrides']['light']);
        self::assertSame('whity', $theme['shape']['preset']);
        self::assertSame('cairo', $theme['type']['family']);
        self::assertStringContainsString('stay Whity\'s', implode(' ', $result['notes']));
        // It compiles through the same guard rails as any theme.
        ThemeCompiler::compile($theme);
    }

    public function testShadcnV3HslAndTailwindThemeBlocks(): void
    {
        $v3 = ":root {\n  --primary: 222.2 47.4% 11.2%;\n  --background: 0 0% 100%;\n  --radius: 0.25rem;\n}\n.dark { --primary: 210 40% 98%; }";
        $theme = ThemeImporter::convert('shadcn', $v3)['theme'];
        self::assertSame('#0f172a', $theme['colour']['seeds']['primary']);
        self::assertSame('#f8fafc', $theme['colour']['overrides']['dark']['primary']);
        self::assertSame('crisp', $theme['shape']['preset']);
        self::assertSame(['en' => 'Imported from shadcn'], $theme['name']);

        $tailwind = "@import \"tailwindcss\";\n@theme {\n  --color-primary: #0b6e74;\n  --color-background: rgb(250 248 243);\n  --color-ring: var(--color-primary);\n  --radius-lg: 1.25rem;\n  --font-sans: 'Inter', sans-serif;\n}";
        $result = ThemeImporter::convert('tailwind', $tailwind, 'Harbour');
        $theme = $result['theme'];
        self::assertSame([], ThemeValidator::validate($theme));
        self::assertSame('#0b6e74', $theme['colour']['seeds']['primary']);
        self::assertSame('#0b6e74', $theme['colour']['overrides']['light']['ring']);
        self::assertSame('#faf8f3', $theme['colour']['overrides']['light']['background']);
        self::assertSame('round', $theme['shape']['preset']);
        self::assertArrayNotHasKey('type', $theme);
        self::assertStringContainsString('keeps Noto Sans', implode(' ', $result['notes']));
        self::assertStringContainsString('no dark colours', implode(' ', $result['notes']));
    }

    public function testMaterialThemeBuilderJson(): void
    {
        $json = json_encode([
            'seed' => '#6750A4',
            'coreColors' => ['primary' => '#6750A4', 'secondary' => '#625B71', 'tertiary' => '#7D5260', 'error' => '#B3261E'],
            'schemes' => [
                'light' => ['primary' => '#65558F', 'onPrimary' => '#FFFFFF', 'primaryContainer' => '#E9DDFF', 'surface' => '#FDF7FF', 'onSurface' => '#1D1B20', 'outlineVariant' => '#CAC4D0', 'surfaceContainerLowest' => '#FFFFFF', 'surfaceContainerHighest' => '#E6E0E9', 'error' => '#BA1A1A'],
                'dark' => ['primary' => '#CFBDFE', 'onPrimary' => '#36265D', 'surface' => '#141218'],
            ],
        ], JSON_THROW_ON_ERROR);
        $result = ThemeImporter::convert('material', $json, 'Lavender');
        $theme = $result['theme'];

        self::assertSame([], ThemeValidator::validate($theme));
        self::assertSame(['primary' => '#6750a4', 'secondary' => '#625b71', 'tertiary' => '#7d5260'], $theme['colour']['seeds']);
        self::assertSame('tonal', $theme['colour']['scheme']);
        self::assertSame('#65558f', $theme['colour']['overrides']['light']['primary']);
        self::assertSame('#65558f', $theme['colour']['overrides']['light']['ring']);
        self::assertSame('#fdf7ff', $theme['colour']['overrides']['light']['background']);
        self::assertSame('#ffffff', $theme['colour']['overrides']['light']['surface-1']);
        self::assertSame('#e6e0e9', $theme['colour']['overrides']['light']['surface-5']);
        self::assertSame('#141218', $theme['colour']['overrides']['dark']['background']);
        self::assertStringContainsString('stay Whity\'s', implode(' ', $result['notes']));
    }

    public function testColoursInEveryCssForm(): void
    {
        self::assertSame('#ffffff', ThemeImporter::colour('#fff'));
        self::assertSame('#0b6e74', ThemeImporter::colour('#0B6E74cc'));
        self::assertSame('#ff0000', ThemeImporter::colour('hsl(0 100% 50%)'));
        self::assertSame('#ff0000', ThemeImporter::colour('hsl(0, 100%, 50%)'));
        self::assertSame('#00ff00', ThemeImporter::colour('rgb(0, 255, 0)'));
        self::assertSame('#000000', ThemeImporter::colour('oklch(0% 0 0)'));
        self::assertNull(ThemeImporter::colour('red'));
        self::assertNull(ThemeImporter::colour('url(x)'));
        self::assertNull(ThemeImporter::colour('12 34 56'));
    }

    public function testNothingUsableSaysWhatToPaste(): void
    {
        foreach ([
            ['shadcn', 'body { color: red }', 'No CSS variables'],
            ['shadcn', ':root { --spacing: 4px; }', 'No colours Whity can use'],
            ['material', 'not json', 'This is not JSON'],
            ['material', '{"schemes": {}}', 'No primary colour'],
            ['figma', ':root{}', 'Choose shadcn'],
            ['tailwind', str_repeat('a', ThemeImporter::MAX_BYTES + 1), 'too long'],
        ] as [$format, $source, $says]) {
            try {
                ThemeImporter::convert($format, $source);
                self::fail("{$format} should not convert");
            } catch (ThemeImportProblem $e) {
                self::assertStringContainsString($says, $e->getMessage());
            }
        }
    }

    /** The name can't carry markup into the theme file, and the id is always valid. */
    public function testTheNameIsPlainAndTheIdValid(): void
    {
        $theme = ThemeImporter::convert('tailwind', '@theme { --color-primary: #123456; }', '<b>Café</b> {x}')['theme'];
        self::assertSame(['en' => 'bCafé/b x'], $theme['name']);
        self::assertMatchesRegularExpression(ThemeValidator::ID_RE, $theme['id']);
        self::assertSame('imported.theme', ThemeImporter::convert('tailwind', '@theme { --color-primary: #123456; }', 'مقهى')['theme']['id']);
    }
}
