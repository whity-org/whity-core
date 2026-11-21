<?php

declare(strict_types=1);

namespace Whity\Core\Theme;

/**
 * Checks a theme file against the closed format (TH.1, `schemas/theme/v1.json`,
 * `docs/design/30-themes.md` section 4). A theme is choices, never CSS: every
 * value is an enum, a `#rrggbb` colour, an id, a version or short plain text,
 * and no key outside the format is allowed at any level.
 *
 * ThemeValidatorTest keeps these lists in step with the JSON Schema, so the
 * schema published in `@whity/tokens` and this check never disagree.
 */
final class ThemeValidator
{
    public const SCHEMA_VERSION = 1;
    public const SCHEMES = ['calm', 'tonal', 'vibrant', 'expressive', 'monochrome'];
    public const NEUTRALS = ['pure', 'warm', 'cool', 'tinted'];
    public const CONTRASTS = ['standard', 'high'];
    public const SCALES = ['standard', 'roomy', 'large'];
    public const TITLE_WEIGHTS = [500, 600];
    public const EMPHASES = ['quiet', 'expressive'];
    public const SHAPES = ['square', 'crisp', 'whity', 'soft', 'round', 'pebble', 'cut'];
    public const MORPHS = ['off', 'on'];
    public const DENSITIES = ['compact', 'standard', 'comfortable'];
    public const ELEVATIONS = ['plane', 'soft', 'flat', 'outline'];
    public const FLOATS = ['light', 'standard', 'strong'];
    public const MOTIONS = ['calm', 'standard', 'expressive', 'brisk'];
    public const ACCENT_USES = ['title', 'title-and-headings'];
    public const TITLE_FACES = ['readex', 'noto'];

    public const ID_RE = '/^[a-z0-9]+(-[a-z0-9]+)*\.[a-z0-9]+(-[a-z0-9]+)*$/';
    public const VERSION_RE = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/';
    public const HEX_RE = '/^#[0-9a-fA-F]{6}$/';
    public const ROLE_RE = '/^[a-z0-9]+(-[a-z0-9]+)*$/';
    private const MAX_OVERRIDES = 64;

    /**
     * @param mixed $theme The decoded JSON (associative arrays).
     * @return list<string> Problems in plain words, empty when the theme is valid.
     */
    public static function validate(mixed $theme): array
    {
        $errors = [];
        if (!self::isObject($theme)) {
            return ['A theme must be a JSON object.'];
        }

        self::onlyKeys($theme, ['$schema', 'schemaVersion', 'id', 'version', 'name', 'description', 'author', 'colour', 'type', 'shape', 'density', 'elevation', 'motion', 'documents'], 'the theme', $errors);

        if (($theme['schemaVersion'] ?? null) !== self::SCHEMA_VERSION) {
            $errors[] = is_int($theme['schemaVersion'] ?? null) && $theme['schemaVersion'] > self::SCHEMA_VERSION
                ? 'This theme needs a newer Whity. Update Whity to use it.'
                : 'schemaVersion must be 1.';
        }
        if (array_key_exists('$schema', $theme) && (!is_string($theme['$schema']) || strlen($theme['$schema']) > 200)) {
            $errors[] = '$schema must be a short text.';
        }
        if (!is_string($theme['id'] ?? null) || preg_match(self::ID_RE, $theme['id']) !== 1 || strlen($theme['id']) > 100) {
            $errors[] = 'id must look like vendor.name, in lowercase letters, digits and hyphens.';
        }
        if (!is_string($theme['version'] ?? null) || preg_match(self::VERSION_RE, $theme['version']) !== 1) {
            $errors[] = 'version must be a version number like 1.0.0.';
        }
        self::bilingual($theme['name'] ?? null, 'name', true, $errors);
        if (array_key_exists('description', $theme)) {
            self::bilingual($theme['description'], 'description', false, $errors);
        }
        if (array_key_exists('author', $theme)) {
            if (!self::isObject($theme['author'])) {
                $errors[] = 'author must be an object with a name.';
            } else {
                self::onlyKeys($theme['author'], ['name'], 'author', $errors);
                self::text($theme['author']['name'] ?? null, 'author.name', $errors);
            }
        }

        if (array_key_exists('colour', $theme)) {
            self::colour($theme['colour'], $errors);
        }
        if (array_key_exists('type', $theme)) {
            $type = $theme['type'];
            if (!self::isObject($type)) {
                $errors[] = 'type must be an object.';
            } else {
                self::onlyKeys($type, ['family', 'headingFamily', 'scale', 'titleWeight', 'emphasis'], 'type', $errors);
                self::oneOf($type, 'family', ThemeFonts::ids(), 'type.family', $errors);
                self::oneOf($type, 'headingFamily', [...ThemeFonts::ids(), 'same'], 'type.headingFamily', $errors);
                self::oneOf($type, 'scale', self::SCALES, 'type.scale', $errors);
                self::oneOf($type, 'titleWeight', self::TITLE_WEIGHTS, 'type.titleWeight', $errors);
                self::oneOf($type, 'emphasis', self::EMPHASES, 'type.emphasis', $errors);
            }
        }
        if (array_key_exists('shape', $theme)) {
            self::group($theme['shape'], 'shape', ['preset' => self::SHAPES, 'morph' => self::MORPHS], $errors);
        }
        if (array_key_exists('density', $theme) && !in_array($theme['density'], self::DENSITIES, true)) {
            $errors[] = 'density must be one of: ' . implode(', ', self::DENSITIES) . '.';
        }
        if (array_key_exists('elevation', $theme)) {
            self::group($theme['elevation'], 'elevation', ['style' => self::ELEVATIONS, 'float' => self::FLOATS], $errors);
        }
        if (array_key_exists('motion', $theme)) {
            self::group($theme['motion'], 'motion', ['preset' => self::MOTIONS], $errors);
        }
        if (array_key_exists('documents', $theme)) {
            $docs = $theme['documents'];
            if (!self::isObject($docs)) {
                $errors[] = 'documents must be an object.';
            } else {
                self::onlyKeys($docs, ['accentUse', 'titleFace', 'largePrint'], 'documents', $errors);
                self::oneOf($docs, 'accentUse', self::ACCENT_USES, 'documents.accentUse', $errors);
                self::oneOf($docs, 'titleFace', self::TITLE_FACES, 'documents.titleFace', $errors);
                if (array_key_exists('largePrint', $docs) && !is_bool($docs['largePrint'])) {
                    $errors[] = 'documents.largePrint must be true or false.';
                }
            }
        }

        return $errors;
    }

    /**
     * Checks a workspace's adjustments on their own (the
     * `branding_theme_overrides` setting): colour overrides
     * `{light: {role: #hex}, dark: {...}}`, and since TH.15 the free tweaks
     * `shape`, `density` and `font`, each from its closed list (ThemeTweaks).
     *
     * @return list<string>
     */
    public static function validateOverrides(mixed $overrides): array
    {
        $errors = [];
        if (!self::isObject($overrides)) {
            self::overrides($overrides, 'overrides', $errors);

            return $errors;
        }
        self::onlyKeys($overrides, ['light', 'dark', ...ThemeTweaks::KEYS], 'overrides', $errors);
        self::oneOf($overrides, 'shape', self::SHAPES, 'overrides.shape', $errors);
        self::oneOf($overrides, 'density', self::DENSITIES, 'overrides.density', $errors);
        self::oneOf($overrides, 'font', ThemeFonts::ids(), 'overrides.font', $errors);
        self::overrides(array_intersect_key($overrides, ['light' => true, 'dark' => true]), 'overrides', $errors);

        return $errors;
    }

    /** @param list<string> $errors */
    private static function colour(mixed $colour, array &$errors): void
    {
        if (!self::isObject($colour)) {
            $errors[] = 'colour must be an object.';
            return;
        }
        self::onlyKeys($colour, ['seeds', 'scheme', 'neutral', 'contrast', 'overrides'], 'colour', $errors);
        if (array_key_exists('seeds', $colour)) {
            if (!self::isObject($colour['seeds'])) {
                $errors[] = 'colour.seeds must be an object.';
            } else {
                self::onlyKeys($colour['seeds'], ['primary', 'secondary', 'tertiary'], 'colour.seeds', $errors);
                foreach ($colour['seeds'] as $name => $value) {
                    if (!is_string($value) || preg_match(self::HEX_RE, $value) !== 1) {
                        $errors[] = "colour.seeds.{$name} must be a #rrggbb colour.";
                    }
                }
            }
        }
        self::oneOf($colour, 'scheme', self::SCHEMES, 'colour.scheme', $errors);
        self::oneOf($colour, 'neutral', self::NEUTRALS, 'colour.neutral', $errors);
        self::oneOf($colour, 'contrast', self::CONTRASTS, 'colour.contrast', $errors);
        if (array_key_exists('overrides', $colour)) {
            self::overrides($colour['overrides'], 'colour.overrides', $errors);
        }
    }

    /** @param list<string> $errors */
    private static function overrides(mixed $overrides, string $where, array &$errors): void
    {
        if (!self::isObject($overrides)) {
            $errors[] = "{$where} must be an object with light and dark.";
            return;
        }
        self::onlyKeys($overrides, ['light', 'dark'], $where, $errors);
        foreach (['light', 'dark'] as $mode) {
            if (!array_key_exists($mode, $overrides)) {
                continue;
            }
            $map = $overrides[$mode];
            if ($map === []) {
                continue;
            }
            if (!self::isObject($map)) {
                $errors[] = "{$where}.{$mode} must map colour roles to #rrggbb colours.";
                continue;
            }
            if (count($map) > self::MAX_OVERRIDES) {
                $errors[] = "{$where}.{$mode} has too many overrides.";
            }
            foreach ($map as $role => $value) {
                $role = (string) $role;
                if (preg_match(self::ROLE_RE, $role) !== 1 || !in_array($role, ThemeCompiler::overridableRoles(), true)) {
                    $errors[] = "{$where}.{$mode}: \"" . substr($role, 0, 40) . '" is not a colour a theme can set.';
                } elseif (!is_string($value) || preg_match(self::HEX_RE, $value) !== 1) {
                    $errors[] = "{$where}.{$mode}.{$role} must be a #rrggbb colour.";
                }
            }
        }
    }

    /**
     * @param array<string, list<string>> $enums
     * @param list<string> $errors
     */
    private static function group(mixed $value, string $where, array $enums, array &$errors): void
    {
        if (!self::isObject($value)) {
            $errors[] = "{$where} must be an object.";
            return;
        }
        self::onlyKeys($value, array_keys($enums), $where, $errors);
        foreach ($enums as $key => $allowed) {
            self::oneOf($value, $key, $allowed, "{$where}.{$key}", $errors);
        }
    }

    /**
     * @param array<string, mixed> $object
     * @param list<string|int> $allowed
     * @param list<string> $errors
     */
    private static function oneOf(array $object, string $key, array $allowed, string $where, array &$errors): void
    {
        if (array_key_exists($key, $object) && !in_array($object[$key], $allowed, true)) {
            $errors[] = "{$where} must be one of: " . implode(', ', array_map('strval', $allowed)) . '.';
        }
    }

    /**
     * @param array<string, mixed> $object
     * @param list<string> $allowed
     * @param list<string> $errors
     */
    private static function onlyKeys(array $object, array $allowed, string $where, array &$errors): void
    {
        foreach (array_keys($object) as $key) {
            if (!in_array($key, $allowed, true)) {
                $errors[] = "{$where} has an unknown key \"" . substr((string) $key, 0, 40) . '".';
            }
        }
    }

    /** @param list<string> $errors */
    private static function bilingual(mixed $value, string $where, bool $required, array &$errors): void
    {
        if (!self::isObject($value)) {
            if ($required || $value !== null) {
                $errors[] = "{$where} must have an English text (en), and may have an Arabic one (ar).";
            }
            return;
        }
        self::onlyKeys($value, ['en', 'ar'], $where, $errors);
        self::text($value['en'] ?? null, "{$where}.en", $errors);
        if (array_key_exists('ar', $value)) {
            self::text($value['ar'], "{$where}.ar", $errors);
        }
    }

    /** @param list<string> $errors */
    private static function text(mixed $value, string $where, array &$errors): void
    {
        if (!is_string($value) || trim($value) === '' || mb_strlen($value) > 200 || preg_match('/[<>{}]/', $value) === 1) {
            $errors[] = "{$where} must be plain text of 1 to 200 characters.";
        }
    }

    /** @phpstan-assert-if-true array<string, mixed> $value */
    private static function isObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }
}
