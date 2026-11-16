<?php

declare(strict_types=1);

namespace Whity\Sdk\Messaging;

/**
 * One message a customer sent, read from the provider by a channel's
 * {@see MessageChannel::receive()} (SDK 1.97).
 *
 * Core files it: it joins the conversation that `inReplyTo`, `references` or
 * `threadKey` points at, else the contact's open conversation on the channel,
 * else a new one. Core stores the body as text and never runs anything in it.
 */
final class InboundMessage
{
    /**
     * @param string       $from        The sender's address on this channel.
     * @param string|null  $fromName    Their name, when the provider gives one.
     * @param string       $body        Plain text.
     * @param string|null  $externalId  The provider's id; a repeat delivery with the
     *                                  same id is ignored, so webhooks may retry.
     * @param string|null  $subject     For channels with subjects.
     * @param string|null  $htmlBody    For channels with HTML; core sanitises it before display.
     * @param string|null  $inReplyTo   The provider id this message answers.
     * @param list<string> $references  Earlier provider ids in the thread.
     * @param string|null  $threadKey   A provider's own conversation id, when it has one.
     * @param string|null  $receivedAt  When the provider received it, ISO 8601. Null: now.
     * @param int          $attachmentsNotShown Attachments core did not keep, so staff are told.
     */
    public function __construct(
        public readonly string $from,
        public readonly ?string $fromName,
        public readonly string $body,
        public readonly ?string $externalId = null,
        public readonly ?string $subject = null,
        public readonly ?string $htmlBody = null,
        public readonly ?string $inReplyTo = null,
        public readonly array $references = [],
        public readonly ?string $threadKey = null,
        public readonly ?string $receivedAt = null,
        public readonly int $attachmentsNotShown = 0,
    ) {
        if (trim($from) === '') {
            throw new \InvalidArgumentException('An inbound message needs a sender address.');
        }
    }
}
