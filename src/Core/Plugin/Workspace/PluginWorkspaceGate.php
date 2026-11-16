<?php

declare(strict_types=1);

namespace Whity\Core\Plugin\Workspace;

/**
 * "Is this plugin off for this workspace?" (WHIT-1122, WHIT-1123), asked by
 * every surface that serves a code plugin: MCP tools, prompts and resources,
 * hooks, jobs and schedules, notifications, and the registries plugins fill.
 *
 * The loader implements it, because only the loader knows how a plugin's
 * class, name and toolset relate. A null tenant means the current request's
 * workspace. The system workspace and work with no workspace never see a
 * plugin as off.
 */
interface PluginWorkspaceGate
{
    /** By the plugin's name (getName()), e.g. "FrontOffice". */
    public function isPluginOffForWorkspace(string $pluginName, ?int $tenantId = null): bool;

    /** By the plugin's stable key (its entry class). */
    public function isOffForWorkspace(string $pluginKey, ?int $tenantId = null): bool;

    /**
     * By an MCP toolset id. A toolset inside an off plugin's root toolset is
     * off; core toolsets never are.
     */
    public function isToolsetOffForWorkspace(?string $toolset, ?int $tenantId = null): bool;

    /**
     * By a PHP class or callable owner: true when the class lives in an off
     * plugin's namespace. Used where a registry keeps the object, not the
     * plugin's name.
     */
    public function isClassOffForWorkspace(string $class, ?int $tenantId = null): bool;
}
