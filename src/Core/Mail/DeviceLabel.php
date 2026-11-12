<?php

declare(strict_types=1);

namespace Whity\Core\Mail;

/**
 * A person-readable name for a browser's User-Agent ("Chrome on Windows"), for
 * the new sign-in email. Plain words, never the raw header.
 */
final class DeviceLabel
{
    public static function from(?string $userAgent, string $language = 'en'): string
    {
        $ua = (string) $userAgent;
        $ar = $language === 'ar';

        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            str_contains($ua, 'Whity') => 'Whity',
            default => null,
        };
        $system = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS X'), str_contains($ua, 'Macintosh') => 'Mac',
            str_contains($ua, 'Linux') => 'Linux',
            default => null,
        };

        if ($browser !== null && $system !== null) {
            return $ar ? $browser . ' على ' . $system : $browser . ' on ' . $system;
        }

        return $browser ?? $system ?? ($ar ? 'جهاز غير معروف' : 'An unknown device');
    }
}
