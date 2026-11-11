<?php

declare(strict_types=1);

namespace Whity\Sdk\Messaging;

/**
 * One message core asks a channel to send (SDK 1.97). Built by core; the
 * channel only reads it.
 */
final class OutboundMessage
{
    /**
     * @param int                   $tenantId          The workspace sending it.
     * @param int                   $conversationId    Core's conversation.
     * @param int                   $messageId         Core's message; stable across retries,
     *                                                 so use it as the provider's idempotency key.
     * @param string                $to                The address on this channel: an email
     *                                                 address, a phone number in E.164, a chat id.
     * @param string|null           $toName            The contact's name, when known.
     * @param string|null           $subject           Only for channels that support one.
     * @param string                $body              Plain text.
     * @param string|null           $htmlBody          Only for channels that support HTML.
     * @param string|null           $template          A {@see MessageTemplate::$name}, when sending one.
     * @param array<string, string> $templateVariables Values for the template's placeholders.
     * @param string|null           $inReplyTo         The provider id of the message this answers.
     * @param list<string>          $references        Earlier provider ids in the thread, oldest first.
     * @param string                $language          `en` or `ar`, for templates in several languages.
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly int $conversationId,
        public readonly int $messageId,
        public readonly string $to,
        public readonly ?string $toName,
        public readonly ?string $subject,
        public readonly string $body,
        public readonly ?string $htmlBody = null,
        public readonly ?string $template = null,
        public readonly array $templateVariables = [],
        public readonly ?string $inReplyTo = null,
        public readonly array $references = [],
        public readonly string $language = 'en',
    ) {
    }
}
