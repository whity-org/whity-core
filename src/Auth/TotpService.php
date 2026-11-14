<?php

declare(strict_types=1);

namespace Whity\Auth;

use OTPHP\TOTP;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Security\EncryptionKeyGuard;

/**
 * TOTP (Time-based One-Time Password) Service
 *
 * Handles TOTP secret generation, encryption/decryption, validation, and QR code URL generation
 * for two-factor authentication.
 *
 * Uses spomky-labs/otphp for TOTP generation and validation (RFC 6238).
 * Secrets are stored through the shared key ring ({@see EncryptedSecretStore}),
 * so they follow the same key id, current key and retired keys as every other
 * stored secret. Values written before that (a bare ciphertext with no key-id
 * prefix) still decrypt under the current or any retired key; callers move them
 * onto the current key with {@see rewrapForCurrentKey()}.
 */
class TotpService
{
    /**
     * Development-only fallback for the secret-encryption key.
     *
     * This is the single source of truth for the dev default. Every code path that needs a
     * TotpService MUST derive its key via {@see TotpService::resolveEncryptionKey()} so the
     * store/confirm path and the login-validation path can never diverge (see WC-95). Outside
     * `APP_ENV=development`, a missing/empty ENCRYPTION_KEY fails fast rather than falling back
     * to this guessable value.
     */
    private const DEV_ENCRYPTION_KEY = 'dev_secret';

    private EncryptedSecretStore $store;

    /**
     * @param string|EncryptedSecretStore $keys The key ring, or a single key (used as key id `v1`).
     */
    public function __construct(string|EncryptedSecretStore $keys)
    {
        $this->store = $keys instanceof EncryptedSecretStore
            ? $keys
            : new EncryptedSecretStore(['v1' => $keys], 'v1');
    }

    /**
     * Build the service on the platform key ring from the environment.
     *
     * The current key comes from {@see resolveEncryptionKey()} (so the development
     * fallback and the non-development guard are unchanged); the key id and the
     * retired keys come from `ENCRYPTION_KEY_ID` / `ENCRYPTION_KEYS_RETIRED`, read
     * by {@see EncryptedSecretStore::fromEnv()}.
     *
     * @param array<string, mixed>|null $env Environment map; defaults to $_ENV.
     */
    public static function fromEnv(?array $env = null): self
    {
        $env ??= $_ENV;
        $env['ENCRYPTION_KEY'] = self::resolveEncryptionKey($env);

        return new self(EncryptedSecretStore::fromEnv($env));
    }

    /**
     * Resolve the TOTP secret-encryption key from the environment.
     *
     * Single source of truth for the encryption key across every 2FA code path (setup/confirm,
     * login TOTP validation, backup-code/version flows). Mirrors how JWT_SECRET is handled in the
     * application bootstrap: a missing/short ENCRYPTION_KEY is fatal outside development (delegated
     * to {@see EncryptionKeyGuard}, WC-security-audit — the >= 32 char convention was documented in
     * .env.example but never actually enforced), and only `APP_ENV=development` may fall back to the
     * well-known dev default.
     *
     * @param array<string, mixed>|null $env Environment map; defaults to $_ENV.
     * @return string The resolved encryption key.
     * @throws \RuntimeException If ENCRYPTION_KEY is missing/too short in a non-development environment.
     */
    public static function resolveEncryptionKey(?array $env = null): string
    {
        $env ??= $_ENV;
        $appEnv = (string) ($env['APP_ENV'] ?? 'production');
        $key = (string) ($env['ENCRYPTION_KEY'] ?? '');

        if ($key === '' && $appEnv === 'development') {
            return self::DEV_ENCRYPTION_KEY;
        }

        EncryptionKeyGuard::assertValid($key === '' ? null : $key, $appEnv);

        return $key;
    }

    /**
     * Generate a new TOTP secret
     *
     * @return string Base32-encoded TOTP secret (typical length 16-32 chars)
     */
    public function generateSecret(): string
    {
        // TOTP::create generates a new random secret and returns it
        $totp = TOTP::create();
        return $totp->getSecret();
    }

    /**
     * Encrypt a TOTP secret under the current key of the key ring.
     *
     * Authenticated encryption (defuse/php-encryption via {@see EncryptedSecretStore}),
     * stored as `"<keyId>:<ciphertext>"`.
     *
     * @param string $secret The TOTP secret to encrypt
     * @return string Opaque authenticated-ciphertext token safe for storage
     */
    public function encryptSecret(string $secret): string
    {
        return $this->store->encrypt($secret);
    }

    /**
     * Decrypt a TOTP secret produced by {@see encryptSecret()}.
     *
     * The ciphertext is authenticated: a wrong key, a corrupted blob, or any tampering
     * raises rather than returning a corrupted secret.
     *
     * @param string $encrypted The authenticated-ciphertext token from encryptSecret()
     * @return string The decrypted TOTP secret
     * @throws \RuntimeException If the ciphertext is invalid, tampered, or the key is wrong
     */
    public function decryptSecret(string $encrypted): string
    {
        try {
            return $this->store->hasKeyIdPrefix($encrypted)
                ? $this->store->decrypt($encrypted)
                : $this->store->decryptUnprefixed($encrypted);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException('Failed to decrypt TOTP secret', 0, $e);
        }
    }

    /**
     * Whether a stored secret is already under the current key, in the key-ring format.
     */
    public function isOnCurrentKey(string $encrypted): bool
    {
        return $this->store->isEncryptedWithCurrentKey($encrypted);
    }

    /**
     * Re-encrypt a stored secret under the current key.
     *
     * @return string|null The new stored value, or null when it is already on the current key.
     * @throws \RuntimeException If the stored value does not decrypt.
     */
    public function rewrapForCurrentKey(string $encrypted): ?string
    {
        if ($this->isOnCurrentKey($encrypted)) {
            return null;
        }

        return $this->encryptSecret($this->decryptSecret($encrypted));
    }

    /**
     * Validate a TOTP code against an encrypted secret
     *
     * Uses a time window of ±1 steps (±30 seconds) to account for clock skew.
     *
     * @param string $encrypted The encrypted TOTP secret
     * @param string $code The TOTP code to validate (6 digits)
     * @return bool True if the code is valid, false otherwise
     */
    public function validateCode(string $encrypted, string $code): bool
    {
        try {
            $secret = $this->decryptSecret($encrypted);
            $totp = TOTP::create($secret);

            // Verify with ±1 window (current time ± 30 seconds)
            return $totp->verify($code, null, 1);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Find the TOTP time-step a submitted code matches, within the same ±1
     * step window as {@see validateCode()}, WITHOUT enforcing anti-replay.
     *
     * Anti-replay (WC-security-audit): a TOTP code is valid for every check
     * made during its ~30-second period, so a plain validateCode()-style TRUE
     * result alone does not tell the caller whether THIS exact step has
     * already been consumed by an earlier login — a captured valid code could
     * otherwise authenticate more than once within that window (the classic
     * TOTP replay weakness RFC 6238 recommends guarding against). The caller
     * uses the returned step to atomically advance a per-profile "last accepted
     * step" floor ({@see TotpStepFloor}) and rejects the code when that atomic
     * advance loses the race — i.e. the step (or a later one) was already
     * consumed.
     *
     * @param string $encrypted The encrypted TOTP secret
     * @param string $code The TOTP code to check (6 digits)
     * @return int|null The highest matching time-step in the ±1 window, or
     *   null if the code does not verify against any step in it.
     */
    public function matchedStep(string $encrypted, string $code): ?int
    {
        try {
            $secret = $this->decryptSecret($encrypted);

            return $this->findMatchingStep(TOTP::create($secret), $code);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Scan the ±1 step window (current time ± one period) for a step whose
     * generated OTP matches $code, returning the HIGHEST matching step (there
     * is normally at most one, but ties are resolved forward in time).
     */
    private function findMatchingStep(TOTP $totp, string $code): ?int
    {
        $period = $totp->getPeriod();
        $epoch  = $totp->getEpoch();
        $now    = time();

        $matchedStep = null;
        foreach ([0, -1, 1] as $delta) {
            $timestamp = $now + ($delta * $period);
            if ($timestamp < 0) {
                continue;
            }
            if (!hash_equals($totp->at($timestamp), $code)) {
                continue;
            }
            $step = intdiv($timestamp - $epoch, $period);
            if ($matchedStep === null || $step > $matchedStep) {
                $matchedStep = $step;
            }
        }

        return $matchedStep;
    }

    /**
     * Validate a TOTP code against a PLAINTEXT secret.
     *
     * Used during enrollment confirmation, where the caller already holds the freshly
     * generated (not-yet-stored) secret. Mirrors {@see validateCode()} but skips the
     * decrypt step, so the secret is never needlessly round-tripped through
     * encrypt()/decrypt() merely to validate a code.
     *
     * @param string $secret The plaintext (Base32) TOTP secret
     * @param string $code The TOTP code to validate (6 digits)
     * @return bool True if the code is valid, false otherwise
     */
    public function verifyPlainCode(string $secret, string $code): bool
    {
        try {
            return TOTP::create($secret)->verify($code, null, 1);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Generate an otpauth:// URL for QR code generation
     *
     * The URL can be encoded as a QR code for easy setup in authenticator apps.
     *
     * @param string $email The user's email address (displayed as label)
     * @param string $secret The TOTP secret (unencrypted)
     * @return string The otpauth:// URL
     */
    public function generateQrCodeUrl(string $email, string $secret): string
    {
        $totp = TOTP::create($secret);
        $totp->setLabel($email);
        $totp->setIssuer('Whity');

        return $totp->getProvisioningUri();
    }
}
