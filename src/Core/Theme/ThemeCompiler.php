<?php

declare(strict_types=1);

namespace Whity\Core\Theme;

use Whity\Core\Branding\TenantTheme;

/**
 * Turns a theme's choices into design tokens (TH.3-TH.12, `30-themes.md`
 * sections 3 and 6). The output is what the web app writes as CSS custom
 * properties, the phone app reads, and the document accent starts from.
 *
 * Colour is worked out in OKLCH, like TenantTheme (2.8). The primary family is
 * TenantTheme's own derivation, so the Calm scheme gives the same primary,
 * label, tint, sidebar and plane values a workspace has today. Everything
 * else is lightness steps of the seeds' hues, then the guard rails run on the
 * result and record what they changed in `adjustments`.
 *
 * Only tokens that differ from the Whity default are emitted, so the default
 * theme with no seed compiles to nothing at all.
 */
final class ThemeCompiler
{
    private const WHITE = '#ffffff';
    private const NEAR_BLACK = '#171717';

    /** Text pairs (background role, text role): 4.5:1, or 7:1 with higher contrast. */
    private const TEXT_PAIRS = [
        ['background', 'foreground'],
        ['card', 'card-foreground'],
        ['popover', 'popover-foreground'],
        ['secondary', 'secondary-foreground'],
        ['accent', 'accent-foreground'],
        ['sidebar', 'sidebar-foreground'],
        ['background', 'muted-foreground'],
        ['card', 'muted-foreground'],
        ['primary', 'primary-foreground'],
        ['brand', 'brand-foreground'],
        ['primary-tint', 'primary-tint-foreground'],
        ['sidebar-primary', 'sidebar-primary-foreground'],
        ['primary-container', 'on-primary-container'],
        ['secondary-container', 'on-secondary-container'],
        ['tertiary', 'tertiary-foreground'],
        ['tertiary-container', 'on-tertiary-container'],
    ];

    /** Non-text pairs: 3:1, or 4.5:1 with higher contrast (2.6, 4.6b). */
    private const NON_TEXT_PAIRS = [
        ['card', 'input'],
        ['background', 'ring'],
        ['card', 'ring'],
    ];

    /** Labels on a filled colour: white or near-black first (2.8a). */
    private const LABELS = ['primary-foreground', 'brand-foreground', 'tertiary-foreground'];

    /**
     * Neutral roles as lightness steps (light, dark) and how much of the
     * neutral's chroma each takes. Values follow base.json; a pure neutral
     * emits none of them.
     */
    private const NEUTRALS = [
        'background' => [0.95, 0.145, 1.0],
        'foreground' => [0.145, 0.985, 0.4],
        'card' => [0.995, 0.205, 0.6],
        'card-foreground' => [0.145, 0.985, 0.4],
        'popover' => [0.995, 0.205, 0.6],
        'popover-foreground' => [0.145, 0.985, 0.4],
        'secondary' => [0.97, 0.269, 1.0],
        'secondary-foreground' => [0.205, 0.985, 0.4],
        'muted' => [0.97, 0.269, 1.0],
        'muted-foreground' => [0.52, 0.708, 0.6],
        'accent' => [0.97, 0.269, 1.0],
        'accent-foreground' => [0.205, 0.985, 0.4],
        'border' => [0.922, null, 1.0],
        'input' => [0.66, 0.52, 0.6],
        'sidebar' => [0.985, 0.17, 1.0],
        'sidebar-foreground' => [0.145, 0.985, 0.4],
        'sidebar-accent' => [0.97, 0.269, 1.0],
        'sidebar-accent-foreground' => [0.205, 0.985, 0.4],
        'sidebar-border' => [0.922, null, 1.0],
        'divider' => [0.94, null, 1.0],
        'table-header' => [0.975, 0.23, 1.0],
    ];

    private const SURFACES = [[1.0, 0.19], [0.985, 0.215], [0.97, 0.24], [0.955, 0.265], [0.94, 0.29]];

    /** 2.7's categorical series 2-5, for the colour-blindness check. */
    private const CHARTS = [
        'light' => ['#f7a224', '#91dbf2', '#9d2398', '#35aa76'],
        'dark' => ['#f7a224', '#91dbf2', '#d25bcb', '#53c48e'],
    ];

    /** Control heights (small, standard, large) and the table row, per density (3.4, A.1). */
    private const DENSITY = [
        'compact' => [32, 36, 40, 44],
        'standard' => [32, 40, 44, 48],
        'comfortable' => [36, 44, 48, 52],
    ];

    /** Shape presets: the control radius as a share of its height, then sm, md, lg, xl, 2xl, card, dialog (3.3). */
    private const SHAPES = [
        'square' => [0.0, 0, 0, 0, 0, 0, 0, 0],
        'crisp' => [0.125, 2, 4, 5, 6, 8, 6, 8],
        'whity' => [0.25, 4, 7, 10, 12, 16, 12, 16],
        'soft' => [1 / 3, 6, 10, 14, 16, 24, 16, 24],
        'round' => [-1.0, 8, 12, 16, 20, 28, 20, 28],
        // TH.17: Soft's cards and dialogs with pill controls, and Crisp's
        // sizes with bevelled corners (corner-shape; rounded where missing).
        'pebble' => [-1.0, 6, 10, 14, 16, 24, 16, 24],
        'cut' => [0.125, 2, 4, 5, 6, 8, 6, 8],
    ];

    /** TH.18: shape morph is on by default in the presets with pill controls. */
    private const MORPH_BY_DEFAULT = ['round', 'pebble'];

    /** Body to 4xl in px for each type scale; `standard` is today's (3.5a, A.1). */
    private const SCALES = [
        'roomy' => ['2xs' => 14, 'xs' => 14, 'sm' => 16, 'base' => 17, 'lg' => 19, 'xl' => 22, '2xl' => 26, '3xl' => 32, '4xl' => 38],
        'large' => ['2xs' => 15, 'xs' => 15, 'sm' => 17, 'base' => 18, 'lg' => 20, 'xl' => 24, '2xl' => 30, '3xl' => 36, '4xl' => 42],
    ];

    /** M3 spatial springs (damping ratio, stiffness) for fast, default and slow. */
    private const SPRINGS = [
        'standard' => [[0.9, 1400.0], [0.9, 700.0], [0.9, 300.0]],
        'expressive' => [[0.6, 800.0], [0.8, 380.0], [0.8, 200.0]],
        // TH.19: the standard springs, settled in 0.8 of the time, for busy front desks.
        'brisk' => [[0.9, 1400.0], [0.9, 700.0], [0.9, 300.0]],
    ];

    /** How long each motion preset takes, against its spring's own settling time. */
    private const MOTION_PACE = ['brisk' => 0.8];

    /** @var array<string, array<string, string>>|null */
    private static ?array $base = null;

    /**
     * The colour roles a theme or a workspace may set by hand. Status colours
     * are not among them (TH.7).
     *
     * @return list<string>
     */
    public static function overridableRoles(): array
    {
        return [
            ...array_keys(self::NEUTRALS),
            'primary', 'primary-foreground', 'brand', 'brand-foreground', 'ring',
            'primary-tint', 'primary-tint-foreground', 'sidebar-primary', 'sidebar-primary-foreground',
            'plane-deep', 'chart-1', 'chart-2', 'chart-3', 'chart-4', 'chart-5',
            'primary-container', 'on-primary-container', 'secondary-container', 'on-secondary-container',
            'tertiary', 'tertiary-foreground', 'tertiary-container', 'on-tertiary-container',
            'surface-1', 'surface-2', 'surface-3', 'surface-4', 'surface-5',
        ];
    }

    /**
     * @param array<string, mixed> $theme A valid theme (ThemeValidator).
     * @param string|null $primarySeed The workspace's main colour (layer 4), replacing the theme's.
     * @param array{light?: array<string, string>, dark?: array<string, string>} $overrides The workspace's overrides (layer 4).
     * @param string $contrast `standard`, or `high` for a person's Higher contrast (TH.12).
     * @return array{
     *   light: array<string, string>, dark: array<string, string>, vars: array<string, string>,
     *   fonts: array{body: string, heading: string},
     *   adjustments: list<array{mode: string, role: string, change: string}>
     * }
     */
    public static function compile(array $theme, ?string $primarySeed = null, array $overrides = [], string $contrast = 'standard'): array
    {
        $colour = is_array($theme['colour'] ?? null) ? $theme['colour'] : [];
        $seeds = is_array($colour['seeds'] ?? null) ? $colour['seeds'] : [];
        if ($primarySeed !== null && preg_match(ThemeValidator::HEX_RE, $primarySeed) === 1) {
            $seeds['primary'] = $primarySeed;
        }
        $scheme = in_array($colour['scheme'] ?? null, ThemeValidator::SCHEMES, true) ? $colour['scheme'] : 'calm';
        $high = $contrast === 'high' || ($colour['contrast'] ?? null) === 'high';
        $merged = ['light' => [], 'dark' => []];
        foreach (['light', 'dark'] as $mode) {
            foreach ([$colour['overrides'][$mode] ?? [], $overrides[$mode] ?? []] as $layer) {
                foreach (is_array($layer) ? $layer : [] as $role => $hex) {
                    if (is_string($role) && in_array($role, self::overridableRoles(), true) && is_string($hex) && preg_match(ThemeValidator::HEX_RE, $hex) === 1) {
                        $merged[$mode][$role] = strtolower($hex);
                    }
                }
            }
        }

        $adjustments = [];
        $out = ['light' => [], 'dark' => []];
        $hasSeed = isset($seeds['primary']) || isset($seeds['secondary']) || isset($seeds['tertiary']);
        $neutral = self::neutral($colour['neutral'] ?? null, $scheme, $seeds['primary'] ?? TenantTheme::whityBlue());
        $colourChanged = $hasSeed || $scheme !== 'calm' || $neutral[1] > 0.0 || $high || $merged['light'] !== [] || $merged['dark'] !== [];

        if ($colourChanged) {
            $primary = strtolower($seeds['primary'] ?? TenantTheme::whityBlue());
            $derived = TenantTheme::derive($primary);
            [, $pc, $ph] = TenantTheme::toOklch($derived['color']);
            $secondary = self::seed($seeds['secondary'] ?? null, $pc, $ph, ['calm' => [0, 0.05], 'tonal' => [0, 0.05], 'monochrome' => [0, 0.0], 'vibrant' => [60, 0.12], 'expressive' => [45, 0.14]][$scheme]);
            $tertiary = self::seed($seeds['tertiary'] ?? null, $pc, $ph, ['calm' => [60, 0.08], 'tonal' => [60, 0.08], 'monochrome' => [0, 0.0], 'vibrant' => [120, 0.14], 'expressive' => [180, 0.16]][$scheme]);

            foreach (['light', 'dark'] as $mode) {
                $dark = $mode === 'dark';
                $tokens = $derived[$mode];
                if ($scheme === 'monochrome') {
                    $ink = $dark ? '#ededed' : self::NEAR_BLACK;
                    $tint = $dark ? '#262626' : '#f0f0f0';
                    $tokens = array_merge($tokens, [
                        'primary' => $ink, 'brand' => $ink, 'ring' => $ink,
                        'primary-foreground' => $dark ? self::NEAR_BLACK : self::WHITE,
                        'brand-foreground' => $dark ? self::NEAR_BLACK : self::WHITE,
                        'primary-tint' => $tint, 'primary-tint-foreground' => $ink,
                        'sidebar-primary' => $tint, 'sidebar-primary-foreground' => $ink,
                    ]);
                }
                if ($neutral[1] > 0.0) {
                    foreach (self::NEUTRALS as $role => [$l, $d, $share]) {
                        $lightness = $dark ? $d : $l;
                        if ($lightness !== null) {
                            $tokens[$role] = TenantTheme::oklchHex($lightness, $neutral[1] * $share, $neutral[0]);
                        }
                    }
                }
                foreach (self::SURFACES as $i => [$l, $d]) {
                    $tokens['surface-' . ($i + 1)] = TenantTheme::oklchHex($dark ? $d : $l, $neutral[1], $neutral[0]);
                }
                $tokens['primary-container'] = TenantTheme::oklchHex($dark ? 0.32 : 0.90, min($pc, $dark ? 0.08 : 0.06), $ph);
                $tokens['on-primary-container'] = TenantTheme::oklchHex($dark ? 0.92 : 0.25, min($pc, $dark ? 0.04 : 0.10), $ph);
                $tokens['secondary-container'] = TenantTheme::oklchHex($dark ? 0.30 : 0.92, min($secondary[0], $dark ? 0.06 : 0.05), $secondary[1]);
                $tokens['on-secondary-container'] = TenantTheme::oklchHex($dark ? 0.92 : 0.25, min($secondary[0], 0.06), $secondary[1]);
                $tokens['tertiary'] = TenantTheme::oklchHex($dark ? 0.75 : 0.50, min($tertiary[0], $dark ? 0.14 : 0.16), $tertiary[1]);
                $tokens['tertiary-foreground'] = $dark ? self::NEAR_BLACK : self::bestLabel($tokens['tertiary']);
                $tokens['tertiary-container'] = TenantTheme::oklchHex($dark ? 0.30 : 0.92, min($tertiary[0], $dark ? 0.06 : 0.05), $tertiary[1]);
                $tokens['on-tertiary-container'] = TenantTheme::oklchHex($dark ? 0.92 : 0.25, min($tertiary[0], 0.06), $tertiary[1]);

                if ($scheme === 'vibrant' || $scheme === 'expressive') {
                    $two = TenantTheme::oklchHex($dark ? 0.72 : 0.65, min(max($secondary[0], 0.10), 0.16), $secondary[1]);
                    $three = TenantTheme::oklchHex($dark ? 0.78 : 0.70, min(max($tertiary[0], 0.10), 0.16), $tertiary[1]);
                    $series = [$tokens['chart-1'], $two, $three, self::CHARTS[$mode][2], self::CHARTS[$mode][3]];
                    if (self::seriesTellApart($series)) {
                        $tokens['chart-2'] = $two;
                        $tokens['chart-3'] = $three;
                    } else {
                        $adjustments[] = ['mode' => $mode, 'role' => 'chart-2', 'change' => 'chart-colours-kept'];
                    }
                }
                if ($high) {
                    $tokens['muted-foreground'] = TenantTheme::oklchHex($dark ? 0.82 : 0.40, $neutral[1] * 0.6, $neutral[0]);
                    $tokens['input'] = TenantTheme::oklchHex($dark ? 0.70 : 0.45, $neutral[1] * 0.6, $neutral[0]);
                }

                $generated = $tokens;
                $tokens = array_merge($tokens, $merged[$mode]);
                $tokens = self::guard($tokens, $generated, array_keys($merged[$mode]), $mode, $high, $adjustments);

                // The plane follows the final primary (4.3, TH.9).
                $tokens = array_merge($tokens, self::elevation($theme, $tokens['primary'], $tokens['plane-deep'], $mode));
                // A workspace with a main colour keeps every token 2.8 derives,
                // even one equal to Whity's, as Branding has always sent them.
                $out[$mode] = self::onlyChanged($tokens, $mode, $hasSeed ? array_keys($derived[$mode]) : []);
            }
        } else {
            foreach (['light', 'dark'] as $mode) {
                $out[$mode] = self::onlyChanged(self::elevation($theme, null, null, $mode), $mode);
            }
        }

        $type = is_array($theme['type'] ?? null) ? $theme['type'] : [];
        $body = in_array($type['family'] ?? null, ThemeFonts::ids(), true) ? $type['family'] : ThemeFonts::DEFAULT;
        $heading = in_array($type['headingFamily'] ?? null, ThemeFonts::ids(), true) ? $type['headingFamily'] : $body;

        return [
            'light' => $out['light'],
            'dark' => $out['dark'],
            'vars' => self::vars($theme, $body, $heading),
            'fonts' => ['body' => $body, 'heading' => $heading],
            'adjustments' => $adjustments,
        ];
    }

    /**
     * The guard rails on one mode's colours (section 6.1): every pair reaches
     * its ratio, by dropping a person's override, then picking white or
     * near-black for a label, then moving the text's lightness away from its
     * background, and last the background's. Never leaves a pair failing.
     *
     * @param array<string, string> $tokens
     * @param array<string, string> $generated
     * @param list<string> $overridden
     * @param list<array{mode: string, role: string, change: string}> $adjustments
     * @return array<string, string>
     */
    private static function guard(array $tokens, array $generated, array $overridden, string $mode, bool $high, array &$adjustments): array
    {
        $base = self::base($mode);
        $pairs = [];
        foreach (self::TEXT_PAIRS as $pair) {
            $pairs[] = [...$pair, $high ? 7.0 : 4.5];
        }
        foreach (self::NON_TEXT_PAIRS as $pair) {
            $pairs[] = [...$pair, $high ? 4.5 : 3.0];
        }

        foreach ($pairs as [$bgRole, $fgRole, $min]) {
            $bg = $tokens[$bgRole] ?? $base[$bgRole] ?? null;
            $fg = $tokens[$fgRole] ?? $base[$fgRole] ?? null;
            if ($bg === null || $fg === null || TenantTheme::contrast($bg, $fg) >= $min) {
                continue;
            }
            foreach ([$fgRole, $bgRole] as $role) {
                if (in_array($role, $overridden, true) && ($tokens[$role] ?? null) !== ($generated[$role] ?? null)) {
                    if (isset($generated[$role])) {
                        $tokens[$role] = $generated[$role];
                    } else {
                        unset($tokens[$role]);
                    }
                    $adjustments[] = ['mode' => $mode, 'role' => $role, 'change' => 'override-dropped'];
                }
            }
            $bg = $tokens[$bgRole] ?? $base[$bgRole];
            $fg = $tokens[$fgRole] ?? $base[$fgRole];
            if (TenantTheme::contrast($bg, $fg) >= $min) {
                continue;
            }
            if (in_array($fgRole, self::LABELS, true) && TenantTheme::contrast($bg, self::bestLabel($bg)) >= $min) {
                $tokens[$fgRole] = self::bestLabel($bg);
                $adjustments[] = ['mode' => $mode, 'role' => $fgRole, 'change' => 'label'];
                continue;
            }
            $moved = self::moveAway($fg, $bg, $min);
            if (TenantTheme::contrast($bg, $moved) >= $min) {
                $tokens[$fgRole] = $moved;
                $adjustments[] = ['mode' => $mode, 'role' => $fgRole, 'change' => self::lightness($moved) < self::lightness($fg) ? 'darkened' : 'lightened'];
                continue;
            }
            $tokens[$fgRole] = self::bestLabel($bg);
            $movedBg = self::moveAway($bg, $tokens[$fgRole], $min);
            if (TenantTheme::contrast($movedBg, $tokens[$fgRole]) < $min) {
                $movedBg = self::lightness($tokens[$fgRole]) > 0.5 ? self::NEAR_BLACK : self::WHITE;
            }
            $tokens[$bgRole] = $movedBg;
            $adjustments[] = ['mode' => $mode, 'role' => $bgRole, 'change' => self::lightness($movedBg) < self::lightness($bg) ? 'darkened' : 'lightened'];
        }

        return $tokens;
    }

    /** Steps a colour's lightness away from another until the pair reaches `$min`, keeping hue and chroma. */
    private static function moveAway(string $hex, string $from, float $min): string
    {
        [$l, $c, $h] = TenantTheme::toOklch($hex);
        $down = self::lightness($from) > 0.6;
        $color = $hex;
        while (TenantTheme::contrast($color, $from) < $min && ($down ? $l > 0.0 : $l < 1.0)) {
            $l = $down ? max(0.0, $l - 0.01) : min(1.0, $l + 0.01);
            $color = TenantTheme::oklchHex($l, $c, $h);
        }

        return $color;
    }

    private static function bestLabel(string $bg): string
    {
        return TenantTheme::contrast(self::WHITE, $bg) >= TenantTheme::contrast(self::NEAR_BLACK, $bg) ? self::WHITE : self::NEAR_BLACK;
    }

    private static function lightness(string $hex): float
    {
        return TenantTheme::toOklch($hex)[0];
    }

    /**
     * The neutral's hue and chroma (3.1): pure grey by default for Calm and
     * Monochrome (2.3), the primary's hue for Tonal, Vibrant and Expressive.
     *
     * @return array{0: float, 1: float}
     */
    private static function neutral(mixed $setting, string $scheme, string $primary): array
    {
        $setting = in_array($setting, ThemeValidator::NEUTRALS, true)
            ? $setting
            : (in_array($scheme, ['calm', 'monochrome'], true) ? 'pure' : 'tinted');
        [, , $hue] = TenantTheme::toOklch(strtolower($primary));

        return match ($setting) {
            'pure' => [0.0, 0.0],
            'warm' => [70.0, 0.010],
            'cool' => [250.0, 0.010],
            default => [$hue, in_array($scheme, ['vibrant', 'expressive'], true) ? 0.018 : 0.010],
        };
    }

    /**
     * A secondary or tertiary seed as chroma and hue: the given colour, or
     * one turned from the primary's hue.
     *
     * @param array{0: int, 1: float} $rule Degrees to turn and the chroma cap.
     * @return array{0: float, 1: float}
     */
    private static function seed(mixed $hex, float $pc, float $ph, array $rule): array
    {
        if (is_string($hex) && preg_match(ThemeValidator::HEX_RE, $hex) === 1) {
            [, $c, $h] = TenantTheme::toOklch(strtolower($hex));

            return [$c, $h];
        }

        return [min($pc, $rule[1]), fmod($ph + $rule[0], 360.0)];
    }

    /**
     * The plane, soft or flat look (TH.9) and the float shadow's strength.
     *
     * @param array<string, mixed> $theme
     * @return array<string, string>
     */
    private static function elevation(array $theme, ?string $primary, ?string $plane, string $mode): array
    {
        $style = $theme['elevation']['style'] ?? 'plane';
        $out = [];
        if ($primary !== null && $plane !== null) {
            $out = match ($style) {
                'soft' => [
                    'plane-rest' => "0 2px 6px 0 {$primary}40",
                    'plane-hover' => "0 6px 16px 0 {$primary}4d",
                    'plane-primary-rest' => "0 2px 6px 0 {$plane}59",
                    'plane-primary-hover' => "0 6px 16px 0 {$plane}66",
                ],
                // TH.19: no shadows at all; the plane is a 2px border, and
                // floating layers get a 1px border (shadow-float below).
                'flat', 'outline' => [
                    'plane-rest' => "0 0 0 2px {$plane}",
                    'plane-hover' => "0 0 0 2px {$primary}",
                    'plane-primary-rest' => "0 0 0 2px {$plane}",
                    'plane-primary-hover' => "0 0 0 2px {$primary}",
                ],
                default => [
                    'plane-rest' => '-3px 3px 0 0 ' . $primary,
                    'plane-hover' => '-5px 5px 0 0 ' . $primary,
                    'plane-primary-rest' => '-3px 3px 0 0 ' . $plane,
                    'plane-primary-hover' => '-5px 5px 0 0 ' . $plane,
                ],
            };
        } elseif ($style === 'soft' || $style === 'flat' || $style === 'outline') {
            $base = self::base($mode);
            return self::elevation($theme, $base['primary'], $base['plane-deep'], $mode);
        }
        if ($style === 'outline') {
            $out['shadow-float'] = '0 0 0 1px ' . (self::base($mode)['border'] ?? ($mode === 'dark' ? '#333333' : '#e5e5e5'));

            return $out;
        }
        $float = $theme['elevation']['float'] ?? 'standard';
        $strength = ['light' => [[0x1f, 0x0f], [0x66, 0x40]], 'strong' => [[0x42, 0x24], [0xb3, 0x73]]][$float] ?? null;
        if ($strength !== null) {
            [$a, $b] = $strength[$mode === 'dark' ? 1 : 0];
            $out['shadow-float'] = sprintf('0 8px 24px -6px #000000%02x, 0 2px 6px -2px #000000%02x', $a, $b);
        }

        return $out;
    }

    /**
     * Type, shape, density and motion as custom properties (3.2-3.6). The
     * same in light and dark.
     *
     * @param array<string, mixed> $theme
     * @return array<string, string>
     */
    private static function vars(array $theme, string $body, string $heading): array
    {
        $vars = [];
        if ($body !== ThemeFonts::DEFAULT) {
            $vars['font-sans'] = ThemeFonts::stack($body);
        }
        if ($heading !== $body || $body !== ThemeFonts::DEFAULT) {
            $vars['font-heading'] = ThemeFonts::stack($heading, true);
        }
        $scale = self::SCALES[$theme['type']['scale'] ?? 'standard'] ?? null;
        foreach ($scale ?? [] as $step => $px) {
            $vars["text-{$step}"] = self::rem($px);
        }

        $density = $theme['density'] ?? 'standard';
        [$small, $control, $large, $row] = self::DENSITY[$density] ?? self::DENSITY['standard'];
        if ($density !== 'standard' && isset(self::DENSITY[$density])) {
            $vars += ['control-h-sm' => "{$small}px", 'control-h' => "{$control}px", 'control-h-lg' => "{$large}px", 'row-h' => "{$row}px"];
        }
        $shape = $theme['shape']['preset'] ?? 'whity';
        if (($shape !== 'whity' || $density !== 'standard') && isset(self::SHAPES[$shape])) {
            [$share, $sm, $md, $lg, $xl, $xxl, $card, $dialog] = self::SHAPES[$shape];
            // Round's pill is half the control's own height, not 999px: a
            // single-line control still reads as a pill, and anything that
            // grows (a textarea, a chip field, a card built on the control
            // radius) keeps that corner instead of turning into a stadium
            // that cuts its text (WHIT-1225, 30-themes 3.3).
            $radius = static fn (int $h): string => self::controlRadius($share, $h) . 'px';
            $vars += [
                'wy-radius-control-sm' => $radius($small),
                'wy-radius-control' => $radius($control),
                'wy-radius-control-lg' => $radius($large),
                'wy-radius-sm' => "{$sm}px", 'wy-radius-md' => "{$md}px", 'wy-radius-lg' => "{$lg}px",
                'wy-radius-xl' => "{$xl}px", 'wy-radius-2xl' => "{$xxl}px",
                'wy-radius-card' => "{$card}px", 'wy-radius-dialog' => "{$dialog}px",
            ];
            if ($shape === 'cut') {
                $vars['wy-corner-shape'] = 'bevel';
            }
        }
        // TH.18: the main button squares off while pressed and a selected chip
        // goes from its pill to a rounded corner. The person's Calm and Off
        // show that end state with no movement (--dur-morph).
        $morph = $theme['shape']['morph'] ?? (in_array($shape, self::MORPH_BY_DEFAULT, true) ? 'on' : 'off');
        if ($morph === 'on' && isset(self::SHAPES[$shape])) {
            $share = self::SHAPES[$shape][0];
            $vars['wy-radius-control-pressed'] = intdiv(self::controlRadius($share, $control), 2) . 'px';
            $vars['wy-radius-control-selected'] = (int) round($small * 0.25) . 'px';
        }

        // TH.21 (30-themes 14.2): a component family with its own preset
        // gets the whole radius set under --wy-<family>-*, at this density,
        // with its own morph and corner, so nothing falls through to the
        // theme's preset. The family's element sets the --shape-* values its
        // utilities read (generate-tokens.js, SHAPE_FAMILIES); every preset
        // is one the corner rules and the CI check already cover.
        $components = $theme['shape']['components'] ?? [];
        foreach (ThemeValidator::COMPONENT_FAMILIES as $family) {
            $preset = is_array($components) ? ($components[$family] ?? null) : null;
            if (!is_string($preset) || !isset(self::SHAPES[$preset]) || $preset === $shape) {
                continue;
            }
            $morphs = ($theme['shape']['morph'] ?? (in_array($preset, self::MORPH_BY_DEFAULT, true) ? 'on' : 'off')) === 'on';
            foreach (self::radii($preset, $small, $control, $large, $morphs) as $name => $value) {
                $vars["wy-{$family}-{$name}"] = $value;
            }
        }

        $motion = $theme['motion']['preset'] ?? 'calm';
        if (isset(self::SPRINGS[$motion])) {
            [$fast, $default, $slow] = self::SPRINGS[$motion];
            // Hover and press stay quick whatever the spring (06-motion).
            $pace = self::MOTION_PACE[$motion] ?? 1.0;
            $vars['dur-fast'] = self::paced(min(200, self::settle(...$fast)), $pace) . 'ms';
            $vars['dur-base'] = self::paced(min(350, self::settle(...$default)), $pace) . 'ms';
            $vars['dur-slow'] = self::paced(min(500, self::settle(...$slow)), $pace) . 'ms';
            // The curve keeps its shape; only the time it takes changes.
            $vars['ease-out'] = self::springCurve(...$default);
        }

        return $vars;
    }

    /**
     * One preset's whole radius set at the given control heights, as the
     * names after `wy-` (or `wy-<family>-`): every radius, the pressed and
     * selected radii (the control's own when it doesn't morph) and the corner.
     *
     * @return array<string, string>
     */
    private static function radii(string $shape, int $small, int $control, int $large, bool $morph): array
    {
        [$share, $sm, $md, $lg, $xl, $xxl, $card, $dialog] = self::SHAPES[$shape];
        $controlRadius = self::controlRadius($share, $control);

        return [
            'radius-control-sm' => self::controlRadius($share, $small) . 'px',
            'radius-control' => "{$controlRadius}px",
            'radius-control-lg' => self::controlRadius($share, $large) . 'px',
            'radius-sm' => "{$sm}px", 'radius-md' => "{$md}px", 'radius-lg' => "{$lg}px",
            'radius-xl' => "{$xl}px", 'radius-2xl' => "{$xxl}px",
            'radius-card' => "{$card}px", 'radius-dialog' => "{$dialog}px",
            'radius-control-pressed' => ($morph ? intdiv($controlRadius, 2) : $controlRadius) . 'px',
            'radius-control-selected' => ($morph ? (int) round($small * 0.25) : self::controlRadius($share, $small)) . 'px',
            'corner-shape' => $shape === 'cut' ? 'bevel' : 'round',
        ];
    }

    /** The control radius for a share of its height; a negative share is the pill, half the height (3.3). */
    private static function controlRadius(float $share, int $height): int
    {
        return $share < 0 ? intdiv($height, 2) : (int) round($height * $share);
    }

    private static function paced(int $ms, float $pace): int
    {
        return (int) (round($ms * $pace / 10) * 10);
    }

    /** Milliseconds until a spring of mass 1 is within 0.2% of rest. */
    private static function settle(float $damping, float $stiffness): int
    {
        $omega = sqrt($stiffness);

        return (int) (round(-log(0.002) / ($damping * $omega) * 1000 / 10) * 10);
    }

    /** The spring as a CSS `linear()` easing over its settling time, so the web needs no motion library. */
    private static function springCurve(float $damping, float $stiffness): string
    {
        $omega = sqrt($stiffness);
        $duration = self::settle($damping, $stiffness) / 1000;
        $damped = $omega * sqrt(1 - $damping * $damping);
        $points = [];
        for ($i = 0; $i <= 20; $i++) {
            $t = $duration * $i / 20;
            $x = 1 - exp(-$damping * $omega * $t) * (cos($damped * $t) + ($damping * $omega / $damped) * sin($damped * $t));
            $points[] = rtrim(rtrim(number_format($i === 20 ? 1.0 : $x, 3, '.', ''), '0'), '.') ?: '0';
        }

        return 'linear(' . implode(', ', $points) . ')';
    }

    private static function rem(int $px): string
    {
        return rtrim(rtrim(number_format($px / 16, 4, '.', ''), '0'), '.') . 'rem';
    }

    /**
     * Under deuteranopia and protanopia, every two chart series stay at least
     * 0.08 apart in OKLab (2.7).
     *
     * @param list<string> $series
     */
    private static function seriesTellApart(array $series): bool
    {
        foreach (['deutan', 'protan'] as $kind) {
            $lab = array_map(static fn (string $hex): array => self::simulatedLab($hex, $kind), $series);
            for ($i = 0; $i < count($lab); $i++) {
                for ($j = $i + 1; $j < count($lab); $j++) {
                    $d = sqrt(($lab[$i][0] - $lab[$j][0]) ** 2 + ($lab[$i][1] - $lab[$j][1]) ** 2 + ($lab[$i][2] - $lab[$j][2]) ** 2);
                    if ($d < 0.08) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * OKLab of a colour as seen with full deuteranopia or protanopia
     * (Machado, Oliveira and Fernandes 2009, severity 1).
     *
     * @return array{0: float, 1: float, 2: float}
     */
    private static function simulatedLab(string $hex, string $kind): array
    {
        $m = $kind === 'deutan'
            ? [[0.367322, 0.860646, -0.227968], [0.280085, 0.672501, 0.047413], [-0.011820, 0.042940, 0.968881]]
            : [[0.152286, 1.052583, -0.204868], [0.114503, 0.786281, 0.099216], [-0.003882, -0.048116, 1.051998]];
        $rgb = [];
        foreach ([1, 3, 5] as $i) {
            $v = hexdec(substr($hex, $i, 2)) / 255;
            $rgb[] = $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }
        $sim = [];
        foreach ($m as $row) {
            $sim[] = min(1.0, max(0.0, $row[0] * $rgb[0] + $row[1] * $rgb[1] + $row[2] * $rgb[2]));
        }
        $l = (0.4122214708 * $sim[0] + 0.5363325363 * $sim[1] + 0.0514459929 * $sim[2]) ** (1 / 3);
        $mm = (0.2119034982 * $sim[0] + 0.6806995451 * $sim[1] + 0.1073969566 * $sim[2]) ** (1 / 3);
        $s = (0.0883024619 * $sim[0] + 0.2817188376 * $sim[1] + 0.6299787005 * $sim[2]) ** (1 / 3);

        return [
            0.2104542553 * $l + 0.793617785 * $mm - 0.0040720468 * $s,
            1.9779984951 * $l - 2.428592205 * $mm + 0.4505937099 * $s,
            0.0259040371 * $l + 0.7827717662 * $mm - 0.808675766 * $s,
        ];
    }

    /**
     * Drops tokens equal to the Whity default, so only real changes are
     * written. Plane shadows and the float shadow are compared as text.
     *
     * @param array<string, string> $tokens
     * @param list<string> $keep Roles written even when equal to the default.
     * @return array<string, string>
     */
    private static function onlyChanged(array $tokens, string $mode, array $keep = []): array
    {
        $base = self::base($mode);
        $out = [];
        foreach ($tokens as $role => $value) {
            if (($base[$role] ?? null) !== $value || in_array($role, $keep, true)) {
                $out[$role] = $value;
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * The Whity default colours as #rrggbb per mode, from the generated
     * colour reference (`theme.json`); translucent ones are left out.
     *
     * @return array<string, string>
     */
    public static function base(string $mode): array
    {
        if (self::$base === null) {
            self::$base = ['light' => [], 'dark' => []];
            $raw = @file_get_contents(__DIR__ . '/../../design/tokens/generated/theme.json');
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            foreach (is_array($decoded['colors'] ?? null) ? $decoded['colors'] : [] as $role => $def) {
                foreach (['light', 'dark'] as $m) {
                    $hex = $def[$m . 'Hex'] ?? null;
                    if (is_string($hex) && preg_match('/^#[0-9a-f]{6}$/', $hex) === 1 && !str_contains((string) ($def[$m] ?? ''), '/')) {
                        self::$base[$m][$role] = $hex;
                    }
                }
            }
        }

        return self::$base[$mode] ?? [];
    }
}
