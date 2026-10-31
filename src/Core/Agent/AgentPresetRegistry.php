<?php

declare(strict_types=1);

namespace Whity\Core\Agent;

/**
 * Ready-made agent templates (WHIT-815; docs/design/AGENT-PERSONAS-AND-WHITY-AGENT.md
 * §4.5): a persona, its toolsets and a starting grant, shipped as manifests.
 *
 * Core's presets are JSON files in `database/agent-presets/`, keyed
 * `core.<slug>`. Plugins and Whity Builder manifests add theirs later
 * (WHIT-818), under their own plugin key. Read-only once loaded, like
 * {@see \Whity\Mcp\Tools\CoreToolsets}, so it is safe to share across requests
 * in a worker.
 *
 * A preset is never live: creating an agent from one copies it into the
 * workspace's `agents` table, so a new preset version never changes an agent
 * behind the workspace's back.
 *
 * Core presets are our own data, so a bad one is a build error, not a warning:
 * the constructor throws and {@see \Tests\Unit\Core\Agent\AgentPresetRegistryTest}
 * loads the real directory.
 */
final class AgentPresetRegistry
{
    public const CORE_PREFIX = 'core.';

    /** @var array<string, array<string, mixed>> key => preset */
    private array $presets = [];

    public function __construct(?string $directory = null)
    {
        $directory ??= dirname(__DIR__, 3) . '/database/agent-presets';
        $files = glob(rtrim($directory, '/\\') . '/*.json') ?: [];
        sort($files);
        foreach ($files as $file) {
            $preset = self::load($file);
            $this->presets[$preset['key']] = $preset;
        }
    }

    /** @return list<array<string, mixed>> every preset, in key order */
    public function all(): array
    {
        $presets = $this->presets;
        ksort($presets);

        return array_values($presets);
    }

    /** @return array<string, mixed>|null */
    public function get(string $key): ?array
    {
        return $this->presets[$key] ?? null;
    }

    /** @return array<string, mixed> */
    private static function load(string $file): array
    {
        $name = basename($file, '.json');
        $raw  = json_decode((string) file_get_contents($file), true);
        if (!is_array($raw)) {
            throw new \LogicException("Agent preset {$name}: not a JSON object");
        }

        $key     = $raw['key'] ?? null;
        $version = $raw['version'] ?? null;
        if ($key !== self::CORE_PREFIX . $name) {
            throw new \LogicException("Agent preset {$name}: key must be " . self::CORE_PREFIX . $name);
        }
        if (!is_int($version) || $version < 1) {
            throw new \LogicException("Agent preset {$name}: version must be a positive integer");
        }

        [$fields, $errors] = AgentDefinition::validate(array_diff_key($raw, ['key' => true, 'version' => true]));
        foreach (['name_ar', 'role', 'role_ar', 'instructions', 'instructions_ar'] as $required) {
            if (($fields[$required] ?? '') === '') {
                $errors[] = "{$required} is required for a core preset";
            }
        }
        if ($errors !== []) {
            throw new \LogicException("Agent preset {$name}: " . implode('; ', $errors));
        }

        return [
            'key'             => $key,
            'version'         => $version,
            'slug'            => $name,
            'name'            => $fields['name'],
            'name_ar'         => $fields['name_ar'],
            'role'            => $fields['role'],
            'role_ar'         => $fields['role_ar'],
            'instructions'    => $fields['instructions'],
            'instructions_ar' => $fields['instructions_ar'],
            'tone'            => $fields['tone'] ?? 'plain',
            'language'        => $fields['language'] ?? 'auto',
            'toolsets'        => $fields['toolsets'] ?? [],
            'grant'           => $fields['grant'] ?? [],
        ];
    }
}
