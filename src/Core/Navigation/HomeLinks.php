<?php

declare(strict_types=1);

namespace Whity\Core\Navigation;

use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;

/**
 * Where "home" is on this instance, and the optional link back to the product
 * Whity runs inside (whity-org/whity-core#2386).
 *
 * A product that runs Whity next to its own app on one origin may want people
 * to land somewhere other than `/dashboard` after signing in, for example on
 * the forms list, and may want a way back to its own pages from Whity's
 * sidebar. Both are instance-wide:
 *
 *  - `navigation.home_path` / `WHITY_HOME_PATH`: the path people land on after
 *    signing in and that every "home" link opens (the logo, the phone's home
 *    tab, "not found"). A path on this origin, never another site.
 *  - `navigation.back_to_product_url` and `navigation.back_to_product_label` /
 *    `WHITY_BACK_TO_PRODUCT_URL` and `WHITY_BACK_TO_PRODUCT_LABEL`: an extra
 *    link at the top of the sidebar. Off while the URL is empty.
 *
 * A stored setting wins; an empty one falls back to the environment variable,
 * then to the default. So a container can set the instance's shape once, and
 * the settings screen can still change it.
 */
final class HomeLinks
{
    public const DEFAULT_HOME = '/dashboard';

    public const HOME_ENV = 'WHITY_HOME_PATH';
    public const BACK_URL_ENV = 'WHITY_BACK_TO_PRODUCT_URL';
    public const BACK_LABEL_ENV = 'WHITY_BACK_TO_PRODUCT_LABEL';

    public const MAX_PATH = 200;
    public const MAX_LABEL = 60;

    /** The label when a back link has a URL and no label of its own. */
    public const DEFAULT_BACK_LABEL = 'Back';

    /**
     * Why a home path is refused, or null. Only a path on this origin: a
     * landing page on another site would turn every sign-in into an open
     * redirect.
     */
    public static function validateHomePath(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        return self::isLocalPath($value)
            ? null
            : SettingsRegistry::NAVIGATION_HOME_PATH . ' must be a path on this site that starts with /, such as /dashboard or /admin/x/forms-catalog.';
    }

    /**
     * Why a back-to-product URL is refused, or null: a path on this origin, or
     * an https address.
     */
    public static function validateBackUrl(string $value): ?string
    {
        if ($value === '' || self::isLocalPath($value) || self::isHttpsUrl($value)) {
            return null;
        }

        return SettingsRegistry::NAVIGATION_BACK_TO_PRODUCT_URL . ' must be a path on this site that starts with /, or an https:// address.';
    }

    public static function validateBackLabel(string $value): ?string
    {
        if (mb_strlen($value) > self::MAX_LABEL) {
            return SettingsRegistry::NAVIGATION_BACK_TO_PRODUCT_LABEL . ' must be at most ' . self::MAX_LABEL . ' characters.';
        }
        if (preg_match('/[\x00-\x1F\x7F<>]/u', $value) === 1) {
            return SettingsRegistry::NAVIGATION_BACK_TO_PRODUCT_LABEL . ' must be plain text on one line.';
        }

        return null;
    }

    /** The path people land on after signing in. Always a local path. */
    public static function homePath(?SettingsService $settings): string
    {
        $value = self::pick($settings, SettingsRegistry::NAVIGATION_HOME_PATH, self::HOME_ENV);

        return $value !== '' && self::isLocalPath($value) ? $value : self::DEFAULT_HOME;
    }

    /**
     * The sidebar's link back to the product, or null when none is set.
     *
     * @return array{url: string, label: string}|null
     */
    public static function backToProduct(?SettingsService $settings): ?array
    {
        $url = self::pick($settings, SettingsRegistry::NAVIGATION_BACK_TO_PRODUCT_URL, self::BACK_URL_ENV);
        if ($url === '' || !(self::isLocalPath($url) || self::isHttpsUrl($url))) {
            return null;
        }
        $label = self::pick($settings, SettingsRegistry::NAVIGATION_BACK_TO_PRODUCT_LABEL, self::BACK_LABEL_ENV);
        if ($label === '' || self::validateBackLabel($label) !== null) {
            $label = self::DEFAULT_BACK_LABEL;
        }

        return ['url' => $url, 'label' => $label];
    }

    /** `/x`, not `//host` or `/\host`, with no scheme, spaces or control characters. */
    public static function isLocalPath(string $value): bool
    {
        return strlen($value) <= self::MAX_PATH
            && preg_match('#^/(?![/\\\\])[^\s\x00-\x1F\x7F\\\\]*$#', $value) === 1;
    }

    private static function isHttpsUrl(string $value): bool
    {
        if (strlen($value) > 2000 || preg_match('/[\s\x00-\x1F\x7F]/', $value) === 1) {
            return false;
        }
        $parts = parse_url($value);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && ($parts['host'] ?? '') !== ''
            && !isset($parts['user'])
            && !isset($parts['pass']);
    }

    private static function pick(?SettingsService $settings, string $key, string $env): string
    {
        if ($settings !== null) {
            try {
                $stored = trim($settings->getGlobal()[$key] ?? '');
                if ($stored !== '') {
                    return $stored;
                }
            } catch (\Throwable $e) {
                error_log('[navigation] reading ' . $key . ' failed: ' . $e->getMessage());
            }
        }
        $value = $_ENV[$env] ?? getenv($env);

        return is_string($value) ? trim($value) : '';
    }
}
