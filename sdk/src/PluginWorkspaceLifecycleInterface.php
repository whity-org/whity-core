<?php

declare(strict_types=1);

namespace Whity\Sdk;

/**
 * Called when a workspace turns the plugin on or off (SDK 1.107, WHIT-1125).
 *
 * Optional. The host calls these after the switch is saved, with the workspace
 * in scope, so the plugin's own services and core's SDK services act on that
 * workspace. Use `onWorkspaceEnabled()` to set the workspace up: seed starter
 * records, templates or settings. It must be idempotent, because a workspace
 * can turn a plugin off and on again, and must never overwrite what the
 * workspace changed. `onWorkspaceDisabled()` must not delete data: turning a
 * plugin back on brings everything back.
 *
 * A throw is logged and doesn't undo the switch.
 */
interface PluginWorkspaceLifecycleInterface
{
    public function onWorkspaceEnabled(int $tenantId): void;

    public function onWorkspaceDisabled(int $tenantId): void;
}
