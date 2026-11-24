<?php

declare(strict_types=1);

namespace Whity\Core\Tenant;

/**
 * Handles no workspace may take (design section 36, HD.2).
 *
 *  1. Every first path segment of the web app and API, so a short link like
 *     `/{handle}` can never shadow a page. {@see \Tests\Unit\Core\Tenant\ReservedHandlesTest}
 *     reads `web/routes.json` and fails when a new top-level page is missing here.
 *  2. Infrastructure and role words (subdomains and impersonation).
 *  3. Chat words, so a later `@` address never clashes with a mention (UC.10).
 *  4. Anything containing "whity"; an operator can still give one out by hand.
 *  5. The operator's own list, the `handles.reserved` setting.
 *
 * Operators (the System workspace) are not bound by 1–4 when they set a
 * handle by hand (HD.9); everyone else is.
 */
final class ReservedHandles
{
    /** @var list<string> */
    public const PAGES = [
        'accept-invitation', 'account', 'account-recovery', 'admin', 'api', 'app-icon', 'billing',
        'boards', 'book', 'booking', 'calendar', 'chat', 'contacts', 'csp-report', 'dashboard', 'forgot-password',
        'forms', 'inventory', 'join', 'licensing', 'login', 'logout', 'mcp', 'messages', 'more',
        'onboarding', 'operator-sign-in', 'ownership-offers', 'push-icon', 'quote', 'register', 'reset-password', 'sales',
        'settings', 'setup', 'status', 'tasks', 'verify', 'verify-email', 'web-build', 'welcome',
        'workspaces', 'well-known', 'openapi', 'manifest', 'robots', 'sitemap', 'favicon',
    ];

    /** @var list<string> */
    public const INFRASTRUCTURE = [
        'system', 'administrator', 'root', 'operator', 'operators', 'staff', 'support', 'help',
        'security', 'abuse', 'payments', 'docs', 'blog', 'www', 'mail', 'email', 'smtp', 'imap',
        'app', 'apps', 'cdn', 'static', 'assets', 'media', 'files', 'auth', 'sso', 'oauth', 'accounts',
        'dev', 'test', 'staging', 'api-docs', 'webhooks', 'webhook', 'store', 'pay', 'render', 'builder',
        'studio', 'cloud', 'platform', 'official', 'legal', 'privacy', 'terms', 'billing-team', 'noreply',
        'no-reply', 'postmaster', 'hostmaster', 'webmaster', 'info', 'contact', 'home', 'new', 'null',
        'undefined', 'tenant', 'tenants', 'workspace', 'organisation', 'organization',
    ];

    /** @var list<string> */
    public const CHAT = [
        'everyone', 'here', 'all', 'channel', 'you', 'team', 'bot', 'bots', 'agent', 'agents',
    ];

    private function __construct()
    {
    }

    /**
     * Whether `$handle` (normalised) is reserved.
     *
     * @param list<string> $operatorList the `handles.reserved` setting, one per entry
     */
    public static function isReserved(string $handle, array $operatorList = []): bool
    {
        if (self::isBuiltIn($handle)) {
            return true;
        }

        return in_array($handle, $operatorList, true);
    }

    /** Reserved by Whity itself (1–4), whatever the operator's list says. */
    public static function isBuiltIn(string $handle): bool
    {
        if (str_contains($handle, 'whity')) {
            return true;
        }

        return in_array($handle, self::PAGES, true)
            || in_array($handle, self::INFRASTRUCTURE, true)
            || in_array($handle, self::CHAT, true);
    }

    /**
     * The `handles.reserved` setting as a list: one handle per line or comma,
     * normalised, blanks dropped.
     *
     * @return list<string>
     */
    public static function parseOperatorList(string $setting): array
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', $setting) ?: [] as $entry) {
            $entry = HandleRules::normalize($entry);
            if ($entry !== '' && !in_array($entry, $out, true)) {
                $out[] = $entry;
            }
        }

        return $out;
    }
}
