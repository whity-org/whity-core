<?php

declare(strict_types=1);

namespace Whity\Sdk;

/**
 * Optional (SDK 1.61, WHIT-818): ready-made agent templates this plugin ships,
 * such as a Collections clerk. A workspace admin can create an agent from one;
 * the agent is a copy, so a later preset version never changes it.
 *
 * Each preset has a `key` (lowercase letters, digits and dashes), which the
 * host prefixes with the plugin's toolset root, and the shape
 * {@see Manifest\AgentPresetValidator} checks. Toolsets must be the plugin's
 * own or core's, and permissions must exist. A preset may start a read at
 * Allowed but never a write: only the person using the agent sets a write to
 * Allowed. A bad preset is dropped with a logged reason; the plugin keeps
 * loading.
 *
 * A preset grants nothing by itself. An agent acts for the person who uses it,
 * never above their permissions, and its writes follow Ask first.
 */
interface PluginAgentPresetsInterface
{
    /**
     * @return list<array{
     *     key: string,
     *     version?: int,
     *     name: array{en: string, ar: string},
     *     role: array{en: string, ar: string},
     *     instructions: array{en: string, ar: string},
     *     tone?: 'plain'|'formal'|'brief',
     *     language?: 'auto'|'en'|'ar',
     *     toolsets?: list<string>,
     *     grant?: array<string, 'off'|'ask'|'allow'>
     * }>
     */
    public function getAgentPresets(): array;
}
