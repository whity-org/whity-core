<?php

declare(strict_types=1);

namespace Whity\Core\Mail;

/**
 * The mail server refused an XOAUTH2 token (WHIT-901). Nothing has been sent at
 * this point, because AUTH comes before MAIL FROM, so one retry with a fresh token
 * can never deliver a message twice.
 */
final class SmtpAuthRejectedException extends MailException
{
    public function publicHint(): string
    {
        return OAuth\SmtpOAuthState::SmtpRejected->hint();
    }
}
