<?php

declare(strict_types=1);

namespace Whity\Core\Theme;

/**
 * A theme's resolved tokens for use outside Whity (TH.25, 30-themes 11 and
 * 14.7): CSS custom properties, design tokens JSON (the W3C Design Tokens
 * Community Group format), a Tailwind v4 `@theme` file, or a Flutter file in
 * the shape of whity_tokens' `AppTokens` plus a Material 3 `ColorScheme`.
 *
 * "Resolved" means what the workspace sees: every colour for light and dark
 * after the compiler and its guard rails, every radius and size including
 * Whity's defaults, the fonts by family name and the motion timings. Nothing
 * here is read back into Whity; importing stays the theme file (TH.16).
 */
final class ThemeTokenExport
{
    public const FORMATS = ['css', 'json', 'tailwind', 'flutter'];

    /** Whity's motion timings when the theme keeps Calm (base.json `motion`). */
    private const MOTION = ['dur-fast' => '120ms', 'dur-base' => '180ms', 'dur-slow' => '250ms', 'ease-out' => 'cubic-bezier(0.2, 0, 0, 1)'];

    private const MONO = ['Geist Mono'];

    /**
     * @param array<string, mixed> $theme A valid theme file, with the workspace's choices folded in.
     * @return array{format: string, filename: string, media_type: string, content: string}
     */
    public static function export(array $theme, string $format): array
    {
        if (!in_array($format, self::FORMATS, true)) {
            throw new \InvalidArgumentException('Unknown export format.');
        }
        $compiled = ThemeCompiler::compile($theme);
        $tokens = [
            'id' => (string) $theme['id'],
            'version' => (string) $theme['version'],
            // Plain text inside a comment: no line breaks, nothing that closes it.
            'name' => trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('#[\x00-\x1f]+|\*/#', ' ', (string) ($theme['name']['en'] ?? $theme['id'])))),
            'light' => self::colours('light', $compiled['light']),
            'dark' => self::colours('dark', $compiled['dark']),
            'shadows' => ['light' => self::shadows($compiled['light']), 'dark' => self::shadows($compiled['dark'])],
            'shape' => ThemeCompiler::resolvedShape($theme),
            'fonts' => [
                'sans' => ThemeFonts::families($compiled['fonts']['body']),
                'heading' => ThemeFonts::families($compiled['fonts']['heading'], true),
                'mono' => self::MONO,
            ],
            'motion' => array_merge(self::MOTION, array_intersect_key($compiled['vars'], self::MOTION)),
        ];
        $base = preg_replace('/[^a-z0-9.-]/', '', $tokens['id']) . '-' . preg_replace('/[^0-9.]/', '', $tokens['version']);

        return match ($format) {
            'css' => ['format' => 'css', 'filename' => "{$base}.css", 'media_type' => 'text/css', 'content' => self::css($tokens)],
            'json' => ['format' => 'json', 'filename' => "{$base}.tokens.json", 'media_type' => 'application/json', 'content' => self::json($tokens)],
            'tailwind' => ['format' => 'tailwind', 'filename' => "{$base}.tailwind.css", 'media_type' => 'text/css', 'content' => self::tailwind($tokens)],
            'flutter' => ['format' => 'flutter', 'filename' => self::snake($tokens['id']) . '_theme.dart', 'media_type' => 'text/x-dart', 'content' => self::flutter($tokens)],
        };
    }

    /**
     * Every colour role as #rrggbb: Whity's own with the theme's on top.
     *
     * @param array<string, string> $compiled
     * @return array<string, string>
     */
    private static function colours(string $mode, array $compiled): array
    {
        $out = ThemeCompiler::base($mode);
        foreach ($compiled as $role => $value) {
            if (preg_match('/^#[0-9a-f]{6}$/', $value) === 1) {
                $out[$role] = $value;
            }
        }

        return $out;
    }

    /**
     * @param array<string, string> $compiled
     * @return array<string, string>
     */
    private static function shadows(array $compiled): array
    {
        return array_filter($compiled, static fn (string $v): bool => preg_match('/^#[0-9a-f]{6}$/', $v) !== 1);
    }

    /** @param list<string> $families */
    private static function stack(array $families, string $fallback): string
    {
        return implode(', ', array_map(static fn (string $f): string => '"' . $f . '"', $families)) . ', ' . $fallback;
    }

    /** @param array<string, mixed> $t */
    private static function header(array $t, string $comment): string
    {
        return "{$comment} {$t['name']} ({$t['id']} {$t['version']}), exported from Whity.\n";
    }

    /**
     * Sizes as custom properties: `radius-control` → `--radius-control`,
     * `buttons-radius-control` → `--buttons-radius-control`, `corner-shape` → `--corner-shape`.
     *
     * @param array<string, mixed> $t
     * @return list<string>
     */
    private static function sizeLines(array $t, string $prefix = ''): array
    {
        $lines = [];
        foreach ($t['shape'] as $name => $value) {
            $lines[] = "  --{$prefix}{$name}: {$value};";
        }

        return $lines;
    }

    /** @param array<string, mixed> $t */
    private static function css(array $t): string
    {
        $root = [];
        foreach ($t['light'] as $role => $hex) {
            $root[] = "  --{$role}: {$hex};";
        }
        foreach ($t['shadows']['light'] as $role => $value) {
            $root[] = "  --{$role}: {$value};";
        }
        $root = [...$root, ...self::sizeLines($t)];
        $root[] = '  --font-sans: ' . self::stack($t['fonts']['sans'], 'ui-sans-serif, system-ui, sans-serif') . ';';
        $root[] = '  --font-heading: ' . self::stack($t['fonts']['heading'], 'ui-sans-serif, system-ui, sans-serif') . ';';
        $root[] = '  --font-mono: ' . self::stack($t['fonts']['mono'], 'ui-monospace, monospace') . ';';
        foreach ($t['motion'] as $name => $value) {
            $root[] = "  --{$name}: {$value};";
        }
        $dark = [];
        foreach ($t['dark'] as $role => $hex) {
            $dark[] = "  --{$role}: {$hex};";
        }
        foreach ($t['shadows']['dark'] as $role => $value) {
            $dark[] = "  --{$role}: {$value};";
        }

        return self::header($t, '/*') . " * Colours, corners, sizes, fonts and motion as CSS variables. Dark applies under .dark. */\n\n"
            . ":root {\n" . implode("\n", $root) . "\n}\n\n.dark {\n" . implode("\n", $dark) . "\n}\n";
    }

    /** @param array<string, mixed> $t */
    private static function tailwind(array $t): string
    {
        $theme = [];
        foreach ($t['light'] as $role => $hex) {
            $theme[] = "  --color-{$role}: {$hex};";
        }
        foreach ($t['shape'] as $name => $value) {
            if (str_starts_with($name, 'radius-')) {
                $theme[] = "  --{$name}: {$value};";
            } elseif (str_contains($name, '-radius-')) {
                // A family's own radius, as `rounded-buttons-control`.
                [$family, $rest] = explode('-radius-', $name, 2);
                $theme[] = "  --radius-{$family}-{$rest}: {$value};";
            } elseif (!str_ends_with($name, 'corner-shape')) {
                // Heights as spacing, so `h-control-h` and `min-h-row-h` work.
                $theme[] = "  --spacing-{$name}: {$value};";
            }
        }
        $theme[] = '  --font-sans: ' . self::stack($t['fonts']['sans'], 'ui-sans-serif, system-ui, sans-serif') . ';';
        $theme[] = '  --font-heading: ' . self::stack($t['fonts']['heading'], 'ui-sans-serif, system-ui, sans-serif') . ';';
        $theme[] = '  --font-mono: ' . self::stack($t['fonts']['mono'], 'ui-monospace, monospace') . ';';
        $theme[] = "  --ease-out: {$t['motion']['ease-out']};";
        $dark = [];
        foreach ($t['dark'] as $role => $hex) {
            $dark[] = "    --color-{$role}: {$hex};";
        }

        return self::header($t, '/*') . " * Tailwind CSS v4: put this after @import \"tailwindcss\"; dark applies under .dark. */\n\n"
            . "@custom-variant dark (&:where(.dark, .dark *));\n\n"
            . "@theme {\n" . implode("\n", $theme) . "\n}\n\n"
            . "@layer base {\n  .dark {\n" . implode("\n", $dark) . "\n  }\n}\n";
    }

    /** @param array<string, mixed> $t */
    private static function json(array $t): string
    {
        $colour = static fn (array $set): array => array_map(static fn (string $hex): array => ['$type' => 'color', '$value' => $hex], $set);
        $radius = [];
        $size = [];
        $corner = [];
        foreach ($t['shape'] as $name => $value) {
            if (str_ends_with($name, 'corner-shape')) {
                $corner[$name] = ['$value' => $value];
            } elseif (str_contains($name, 'radius-')) {
                $radius[str_replace('radius-', '', $name)] = ['$type' => 'dimension', '$value' => $value];
            } else {
                $size[$name] = ['$type' => 'dimension', '$value' => $value];
            }
        }
        $motion = [];
        foreach (['dur-fast' => 'fast', 'dur-base' => 'base', 'dur-slow' => 'slow'] as $key => $name) {
            $motion['duration'][$name] = ['$type' => 'duration', '$value' => $t['motion'][$key]];
        }
        if (preg_match('/^cubic-bezier\(([^)]+)\)$/', $t['motion']['ease-out'], $m) === 1) {
            $motion['easing']['out'] = ['$type' => 'cubicBezier', '$value' => array_map('floatval', array_map('trim', explode(',', $m[1])))];
        } else {
            // A spring as CSS linear(); there is no token type for it.
            $motion['easing']['out'] = ['$value' => $t['motion']['ease-out'], '$description' => 'CSS linear() easing for the theme\'s spring.'];
        }

        $doc = [
            '$description' => "{$t['name']} ({$t['id']} {$t['version']}), exported from Whity. Design Tokens Community Group format.",
            'color' => ['light' => $colour($t['light']), 'dark' => $colour($t['dark'])],
            'radius' => $radius,
            'size' => $size,
            'corner' => $corner,
            'font' => [
                'sans' => ['$type' => 'fontFamily', '$value' => $t['fonts']['sans']],
                'heading' => ['$type' => 'fontFamily', '$value' => $t['fonts']['heading']],
                'mono' => ['$type' => 'fontFamily', '$value' => $t['fonts']['mono']],
            ],
            'motion' => $motion,
        ];

        return json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    /** Material 3 roles (Flutter's ColorScheme) and the Whity role each comes from. */
    private const SCHEME = [
        'primary' => 'primary', 'onPrimary' => 'primary-foreground',
        'primaryContainer' => 'primary-container', 'onPrimaryContainer' => 'on-primary-container',
        'secondary' => 'on-secondary-container', 'onSecondary' => 'secondary-container',
        'secondaryContainer' => 'secondary-container', 'onSecondaryContainer' => 'on-secondary-container',
        'tertiary' => 'tertiary', 'onTertiary' => 'tertiary-foreground',
        'tertiaryContainer' => 'tertiary-container', 'onTertiaryContainer' => 'on-tertiary-container',
        'error' => 'error', 'onError' => 'error-foreground',
        'surface' => 'background', 'onSurface' => 'foreground', 'onSurfaceVariant' => 'muted-foreground',
        'surfaceContainerLowest' => 'surface-1', 'surfaceContainerLow' => 'surface-2', 'surfaceContainer' => 'surface-3',
        'surfaceContainerHigh' => 'surface-4', 'surfaceContainerHighest' => 'surface-5',
        'outline' => 'input', 'outlineVariant' => 'border',
    ];

    /** @param array<string, mixed> $t */
    private static function flutter(array $t): string
    {
        $class = str_replace(' ', '', ucwords(str_replace(['.', '-'], ' ', $t['id']))) . 'Theme';
        if (preg_match('/^[A-Za-z]/', $class) !== 1) {
            $class = 'Whity' . $class;
        }
        $dartColour = static fn (string $hex): string => 'Color(0xFF' . strtoupper(substr($hex, 1)) . ')';
        $camel = static fn (string $name): string => lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $name))));
        $map = static function (array $set) use ($dartColour, $camel): string {
            $lines = [];
            foreach ($set as $role => $hex) {
                $lines[] = "    '" . $camel($role) . "': " . $dartColour($hex) . ',';
            }

            return implode("\n", $lines);
        };
        $scheme = static function (array $set, string $brightness) use ($dartColour): string {
            $lines = ["    brightness: Brightness.{$brightness},"];
            foreach (self::SCHEME as $role => $from) {
                $lines[] = "    {$role}: " . $dartColour($set[$from] ?? ($brightness === 'dark' ? '#ffffff' : '#000000')) . ',';
            }

            return implode("\n", $lines);
        };
        $radius = [];
        $size = [];
        foreach ($t['shape'] as $name => $value) {
            if (str_ends_with($name, 'corner-shape')) {
                continue;
            }
            $line = "    '" . $camel(str_replace('radius-', '', $name)) . "': " . (int) $value . ',';
            if (str_contains($name, 'radius-')) {
                $radius[] = $line;
            } else {
                $size[] = $line;
            }
        }
        $q = static fn (string $s): string => "'" . str_replace(["\\", "'", '$'], ["\\\\", "\\'", '\\$'], $s) . "'";

        return self::header($t, '//') . "// Generated; do not edit. The same shape as AppTokens in whity_tokens, so it\n"
            . "// can stand in for it, plus Material 3 colour schemes and a ThemeData.\n\n"
            . "import 'package:flutter/material.dart';\n\n"
            . "class {$class} {\n"
            . "  static const lightColors = <String, Color>{\n" . $map($t['light']) . "\n  };\n\n"
            . "  static const darkColors = <String, Color>{\n" . $map($t['dark']) . "\n  };\n\n"
            . "  static const radius = <String, double>{\n" . implode("\n", $radius) . "\n  };\n\n"
            . "  static const size = <String, double>{\n" . implode("\n", $size) . "\n  };\n\n"
            . '  static const String fontSans = ' . $q($t['fonts']['sans'][0]) . ";\n"
            . '  static const String fontArabic = ' . $q($t['fonts']['sans'][count($t['fonts']['sans']) - 1]) . ";\n"
            . '  static const String fontHeading = ' . $q($t['fonts']['heading'][0]) . ";\n"
            . '  static const String fontHeadingArabic = ' . $q($t['fonts']['heading'][count($t['fonts']['heading']) - 1]) . ";\n"
            . '  static const String fontMono = ' . $q($t['fonts']['mono'][0]) . ";\n\n"
            . "  static const lightScheme = ColorScheme(\n" . $scheme($t['light'], 'light') . "\n  );\n\n"
            . "  static const darkScheme = ColorScheme(\n" . $scheme($t['dark'], 'dark') . "\n  );\n\n"
            . "  static ThemeData theme(Brightness brightness) => ThemeData(\n"
            . "        useMaterial3: true,\n"
            . "        colorScheme: brightness == Brightness.dark ? darkScheme : lightScheme,\n"
            . "        fontFamily: fontSans,\n"
            . "        fontFamilyFallback: const [fontArabic],\n"
            . "      );\n"
            . "}\n";
    }

    private static function snake(string $id): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '_', strtolower($id));
    }
}
