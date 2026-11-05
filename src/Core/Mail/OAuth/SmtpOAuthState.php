<?php

declare(strict_types=1);

namespace Whity\Core\Mail\OAuth;

/**
 * What the operator is told about the SMTP OAuth2 connection (WHIT-901).
 *
 * The value travels on GET /api/v1/settings/mail/status and the Email settings
 * screen words it in English and Arabic. It is an enumerated code on purpose: a
 * provider's own error text never reaches the status, the log or the screen.
 */
enum SmtpOAuthState: string
{
    /** A client id, client secret or refresh token is missing. */
    case NotConnected = 'not_connected';
    /** Everything is stored and nothing has been sent with it yet. */
    case NotYetUsed = 'not_yet_used';
    case Connected = 'connected';
    /** The refresh token was revoked or expired. A person has to reconnect. */
    case NeedsReconnect = 'needs_reconnect';
    /** The provider refused the client id or client secret. */
    case ClientRejected = 'client_rejected';
    /** The token service could not be reached or answered with a temporary error. */
    case TokenServiceUnavailable = 'token_service_unavailable';
    /** A freshly refreshed token was refused by the mail server too. */
    case SmtpRejected = 'smtp_rejected';
    /** The stored credentials do not decrypt, usually a changed encryption key. */
    case CredentialsUnreadable = 'credentials_unreadable';
    /** The SMTP host is not one of the chosen provider's mail servers. */
    case SmtpHostNotAllowed = 'smtp_host_not_allowed';
    /** The connection is unencrypted, which would put the token on the wire. */
    case InsecureConnection = 'insecure_connection';

    /** The plain-words line a person sees when a test send fails in this state. */
    public function hint(): string
    {
        return match ($this) {
            self::NotConnected => 'finish connecting the mail account: the client ID, client secret and refresh token are all needed',
            self::NotYetUsed, self::Connected => 'the mail server rejected the request',
            self::NeedsReconnect => 'the mail account needs reconnecting: its refresh token was revoked or has expired, so paste a new one',
            self::ClientRejected => 'the provider refused the client ID or client secret: check them, as client secrets can expire',
            self::TokenServiceUnavailable => 'could not get a sign-in token from the provider right now: mail will try again',
            self::SmtpRejected => 'the mail server refused the sign-in token: check that the SMTP username is the mailbox that granted access and that SMTP sign-in is turned on for it',
            self::CredentialsUnreadable => 'the stored credentials can no longer be read, usually because the encryption key changed: enter them again',
            self::SmtpHostNotAllowed => 'the SMTP host is not a mail server of the chosen provider',
            self::InsecureConnection => 'OAuth sign-in needs an encrypted connection: choose TLS or SSL',
        };
    }

    /**
     * Failures only a person can fix. Trying again on every send would only hit
     * the provider with credentials it has already refused.
     */
    public function needsPerson(): bool
    {
        return $this === self::NeedsReconnect
            || $this === self::ClientRejected
            || $this === self::CredentialsUnreadable;
    }
}
