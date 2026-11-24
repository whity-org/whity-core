<?php

declare(strict_types=1);

namespace Whity\Sdk\Contribute;

/*
 * {@see \Whity\Sdk\PluginMcpInterface} under the name it keeps in 2.0 (SDK
 * 1.161,
 * decision D6). It declares MCP prompts only, which its old name hid; in 2.0
 * `PluginMcpInterface` becomes the one MCP contribution point (tools,
 * toolsets, resources and prompts).
 *
 * `class_alias` makes the two names one type: a plugin may implement either,
 * and `instanceof` answers the same for both. The contract is documented on
 * {@see \Whity\Sdk\PluginMcpInterface}; the old name keeps working through
 * 2.0.x (docs/wiki/Plugin-SDK-2.0-Plan.md).
 */
\class_alias(\Whity\Sdk\PluginMcpInterface::class, PluginMcpPromptsInterface::class);

// Never runs: it tells IDEs and static analysis what the line above defines.
if (false) { // @phpstan-ignore if.alwaysFalse
    /** @see \Whity\Sdk\PluginMcpInterface */
    interface PluginMcpPromptsInterface extends \Whity\Sdk\PluginMcpInterface
    {
    }
}
