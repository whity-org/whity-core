<?php

declare(strict_types=1);

namespace Whity\Core\Theme;

/**
 * The free tweaks (TH.15) and the free export (TH.16).
 *
 * A workspace may pick the shape preset, the density and the font pair from
 * the allowed lists on top of the theme it chose, as it picks the main colour.
 * They live in the `branding_theme_overrides` setting next to the colour
 * overrides: `{light, dark, shape, density, font}`, every key optional, so
 * the workspace's adjustments (layer 4, section 5) stay one value.
 *
 * Export writes the theme in use as a theme file (JSON v1) with the main
 * colour, the colour overrides and these tweaks folded in, so importing it
 * elsewhere gives the same look. Re-importing a newer version of a theme
 * lists what changed, field by field, for the picker to say in words.
 */
final class ThemeTweaks
{
    public const KEYS = ['shape', 'density', 'font'];

    /**
     * The theme with the workspace's tweaks applied. A font pair sets the body
     * and the headings together, as one choice (TH.6).
     *
     * @param array<string, mixed> $theme
     * @param array<string, mixed> $overrides The decoded `branding_theme_overrides`.
     * @return array<string, mixed>
     */
    public static function apply(array $theme, array $overrides): array
    {
        $shape = $overrides['shape'] ?? null;
        if (is_string($shape) && in_array($shape, ThemeValidator::SHAPES, true)) {
            $theme['shape'] = array_merge(is_array($theme['shape'] ?? null) ? $theme['shape'] : [], ['preset' => $shape]);
        }
        $density = $overrides['density'] ?? null;
        if (is_string($density) && in_array($density, ThemeValidator::DENSITIES, true)) {
            $theme['density'] = $density;
        }
        $font = $overrides['font'] ?? null;
        if (is_string($font) && in_array($font, ThemeFonts::ids(), true)) {
            $type = is_array($theme['type'] ?? null) ? $theme['type'] : [];
            unset($type['headingFamily']);
            $type['family'] = $font;
            $theme['type'] = $type;
        }

        return $theme;
    }

    /**
     * The tweaks the workspace has set, each null when it follows the theme.
     *
     * @param array<string, mixed> $overrides
     * @return array{shape: ?string, density: ?string, font: ?string}
     */
    public static function chosen(array $overrides): array
    {
        $pick = static fn (string $key, array $allowed): ?string => is_string($overrides[$key] ?? null) && in_array($overrides[$key], $allowed, true) ? $overrides[$key] : null;

        return [
            'shape' => $pick('shape', ThemeValidator::SHAPES),
            'density' => $pick('density', ThemeValidator::DENSITIES),
            'font' => $pick('font', ThemeFonts::ids()),
        ];
    }

    /**
     * The theme in use as a theme file (TH.16): the main colour as the primary
     * seed, the colour overrides merged per mode, and the tweaks applied.
     * Whity's own themes can't be imported under their id (`whity.` is
     * reserved), so a copy of one is named `workspace.<name>`.
     *
     * @param array<string, mixed> $theme
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function export(array $theme, ?string $primarySeed, array $overrides, ?string $author = null): array
    {
        $out = self::apply($theme, $overrides);
        $colour = is_array($out['colour'] ?? null) ? $out['colour'] : [];
        if ($primarySeed !== null) {
            $seeds = is_array($colour['seeds'] ?? null) ? $colour['seeds'] : [];
            $seeds['primary'] = strtolower($primarySeed);
            $colour['seeds'] = $seeds;
        }
        foreach (['light', 'dark'] as $mode) {
            $mine = is_array($overrides[$mode] ?? null) ? $overrides[$mode] : [];
            if ($mine === []) {
                continue;
            }
            $existing = is_array($colour['overrides'][$mode] ?? null) ? $colour['overrides'][$mode] : [];
            $colour['overrides'][$mode] = array_merge($existing, $mine);
        }
        if ($colour !== []) {
            $out['colour'] = $colour;
        }

        $id = (string) ($out['id'] ?? ThemeLibrary::DEFAULT_ID);
        if (str_starts_with($id, 'whity.')) {
            $name = substr($id, strlen('whity.'));
            $out['id'] = 'workspace.' . ($name === 'default' ? 'whity' : $name);
        }
        if ($author !== null && trim($author) !== '' && preg_match('/[<>{}]/', $author) !== 1) {
            $out['author'] = ['name' => mb_substr(trim($author), 0, 200)];
        }
        unset($out['$schema']);

        return ['$schema' => 'https://whity.dev/schema/theme/v1.json'] + $out;
    }

    /**
     * What a newer file changes in a theme already installed, field by field,
     * for the picker to say in words before it replaces the old one (TH.16).
     *
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     * @return list<array{field: string, from: ?string, to: ?string}>
     */
    public static function changes(array $old, array $new): array
    {
        $fields = [
            'name' => ['name', 'en'],
            'colour.primary' => ['colour', 'seeds', 'primary'],
            'colour.secondary' => ['colour', 'seeds', 'secondary'],
            'colour.tertiary' => ['colour', 'seeds', 'tertiary'],
            'colour.scheme' => ['colour', 'scheme'],
            'colour.neutral' => ['colour', 'neutral'],
            'colour.contrast' => ['colour', 'contrast'],
            'type.family' => ['type', 'family'],
            'type.headingFamily' => ['type', 'headingFamily'],
            'type.scale' => ['type', 'scale'],
            'type.titleWeight' => ['type', 'titleWeight'],
            'shape.preset' => ['shape', 'preset'],
            'shape.morph' => ['shape', 'morph'],
            'density' => ['density'],
            'elevation.style' => ['elevation', 'style'],
            'elevation.float' => ['elevation', 'float'],
            'motion.preset' => ['motion', 'preset'],
        ];
        $out = [];
        foreach ($fields as $field => $path) {
            $from = self::at($old, $path);
            $to = self::at($new, $path);
            if ($from !== $to) {
                $out[] = ['field' => $field, 'from' => $from, 'to' => $to];
            }
        }
        $count = static fn (array $theme): int => count(is_array($theme['colour']['overrides']['light'] ?? null) ? $theme['colour']['overrides']['light'] : [])
            + count(is_array($theme['colour']['overrides']['dark'] ?? null) ? $theme['colour']['overrides']['dark'] : []);
        $oldOverrides = is_array($old['colour']['overrides'] ?? null) ? $old['colour']['overrides'] : [];
        $newOverrides = is_array($new['colour']['overrides'] ?? null) ? $new['colour']['overrides'] : [];
        if ($oldOverrides != $newOverrides) {
            $out[] = ['field' => 'colour.overrides', 'from' => (string) $count($old), 'to' => (string) $count($new)];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $theme
     * @param list<string> $path
     */
    private static function at(array $theme, array $path): ?string
    {
        $value = $theme;
        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return is_string($value) || is_int($value) ? (string) $value : null;
    }
}
