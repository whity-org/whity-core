<?php

declare(strict_types=1);

namespace Whity\Core\Mail;

/**
 * A mailer that can send the headers a conversation needs (ADR 0016 §4).
 *
 * Separate from {@see Mailer} so nothing that only sends notices has to change.
 * Every core mailer implements both.
 */
interface ThreadedMailer
{
    /** @throws MailException when the message could not be handed over */
    public function sendThreaded(OutgoingEmail $email): void;
}
