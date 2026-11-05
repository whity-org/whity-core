<?php

declare(strict_types=1);

namespace Whity\Core\Mail\OAuth;

/**
 * The stored OAuth2 connection as read from settings (WHIT-901). The secret and
 * the refresh token stay in their encrypted form here and are decrypted only for
 * the moment a refresh needs them.
 */
final class SmtpOAuthCredentials
{
    public function __construct(
        public readonly string $provider,
        public readonly string $microsoftTenant,
        public readonly string $clientId,
        public readonly ?string $clientSecretCiphertext,
        public readonly ?string $refreshTokenCiphertext,
    ) {
    }

    public function isComplete(): bool
    {
        return SmtpOAuthProvider::isKnown($this->provider)
            && $this->clientId !== ''
            && $this->clientSecretCiphertext !== null
            && $this->refreshTokenCiphertext !== null;
    }

    public function withRefreshTokenCiphertext(string $ciphertext): self
    {
        return new self(
            $this->provider,
            $this->microsoftTenant,
            $this->clientId,
            $this->clientSecretCiphertext,
            $ciphertext,
        );
    }

    /**
     * Ties a cached token and a recorded status to this exact connection. Change
     * any credential and both stop applying, with nothing left to clear by hand.
     * Worked out from ciphertext, so it says nothing about the secrets.
     */
    public function binding(): string
    {
        return hash('sha256', implode("\0", [
            $this->provider,
            $this->microsoftTenant,
            $this->clientId,
            (string) $this->clientSecretCiphertext,
            (string) $this->refreshTokenCiphertext,
        ]));
    }
}
