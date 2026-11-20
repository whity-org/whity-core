<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Theme;

use PHPUnit\Framework\TestCase;
use Whity\Core\Branding\TenantTheme;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Theme\ThemeCompiler;
use Whity\Core\Theme\ThemeFonts;
use Whity\Core\Theme\ThemeLibrary;
use Whity\Core\Theme\ThemeResolver;
use Whity\Core\Theme\ThemeValidator;

/**
 * The theme engine (WHIT-1225, TH.1-TH.14, docs/design/30-themes.md): the
 * closed format, the compiler and its guard rails, and how a workspace's
 * settings resolve to a theme.
 */
final class ThemeEngineTest extends TestCase
{
    private const SCHEMA = __DIR__ . '/../../../../schemas/theme/v1.json';

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function theme(array $extra = []): array
    {
        return array_merge(['schemaVersion' => 1, 'id' => 'acme.test', 'version' => '1.0.0', 'name' => ['en' => 'Test']], $extra);
    }

    public function testTheValidatorListsMatchTheJsonSchema(): void
    {
        $schema = json_decode((string) file_get_contents(self::SCHEMA), true);
        $p = $schema['properties'];
        self::assertSame(ThemeValidator::SCHEMES, $p['colour']['properties']['scheme']['enum']);
        self::assertSame(ThemeValidator::NEUTRALS, $p['colour']['properties']['neutral']['enum']);
        self::assertSame(ThemeValidator::CONTRASTS, $p['colour']['properties']['contrast']['enum']);
        self::assertSame(ThemeValidator::SCALES, $p['type']['properties']['scale']['enum']);
        self::assertSame(ThemeValidator::TITLE_WEIGHTS, $p['type']['properties']['titleWeight']['enum']);
        self::assertSame(ThemeValidator::EMPHASES, $p['type']['properties']['emphasis']['enum']);
        self::assertSame(ThemeValidator::SHAPES, $p['shape']['properties']['preset']['enum']);
        self::assertSame(ThemeValidator::MORPHS, $p['shape']['properties']['morph']['enum']);
        self::assertSame(ThemeValidator::DENSITIES, $p['density']['enum']);
        self::assertSame(ThemeValidator::ELEVATIONS, $p['elevation']['properties']['style']['enum']);
        self::assertSame(ThemeValidator::FLOATS, $p['elevation']['properties']['float']['enum']);
        self::assertSame(ThemeValidator::MOTIONS, $p['motion']['properties']['preset']['enum']);
        self::assertSame(ThemeValidator::ACCENT_USES, $p['documents']['properties']['accentUse']['enum']);
        self::assertSame(ThemeValidator::TITLE_FACES, $p['documents']['properties']['titleFace']['enum']);
        self::assertSame(ThemeFonts::ids(), $schema['$defs']['fontId']['enum']);
    }

    public function testWhitysOwnThemesAreValidAndTheDefaultComesFirst(): void
    {
        $themes = ThemeLibrary::builtIn();
        self::assertSame(ThemeLibrary::DEFAULT_ID, array_key_first($themes));
        self::assertGreaterThanOrEqual(5, count($themes));
        foreach ($themes as $theme) {
            self::assertSame([], ThemeValidator::validate($theme), (string) $theme['id']);
        }
    }

    public function testAThemeIsChoicesNeverCss(): void
    {
        self::assertSame([], ThemeValidator::validate(self::theme(['colour' => ['seeds' => ['primary' => '#0b6e74'], 'scheme' => 'tonal'], 'shape' => ['preset' => 'soft']])));

        $bad = ThemeValidator::validate(self::theme([
            'css' => 'body{}',
            'colour' => ['seeds' => ['primary' => 'red'], 'overrides' => ['light' => ['background' => 'url(x)', 'success' => '#00ff00']]],
            'type' => ['family' => 'Comic Sans'],
            'density' => 'tiny',
        ]));
        $text = implode(' ', $bad);
        self::assertStringContainsString('unknown key "css"', $text);
        self::assertStringContainsString('colour.seeds.primary', $text);
        self::assertStringContainsString('background must be a #rrggbb', $text);
        self::assertStringContainsString('"success" is not a colour a theme can set', $text);
        self::assertStringContainsString('type.family', $text);
        self::assertStringContainsString('density', $text);

        self::assertNotSame([], ThemeValidator::validate(self::theme(['name' => ['en' => '<script>']])));
        self::assertNotSame([], ThemeValidator::validate(['schemaVersion' => 1, 'id' => 'NoDot', 'version' => '1', 'name' => ['en' => 'x']]));
        self::assertStringContainsString('newer Whity', implode(' ', ThemeValidator::validate(['schemaVersion' => 2] + self::theme())));
    }

    public function testTheDefaultThemeCompilesToNothing(): void
    {
        $out = ThemeCompiler::compile(ThemeLibrary::builtIn()[ThemeLibrary::DEFAULT_ID]);
        self::assertSame([], $out['light']);
        self::assertSame([], $out['dark']);
        self::assertSame([], $out['vars']);
        self::assertSame(['body' => 'noto', 'heading' => 'noto'], $out['fonts']);
    }

    /** The Calm scheme gives a main colour exactly what 2.8 gives it today. */
    public function testCalmReproducesTenantTheme(): void
    {
        foreach (['#b74c05', '#0961bb', '#00792f', '#7a1f3d'] as $colour) {
            $derived = TenantTheme::derive($colour);
            $out = ThemeCompiler::compile(self::theme(), $colour);
            foreach (['light', 'dark'] as $mode) {
                foreach ($derived[$mode] as $role => $value) {
                    self::assertSame($value, $out[$mode][$role] ?? null, "{$colour} {$mode} {$role}");
                }
            }
        }
    }

    public function testEveryPairPassesTheGuardRails(): void
    {
        $cases = [
            [self::theme(), '#f2b036', 'standard'],
            [self::theme(['colour' => ['scheme' => 'vibrant']]), '#ffd84d', 'standard'],
            [self::theme(['colour' => ['scheme' => 'expressive', 'neutral' => 'warm']]), '#8a2a86', 'high'],
            [self::theme(['colour' => ['scheme' => 'monochrome']]), null, 'high'],
            [self::theme(['colour' => ['overrides' => ['light' => ['background' => '#777777', 'primary-foreground' => '#eeeeee']]]]), '#0961bb', 'standard'],
        ];
        $pairs = [
            ['background', 'foreground', 4.5], ['card', 'card-foreground', 4.5], ['primary', 'primary-foreground', 4.5],
            ['card', 'muted-foreground', 4.5], ['background', 'muted-foreground', 4.5], ['primary-tint', 'primary-tint-foreground', 4.5],
            ['tertiary', 'tertiary-foreground', 4.5], ['primary-container', 'on-primary-container', 4.5], ['card', 'input', 3.0], ['background', 'ring', 3.0],
        ];
        foreach ($cases as [$theme, $seed, $contrast]) {
            $out = ThemeCompiler::compile($theme, $seed, [], $contrast);
            foreach (['light', 'dark'] as $mode) {
                $base = ThemeCompiler::base($mode);
                foreach ($pairs as [$bg, $fg, $min]) {
                    $min = $contrast === 'high' ? ($min === 3.0 ? 4.5 : 7.0) : $min;
                    $b = $out[$mode][$bg] ?? $base[$bg] ?? null;
                    $f = $out[$mode][$fg] ?? $base[$fg] ?? null;
                    if ($b === null || $f === null) {
                        continue;
                    }
                    self::assertGreaterThanOrEqual($min, TenantTheme::contrast($b, $f), "{$mode} {$bg}/{$fg} ({$seed}, {$contrast})");
                }
            }
        }
    }

    public function testAFailingOverrideIsDroppedAndSaysSo(): void
    {
        $out = ThemeCompiler::compile(self::theme(), null, ['light' => ['background' => '#777777']]);
        self::assertArrayNotHasKey('background', $out['light']);
        self::assertContains(['mode' => 'light', 'role' => 'background', 'change' => 'override-dropped'], $out['adjustments']);

        $kept = ThemeCompiler::compile(self::theme(), null, ['light' => ['background' => '#f4f1ea']]);
        self::assertSame('#f4f1ea', $kept['light']['background']);
    }

    public function testStatusColoursAreNeverThemed(): void
    {
        $out = ThemeCompiler::compile(self::theme(['colour' => ['scheme' => 'expressive']]), '#cc2827');
        foreach (['success', 'warning', 'error', 'destructive', 'info'] as $role) {
            self::assertArrayNotHasKey($role, $out['light']);
            self::assertArrayNotHasKey($role, $out['dark']);
        }
        self::assertNotContains('success', ThemeCompiler::overridableRoles());
    }

    public function testShapeDensityTypeAndMotionBecomeVariables(): void
    {
        $out = ThemeCompiler::compile(self::theme([
            'type' => ['family' => 'plex', 'headingFamily' => 'readex', 'scale' => 'large'],
            'shape' => ['preset' => 'round'],
            'density' => 'comfortable',
            'motion' => ['preset' => 'expressive'],
        ]));
        $vars = $out['vars'];
        self::assertStringStartsWith('var(--font-ibm-plex-sans), var(--font-ibm-plex-sans-arabic)', $vars['font-sans']);
        self::assertStringStartsWith('var(--font-readex-pro),', $vars['font-heading']);
        self::assertSame('1.0625rem', $vars['text-sm']);
        self::assertSame('44px', $vars['control-h']);
        // Round's pill is half the control height (44px comfortable), so a
        // control that grows keeps that corner (WHIT-1225).
        self::assertSame('22px', $vars['wy-radius-control']);
        self::assertSame('20px', $vars['wy-radius-card']);
        self::assertMatchesRegularExpression('/^linear\(0(, -?\d+(\.\d+)?)+\)$/', $vars['ease-out']);
        self::assertSame('200ms', $vars['dur-fast']);

        // Compact never goes below A.1: 32px small controls, 15px body.
        $compact = ThemeCompiler::compile(self::theme(['density' => 'compact']))['vars'];
        self::assertSame('32px', $compact['control-h-sm']);
        self::assertSame('36px', $compact['control-h']);
    }

    public function testElevationStylesKeepAPlaneToShow(): void
    {
        $soft = ThemeCompiler::compile(self::theme(['elevation' => ['style' => 'soft']]));
        self::assertMatchesRegularExpression('/^0 2px 6px 0 #[0-9a-f]{8}$/', $soft['light']['plane-rest']);
        $flat = ThemeCompiler::compile(self::theme(['elevation' => ['style' => 'flat']]), '#b74c05');
        self::assertMatchesRegularExpression('/^0 0 0 2px #[0-9a-f]{6}$/', $flat['dark']['plane-primary-rest']);
    }

    public function testTheResolverUsesTheChosenThemeTheMainColourAndHigherContrast(): void
    {
        $library = new ThemeLibrary();
        $none = ThemeResolver::resolve([], $library, 1);
        self::assertNull($none['theme']);
        self::assertNotSame([], $none['high']['light']);

        $harbour = ThemeResolver::resolve([SettingsRegistry::BRANDING_THEME => 'whity.harbour'], $library, 1);
        self::assertNotNull($harbour['theme']);
        self::assertSame('whity.harbour', $harbour['theme']['id']);
        self::assertSame('#0b6e74', $harbour['theme']['light']['primary']);
        self::assertSame('plex', $harbour['theme']['fonts']['body']);

        $seeded = ThemeResolver::resolve([SettingsRegistry::BRANDING_THEME => 'whity.harbour', SettingsRegistry::BRANDING_MAIN_COLOR => '#B74C05'], $library, 1);
        self::assertNotNull($seeded['theme']);
        self::assertSame('#b74c05', $seeded['theme']['light']['primary']);
        self::assertSame('#b74c05', $seeded['mainColor']);

        $missing = ThemeResolver::resolve([SettingsRegistry::BRANDING_THEME => 'acme.gone'], $library, 1);
        self::assertNotNull($missing['theme']);
        self::assertSame(ThemeLibrary::DEFAULT_ID, $missing['theme']['id']);
        self::assertContains('theme-missing', array_column($missing['theme']['adjustments'], 'change'));
    }

    /** SDK 1.125: plugin themes join the list after Whity's own, never replacing them. */
    public function testPluginThemesAreListedAndResolvable(): void
    {
        $plugin = ['schemaVersion' => 1, 'id' => 'acme.sea', 'version' => '1.0.0', 'name' => ['en' => 'Sea'], 'colour' => ['seeds' => ['primary' => '#006d77']]];
        $clash = ['schemaVersion' => 1, 'id' => 'whity.harbour', 'version' => '9.0.0', 'name' => ['en' => 'Fake']];
        $library = new ThemeLibrary(null, static fn (): array => [
            ['plugin' => 'acme', 'theme' => $plugin],
            ['plugin' => 'evil', 'theme' => $clash],
        ]);

        $available = $library->available(1);
        $sea = array_values(array_filter($available, static fn (array $e): bool => $e['theme']['id'] === 'acme.sea'));
        self::assertCount(1, $sea);
        self::assertSame('plugin', $sea[0]['source']);
        self::assertSame('acme', $sea[0]['plugin']);
        $harbour = $library->find(1, 'whity.harbour');
        self::assertNotNull($harbour);
        self::assertSame('1.0.0', $harbour['version']);
        self::assertCount(1, array_filter($available, static fn (array $e): bool => $e['theme']['id'] === 'whity.harbour'));

        $resolved = ThemeResolver::resolve([SettingsRegistry::BRANDING_THEME => 'acme.sea'], $library, 1);
        self::assertNotNull($resolved['theme']);
        self::assertSame('#006d77', $resolved['theme']['light']['primary']);

        $broken = new ThemeLibrary(null, static fn (): array => throw new \RuntimeException('loader down'));
        self::assertSame(ThemeLibrary::DEFAULT_ID, $broken->available(1)[0]['theme']['id']);
    }

    /** No preset ever emits a radius larger than half the control it sits on. */
    public function testNoShapePresetTurnsAGrowingControlIntoAStadium(): void
    {
        foreach (ThemeValidator::SHAPES as $shape) {
            foreach (ThemeValidator::DENSITIES as $density) {
                $vars = ThemeCompiler::compile(self::theme(['shape' => ['preset' => $shape], 'density' => $density]))['vars'];
                foreach (['control-sm' => 'control-h-sm', 'control' => 'control-h', 'control-lg' => 'control-h-lg'] as $radius => $height) {
                    $r = (int) ($vars['wy-radius-' . $radius] ?? 0);
                    $h = (int) ($vars[$height] ?? ['control-h-sm' => 32, 'control-h' => 40, 'control-h-lg' => 44][$height]);
                    self::assertLessThanOrEqual(intdiv($h, 2), $r, "{$shape}/{$density} {$radius}");
                }
            }
        }
    }

    /**
     * The browser check (packages/ui/scripts/shape-clip-check.mjs) runs every
     * preset from this file, so it must match what the compiler emits. Run
     * with UPDATE_SHAPE_PRESETS=1 to rewrite it.
     */
    public function testTheShapeCheckPresetsMatchTheCompiler(): void
    {
        $file = __DIR__ . '/../../../../packages/ui/scripts/shape-presets.json';
        $presets = [];
        foreach (ThemeValidator::SHAPES as $shape) {
            foreach (['standard', 'compact', 'comfortable'] as $density) {
                $vars = ThemeCompiler::compile(self::theme(['shape' => ['preset' => $shape], 'density' => $density]))['vars'];
                $keep = array_filter($vars, static fn (string $k): bool => str_starts_with($k, 'wy-radius-') || str_starts_with($k, 'control-h') || $k === 'row-h', ARRAY_FILTER_USE_KEY);
                ksort($keep);
                $presets[$density === 'standard' ? $shape : "{$shape}-{$density}"] = (object) $keep;
            }
        }
        $json = json_encode($presets, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "
";
        if (getenv('UPDATE_SHAPE_PRESETS') === '1') {
            file_put_contents($file, $json);
        }
        self::assertFileExists($file);
        self::assertSame($json, str_replace("
", "
", (string) file_get_contents($file)), 'Run UPDATE_SHAPE_PRESETS=1 vendor/bin/phpunit --filter ShapeCheckPresets');
    }
}
