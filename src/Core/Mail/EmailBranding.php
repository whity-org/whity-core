<?php

declare(strict_types=1);

namespace Whity\Core\Mail;

use Whity\Core\Branding\BrandingService;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;

/**
 * The brand an {@see EmailLayout} draws (EM.1): the name, its initial on its
 * colour, the support address and footer line, and whether "Sent with Whity"
 * shows.
 *
 * Two sources. {@see fromSettings()} reads the instance's own settings (the
 * account emails a person gets before they are in a workspace). {@see forTenant()}
 * reads the workspace's branding: its name and main colour, and white-label from
 * its plan (`brand.remove_badge`, the same grant that removes "Built on Whity"
 * from its public pages).
 *
 * A text wordmark (the initial on the colour) rather than a remote logo: email
 * clients block images often, and the initial always renders.
 */
final class EmailBranding
{
    public const DEFAULT_COLOR = '#2B6CD2';

    public function __construct(
        public readonly string $siteName,
        public readonly string $brandColor,
        public readonly string $supportEmail,
        public readonly string $footerText,
        /** True on plans with white-label: "Sent with Whity" is left out. */
        public readonly bool $whiteLabel = false,
    ) {
    }

    /**
     * Resolve branding from the global settings layer, falling back to registry
     * defaults. Never throws on a read issue: the caller (mail) is best-effort.
     */
    public static function fromSettings(SettingsService $settings): self
    {
        try {
            $g = $settings->getGlobal();
        } catch (\Throwable) {
            $g = [];
        }

        $siteName = trim((string) ($g[SettingsRegistry::SITE_NAME] ?? '')) ?: 'Whity';

        return new self(
            siteName: $siteName,
            brandColor: self::color((string) ($g[SettingsRegistry::MAIL_BRAND_COLOR] ?? '')),
            supportEmail: trim((string) ($g[SettingsRegistry::SUPPORT_EMAIL] ?? '')),
            footerText: trim((string) ($g[SettingsRegistry::MAIL_FOOTER_TEXT] ?? '')),
        );
    }

    /**
     * The workspace's brand: its name and main colour over the instance's mail
     * settings, and white-label from its plan. Falls back to {@see fromSettings()}
     * for the system tenant or when the workspace's branding can't be read.
     */
    public static function forTenant(SettingsService $settings, BrandingService $branding, int $tenantId): self
    {
        $base = self::fromSettings($settings);
        if ($tenantId <= 0) {
            return $base;
        }

        try {
            $effective = $branding->effective($tenantId);
        } catch (\Throwable) {
            return $base;
        }

        $name = trim($effective->siteName);

        return new self(
            siteName: $name !== '' ? $name : $base->siteName,
            brandColor: $effective->mainColor !== null ? self::color($effective->mainColor) : $base->brandColor,
            supportEmail: $base->supportEmail,
            footerText: $base->footerText,
            whiteLabel: !$effective->builtOnWhity,
        );
    }

    /**
     * The single uppercase letter shown on the brand tile (first letter of the
     * site name, or 'W').
     */
    public function initial(): string
    {
        $first = mb_substr($this->siteName, 0, 1);
        return $first !== '' ? mb_strtoupper($first) : 'W';
    }

    private static function color(string $value): string
    {
        $value = trim($value);

        return preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1 ? $value : self::DEFAULT_COLOR;
    }
}
