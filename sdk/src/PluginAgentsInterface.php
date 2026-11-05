<?php

declare(strict_types=1);

namespace Whity\Sdk;

/**
 * Optional (SDK 1.64): agents this plugin declares or customizes.
 *
 * Each agent is a persona in English and Arabic, toolsets, where a person's
 * grant starts, and the host feature flags it turns on ({@see Manifest\AgentFeatures}).
 * It is addressed as `<plugin toolset root>.<key>`, so a plugin can only
 * declare or customize agents under its own root. A declaration that names
 * another plugin's or core's agent, grants a write at Allowed, or asks for a
 * feature the host does not expose is refused with a logged reason; the plugin
 * keeps loading.
 *
 * Customizing an agent means declaring it again under the same key with a
 * higher `version`. The host keeps each workspace's copy of the agent it
 * created, so a new declaration never changes an agent behind the workspace's
 * back, and no declaration reaches another workspace's agents.
 *
 * A declaration grants nothing by itself. An agent acts for the person who
 * uses it, never above their permissions, and its writes follow Ask first.
 */
interface PluginAgentsInterface
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
     *     grant?: array<string, 'off'|'ask'|'allow'>,
     *     features?: array<string, bool>
     * }>
     */
    public function getAgents(): array;
}
