<?php

declare(strict_types=1);

namespace Whity\Core\Mail;

/**
 * What one email needs beyond its content (E2): a brand other than the
 * workspace's, files sent with it, where replies go and the thread it belongs
 * to. Everything is optional; {@see BrandedEmailSender} uses the workspace's
 * brand and sends a plain message when nothing here is set.
 */
final class EmailOptions
{
    /**
     * @param EmailBranding|null    $branding   Draw the email in this brand instead of the workspace's
     *                                          (an invitation names the inviting workspace).
     * @param list<MailAttachment>  $attachments Files sent with it, such as a booking's `.ics`.
     * @param list<string>          $references Earlier Message-IDs in the thread, oldest first.
     */
    public function __construct(
        public readonly ?EmailBranding $branding = null,
        public readonly array $attachments = [],
        public readonly ?string $replyTo = null,
        public readonly ?string $messageId = null,
        public readonly ?string $inReplyTo = null,
        public readonly array $references = [],
    ) {
    }

    /** Whether anything here needs a {@see ThreadedMailer}. */
    public function needsHeaders(): bool
    {
        return $this->attachments !== [] || $this->replyTo !== null || $this->messageId !== null
            || $this->inReplyTo !== null || $this->references !== [];
    }
}
