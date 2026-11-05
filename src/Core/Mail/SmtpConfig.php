<?php

declare(strict_types=1);

namespace Whity\Core\Mail;

/**
 * Immutable SMTP connection config for {@see SmtpMailer} (WC-email).
 *
 * `encryption`:
 *   - 'none' — plain TCP (e.g. Mailpit/MailHog on :1025, or an internal relay).
 *   - 'tls'  — plain TCP then STARTTLS upgrade (submission on :587).
 *   - 'ssl'  — implicit TLS from connect (SMTPS on :465).
 *
 * Auth is attempted only when `username` is non-empty. The password is a
 * secret supplied by the caller — decrypted from the encrypted-secret store by
 * {@see MailerFactory}, never held in plaintext settings.
 *
 * With an `accessToken` the session signs in with AUTH XOAUTH2 instead (WHIT-901).
 * `username` is then the mailbox the token belongs to, falling back to the from
 * address.
 */
final class SmtpConfig
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $fromEmail,
        public readonly string $fromName = '',
        public readonly string $encryption = 'tls',
        public readonly ?string $username = null,
        #[\SensitiveParameter] public readonly ?string $password = null,
        public readonly int $timeoutSeconds = 10,
        /** HELO/EHLO domain the client announces; kept generic by default. */
        public readonly string $ehloDomain = 'localhost',
        #[\SensitiveParameter] public readonly ?string $accessToken = null,
    ) {
    }

    public function withAccessToken(#[\SensitiveParameter] string $accessToken): self
    {
        return new self(
            $this->host,
            $this->port,
            $this->fromEmail,
            $this->fromName,
            $this->encryption,
            $this->username,
            $this->password,
            $this->timeoutSeconds,
            $this->ehloDomain,
            $accessToken,
        );
    }

    public function usesXoauth2(): bool
    {
        return $this->accessToken !== null;
    }

    /** @return array<string, mixed> The config with its secrets left out of any dump. */
    public function __debugInfo(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'encryption' => $this->encryption,
            'username' => $this->username,
            'password' => $this->password === null ? null : '[redacted]',
            'accessToken' => $this->accessToken === null ? null : '[redacted]',
        ];
    }

    public function usesImplicitTls(): bool
    {
        return $this->encryption === 'ssl';
    }

    public function usesStartTls(): bool
    {
        return $this->encryption === 'tls';
    }

    public function hasAuth(): bool
    {
        return $this->username !== null && $this->username !== '';
    }
}
