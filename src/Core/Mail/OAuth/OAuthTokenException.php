<?php

declare(strict_types=1);

namespace Whity\Core\Mail\OAuth;

use Whity\Core\Mail\MailException;

/**
 * An SMTP OAuth2 token could not be used (WHIT-901). It is a
 * {@see MailException}, so every caller that already treats mail as best-effort
 * keeps working, and the durable queue retries the send as it does for any other
 * transport failure.
 *
 * The message only ever carries the state code. It never holds a token, a
 * secret, or the provider's response.
 */
final class OAuthTokenException extends MailException
{
    public function __construct(public readonly SmtpOAuthState $state)
    {
        parent::__construct('SMTP OAuth2 token unavailable: ' . $state->value);
    }

    public function publicHint(): string
    {
        return $this->state->hint();
    }
}
