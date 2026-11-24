<?php

declare(strict_types=1);

namespace Whity\Sdk\Manifest;

/**
 * The shape of the settings a plugin declares, in `plugin.json`'s `settings`
 * or {@see \Whity\Sdk\Settings\PluginSettingsInterface::getSettings()}
 * (SDK 1.163, decision D4).
 *
 * The same rules the host applies when it registers them: bare keys, one of
 * four types, a `default` that satisfies its own constraints, `options` only on
 * `enum`, `min`/`max` only on `int`, `max_length`/`pattern` only on `string`, a
 * pattern without delimiters, and no secret-shaped fields. The host refuses a
 * setting this refuses; a plugin, the CLI and a builder can check before load.
 *
 * Pure and static: no I/O, no host types.
 */
final class SettingsValidator
{
    public const TYPES = ['string', 'bool', 'int', 'enum'];

    /** Bare key: lowercase, dotted segments. */
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$/';

    /** The canonical key, `<plugin slug>:<bare key>`, is at most this long. */
    public const MAX_KEY_LENGTH = 100;

    /** @var list<string> */
    public const FIELDS = ['type', 'default', 'options', 'min', 'max', 'max_length', 'pattern', 'label', 'description', 'global_only', 'admin'];

    /** Fields that name a credential: settings are readable text, so these are refused. */
    private const SECRET_FIELDS = ['secret', 'encrypted', 'write_only'];

    private function __construct()
    {
    }

    /**
     * @param mixed $settings bare key => declaration
     * @param string|null $slug the plugin's slug, to check the canonical key's length; null skips it
     * @return list<string> each naming its path; empty when valid
     */
    public static function errors(mixed $settings, ?string $slug = null, string $path = 'settings'): array
    {
        if ($settings === null) {
            return [];
        }
        if (!is_array($settings) || ($settings !== [] && array_is_list($settings))) {
            return ["{$path}: must be an object of setting key => declaration"];
        }

        $errors = [];
        foreach ($settings as $key => $declaration) {
            $errors = [...$errors, ...self::settingErrors((string) $key, $declaration, $slug, "{$path}.{$key}")];
        }

        return $errors;
    }

    /** @return list<string> */
    public static function settingErrors(string $key, mixed $declaration, ?string $slug, string $path): array
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            return ["{$path}: key must be lowercase letters, digits and underscores in dotted segments, starting with a letter"];
        }
        if ($slug !== null && strlen($slug . ':' . $key) > self::MAX_KEY_LENGTH) {
            return ["{$path}: key is too long (\"{$slug}:{$key}\" is over " . self::MAX_KEY_LENGTH . ' characters)'];
        }
        if (!is_array($declaration) || ($declaration !== [] && array_is_list($declaration))) {
            return ["{$path}: must be an object"];
        }

        $errors = [];
        foreach (self::SECRET_FIELDS as $field) {
            if (array_key_exists($field, $declaration)) {
                $errors[] = "{$path}.{$field}: settings are readable text, so a secret is never a setting; declare it as a secret field instead";
            }
        }
        foreach (array_keys($declaration) as $field) {
            if (!in_array((string) $field, self::FIELDS, true) && !in_array((string) $field, self::SECRET_FIELDS, true)) {
                $errors[] = "{$path}.{$field}: unknown field; expected one of " . implode(', ', self::FIELDS);
            }
        }

        $type = $declaration['type'] ?? null;
        if (!is_string($type) || !in_array($type, self::TYPES, true)) {
            $errors[] = "{$path}.type: must be one of " . implode(', ', self::TYPES);

            return $errors;
        }

        $options = $declaration['options'] ?? null;
        if ($type === 'enum') {
            if (!is_array($options) || $options === [] || !array_is_list($options)) {
                $errors[] = "{$path}.options: is required for type enum, a non-empty list of allowed values";
                $options = null;
            } else {
                foreach ($options as $i => $option) {
                    if (!is_string($option) || $option === '') {
                        $errors[] = "{$path}.options[{$i}]: must be a non-empty string";
                    }
                }
            }
        } elseif ($options !== null) {
            $errors[] = "{$path}.options: is only for type enum";
        }

        foreach (['min', 'max'] as $field) {
            $value = $declaration[$field] ?? null;
            if ($value === null) {
                continue;
            }
            if ($type !== 'int') {
                $errors[] = "{$path}.{$field}: is only for type int";
            } elseif (!is_int($value)) {
                $errors[] = "{$path}.{$field}: must be a whole number";
            }
        }
        $min = is_int($declaration['min'] ?? null) ? $declaration['min'] : null;
        $max = is_int($declaration['max'] ?? null) ? $declaration['max'] : null;
        if ($type === 'int' && $min !== null && $max !== null && $min > $max) {
            $errors[] = "{$path}.min: must not exceed max";
        }

        $maxLength = $declaration['max_length'] ?? null;
        if ($maxLength !== null) {
            if ($type !== 'string') {
                $errors[] = "{$path}.max_length: is only for type string";
            } elseif (!is_int($maxLength) || $maxLength < 1) {
                $errors[] = "{$path}.max_length: must be a positive whole number";
            }
        }

        $pattern = $declaration['pattern'] ?? null;
        $patternOk = false;
        if ($pattern !== null) {
            if ($type !== 'string') {
                $errors[] = "{$path}.pattern: is only for type string";
            } elseif (!is_string($pattern) || $pattern === '') {
                $errors[] = "{$path}.pattern: must be a non-empty string";
            } elseif (str_contains($pattern, '/')) {
                $errors[] = "{$path}.pattern: write the bare expression (^[a-z]+\$) without delimiters; the host adds them";
            } elseif (@preg_match('/' . $pattern . '/u', '') === false) {
                $errors[] = "{$path}.pattern: is not a valid regular expression";
            } else {
                $patternOk = true;
            }
        }

        if (array_key_exists('description', $declaration) && $declaration['description'] !== null && !is_string($declaration['description'])) {
            $errors[] = "{$path}.description: must be a string";
        }
        foreach (['global_only', 'admin'] as $flag) {
            if (array_key_exists($flag, $declaration) && !is_bool($declaration[$flag])) {
                $errors[] = "{$path}.{$flag}: must be true or false";
            }
        }
        if (array_key_exists('label', $declaration) && !is_array($declaration['label'])) {
            $errors[] = "{$path}.label: must be an object of language => text";
        }

        if (!array_key_exists('default', $declaration)) {
            $errors[] = "{$path}.default: is required; it is the value until someone changes it";

            return $errors;
        }
        $raw = $declaration['default'];
        $default = match (true) {
            is_string($raw) => $raw,
            is_bool($raw) => $raw ? 'true' : 'false',
            is_int($raw) => (string) $raw,
            default => null,
        };
        if ($default === null) {
            $errors[] = "{$path}.default: must be a string, true or false, or a whole number";

            return $errors;
        }

        // The default must satisfy the declaration itself, or the key can never be reset.
        $reason = match ($type) {
            'bool' => $default === 'true' || $default === 'false' ? null : "must be true or false",
            'int' => preg_match('/^-?\d+$/', $default) !== 1 ? 'must be a whole number'
                : ($min !== null && (int) $default < $min ? "must be at least {$min}"
                : ($max !== null && (int) $default > $max ? "must be at most {$max}" : null)),
            'enum' => is_array($options) && !in_array($default, $options, true) ? 'must be one of the options' : null,
            default => (is_int($maxLength) && $maxLength > 0 && mb_strlen(trim($default)) > $maxLength ? "must be at most {$maxLength} characters"
                : ($patternOk && preg_match('/' . $pattern . '/u', trim($default)) !== 1 ? 'must match the pattern' : null)),
        };
        if ($reason !== null) {
            $errors[] = "{$path}.default: {$reason}";
        }

        return $errors;
    }
}
