<?php

declare(strict_types=1);

namespace Whity\Core\Contacts;

/**
 * Tidies a phone or mobile number as people type it (WHIT-953).
 *
 * Spaces, dashes, dots and brackets go; `00` at the start becomes `+`. When
 * the workspace gives a country code (such as `+962`) a local number gets it:
 * the leading 0 is dropped and the code put in front, which the import says
 * it did. Stateless.
 */
final class PhoneNumber
{
    /**
     * @return array{value: string, fixed: bool}|null the tidied number and whether
     *         a country code was added, or null when it doesn't look like a number
     */
    public static function tidy(string $raw, ?string $countryCode = null): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return ['value' => '', 'fixed' => false];
        }
        // Western digits only: Arabic-Indic digits are converted first.
        $raw = strtr($raw, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        $number = (string) preg_replace('/[\s\-\.\(\)\/]/u', '', $raw);
        if (str_starts_with($number, '00')) {
            $number = '+' . substr($number, 2);
        }
        if (preg_match('/^\+?[0-9]{5,20}$/', $number) !== 1) {
            return null;
        }
        $fixed = false;
        $code = self::countryCode($countryCode);
        if (!str_starts_with($number, '+') && $code !== null) {
            $number = $code . ltrim($number, '0');
            $fixed = true;
        }

        return ['value' => $number, 'fixed' => $fixed];
    }

    /** `+962`, `962` and `00962` all become `+962`; anything else is null. */
    public static function countryCode(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }
        $code = trim($code);
        if (str_starts_with($code, '00')) {
            $code = substr($code, 2);
        }
        $code = ltrim($code, '+');

        return preg_match('/^[1-9][0-9]{0,3}$/', $code) === 1 ? '+' . $code : null;
    }
}
