<?php

declare(strict_types=1);

namespace Whity\Core\Theme;

use InvalidArgumentException;
use Whity\Core\Branding\TenantTheme;

/**
 * Turns a theme made elsewhere into a Whity theme file (TH.25, 30-themes 11
 * and 14.7): shadcn CSS variables, a Tailwind v4 `@theme` block, or Material
 * Theme Builder JSON. Colours become the main colour (the seed) plus hand-set
 * colours for the roles Whity has; the radius becomes the nearest shape
 * preset and a font becomes its pair. Nothing here is CSS that reaches a
 * page: the result is choices, checked by ThemeValidator and the compiler's
 * guard rails like any other theme file. `notes` says in plain words what was
 * kept and what wasn't.
 */
final class ThemeImporter
{
    public const FORMATS = ['shadcn', 'tailwind', 'material'];
    public const MAX_BYTES = 65536;

    /** shadcn and Tailwind role names that are Whity's roles under the same name. */
    private const CSS_ROLES = [
        'background', 'foreground', 'card', 'card-foreground', 'popover', 'popover-foreground',
        'primary', 'primary-foreground', 'secondary', 'secondary-foreground', 'muted', 'muted-foreground',
        'accent', 'accent-foreground', 'border', 'input', 'ring',
        'chart-1', 'chart-2', 'chart-3', 'chart-4', 'chart-5',
        'sidebar', 'sidebar-foreground', 'sidebar-primary', 'sidebar-primary-foreground',
        'sidebar-accent', 'sidebar-accent-foreground', 'sidebar-border',
    ];

    /** Status colours stay Whity's (TH.7), whatever the source says. */
    private const STATUS = ['destructive', 'destructive-foreground', 'success', 'warning', 'info', 'error'];

    /** Material 3 roles (Theme Builder's camelCase) to Whity's. */
    private const MATERIAL_ROLES = [
        'primary' => 'primary',
        'onPrimary' => 'primary-foreground',
        'primaryContainer' => 'primary-container',
        'onPrimaryContainer' => 'on-primary-container',
        'secondaryContainer' => 'secondary-container',
        'onSecondaryContainer' => 'on-secondary-container',
        'tertiary' => 'tertiary',
        'onTertiary' => 'tertiary-foreground',
        'tertiaryContainer' => 'tertiary-container',
        'onTertiaryContainer' => 'on-tertiary-container',
        'surface' => 'background',
        'onSurface' => 'foreground',
        'surfaceContainerLowest' => 'card',
        'surfaceContainerHigh' => 'popover',
        'surfaceContainer' => 'muted',
        'onSurfaceVariant' => 'muted-foreground',
        'outlineVariant' => 'border',
        'outline' => 'input',
    ];

    /** Material's surface containers, lowest to highest, as Whity's surfaces 1-5. */
    private const MATERIAL_SURFACES = ['surfaceContainerLowest', 'surfaceContainerLow', 'surfaceContainer', 'surfaceContainerHigh', 'surfaceContainerHighest'];

    /**
     * @return array{theme: array<string, mixed>, notes: list<string>}
     * @throws InvalidArgumentException in plain words when nothing usable is found
     */
    public static function convert(string $format, string $source, string $name = ''): array
    {
        if (!in_array($format, self::FORMATS, true)) {
            throw new InvalidArgumentException('Choose shadcn, Tailwind or Material Theme Builder.');
        }
        if (strlen($source) > self::MAX_BYTES) {
            throw new InvalidArgumentException('This is too long to be a theme. Paste only the colour variables.');
        }
        $label = ['shadcn' => 'shadcn', 'tailwind' => 'Tailwind', 'material' => 'Material Theme Builder'][$format];
        $name = trim(preg_replace('/[<>{}\x00-\x1f]/u', '', $name) ?? '');
        $name = $name !== '' ? mb_substr($name, 0, 80) : "Imported from {$label}";

        $notes = [];
        $theme = $format === 'material' ? self::fromMaterial($source, $notes) : self::fromCss($source, $notes);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name))), '-');
        $theme = [
            '$schema' => 'https://whity.dev/schema/theme/v1.json',
            'schemaVersion' => 1,
            'id' => 'imported.' . ($slug !== '' ? substr($slug, 0, 60) : 'theme'),
            'version' => '1.0.0',
            'name' => ['en' => $name],
            'description' => ['en' => "Imported from {$label}."],
        ] + $theme;

        $errors = ThemeValidator::validate($theme);
        if ($errors !== []) {
            throw new InvalidArgumentException('The colours found could not make a theme: ' . implode(' ', array_slice($errors, 0, 3)));
        }

        return ['theme' => $theme, 'notes' => $notes];
    }

    /**
     * shadcn (`:root` and `.dark`) and Tailwind v4 (`@theme`, `--color-*`).
     *
     * @param list<string> $notes
     * @return array<string, mixed>
     */
    private static function fromCss(string $css, array &$notes): array
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
        $modes = ['light' => [], 'dark' => []];
        // Innermost blocks with what comes before them: `:root {`, `.dark {`,
        // `@theme inline {`, and the same inside `@layer base { … }`.
        preg_match_all('/([^{}]*)\{([^{}]*)\}/', $css, $blocks, PREG_SET_ORDER);
        foreach ($blocks as [, $prelude, $body]) {
            // Only what follows the last statement: `@import "tailwindcss"; @theme`.
            $parts = explode(';', $prelude);
            $prelude = strtolower(trim((string) end($parts)));
            $mode = str_contains($prelude, 'dark') ? 'dark' : (str_contains($prelude, ':root') || str_starts_with($prelude, '@theme') || $prelude === 'html' ? 'light' : null);
            if ($mode === null) {
                continue;
            }
            preg_match_all('/--([a-z0-9-]+)\s*:\s*([^;]+);?/i', $body, $decls, PREG_SET_ORDER);
            foreach ($decls as [, $prop, $value]) {
                $modes[$mode][strtolower($prop)] = trim($value);
            }
        }
        if ($modes['light'] === [] && $modes['dark'] === []) {
            throw new InvalidArgumentException('No CSS variables were found. Paste the :root and .dark blocks, or the @theme block.');
        }

        $colours = ['light' => [], 'dark' => []];
        $unread = [];
        $status = false;
        foreach ($modes as $mode => $decls) {
            foreach ($decls as $prop => $value) {
                $role = str_starts_with($prop, 'color-') ? substr($prop, 6) : $prop;
                if (in_array($role, self::STATUS, true)) {
                    $status = true;
                    continue;
                }
                if (!in_array($role, self::CSS_ROLES, true)) {
                    continue;
                }
                $hex = self::colour(self::resolve($value, $decls));
                if ($hex === null) {
                    $unread[$role] = true;
                    continue;
                }
                $colours[$mode][$role] = $hex;
            }
        }
        // `.dark` alone is enough to read; light falls back to it only for the seed.
        $primary = $colours['light']['primary'] ?? $colours['dark']['primary'] ?? null;
        if ($primary === null && $colours['light'] === [] && $colours['dark'] === []) {
            throw new InvalidArgumentException('No colours Whity can use were found, such as --primary or --background.');
        }

        $theme = ['colour' => self::colourBlock($primary, null, null, $colours, 'calm')];
        $count = count($colours['light']) + count($colours['dark']);
        $notes[] = "Kept {$count} colours" . ($primary !== null ? ", with {$primary} as the main colour." : '.');
        if ($unread !== []) {
            $notes[] = 'Some colours could not be read and follow the theme: ' . implode(', ', array_keys($unread)) . '.';
        }
        if ($status) {
            $notes[] = 'Colours for errors, warnings and success stay Whity\'s, so they mean the same everywhere.';
        }
        if ($colours['dark'] === []) {
            $notes[] = 'There were no dark colours, so dark mode is made from the main colour.';
        }

        $light = $modes['light'] + $modes['dark'];
        $radius = self::px($light['radius'] ?? $light['radius-lg'] ?? $light['radius-md'] ?? null);
        if ($radius !== null) {
            $preset = self::presetFor($radius);
            $theme['shape'] = ['preset' => $preset];
            $notes[] = "A radius of {$radius}px became the " . ucfirst($preset) . ' corners.';
        }
        $font = $light['font-sans'] ?? $light['font-body'] ?? null;
        if ($font !== null) {
            $pair = self::fontFor($font);
            if ($pair !== null) {
                $theme['type'] = ['family' => $pair];
                $notes[] = 'The font became Whity\'s ' . ThemeFonts::name($pair) . ' pair, with its Arabic.';
            } else {
                $notes[] = 'The font is not one Whity has with an Arabic face, so the theme keeps Noto Sans.';
            }
        }

        return $theme;
    }

    /**
     * Material Theme Builder's export: `coreColors`, `schemes.light|dark`.
     *
     * @param list<string> $notes
     * @return array<string, mixed>
     */
    private static function fromMaterial(string $json, array &$notes): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new InvalidArgumentException('This is not JSON. Export the theme from Material Theme Builder as JSON and paste the whole file.');
        }
        $core = is_array($data['coreColors'] ?? null) ? $data['coreColors'] : [];
        $schemes = is_array($data['schemes'] ?? null) ? $data['schemes'] : [];
        $light = is_array($schemes['light'] ?? null) ? $schemes['light'] : [];
        $dark = is_array($schemes['dark'] ?? null) ? $schemes['dark'] : [];

        $hex = static fn (mixed $v): ?string => is_string($v) ? self::colour($v) : null;
        $primary = $hex($core['primary'] ?? null) ?? $hex($data['seed'] ?? null) ?? $hex($light['primary'] ?? null);
        if ($primary === null) {
            throw new InvalidArgumentException('No primary colour was found. Material Theme Builder files have coreColors.primary or schemes.light.primary.');
        }

        $colours = ['light' => [], 'dark' => []];
        foreach (['light' => $light, 'dark' => $dark] as $mode => $scheme) {
            foreach (self::MATERIAL_ROLES as $from => $to) {
                $value = $hex($scheme[$from] ?? null);
                if ($value !== null) {
                    $colours[$mode][$to] = $value;
                }
            }
            foreach (self::MATERIAL_SURFACES as $i => $from) {
                $value = $hex($scheme[$from] ?? null);
                if ($value !== null) {
                    $colours[$mode]['surface-' . ($i + 1)] = $value;
                }
            }
            if (isset($colours[$mode]['primary'])) {
                $colours[$mode]['ring'] = $colours[$mode]['primary'];
            }
        }

        $theme = ['colour' => self::colourBlock($primary, $hex($core['secondary'] ?? null), $hex($core['tertiary'] ?? null), $colours, 'tonal')];
        $count = count($colours['light']) + count($colours['dark']);
        $notes[] = "Kept {$count} colours from the light and dark schemes, with {$primary} as the main colour.";
        if (($core['error'] ?? null) !== null || isset($light['error'])) {
            $notes[] = 'Colours for errors, warnings and success stay Whity\'s, so they mean the same everywhere.';
        }
        if ($dark === []) {
            $notes[] = 'There was no dark scheme, so dark mode is made from the main colour.';
        }
        $notes[] = 'Material\'s shapes and type are not in this file; the theme keeps Whity\'s corners and font.';

        return $theme;
    }

    /**
     * @param array{light: array<string, string>, dark: array<string, string>} $colours
     * @return array<string, mixed>
     */
    private static function colourBlock(?string $primary, ?string $secondary, ?string $tertiary, array $colours, string $scheme): array
    {
        $seeds = array_filter(['primary' => $primary, 'secondary' => $secondary, 'tertiary' => $tertiary], static fn (?string $v): bool => $v !== null);
        $roles = array_flip(ThemeCompiler::overridableRoles());
        $overrides = [];
        foreach ($colours as $mode => $set) {
            $set = array_intersect_key($set, $roles);
            if ($set !== []) {
                ksort($set);
                $overrides[$mode] = $set;
            }
        }
        $block = ['scheme' => $scheme];
        if ($seeds !== []) {
            $block['seeds'] = $seeds;
        }
        if ($overrides !== []) {
            $block['overrides'] = $overrides;
        }

        return $block;
    }

    /**
     * Follows `var(--x)` (with or without a fallback) to a value in the same block, a few steps at most.
     *
     * @param array<string, string> $decls
     */
    private static function resolve(string $value, array $decls): string
    {
        for ($i = 0; $i < 4 && preg_match('/^var\(\s*--([a-z0-9-]+)\s*(?:,\s*(.+))?\)$/i', trim($value), $m) === 1; $i++) {
            $value = $decls[strtolower($m[1])] ?? ($m[2] ?? '');
        }

        return trim($value);
    }

    /**
     * A CSS colour as #rrggbb: hex, `oklch()`, `hsl()` or shadcn's bare
     * `h s% l%`, `rgb()`. Alpha is dropped. Null for anything else.
     */
    public static function colour(string $value): ?string
    {
        $v = strtolower(trim($value));
        if (preg_match('/^#([0-9a-f]{3})$/', $v, $m) === 1) {
            return '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
        }
        if (preg_match('/^#([0-9a-f]{6})(?:[0-9a-f]{2})?$/', $v, $m) === 1) {
            return '#' . $m[1];
        }
        $num = '(-?[0-9]*\.?[0-9]+)(%|deg)?';
        if (preg_match("/^oklch\\(\\s*{$num}[\\s,]+{$num}[\\s,]+{$num}\\s*(?:\\/.*)?\\)$/", $v, $m) === 1) {
            $m = array_pad($m, 7, '');
            $l = (float) $m[1] / ($m[2] === '%' ? 100 : 1);
            $c = (float) $m[3] * ($m[4] === '%' ? 0.004 : 1);

            return TenantTheme::oklchHex(max(0.0, min(1.0, $l)), max(0.0, $c), fmod((float) $m[5] + 360.0, 360.0));
        }
        if (preg_match("/^(?:hsla?\\(\\s*)?{$num}[\\s,]+{$num}[\\s,]+{$num}\\s*(?:\\/[^)]*)?\\)?$/", $v, $m) === 1
            && ($m = array_pad($m, 7, ''))[4] === '%' && $m[6] === '%' && (str_starts_with($v, 'hsl') || !str_contains($v, '('))) {
            return self::hsl((float) $m[1], (float) $m[3] / 100, (float) $m[5] / 100);
        }
        if (preg_match("/^rgba?\\(\\s*{$num}[\\s,]+{$num}[\\s,]+{$num}\\s*(?:[\\/,][^)]*)?\\)$/", $v, $m) === 1) {
            $m = array_pad($m, 7, '');
            $ch = static fn (string $n, string $unit): int => (int) round(max(0.0, min(255.0, $unit === '%' ? (float) $n * 2.55 : (float) $n)));

            return sprintf('#%02x%02x%02x', $ch($m[1], $m[2]), $ch($m[3], $m[4]), $ch($m[5], $m[6]));
        }

        return null;
    }

    private static function hsl(float $h, float $s, float $l): string
    {
        $h = fmod(fmod($h, 360.0) + 360.0, 360.0);
        $s = max(0.0, min(1.0, $s));
        $l = max(0.0, min(1.0, $l));
        $a = $s * min($l, 1 - $l);
        $f = static function (int $n) use ($h, $l, $a): int {
            $k = fmod($n + $h / 30, 12);

            return (int) round(255 * ($l - $a * max(-1, min($k - 3, 9 - $k, 1))));
        };

        return sprintf('#%02x%02x%02x', $f(0), $f(8), $f(4));
    }

    /** A length in px (rem at 16px), or null. */
    private static function px(?string $value): ?int
    {
        if ($value === null || preg_match('/^([0-9]*\.?[0-9]+)(px|rem)?$/', strtolower(trim($value)), $m) !== 1) {
            return null;
        }

        return (int) round((float) $m[1] * (($m[2] ?? '') === 'rem' ? 16 : 1));
    }

    /**
     * shadcn's --radius is its `rounded-lg`, a control's corner at about 36px
     * high; the nearest Whity preset at a 40px control.
     */
    private static function presetFor(int $px): string
    {
        return match (true) {
            $px <= 1 => 'square',
            $px <= 6 => 'crisp',
            $px <= 11 => 'whity',
            $px <= 15 => 'soft',
            default => 'round',
        };
    }

    /** The first family in a font stack that is one of Whity's pairs, by its Latin name. */
    private static function fontFor(string $stack): ?string
    {
        $names = [
            'noto sans' => 'noto', 'ibm plex sans' => 'plex', 'readex pro' => 'readex', 'cairo' => 'cairo',
            'rubik' => 'rubik', 'alexandria' => 'alexandria', 'noto serif' => 'naskh', 'noto kufi arabic' => 'kufi',
            'ibm plex sans arabic' => 'plex', 'noto sans arabic' => 'noto', 'noto naskh arabic' => 'naskh',
        ];
        foreach (explode(',', $stack) as $family) {
            $family = strtolower(trim($family, " \t\"'"));
            if (isset($names[$family])) {
                return $names[$family];
            }
        }

        return null;
    }
}
