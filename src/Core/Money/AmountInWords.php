<?php

declare(strict_types=1);

namespace Whity\Core\Money;

/**
 * An amount written in words, in English or Arabic (16, CU.3).
 *
 * The design's `Whity.amountInWords(amount, lang, currency)`, working in whole
 * minor units so no float is involved. Each currency brings its own nouns and
 * sub-unit: one US dollar is 100 cents, one Jordanian dinar is 1000 fils.
 *
 * The Arabic follows accounting usage as section 16 draws it: the noun's form
 * follows the last two digits. Section 16 still asks for an Arabic-speaking
 * accountant's review before this is relied on, so nothing here claims it meets
 * any invoicing rule. Only the currencies in {@see self::WORDS} are written:
 * another currency's nouns and gender would be different words, so the caller
 * gets null rather than wrong ones.
 */
final class AmountInWords
{
    /** The design's words stop at millions, so this is the most whole units it can write. */
    private const MAX_UNITS = 999_999_999;

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
    /**
     * Each currency's nouns, from the design's `Whity.currencies`. English is
     * [one, many]; Arabic is [one, two, 3-10, 11-99, otherwise].
     *
     * @var array<string, array{en: list<string>, subEn: list<string>, ar: list<string>, subAr: list<string>}>
     */
    private const WORDS = [
        'USD' => [
            'en' => ['US dollar', 'US dollars'],
            'subEn' => ['cent', 'cents'],
            'ar' => ['دولار أمريكي واحد', 'دولاران أمريكيان', 'دولارات أمريكية', 'دولاراً أمريكياً', 'دولار أمريكي'],
            'subAr' => ['سنت واحد', 'سنتان', 'سنتات', 'سنتاً', 'سنت'],
        ],
        'EUR' => [
            'en' => ['euro', 'euros'],
            'subEn' => ['cent', 'cents'],
            'ar' => ['يورو واحد', 'يوروان', 'يوروات', 'يورو', 'يورو'],
            'subAr' => ['سنت واحد', 'سنتان', 'سنتات', 'سنتاً', 'سنت'],
        ],
        'JOD' => [
            'en' => ['Jordanian dinar', 'Jordanian dinars'],
            'subEn' => ['fils', 'fils'],
            'ar' => ['دينار أردني واحد', 'ديناران أردنيان', 'دنانير أردنية', 'ديناراً أردنياً', 'دينار أردني'],
            'subAr' => ['فلس واحد', 'فلسان', 'فلسات', 'فلساً', 'فلس'],
        ],
        'SAR' => [
            'en' => ['Saudi riyal', 'Saudi riyals'],
            'subEn' => ['halala', 'halalas'],
            'ar' => ['ريال سعودي واحد', 'ريالان سعوديان', 'ريالات سعودية', 'ريالاً سعودياً', 'ريال سعودي'],
            'subAr' => ['هللة واحدة', 'هللتان', 'هللات', 'هللة', 'هللة'],
        ],
        'AED' => [
            'en' => ['UAE dirham', 'UAE dirhams'],
            'subEn' => ['fils', 'fils'],
            'ar' => ['درهم إماراتي واحد', 'درهمان إماراتيان', 'دراهم إماراتية', 'درهماً إماراتياً', 'درهم إماراتي'],
            'subAr' => ['فلس واحد', 'فلسان', 'فلسات', 'فلساً', 'فلس'],
        ],
    ];

    /**
     * @param int    $minor    The amount in the currency's minor units (cents for USD, fils for JOD).
     * @param string $currency ISO code. One without words here answers null.
     * @param string $language 'en' or 'ar'.
     */
    public static function write(int $minor, string $currency, string $language): ?string
    {
        $code = strtoupper(trim($currency));
        $words = self::WORDS[$code] ?? null;
        if ($words === null || !in_array($language, ['en', 'ar'], true)) {
            return null;
        }

        $perMajor = Currency::minorUnitsPerMajor($code);
        $magnitude = abs($minor);
        $units = intdiv($magnitude, $perMajor);
        if ($units > self::MAX_UNITS) {
            return null;
        }
        $sub = $magnitude % $perMajor;

        return $language === 'ar'
            ? self::arabic($units, $sub, $words['ar'], $words['subAr'])
            : self::english($units, $sub, $words['en'], $words['subEn']);
    }

    /** Whether {@see self::write()} has words for this currency. */
    public static function supports(string $currency): bool
    {
        return isset(self::WORDS[strtoupper(trim($currency))]);
    }

    /**
     * @param list<string> $nouns
     * @param list<string> $subNouns
     */
    private static function english(int $units, int $sub, array $nouns, array $subNouns): string
    {
        $parts = [];
        if ($units > 0 || $sub === 0) {
            $parts[] = self::enInteger($units) . ' ' . ($units === 1 ? $nouns[0] : $nouns[1]);
        }
        if ($sub > 0) {
            $parts[] = self::enInteger($sub) . ' ' . ($sub === 1 ? $subNouns[0] : $subNouns[1]);
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

    /**
     * @param list<string> $nouns
     * @param list<string> $subNouns
     */
    private static function arabic(int $units, int $sub, array $nouns, array $subNouns): string
    {
        $parts = [];
        if ($units > 0 || $sub === 0) {
            $parts[] = self::arCount($units, $nouns);
        }
        if ($sub > 0) {
            $parts[] = self::arCount($sub, $subNouns);
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
