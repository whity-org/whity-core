<?php

declare(strict_types=1);

namespace Whity\Sdk\Manifest;

/**
 * Agent presets (SDK 1.61, WHIT-818): ready-made agent templates a plugin
 * ships, from `agentPresets` in `plugin.json` or
 * {@see \Whity\Sdk\PluginAgentPresetsInterface}.
 *
 * ```json
 * "agentPresets": {
 *   "collections-clerk": {
 *     "version": 1,
 *     "name": { "en": "Collections clerk", "ar": "موظف التحصيل" },
 *     "role": { "en": "Follows up unpaid invoices.", "ar": "يتابع الفواتير غير المدفوعة." },
 *     "instructions": { "en": "…", "ar": "…" },
 *     "tone": "formal",
 *     "toolsets": ["core.documents"],
 *     "grant": { "documents:read": "allow", "documents:issue": "ask" }
 *   }
 * }
 * ```
 *
 * The host addresses a preset as `<plugin toolset root>.<key>` and copies it
 * when a workspace creates an agent from it. A name is a role, never a
 * person's. The grant only sets where a person's grant STARTS, and it may
 * start a read at Allowed but never a write: only the person sets a write to
 * Allowed. The host also checks what this file can't know: that toolsets are
 * the plugin's own or core's, and that permissions exist.
 */
final class AgentPresetValidator
{
    public const TONES = ['plain', 'formal', 'brief'];
    public const LANGUAGES = ['auto', 'en', 'ar'];
    public const MODES = ['off', 'ask', 'allow'];

    public const MAX_PRESETS = 50;
    public const NAME_MAX = 60;
    public const ROLE_MAX = 200;
    public const INSTRUCTIONS_MAX = 4000;

    private const KEY_PATTERN = '/^[a-z][a-z0-9-]{0,47}$/';
    private const TOOLSET_PATTERN = '/^[a-z0-9_-]+(\.[a-z0-9_-]+)*(\.\*)?$/';
    private const PERMISSION_PATTERN = '/^[a-z][a-z0-9_]*(:[a-z][a-z0-9_]*)+$/';
    private const KEYS = ['version', 'name', 'role', 'instructions', 'tone', 'language', 'toolsets', 'grant'];

    private function __construct()
    {
    }

    /**
     * Whether a permission only reads: its action is `read` or `view`
     * (`documents:read`, `billing:view`, `documents:read:all`). Judged by name
     * and deliberately strict: a read named otherwise simply starts Ask first.
     */
    public static function isReadPermission(string $permission): bool
    {
        return preg_match('/:(read|view)(:all)?$/', $permission) === 1;
    }

    public static function isKey(string $key): bool
    {
        return preg_match(self::KEY_PATTERN, $key) === 1;
    }

    /**
     * The `agentPresets` section of a manifest: preset key to preset.
     *
     * @param list<string> $errors
     */
    public static function check(mixed $presets, array &$errors): void
    {
        if ($presets === null) {
            return;
        }
        if (!is_array($presets) || (array_is_list($presets) && $presets !== [])) {
            $errors[] = 'agentPresets: must be an object of preset key to preset';
            return;
        }
        if (count($presets) > self::MAX_PRESETS) {
            $errors[] = 'agentPresets: at most ' . self::MAX_PRESETS;
        }
        foreach ($presets as $key => $preset) {
            $path = "agentPresets.{$key}";
            if (!self::isKey((string) $key)) {
                $errors[] = "{$path}: preset keys are lowercase letters, digits and dashes, up to 48";
            }
            self::checkPreset($preset, $path, $errors);
        }
    }

    /**
     * One preset, without its key.
     *
     * @param list<string> $errors
     */
    public static function checkPreset(mixed $preset, string $path, array &$errors): void
    {
        if (!is_array($preset) || (array_is_list($preset) && $preset !== [])) {
            $errors[] = "{$path}: must be an object";
            return;
        }
        foreach (array_keys($preset) as $key) {
            if (!in_array($key, self::KEYS, true)) {
                $errors[] = "{$path}: unknown key '{$key}'";
            }
        }
        if (array_key_exists('version', $preset) && (!is_int($preset['version']) || $preset['version'] < 1)) {
            $errors[] = "{$path}.version: a positive whole number";
        }
        ManifestValidator::checkLabel($preset['name'] ?? null, "{$path}.name", self::NAME_MAX, true, $errors);
        ManifestValidator::checkLabel($preset['role'] ?? null, "{$path}.role", self::ROLE_MAX, true, $errors);
        ManifestValidator::checkLabel($preset['instructions'] ?? null, "{$path}.instructions", self::INSTRUCTIONS_MAX, true, $errors);

        foreach (['tone' => self::TONES, 'language' => self::LANGUAGES] as $key => $allowed) {
            if (array_key_exists($key, $preset) && (!is_string($preset[$key]) || !in_array($preset[$key], $allowed, true))) {
                $errors[] = "{$path}.{$key}: one of " . implode(', ', $allowed);
            }
        }

        if (array_key_exists('toolsets', $preset)) {
            $toolsets = $preset['toolsets'];
            if (!is_array($toolsets) || !array_is_list($toolsets)) {
                $errors[] = "{$path}.toolsets: a list of toolset ids";
            } else {
                foreach ($toolsets as $toolset) {
                    if (!is_string($toolset) || preg_match(self::TOOLSET_PATTERN, $toolset) !== 1) {
                        $errors[] = "{$path}.toolsets: each is a toolset id, or a prefix ending in .*";
                    }
                }
            }
        }

        if (array_key_exists('grant', $preset)) {
            $grant = $preset['grant'];
            if (!is_array($grant) || (array_is_list($grant) && $grant !== [])) {
                $errors[] = "{$path}.grant: an object of permission to off, ask or allow";
                return;
            }
            foreach ($grant as $permission => $mode) {
                $permission = (string) $permission;
                if (preg_match(self::PERMISSION_PATTERN, $permission) !== 1) {
                    $errors[] = "{$path}.grant: '{$permission}' is not a permission";
                } elseif (!is_string($mode) || !in_array($mode, self::MODES, true)) {
                    $errors[] = "{$path}.grant.{$permission}: off, ask or allow";
                } elseif ($mode === 'allow' && !self::isReadPermission($permission)) {
                    $errors[] = "{$path}.grant.{$permission}: can't start Allowed: only reads can; the person sets a write to Allowed themselves";
                }
            }
        }
    }
}
