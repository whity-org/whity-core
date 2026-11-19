<?php

declare(strict_types=1);

namespace Whity\Sdk\Context;

use Whity\Sdk\Audit\AuditTrail;
use Whity\Sdk\Documents\PluginDocumentStore;
use Whity\Sdk\Http\Outbound\AddressPolicy;
use Whity\Sdk\Http\Outbound\HttpClient;
use Whity\Sdk\Jobs\JobQueue;
use Whity\Sdk\Notification\Notifier;
use Whity\Sdk\Secrets\WorkspaceSecrets;
use Whity\Sdk\Settings\PluginSettingsStore;
use Whity\Sdk\Storage\PluginFileStore;
use Whity\Sdk\Theme\WorkspaceTheme;

/**
 * One plugin's view of the host, from {@see \Whity\Sdk\PluginContext::for()}
 * (SDK 1.124).
 *
 * Every store acts as this plugin, in the workspace the request or job is
 * running in at the moment of each call. Nothing here takes a workspace. A
 * store used when there is no workspace (a command run for the whole
 * deployment) throws, as each store's own contract says.
 *
 * A service this host doesn't provide throws {@see ServiceUnavailable} when
 * asked for, never earlier, so a plugin that uses only settings loads on a host
 * without, say, documents.
 */
interface PluginScope
{
    /** The plugin's name, as {@see \Whity\Sdk\PluginInterface::getName()} gave it. */
    public function pluginName(): string;

    /** The workspace this call runs in, or null when there is none. */
    public function workspaceId(): ?int;

    /** The settings this plugin declared, in this workspace. */
    public function settings(): PluginSettingsStore;

    /** This plugin's secrets in this workspace: encrypted, never read back on screens. */
    public function secrets(): WorkspaceSecrets;

    /** This plugin's files in this workspace. */
    public function files(): PluginFileStore;

    /** Documents issued as this plugin, with QR verification and tracking. */
    public function documents(): PluginDocumentStore;

    /** Notices to people in this workspace, with the types this plugin declared. */
    public function notify(): Notifier;

    /** This plugin's jobs in this workspace. */
    public function jobs(): JobQueue;

    /** This plugin's audit entries in this workspace. */
    public function audit(): AuditTrail;

    /**
     * An outbound HTTP client: https only, verified TLS, no redirects, timeouts
     * and a size cap. Pass {@see AddressPolicy::publicOnly()} when a person
     * supplied the URL.
     */
    public function http(?AddressPolicy $policy = null): HttpClient;

    /**
     * The workspace's theme, read-only (1.125): what it looks like now and
     * which themes it can choose. A person chooses; a plugin only reads.
     */
    public function themes(): WorkspaceTheme;

    /**
     * Core's items, places, stock and units in this workspace (SDK 1.127, WHIT-1232).
     * Its `stock()` is bound to this plugin, the only way a plugin writes stock.
     */
    public function inventory(): InventoryScope;
}
