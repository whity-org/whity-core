<?php

declare(strict_types=1);

namespace Whity\Core\Agent;

use Whity\Core\Container\HostWiredService;
use Whity\Core\RBAC\CorePermissions;
use Whity\Mcp\Tools\CoreToolsets;
use Whity\Mcp\Tools\ToolSurface;
use Whity\Sdk\Manifest\AgentPresetValidator;
use Whity\Sdk\Manifest\PluginManifest;

/**
 * Ready-made agent templates (WHIT-815; docs/design/AGENT-PERSONAS-AND-WHITY-AGENT.md
 * §4.5): a persona, its toolsets and a starting grant, shipped as manifests.
 *
 * Core's presets are JSON files in `database/agent-presets/`, keyed
 * `core.<slug>`. Code plugins add theirs at load (SDK 1.61, WHIT-818), and a
 * workspace's switched-on Whity Builder plugins are read per call from its live
 * manifests, so one workspace's presets never reach another. Both are keyed
 * `<plugin toolset root>.<key>`. A plugin that is off contributes none.
 *
 * A preset is never live: creating an agent from one copies it into the
 * workspace's `agents` table, so a new preset version never changes an agent
 * behind the workspace's back.
 *
 * Core presets are our own data, so a bad one is a build error, not a warning:
 * the constructor throws and {@see \Tests\Unit\Core\Agent\AgentPresetRegistryTest}
 * loads the real directory.
 *
 * A plugin's preset is the plugin author's data: a bad one is dropped with a
 * reason and the plugin keeps loading. Its toolsets must be the plugin's own or
 * core's, its permissions must exist, and it may start a read at Allowed but
 * never a write (owner decision Q6).
 *
 * Host-wired: plugin presets join it at boot, so an improvised instance would
 * silently offer core's presets only.
 */
final class AgentPresetRegistry implements HostWiredService
{
    public const CORE_PREFIX = 'core.';

    /** @var array<string, array<string, mixed>> key => preset */
    private array $presets = [];

    /** @var array<string, array<string, array<string, mixed>>> plugin key => key => preset */
    private array $plugins = [];

    /** @var (callable(int): list<PluginManifest>)|null the workspace's live, switched-on manifests */
    private mixed $manifests = null;

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

    /**
     * Where a workspace's Whity Builder plugins are read from.
     *
     * @param callable(int): list<PluginManifest> $manifests
     */
    public function useManifests(callable $manifests): void
    {
        $this->manifests = $manifests;
    }

    /**
     * A code plugin's presets, replacing any it had. Returns why any was dropped.
     *
     * @param list<mixed>            $declared  what getAgentPresets() returned
     * @param callable(string): bool $permissionExists
     * @return list<string>
     */
    public function registerPlugin(string $pluginKey, array $declared, callable $permissionExists): array
    {
        $root    = CoreToolsets::pluginToolsetIdForClass($pluginKey);
        $refused = [];
        $kept    = [];
        foreach ($declared as $preset) {
            $key = is_array($preset) && is_string($preset['key'] ?? null) ? $preset['key'] : '';
            if (!AgentPresetValidator::isKey($key)) {
                $refused[] = 'a preset has no valid key';
                continue;
            }
            unset($preset['key']);
            [$normal, $errors] = self::normalize($root, $key, $preset, $permissionExists);
            if ($normal === null) {
                array_push($refused, ...$errors);
                continue;
            }
            $kept[$normal['key']] = $normal;
        }
        $this->plugins[$pluginKey] = $kept;

        return $refused;
    }

    public function unregisterPlugin(string $pluginKey): void
    {
        unset($this->plugins[$pluginKey]);
    }

    /**
     * Every preset a workspace can start an agent from, in key order: core's,
     * code plugins', and its own switched-on workspace plugins'. When two
     * declare the same key the first wins.
     *
     * @return list<array<string, mixed>>
     */
    public function forTenant(int $tenantId): array
    {
        $presets = $this->presets;
        foreach ($this->plugins as $declared) {
            $presets += $declared;
        }
        if ($this->manifests !== null && $tenantId !== 0) {
            foreach (($this->manifests)($tenantId) as $manifest) {
                $own  = $manifest->permissions();
                $core = CorePermissions::all();
                $root = CoreToolsets::pluginToolsetId($manifest->name());
                foreach ($manifest->agentPresets() as $key => $preset) {
                    [$normal] = self::normalize(
                        $root,
                        (string) $key,
                        $preset,
                        static fn (string $p): bool => in_array($p, $own, true) || in_array($p, $core, true),
                    );
                    if ($normal !== null) {
                        $presets[$normal['key']] ??= $normal;
                    }
                }
            }
        }
        ksort($presets);

        return array_values($presets);
    }

    /** @return array<string, mixed>|null */
    public function find(int $tenantId, string $key): ?array
    {
        foreach ($this->forTenant($tenantId) as $preset) {
            if ($preset['key'] === $key) {
                return $preset;
            }
        }

        return null;
    }

    /**
     * A plugin's preset in the registry's shape, or why it can't be offered.
     *
     * @param array<mixed>           $preset
     * @param callable(string): bool $permissionExists
     * @return array{0: array<string, mixed>|null, 1: list<string>}
     */
    private static function normalize(string $root, string $key, array $preset, callable $permissionExists): array
    {
        $path   = "agent preset {$key}";
        $errors = [];
        AgentPresetValidator::checkPreset($preset, $path, $errors);
        if ($errors !== []) {
            return [null, $errors];
        }

        foreach ((array) ($preset['toolsets'] ?? []) as $toolset) {
            $id = str_ends_with((string) $toolset, '.*') ? substr((string) $toolset, 0, -2) : (string) $toolset;
            if ($id !== 'core' && !str_starts_with($id, 'core.') && !ToolSurface::isWithin($id, $root)) {
                $errors[] = "{$path}: toolset {$toolset} is another plugin's; use this plugin's ({$root}) or core's";
            }
        }
        foreach (array_keys((array) ($preset['grant'] ?? [])) as $permission) {
            if (!$permissionExists((string) $permission)) {
                $errors[] = "{$path}: permission {$permission} doesn't exist";
            }
        }

        [$fields, $definitionErrors] = AgentDefinition::validate([
            'name'            => $preset['name']['en'] ?? '',
            'name_ar'         => $preset['name']['ar'] ?? '',
            'role'            => $preset['role']['en'] ?? '',
            'role_ar'         => $preset['role']['ar'] ?? '',
            'instructions'    => $preset['instructions']['en'] ?? '',
            'instructions_ar' => $preset['instructions']['ar'] ?? '',
        ] + array_intersect_key($preset, array_flip(['tone', 'language', 'toolsets', 'grant'])));
        foreach ($definitionErrors as $error) {
            $errors[] = "{$path}: {$error}";
        }
        if ($errors !== []) {
            return [null, $errors];
        }

        return [[
            'key'             => $root . '.' . $key,
            'version'         => (int) ($preset['version'] ?? 1),
            'slug'            => $key,
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
        ], []];
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
