<?php

declare(strict_types=1);

namespace Whity\Core\Mail;

use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;

/**
 * The brand inputs an {@see EmailLayout} needs (WC-email, EM.1): whoever sends
 * the email shows their own name, colour and logo on it. This is what makes the
 * template "customisable without editing it": an operator or a workspace sets
 * them once (Branding + mail settings) and every message inherits them.
 *
 * The logo is optional and only used when it is an absolute http(s) URL (an
 * email cannot load a path relative to the app). Without one, the header shows
 * the name's initial on a tile in the brand colour, which never breaks when a
 * client blocks images.
 */
final class EmailBranding
{
    /** Whity Blue, used when nothing valid is set. */
    public const DEFAULT_COLOR = '#2B6CD2';

    /**
     * @param string      $siteName      The sender's name shown in the header.
     * @param string      $brandColor    A #RRGGBB colour for the tile and the main button.
     * @param string      $supportEmail  Where "Need help?" points, or '' for no line.
     * @param string      $footerText    The sender's own footer line, or ''.
     * @param string|null $logoUrl       An absolute http(s) square logo, or null for the initial tile.
     * @param bool        $sentWithWhity Whether the footer says "Sent with Whity" (off with white-label).
     */
    public function __construct(
        public readonly string $siteName,
        public readonly string $brandColor,
        public readonly string $supportEmail,
        public readonly string $footerText,
        public readonly ?string $logoUrl = null,
        public readonly bool $sentWithWhity = true,
    ) {
    }

    /**
     * Resolve branding from the global settings layer, falling back to registry
     * defaults. Never throws on a read issue — the caller (mail) is best-effort.
     *
     * The colour is the mail brand colour, else the instance's main colour, else
     * Whity Blue.
     */
    public static function fromSettings(SettingsService $settings): self
    {
        try {
            $g = $settings->getGlobal();
        } catch (\Throwable) {
            $g = [];
        }

        return self::fromValues($g, '', 0, true);
    }

    /**
     * Resolve the brand of one workspace (tenant → global → default), so the
     * email carries the workspace's own name, colour and logo. Tenant 0 is the
     * instance itself. Never throws; a read issue falls back to the instance.
     *
     * The workspace's own main colour comes before the instance's mail colour:
     * an email a workspace sends is in that workspace's colour.
     *
     * @param string $appUrl The app's absolute address (APP_URL), used to turn the
     *                       stored logo into a link an email client can load.
     */
    public static function forTenant(SettingsService $settings, int $tenantId, string $appUrl = '', bool $sentWithWhity = true): self
    {
        try {
            $values = $settings->effective($tenantId);
        } catch (\Throwable) {
            return self::fromSettings($settings);
        }

        $main = trim((string) ($values[SettingsRegistry::BRANDING_MAIN_COLOR] ?? ''));
        if (self::isColor($main)) {
            $values[SettingsRegistry::MAIL_BRAND_COLOR] = $main;
        }

        return self::fromValues($values, $appUrl, $tenantId, $sentWithWhity);
    }

    /**
     * The single uppercase letter shown on the brand tile (first letter of the
     * site name, or 'W').
     */
    public function initial(): string
    {
        $first = mb_substr(trim($this->siteName), 0, 1);
        return $first !== '' ? mb_strtoupper($first) : 'W';
    }

    /**
     * @param array<string, mixed> $v
     */
    private static function fromValues(array $v, string $appUrl, int $tenantId, bool $sentWithWhity): self
    {
        $siteName = trim(self::str($v, SettingsRegistry::SITE_NAME)) ?: 'Whity';
        $brandColor = trim(self::str($v, SettingsRegistry::MAIL_BRAND_COLOR));
        if (!self::isColor($brandColor)) {
            $main = trim(self::str($v, SettingsRegistry::BRANDING_MAIN_COLOR));
            $brandColor = self::isColor($main) ? $main : self::DEFAULT_COLOR;
        }

        return new self(
            siteName: $siteName,
            brandColor: $brandColor,
            supportEmail: trim(self::str($v, SettingsRegistry::SUPPORT_EMAIL)),
            footerText: trim(self::str($v, SettingsRegistry::MAIL_FOOTER_TEXT)),
            logoUrl: self::logoUrl(self::str($v, SettingsRegistry::BRANDING_LOGO_SQUARE), $appUrl, $tenantId),
            sentWithWhity: $sentWithWhity,
        );
    }

    /**
     * The square logo as an absolute URL through the public branding asset
     * route (the shape {@see \Whity\Core\Branding\BrandingService::assetUrl()}
     * builds). Null without an absolute app address or a logo.
     */
    private static function logoUrl(string $storageKey, string $appUrl, int $tenantId): ?string
    {
        $storageKey = trim($storageKey);
        $appUrl = rtrim(trim($appUrl), '/');
        if ($storageKey === '' || preg_match('#^https?://[^\s"\'<>]+$#i', $appUrl) !== 1) {
            return null;
        }
        $owner = preg_match('#^tenants/(\d+)/#', $storageKey, $m) === 1 ? (int) $m[1] : $tenantId;

        return sprintf('%s/api/v1/branding/asset/%d/%s', $appUrl, $owner, rawurlencode(basename($storageKey)));
    }

    /**
     * @param array<string, mixed> $v
     */
    private static function str(array $v, string $key): string
    {
        $value = $v[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    private static function isColor(string $value): bool
    {
        return preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1;
    }
}
