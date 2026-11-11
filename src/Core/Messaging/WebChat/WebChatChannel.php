<?php

declare(strict_types=1);

namespace Whity\Core\Messaging\WebChat;

use Whity\Core\Messaging\MessagingSettings;
use Whity\Sdk\Messaging\ChannelCapabilities;
use Whity\Sdk\Messaging\MessageChannel;
use Whity\Sdk\Messaging\OutboundMessage;
use Whity\Sdk\Messaging\SendOutcome;

/**
 * Core's web chat channel (ADR 0016 §5).
 *
 * There is no provider: the visitor's chat box reads the conversation by
 * polling the public chat routes, so "sending" is the message becoming
 * visible to them. Inbound messages arrive through those routes too, never
 * through the channel webhook, so {@see receive()} reads nothing.
 */
final class WebChatChannel implements MessageChannel
{
    public const KEY = 'webchat';
    public const MAX_BODY = 4000;

    public function __construct(private readonly MessagingSettings $settings)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): array
    {
        return ['en' => 'Web chat', 'ar' => 'الدردشة على الموقع'];
    }

    public function isEnabled(int $tenantId): bool
    {
        return $this->settings->get($tenantId)['webchat_enabled'];
    }

    public function capabilities(): ChannelCapabilities
    {
        return new ChannelCapabilities(maxBodyLength: self::MAX_BODY, readReceipts: false);
    }

    public function send(OutboundMessage $message): SendOutcome
    {
        return SendOutcome::sent(null);
    }

    public function receive(int $tenantId, string $rawBody, array $headers): array
    {
        return [];
    }

    public function templates(int $tenantId): array
    {
        return [];
    }
}
