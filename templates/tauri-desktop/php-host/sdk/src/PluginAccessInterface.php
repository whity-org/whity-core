<?php

declare(strict_types=1);

namespace Whity\Sdk;

/**
 * A paid plugin says what a workspace needs to use it (SDK 1.107, WHIT-1125).
 *
 * Optional. A plugin that doesn't implement it, or returns null, is free: any
 * workspace admin may turn it on in Settings › Plugins.
 *
 * A paid plugin returns:
 * - `entitlement`: a boolean entitlement the plugin also declares through
 *   {@see PluginEntitlementsInterface}, named `<plugin slug>.access`. A
 *   workspace whose plan or add-on grants it may turn the plugin on.
 * - `product`: what the workspace buys, `plugin.<store slug>`. Whity Pay sells
 *   it as an add-on plan keyed `plugin.<store slug>.<plan>`.
 * - `trialDays` (optional, 0 to 90): a free trial, once per workspace.
 *
 * The host also lets a workspace use it when the operator made it free for
 * every workspace. When access ends (trial over, add-on or plan gone) the
 * plugin stays on for 7 days of grace, then turns read-only: its pages and
 * reads keep working, writes answer 402 `plugin_read_only`, and its jobs,
 * hooks and notifications pause. Nothing is deleted.
 *
 * On a self-hosted installation a plugin installed from the Whity store also
 * needs the installation's store licence ({@see Licensing\StoreLicence}).
 * Without it the plugin is read-only everywhere.
 */
interface PluginAccessInterface
{
    /**
     * @return array{entitlement: string, product: string, trialDays?: int}|null null when free
     */
    public function getAccess(): ?array;
}
