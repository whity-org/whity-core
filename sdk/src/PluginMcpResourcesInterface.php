<?php

declare(strict_types=1);

namespace Whity\Sdk;

use Whity\Sdk\Mcp\McpToolContext;

/**
 * Optional (SDK 1.80, WHIT-993): MCP resources this plugin offers to AI agents,
 * such as a price list or a policy an agent should read before acting.
 *
 * What a resource returns goes straight into an agent's context, so the host
 * serves a plugin's resources (and its prompts, {@see PluginMcpInterface}) only
 * from a Verified plugin (a live Whity mark on the exact package installed) that
 * the operator has granted **agent context**. An unverified plugin can't be
 * granted, by anybody. That grant is
 * install-wide, made by the operator (the system tenant) alone, audited, and can
 * be taken back at any time; until then the plugin's resources are not listed
 * and can't be read. A workspace can't grant it.
 *
 * Each entry:
 *
 * - `path` (string, required): lowercase letters, digits, `-`, `_`, `.` and `/`,
 *   at most 120 characters. The host publishes it as
 *   `whity-plugin://<plugin slug>/<path>`, so a plugin can't name another's.
 * - `name` (string, required) and `description` (string, at most 500
 *   characters): shown in `resources/list`.
 * - `mimeType` (string, optional): default `text/plain`.
 * - `requiredPermission` (string, required): only callers holding it, in the
 *   workspace the call is in, see or read the resource, and a token limited to
 *   some permissions must include it. A resource without one is refused at load.
 * - `read` (callable, required): `fn(McpToolContext $context): string`. Scope
 *   what you return to `$context->tenantId`, as a tool handler does. At most
 *   1 MB is served; a read that throws answers the caller with an error and is
 *   logged against the plugin.
 *
 * Refused entries are logged and skipped; the plugin keeps loading.
 */
interface PluginMcpResourcesInterface
{
    /**
     * @return list<array{
     *     path: string,
     *     name: string,
     *     description?: string,
     *     mimeType?: string,
     *     requiredPermission: string,
     *     read: callable(McpToolContext): string
     * }>
     */
    public function getMcpResources(): array;
}
