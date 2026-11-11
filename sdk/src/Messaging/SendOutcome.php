<?php

declare(strict_types=1);

namespace Whity\Sdk\Messaging;

/**
 * What happened when a channel tried to send (SDK 1.97).
 */
final class SendOutcome
{
    private function __construct(
        public readonly bool $sent,
        public readonly ?string $externalId,
        public readonly ?string $reason,
        public readonly bool $retryable,
    ) {
    }

    /**
     * The provider accepted it.
     *
     * @param string|null $externalId The provider's id for the message, used to
     *                                match delivery updates and replies to it.
     */
    public static function sent(?string $externalId = null): self
    {
        return new self(true, $externalId, null, false);
    }

    /**
     * It was not sent.
     *
     * @param string $reason    Shown to staff on the message. Plain words, no secrets.
     * @param bool   $retryable True when trying again later could work (timeouts,
     *                          rate limits); false for a bad address or a refused template.
     */
    public static function failed(string $reason, bool $retryable = false): self
    {
        return new self(false, null, mb_substr($reason, 0, 500), $retryable);
    }
}
