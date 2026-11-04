<?php

declare(strict_types=1);

namespace Whity\Core\Money;

/**
 * An amount of Jordanian dinars written in words, in English or Arabic (16).
 *
 * The design's `Whity.amountInWords`, working in whole fils so no float is
 * involved. One dinar is 1000 fils.
 *
 * The Arabic follows accounting usage as section 16 draws it: the noun's form
 * follows the last two digits. Section 16 still asks for an Arabic-speaking
 * accountant's review before this is relied on, so nothing here claims it meets
 * any invoicing rule. Only JOD is written: another currency's sub-unit and gender
 * would be different words, so the caller gets null rather than wrong ones.
 */
final class AmountInWords
{
    /** The design's words stop at millions, so this is the most it can write. */
    private const MAX_FILS = 999_999_999_999;

    private const EN_ONES = [
        'zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve',
        'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen',
    ];
    private const EN_TENS = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];
    private const EN_SCALES = ['', ' thousand', ' million'];

    private const AR_ONES = ['', 'واحد', 'اثنان', 'ثلاثة', 'أربعة', 'خمسة', 'ستة', 'سبعة', 'ثمانية', 'تسعة', 'عشرة'];
    private const AR_TENS = ['', 'عشرة', 'عشرون', 'ثلاثون', 'أربعون', 'خمسون', 'ستون', 'سبعون', 'ثمانون', 'تسعون'];
    private const AR_HUNDREDS = ['', 'مئة', 'مئتان', 'ثلاثمئة', 'أربعمئة', 'خمسمئة', 'ستمئة', 'سبعمئة', 'ثمانمئة', 'تسعمئة'];
    /** one, two, 3-10, 11+ with more to come, otherwise */
    private const AR_SCALES = [
        1 => ['ألف', 'ألفان', 'آلاف', 'ألفاً'],
        2 => ['مليون', 'مليونان', 'ملايين', 'مليوناً'],
    ];
    /** one, two, 3-10, 11-99, otherwise */
    private const AR_DINARS = ['دينار أردني واحد', 'ديناران أردنيان', 'دنانير أردنية', 'ديناراً أردنياً', 'دينار أردني'];
    private const AR_FILS = ['فلس واحد', 'فلسان', 'فلسات', 'فلساً', 'فلس'];

    /**
     * @param int    $minor    The amount in the currency's minor units (fils for JOD).
     * @param string $currency ISO code. Anything but JOD answers null.
     * @param string $language 'en' or 'ar'.
     */
    public static function write(int $minor, string $currency, string $language): ?string
    {
        if (strtoupper($currency) !== 'JOD' || !in_array($language, ['en', 'ar'], true)) {
            return null;
        }

        $fils = abs($minor);
        if ($fils > self::MAX_FILS) {
            return null;
        }

        $dinars = intdiv($fils, 1000);
        $remainder = $fils % 1000;

        return $language === 'ar' ? self::arabic($dinars, $remainder) : self::english($dinars, $remainder);
    }

    private static function english(int $dinars, int $fils): string
    {
        $parts = [];
        if ($dinars > 0 || $fils === 0) {
            $parts[] = self::enInteger($dinars) . ' Jordanian ' . ($dinars === 1 ? 'dinar' : 'dinars');
        }
        if ($fils > 0) {
            $parts[] = self::enInteger($fils) . ' fils';
        }

        return ucfirst(implode(' and ', $parts));
    }

    private static function enInteger(int $n): string
    {
        if ($n === 0) {
            return 'zero';
        }

        $parts = [];
        $scale = 0;
        $unitsAreSmall = false;
        while ($n > 0) {
            $group = $n % 1000;
            if ($group > 0) {
                array_unshift($parts, self::en999($group) . self::EN_SCALES[$scale]);
                if ($scale === 0 && $group < 100) {
                    $unitsAreSmall = true;
                }
            }
            $n = intdiv($n, 1000);
            $scale++;
        }
        if ($unitsAreSmall && count($parts) > 1) {
            $parts[count($parts) - 1] = 'and ' . $parts[count($parts) - 1];
        }

        return implode(' ', $parts);
    }

    private static function en999(int $n): string
    {
        $hundreds = intdiv($n, 100);
        $rest = $n % 100;
        $parts = [];
        if ($hundreds > 0) {
            $parts[] = self::EN_ONES[$hundreds] . ' hundred';
        }
        if ($rest > 0) {
            $words = $rest < 20
                ? self::EN_ONES[$rest]
                : self::EN_TENS[intdiv($rest, 10)] . ($rest % 10 > 0 ? '-' . self::EN_ONES[$rest % 10] : '');
            $parts[] = $hundreds > 0 ? 'and ' . $words : $words;
        }

        return implode(' ', $parts);
    }

    private static function arabic(int $dinars, int $fils): string
    {
        $parts = [];
        if ($dinars > 0 || $fils === 0) {
            $parts[] = self::arCount($dinars, self::AR_DINARS);
        }
        if ($fils > 0) {
            $parts[] = self::arCount($fils, self::AR_FILS);
        }

        return implode(' و', $parts);
    }

    /** @param list<string> $forms */
    private static function arCount(int $n, array $forms): string
    {
        if ($n === 1) {
            return $forms[0];
        }
        if ($n === 2) {
            return $forms[1];
        }

        $lastTwo = $n % 100;
        $noun = $lastTwo >= 3 && $lastTwo <= 10 ? $forms[2] : ($lastTwo >= 11 ? $forms[3] : $forms[4]);

        return self::arInteger($n) . ' ' . $noun;
    }

    private static function arInteger(int $n): string
    {
        if ($n === 0) {
            return 'صفر';
        }

        $groups = [];
        while ($n > 0) {
            $groups[] = $n % 1000;
            $n = intdiv($n, 1000);
        }

        $parts = [];
        for ($k = count($groups) - 1; $k >= 0; $k--) {
            $count = $groups[$k];
            if ($count === 0) {
                continue;
            }
            $scale = self::AR_SCALES[$k] ?? null;
            $rest = array_sum(array_slice($groups, 0, $k));

            if ($scale === null) {
                $words = self::ar999($count);
            } elseif ($count === 1) {
                $words = $scale[0];
            } elseif ($count === 2) {
                // The two-thousand form loses its nun when nothing follows it.
                $words = $rest > 0 ? $scale[1] : (string) preg_replace('/ان$/u', 'ا', $scale[1]);
            } else {
                $lastTwo = $count % 100;
                $noun = $lastTwo >= 3 && $lastTwo <= 10 ? $scale[2] : ($lastTwo >= 11 && $rest > 0 ? $scale[3] : $scale[0]);
                $words = self::ar999($count) . ' ' . $noun;
            }

            if ($count === 200 && ($scale !== null || $rest === 0)) {
                $words = str_replace('مئتان', 'مئتا', $words);
            }
            $parts[] = $words;
        }

        return implode(' و', $parts);
    }

    private static function ar999(int $n): string
    {
        $hundreds = intdiv($n, 100);
        $rest = $n % 100;
        $parts = [];
        if ($hundreds > 0) {
            $parts[] = self::AR_HUNDREDS[$hundreds];
        }
        if ($rest > 0) {
            $parts[] = self::ar99($rest);
        }

        return implode(' و', $parts);
    }

    private static function ar99(int $n): string
    {
        if ($n <= 10) {
            return self::AR_ONES[$n];
        }
        if ($n === 11) {
            return 'أحد عشر';
        }
        if ($n === 12) {
            return 'اثنا عشر';
        }
        if ($n < 20) {
            return self::AR_ONES[$n - 10] . ' عشر';
        }

        $units = $n % 10;
        $tens = self::AR_TENS[intdiv($n, 10)];

        return $units > 0 ? self::AR_ONES[$units] . ' و' . $tens : $tens;
    }
}
