<?php

declare(strict_types=1);

namespace Whity\Core\Theme;

/**
 * The allowed font pairs (TH.6). Each is a Latin face and an Arabic face, with
 * the 400, 500 and 600 weights (3.6: no 700), loaded by the web app through
 * next/font (self-hosted at build time) and bundled in the phone app. A theme
 * names an id, never a file or a URL. Since TH.6 a theme may set the pair for
 * headings and body on product screens; print keeps E.46.
 *
 * Tajawal and Almarai, on the list the owner saw, are left out until there is
 * a 600 weight for them: both jump from 500 to 700, so 600 would print at 700.
 *
 * `latin` and `arabic` are the CSS custom properties next/font sets in
 * `web/app/layout.tsx`.
 */
final class ThemeFonts
{
    public const DEFAULT = 'noto';

    /**
     * `headingArabic`, when set, is the Arabic face for headings in that pair
     * (`ibm-kufi`: IBM Plex Sans Arabic for text, Noto Kufi Arabic for headings).
     *
     * @var array<string, array{name: string, latin: string, arabic: string, headingArabic?: string}>
     */
    private const PAIRS = [
        'noto' => ['name' => 'Noto Sans', 'latin' => 'font-noto-sans', 'arabic' => 'font-noto-sans-arabic'],
        'plex' => ['name' => 'IBM Plex Sans', 'latin' => 'font-ibm-plex-sans', 'arabic' => 'font-ibm-plex-sans-arabic'],
        'readex' => ['name' => 'Readex Pro', 'latin' => 'font-readex-pro', 'arabic' => 'font-readex-pro'],
        'cairo' => ['name' => 'Cairo', 'latin' => 'font-cairo', 'arabic' => 'font-cairo'],
        'kufi' => ['name' => 'Noto Sans and Noto Kufi Arabic', 'latin' => 'font-noto-sans', 'arabic' => 'font-noto-kufi-arabic'],
        // TH.20: chosen by the Arabic face; the Latin face follows. All OFL,
        // with 400, 500 and 600.
        'rubik' => ['name' => 'Rubik', 'latin' => 'font-rubik', 'arabic' => 'font-rubik'],
        'alexandria' => ['name' => 'Alexandria', 'latin' => 'font-alexandria', 'arabic' => 'font-alexandria'],
        'naskh' => ['name' => 'Noto Serif and Noto Naskh Arabic', 'latin' => 'font-noto-serif', 'arabic' => 'font-noto-naskh-arabic'],
        'ibm-kufi' => ['name' => 'IBM Plex Sans with Noto Kufi Arabic headings', 'latin' => 'font-ibm-plex-sans', 'arabic' => 'font-ibm-plex-sans-arabic', 'headingArabic' => 'font-noto-kufi-arabic'],
    ];

    /** @return list<string> */
    public static function ids(): array
    {
        return array_keys(self::PAIRS);
    }

    public static function name(string $id): string
    {
        return self::PAIRS[$id]['name'] ?? self::PAIRS[self::DEFAULT]['name'];
    }

    /** The family name behind each font variable, for exports outside Whity (TH.25). */
    private const FAMILIES = [
        'font-noto-sans' => 'Noto Sans', 'font-noto-sans-arabic' => 'Noto Sans Arabic',
        'font-ibm-plex-sans' => 'IBM Plex Sans', 'font-ibm-plex-sans-arabic' => 'IBM Plex Sans Arabic',
        'font-readex-pro' => 'Readex Pro', 'font-cairo' => 'Cairo', 'font-noto-kufi-arabic' => 'Noto Kufi Arabic',
        'font-rubik' => 'Rubik', 'font-alexandria' => 'Alexandria',
        'font-noto-serif' => 'Noto Serif', 'font-noto-naskh-arabic' => 'Noto Naskh Arabic',
    ];

    /**
     * The pair's family names, Latin then Arabic (one when they're the same
     * face), as another tool needs them: no CSS variables.
     *
     * @return list<string>
     */
    public static function families(string $id, bool $heading = false): array
    {
        $pair = self::PAIRS[$id] ?? self::PAIRS[self::DEFAULT];
        $arabic = $heading && isset($pair['headingArabic']) ? $pair['headingArabic'] : $pair['arabic'];

        return array_values(array_unique([self::FAMILIES[$pair['latin']], self::FAMILIES[$arabic]]));
    }

    /** The font stack as CSS: Latin first, then Arabic, then the system faces. */
    public static function stack(string $id, bool $heading = false): string
    {
        $pair = self::PAIRS[$id] ?? self::PAIRS[self::DEFAULT];
        $arabic = $heading && isset($pair['headingArabic']) ? $pair['headingArabic'] : $pair['arabic'];
        $vars = array_unique([$pair['latin'], $arabic]);

        return implode(', ', array_map(static fn (string $v): string => "var(--{$v})", $vars)) . ', ui-sans-serif, system-ui, sans-serif';
    }
}
