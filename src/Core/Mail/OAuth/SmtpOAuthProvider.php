<?php

declare(strict_types=1);

namespace Whity\Core\Mail\OAuth;

/**
 * The fixed list of OAuth2 providers SMTP mail can sign in with (WHIT-901).
 *
 * Token endpoints live here as constants and no setting can supply one, so a
 * mail setting can never point the server at an address of the caller's choosing.
 * The one variable part is Microsoft's directory segment, and that is only ever a
 * GUID or one of Microsoft's three keywords, under a fixed host.
 *
 * SMTP hosts are fixed too. An access token with mail scope is worth more than
 * the write-only secrets it comes from, and sending it to whatever host the
 * settings name would let anyone with settings:manage read it by pointing the
 * host at their own server and pressing "send test". Loopback stays allowed for
 * local fakes: a token sent there never leaves the machine.
 */
final class SmtpOAuthProvider
{
    public const GOOGLE = 'google';
    public const MICROSOFT = 'microsoft';

    /** @var list<string> */
    public const ALL = [self::GOOGLE, self::MICROSOFT];

    public const DEFAULT_MICROSOFT_TENANT = 'common';

    private const GOOGLE_TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    private const MICROSOFT_TOKEN_ENDPOINT = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';

    /** Google keeps the scope the refresh token was granted, so none is sent. */
    private const MICROSOFT_SCOPE = 'https://outlook.office365.com/SMTP.Send offline_access';

    /** @var array<string, list<string>> */
    private const SMTP_HOSTS = [
        self::GOOGLE => ['smtp.gmail.com'],
        self::MICROSOFT => ['smtp.office365.com', 'smtp-legacy.office365.com', 'smtp-mail.outlook.com'],
    ];

    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '::1'];

    private const GUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD';

    public static function isKnown(string $provider): bool
    {
        return in_array($provider, self::ALL, true);
    }

    public static function isValidMicrosoftTenant(string $tenant): bool
    {
        return in_array($tenant, ['common', 'organizations', 'consumers'], true)
            || preg_match(self::GUID, $tenant) === 1;
    }

    /**
     * The token endpoint for a provider from the fixed list.
     *
     * @throws \InvalidArgumentException For a provider outside the list.
     */
    public static function tokenEndpoint(string $provider, string $microsoftTenant = self::DEFAULT_MICROSOFT_TENANT): string
    {
        return match ($provider) {
            self::GOOGLE => self::GOOGLE_TOKEN_ENDPOINT,
            self::MICROSOFT => sprintf(
                self::MICROSOFT_TOKEN_ENDPOINT,
                self::isValidMicrosoftTenant($microsoftTenant) ? $microsoftTenant : self::DEFAULT_MICROSOFT_TENANT,
            ),
            default => throw new \InvalidArgumentException('Unknown SMTP OAuth provider'),
        };
    }

    public static function refreshScope(string $provider): ?string
    {
        return $provider === self::MICROSOFT ? self::MICROSOFT_SCOPE : null;
    }

    public static function allowsSmtpHost(string $provider, string $host): bool
    {
        $host = strtolower(trim($host, " \t[]"));

        return self::isLoopback($host) || in_array($host, self::SMTP_HOSTS[$provider] ?? [], true);
    }

    public static function isLoopback(string $host): bool
    {
        return in_array(strtolower(trim($host, " \t[]")), self::LOOPBACK_HOSTS, true);
    }
}
