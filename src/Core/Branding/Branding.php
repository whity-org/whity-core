<?php

declare(strict_types=1);

namespace Whity\Core\Branding;

/**
 * Immutable effective branding for one tenant (Tenant Branding). The single
 * typed contract every caller consumes — never raw setting keys. URLs point at
 * the public asset route; null means "unset" (the frontend falls back to text
 * for logos and omits the favicon link).
 */
final readonly class Branding
{
    public function __construct(
        public string $siteName,
        public ?string $logoWideUrl,
        public ?string $logoSquareUrl,
        public ?string $faviconUrl,
        public ?string $mainColor = null,
        /** @var array<string, mixed>|null The resolved theme (ThemeResolver): light, dark, vars, fonts, id, version, adjustments. */
        public ?array $theme = null,
        /**
         * Whether the web app offers itself as an installable app (#1733). An
         * instance setting, not a workspace one: the same on every host.
         */
        public bool $installable = true,
        /**
         * Whether the workspace's public pages show "Built on Whity" (WHIT-932):
         * false when its plan holds `brand.remove_badge`.
         */
        public bool $builtOnWhity = true,
        /**
         * The workspace's currency (CU.4): what Money shows and new amounts
         * start in when a screen has no row of its own. USD by default.
         */
        public string $currency = 'USD',
        // WHIT-1225 (TH.12): the tokens Higher contrast changes, per mode.
        /** @var array{light: array<string, string>, dark: array<string, string>} */
        public array $themeHigh = ['light' => [], 'dark' => []],
        /**
         * #2386: where people land after signing in and where home links go,
         * a path on this site. An instance setting ({@see \Whity\Core\Navigation\HomeLinks}).
         */
        public string $homePath = '/dashboard',
        /**
         * #2386: the sidebar's link back to the product Whity runs beside,
         * or null for none.
         *
         * @var array{url: string, label: string}|null
         */
        public ?array $backToProduct = null,
    ) {
    }

    /**
     * The main colour (2.8) and the light and dark token sets derived from it
     * are null until the workspace sets one; clients then keep Whity Blue.
     *
     * @return array{siteName: string, logoWideUrl: ?string, logoSquareUrl: ?string, faviconUrl: ?string, mainColor: ?string, theme: array<string, mixed>|null, installable: bool, builtOnWhity: bool, currency: string, themeHigh: array{light: array<string, string>, dark: array<string, string>}, homePath: string, backToProduct: array{url: string, label: string}|null}
     */
    public function toArray(): array
    {
        return [
            'siteName' => $this->siteName,
            'logoWideUrl' => $this->logoWideUrl,
            'logoSquareUrl' => $this->logoSquareUrl,
            'faviconUrl' => $this->faviconUrl,
            'mainColor' => $this->mainColor,
            'theme' => $this->theme,
            'installable' => $this->installable,
            'builtOnWhity' => $this->builtOnWhity,
            'currency' => $this->currency,
            'themeHigh' => $this->themeHigh,
            'homePath' => $this->homePath,
            'backToProduct' => $this->backToProduct,
        ];
    }
}
