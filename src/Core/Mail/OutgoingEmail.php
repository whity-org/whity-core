<?php

declare(strict_types=1);

namespace Whity\Core\Mail;

/**
 * One email with the headers a conversation needs (ADR 0016 §4): its own
 * Message-ID, the thread it answers, and where replies go.
 *
 * The address and id headers are checked for line breaks when built, so an id
 * taken from a customer's email cannot add headers of its own.
 */
final class OutgoingEmail
{
    /**
     * @param list<string> $references Earlier Message-IDs in the thread, oldest first,
     *                                 each with its angle brackets.
     */
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $textBody,
        public readonly ?string $htmlBody = null,
        public readonly ?string $fromName = null,
        public readonly ?string $messageId = null,
        public readonly ?string $inReplyTo = null,
        public readonly array $references = [],
        public readonly ?string $replyTo = null,
        /** @var list<MailAttachment> Files sent with it (WHIT-947), such as a booking's .ics. */
        public readonly array $attachments = [],
    ) {
        foreach ($attachments as $attachment) {
            if (!$attachment instanceof MailAttachment) { // @phpstan-ignore instanceof.alwaysTrue
                throw new MailException('An attachment must be a MailAttachment');
            }
        }
        // The subject and display name are encoded by the transport as they always were.
        foreach ([$to, $messageId, $inReplyTo, $replyTo, ...$references] as $value) {
            if ($value !== null && strpbrk($value, "\r\n") !== false) {
                throw new MailException('A header value contains a line break');
            }
        }
        foreach ([$messageId, $inReplyTo, ...$references] as $id) {
            if ($id !== null && preg_match('/^<[^<>\s]+@[^<>\s]+>$/', $id) !== 1) {
                throw new MailException('A message id is not in <local@host> form');
            }
        }
    }

    /**
     * The threading headers, in the order they are written. `References` is
     * capped to the last 20 ids, as long threads otherwise grow a header some
     * servers refuse.
     *
     * @return list<string>
     */
    public function extraHeaders(): array
    {
        $headers = [];
        if ($this->messageId !== null) {
            $headers[] = 'Message-ID: ' . $this->messageId;
        }
        if ($this->inReplyTo !== null) {
            $headers[] = 'In-Reply-To: ' . $this->inReplyTo;
        }
        if ($this->references !== []) {
            $headers[] = 'References: ' . implode(' ', array_slice($this->references, -20));
        }
        if ($this->replyTo !== null && $this->replyTo !== '') {
            $headers[] = 'Reply-To: ' . $this->replyTo;
        }

        return $headers;
    }
}
