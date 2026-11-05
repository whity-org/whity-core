<?php

declare(strict_types=1);

namespace Whity\Sdk\Manifest;

/**
 * Checks what a code plugin's {@see \Whity\Sdk\PluginAgentsInterface} returns
 * (SDK 1.64). The persona, toolsets and grant follow the same rules as an agent
 * preset ({@see AgentPresetValidator}); this adds the feature flags and the
 * scope rule that keeps one plugin's agents its own.
 *
 * Scope: a declaration's key is local. The host addresses it as
 * `<root>.<key>`, where the root is the plugin's own toolset root, so the
 * result can never name another plugin's agent or core's. A toolset must be
 * the plugin's own or core's, as for presets. A declaration with any other key
 * (for example `customizes` or `tenant`, which would reach past the plugin) is
 * refused.
 *
 * Pure: it reads only what it is given and keeps no state, so one plugin's
 * declarations never reach another plugin's or a workspace's agents.
 */
final class AgentDeclarationValidator
{
    private const KEYS = [
        'key', 'version', 'name', 'role', 'instructions', 'tone', 'language', 'toolsets', 'grant', 'features',
    ];

    private function __construct()
    {
    }

    /**
     * @param list<mixed>            $declared        what getAgents() returned
     * @param string                 $root            the plugin's toolset root, e.g. `acme.collections`
     * @param callable(string): bool $permissionExists whether a grant's permission is the plugin's own or core's
     * @return array{0: array<string, array<string, mixed>>, 1: list<string>} accepted agents by `<root>.<key>`, and refusal reasons
     */
    public static function check(array $declared, string $root, callable $permissionExists): array
    {
        $accepted = [];
        $refused  = [];
        foreach ($declared as $index => $agent) {
            if (!is_array($agent)) {
                $refused[] = "agent #{$index}: must be an object";
                continue;
            }
            $key = $agent['key'] ?? null;
            $path = is_string($key) ? "agent {$key}" : "agent #{$index}";
            if (!is_string($key) || !AgentPresetValidator::isKey($key)) {
                $refused[] = "{$path}: key must be lowercase letters, digits and dashes, up to 48";
                continue;
            }
            $unknown = array_diff(array_keys($agent), self::KEYS);
            if ($unknown !== []) {
                $refused[] = "{$path}: unknown key '" . implode("', '", $unknown) . "'";
                continue;
            }

            $preset = $agent;
            unset($preset['key'], $preset['features']);
            $errors = [];
            AgentPresetValidator::checkPreset($preset, $path, $errors);
            AgentFeatures::check($agent['features'] ?? null, $path, $errors);
            foreach ((array) ($preset['toolsets'] ?? []) as $toolset) {
                $id = str_ends_with((string) $toolset, '.*') ? substr((string) $toolset, 0, -2) : (string) $toolset;
                $own = $id === $root || str_starts_with($id, $root . '.');
                if ($id !== 'core' && !str_starts_with($id, 'core.') && !$own) {
                    $errors[] = "{$path}: toolset {$toolset} is another plugin's; use this plugin's ({$root}) or core's";
                }
            }
            foreach ((array) ($preset['grant'] ?? []) as $permission => $mode) {
                if ($mode !== 'off' && !$permissionExists((string) $permission)) {
                    $errors[] = "{$path}: permission {$permission} does not exist";
                }
            }

            if ($errors !== []) {
                array_push($refused, ...$errors);
                continue;
            }
            $accepted["{$root}.{$key}"] = $agent;
        }

        return [$accepted, $refused];
    }
}
