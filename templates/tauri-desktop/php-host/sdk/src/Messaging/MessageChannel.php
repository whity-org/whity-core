<?php

declare(strict_types=1);

namespace Whity\Sdk\Messaging;

/**
 * A way of talking to customers: email, web chat, WhatsApp, SMS (SDK 1.97,
 * ADR 0016).
 *
 * Core owns conversations, the staff inbox, metering, agents and the send API.
 * A channel owns only its provider: how a message leaves, how an inbound
 * request is proven authentic and read, and what the provider can do. A store
 * plugin adds WhatsApp or SMS by returning one of these from
 * {@see \Whity\Sdk\PluginMessageChannelsInterface}; nothing in core changes.
 *
 * Credentials are the channel's own business. Keep the installer's provider
 * account in the plugin's settings ({@see \Whity\Sdk\Settings\PluginSettings});
 * core never sees a provider token.
 *
 * Every method is called with a workspace id and must act only for that
 * workspace.
 */
interface MessageChannel
{
    /**
     * Bare key, `[a-z][a-z0-9_]*`, at most 64 characters. The host stores a
     * plugin's channel as `<plugin>:<key>`; `email` and `webchat` belong to core.
     */
    public function key(): string;

    /**
     * The name staff see, in English and Arabic.
     *
     * @return array{en: string, ar?: string}
     */
    public function label(): array;

    /** Whether this workspace has the channel set up and turned on. */
    public function isEnabled(int $tenantId): bool;

    public function capabilities(): ChannelCapabilities;

    /**
     * Hand one message to the provider.
     *
     * Called from a background job, never during a request. Must not throw for
     * an ordinary provider failure: return {@see SendOutcome::failed()} and say
     * whether trying again could help. A throw is treated as a retryable
     * failure and logged against the plugin.
     */
    public function send(OutboundMessage $message): SendOutcome;

    /**
     * Read one request the provider sent to this channel's webhook,
     * `POST /api/public/messages/inbound/{channel}/{workspaceSlug}`.
     *
     * Verify the provider's signature first, with the workspace's own secret,
     * and return an empty list for anything not authentic. The host passes the
     * raw body untouched and does not parse it. One request may carry several
     * messages and status updates.
     *
     * @param array<string, string> $headers Lower-cased header names.
     *
     * @return list<InboundMessage|DeliveryStatusUpdate>
     */
    public function receive(int $tenantId, string $rawBody, array $headers): array;

    /**
     * Approved templates this workspace may send, for channels that need them
     * outside a reply window. An empty list for channels that do not.
     *
     * @return list<MessageTemplate>
     */
    public function templates(int $tenantId): array;
}
