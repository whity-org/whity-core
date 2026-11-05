<?php

declare(strict_types=1);

namespace Whity\Core\Mail;

use Closure;
use Psr\Log\LoggerInterface;
use Whity\Core\Http\HttpClient;
use Whity\Core\Http\HttpFetcher;
use Whity\Core\Mail\OAuth\SmtpOAuthStore;
use Whity\Core\Mail\OAuth\SmtpOAuthTokenManager;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;

/**
 * Selects the {@see Mailer} transport (WC-235 / WC-email).
 *
 * Two sources:
 *   - {@see fromEnv()} — the boot-time fallback driven by MAIL_TRANSPORT
 *     ('' / 'null' → Null, 'log' → Log). SMTP is NOT built from env: it needs
 *     the settings-backed config below.
 *   - {@see fromSettings()} — the real, operator-configured transport read from
 *     the GLOBAL instance settings (`mail.*`). This is what the app uses.
 *
 * Fail-safe throughout: an unknown/misconfigured transport degrades to
 * {@see NullMailer} with a warning rather than crashing the worker on boot —
 * email is a non-critical side channel.
 */
final class MailerFactory
{
    /** Environment variable naming the transport (legacy/boot fallback). */
    public const ENV_TRANSPORT = 'MAIL_TRANSPORT';

    /**
     * The app_settings key holding the SMTP password, encrypted at rest. It is
     * deliberately NOT a {@see SettingsRegistry} key: it lives in `app_settings`
     * but never surfaces on the typed settings API (which only iterates registry
     * keys), so the secret cannot leak through GET /settings.
     */
    public const SMTP_PASSWORD_SETTING_KEY = 'mail.smtp.password_encrypted';

    public const AUTH_MODE_PASSWORD = 'password';
    public const AUTH_MODE_OAUTH2 = 'oauth2';

    private const TOKEN_REQUEST_TIMEOUT_SECONDS = 10;

    /**
     * @param array<string, mixed> $env Environment map (e.g. $_ENV).
     */
    public static function fromEnv(array $env, LoggerInterface $logger): Mailer
    {
        $transport = strtolower(trim((string) ($env[self::ENV_TRANSPORT] ?? '')));

        return match ($transport) {
            '', 'null' => new NullMailer(),
            'log'      => new LogMailer($logger),
            default    => self::unknown($transport, $logger),
        };
    }

    /**
     * Build the transport from GLOBAL instance settings (`mail.*`).
     *
     * `mail.transport` selects the backend:
     *   - 'none' (default) → {@see NullMailer} (email disabled).
     *   - 'log'            → {@see LogMailer}.
     *   - 'smtp'           → {@see SmtpMailer} from `mail.smtp.*` + `mail.from_*`,
     *                        with the password decrypted from the encrypted-secret
     *                        store.
     *
     * A 'smtp' selection with missing host/from-address degrades to Null (with a
     * warning): a half-configured server must not crash boot nor throw on every
     * send. A password that fails to decrypt is treated as "no password" (warned),
     * so a key-rotation mishap can't wedge the worker.
     *
     * `mail.smtp.auth_mode` = 'oauth2' signs in with XOAUTH2 instead (WHIT-901). An
     * unfinished OAuth2 connection does NOT degrade to a no-op: the send throws, so
     * the queue keeps the message and the operator sees why.
     *
     * @param HttpClient|null $http Test seam for the token endpoint.
     * @param (Closure(SmtpConfig): SmtpConnection)|null $connector Test seam for SMTP.
     */
    public static function fromSettings(
        SettingsService $settings,
        GlobalSettingsRepository $globals,
        EncryptedSecretStore $secrets,
        LoggerInterface $logger,
        ?HttpClient $http = null,
        ?Closure $connector = null,
    ): Mailer {
        // Reading the settings hits the database. A failure here (transient DB
        // outage, a migration lagging behind a deploy) must NOT propagate — email
        // is best-effort, so degrade to a no-op rather than break the caller. This
        // is what makes it safe to (re)build the mailer on every send.
        try {
            $global = $settings->getGlobal();
        } catch (\Throwable $e) {
            $logger->warning('[mail] could not read mail settings; email disabled for this send: ' . $e->getMessage());

            return new NullMailer();
        }

        $transport = (string) ($global[SettingsRegistry::MAIL_TRANSPORT] ?? 'none');

        if ($transport === 'none') {
            return new NullMailer();
        }
        if ($transport === 'log') {
            return new LogMailer($logger);
        }
        if ($transport !== 'smtp') {
            return self::unknown($transport, $logger);
        }

        $host = trim((string) ($global[SettingsRegistry::MAIL_SMTP_HOST] ?? ''));
        $fromEmail = trim((string) ($global[SettingsRegistry::MAIL_FROM_ADDRESS] ?? ''));

        if ($host === '' || $fromEmail === '') {
            $logger->warning('[mail] transport is "smtp" but host/from_address is not configured; email disabled', [
                'has_host' => $host !== '',
                'has_from' => $fromEmail !== '',
            ]);

            return new NullMailer();
        }

        $username = trim((string) ($global[SettingsRegistry::MAIL_SMTP_USERNAME] ?? ''));
        $authMode = (string) ($global[SettingsRegistry::MAIL_SMTP_AUTH_MODE] ?? self::AUTH_MODE_PASSWORD);
        $password = $authMode === self::AUTH_MODE_OAUTH2
            ? null
            : self::decryptPassword($globals, $secrets, $logger);

        $config = new SmtpConfig(
            host: $host,
            port: (int) ($global[SettingsRegistry::MAIL_SMTP_PORT] ?? '587'),
            fromEmail: $fromEmail,
            fromName: trim((string) ($global[SettingsRegistry::MAIL_FROM_NAME] ?? '')),
            encryption: (string) ($global[SettingsRegistry::MAIL_SMTP_ENCRYPTION] ?? 'tls'),
            username: $username !== '' ? $username : null,
            password: $password,
        );

        if ($authMode === self::AUTH_MODE_OAUTH2) {
            return new OAuth2SmtpMailer(
                $config,
                self::oauthTokens($settings, $globals, $secrets, $logger, $http),
                $connector,
            );
        }

        return new SmtpMailer($config, $connector);
    }

    public static function oauthTokens(
        SettingsService $settings,
        GlobalSettingsRepository $globals,
        EncryptedSecretStore $secrets,
        LoggerInterface $logger,
        ?HttpClient $http = null,
    ): SmtpOAuthTokenManager {
        return new SmtpOAuthTokenManager(
            $settings,
            new SmtpOAuthStore($globals, $secrets),
            $http ?? new HttpFetcher(self::TOKEN_REQUEST_TIMEOUT_SECONDS),
            $logger,
        );
    }

    /**
     * Decrypt the stored SMTP password, or null when unset. A decryption failure
     * (e.g. missing rotation key) is logged and treated as "no password" rather
     * than propagated — email stays best-effort.
     */
    private static function decryptPassword(
        GlobalSettingsRepository $globals,
        EncryptedSecretStore $secrets,
        LoggerInterface $logger,
    ): ?string {
        $stored = $globals->get(self::SMTP_PASSWORD_SETTING_KEY);
        if ($stored === null || $stored === '') {
            return null;
        }

        try {
            return $secrets->decrypt($stored);
        } catch (\RuntimeException) {
            // Never log the ciphertext or the underlying crypto detail.
            $logger->warning('[mail] stored SMTP password could not be decrypted; sending without auth password');

            return null;
        }
    }

    private static function unknown(string $transport, LoggerInterface $logger): Mailer
    {
        $logger->warning('[mail] unknown mail transport; falling back to no-op', [
            'transport' => $transport,
        ]);

        return new NullMailer();
    }
}
