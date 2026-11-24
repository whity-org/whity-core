<?php

declare(strict_types=1);

namespace Whity\Sdk;

/**
 * Called once when the plugin is first installed on a deployment, and once
 * when it is upgraded to a new version (SDK 1.152, #1686).
 *
 * Optional. The host calls these after the plugin's migrations have run: when
 * the operator runs `migrate` after a deploy, or turns an uploaded or store
 * plugin on. There is no workspace and no signed-in person: use them for
 * deployment-wide set-up (filling a lookup table, moving data a new version
 * reshapes). Per-workspace set-up belongs in
 * {@see PluginWorkspaceLifecycleInterface::onWorkspaceEnabled()}.
 *
 * Installing, enabling and upgrading stay the operator's: these callbacks are
 * told it happened and are given no way to install, enable or change any
 * plugin, this one included.
 *
 * A call that throws is logged, and the version is NOT recorded, so the same
 * call is made again next time. Make both idempotent. The version recorded is
 * {@see PluginInterface::getVersion()}; a downgrade counts as an upgrade from
 * the newer version to the older one.
 *
 * @deprecated 1.161 Use {@see \Whity\Sdk\Contribute\PluginInstallLifecycleInterface}, the same type under the name it keeps in 2.0 (D6).
 *             This name keeps working through 2.0.x.
 */
interface PluginInstallLifecycleInterface
{
    public function onInstalled(string $version): void;

    public function onUpgraded(string $fromVersion, string $toVersion): void;
}
