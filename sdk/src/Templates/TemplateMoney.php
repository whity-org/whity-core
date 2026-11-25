<?php

declare(strict_types=1);

namespace Whity\Sdk\Templates;

/**
 * A template's price in normal units ("10.000", "14.00") as the minor units
 * core's routes take, in the currency's own decimals (CU.1; SDK 1.169).
 *
 * The decimals are ISO 4217's, the same table as core's `Money\Currency`
 * (core's `TemplateMoneyTest` fails if the two ever disagree).
 */
final class TemplateMoney
{
    private const EXPONENTS = [
        'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3, 'TND' => 3,
        'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'ISK' => 0, 'JPY' => 0, 'KMF' => 0, 'KRW' => 0,
        'PYG' => 0, 'RWF' => 0, 'UGX' => 0, 'UYI' => 0, 'VND' => 0, 'VUV' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
        'CLF' => 4, 'UYW' => 4,
    ];

    private function __construct()
    {
    }

    /** How many decimals the currency has: 3 for JOD, 2 for USD, 0 for JPY. */
    public static function exponent(string $currency): int
    {
        return self::EXPONENTS[strtoupper(trim($currency))] ?? 2;
    }

    /**
     * "10.000" in JOD is 10000; "10.000" in USD is 1000 (trailing zeros past
     * the currency's decimals are dropped, anything real is kept). Null when
     * the amount cannot be read or has more decimals than the currency.
     */
    public static function toMinor(string $amount, string $currency): ?int
    {
        $amount = trim($amount);
        if (str_contains($amount, '.')) {
            $amount = rtrim(rtrim($amount, '0'), '.');
        }
        if (preg_match('/^(\d{1,15})(?:\.(\d+))?$/', $amount, $match) !== 1) {
            return null;
        }
        $exponent = self::exponent($currency);
        $fraction = $match[2] ?? '';
        if (strlen($fraction) > $exponent) {
            return null;
        }

        return (int) ($match[1] . str_pad($fraction, $exponent, '0'));
    }
}
