<?php

declare(strict_types=1);

namespace Whity\Core\Inventory;

/**
 * Reads a GS1 barcode (GS1-128, GS1 DataMatrix or QR) as a scanner sends it
 * (WHIT-1235, design 31 §3.5): the product's GTIN, and the batch, expiry,
 * production date, serial number and count it carries, so one scan fills the
 * item, its batch and its expiry on a receipt.
 *
 * Accepts the raw element string (fields of variable length end at the group
 * separator, ASCII 29, or at the end), the human-readable form with
 * application identifiers in brackets, `(01)06251234567890(17)270331(10)B-0412`,
 * and a leading symbology identifier such as `]C1`, `]d2` or `]Q3`. Anything
 * else is not GS1 and gives null, so a plain EAN or SKU is looked up as it is.
 */
final class Gs1Barcode
{
    private const GS = "\x1D";

    /** Application identifier => fixed length of its data, or null when it runs to a separator (with its most). */
    private const FIXED = ['00' => 18, '01' => 14, '02' => 14, '11' => 6, '12' => 6, '13' => 6, '15' => 6, '16' => 6, '17' => 6, '20' => 2];
    private const VARIABLE = ['10' => 20, '21' => 20, '22' => 20, '30' => 8, '37' => 8, '240' => 30, '241' => 30, '250' => 30, '400' => 30];

    /**
     * @return array{gtin: string, lot: ?string, expires_on: ?string, made_on: ?string, serial: ?string, count: ?int}|null
     */
    public static function parse(string $raw): ?array
    {
        $text = str_replace(['<GS>', '{GS}', '~1'], self::GS, trim($raw));
        $text = preg_replace('/^\][A-Za-z]\d/', '', $text) ?? $text;
        $text = strtr($text, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
        $fields = str_starts_with($text, '(') ? self::bracketed($text) : self::elementString($text);
        if ($fields === null || $fields === []) {
            return null;
        }
        $out = ['gtin' => null, 'lot' => null, 'expires_on' => null, 'made_on' => null, 'serial' => null, 'count' => null];
        foreach ($fields as [$ai, $data]) {
            switch ($ai) {
                case '01':
                case '02':
                    if (preg_match('/^\d{14}$/', $data) !== 1) {
                        return null;
                    }
                    $out['gtin'] ??= $data;
                    break;
                case '10':
                    $out['lot'] = $data;
                    break;
                case '21':
                    $out['serial'] = $data;
                    break;
                case '17':
                case '15':
                    $date = self::date($data, true);
                    if ($date === null) {
                        return null;
                    }
                    // A use-by date (17) wins over a best-before date (15).
                    $out['expires_on'] = $ai === '17' ? $date : ($out['expires_on'] ?? $date);
                    break;
                case '11':
                case '13':
                    $out['made_on'] ??= self::date($data, false);
                    break;
                case '30':
                case '37':
                    $out['count'] = preg_match('/^\d{1,8}$/', $data) === 1 ? (int) $data : null;
                    break;
            }
        }

        return $out['gtin'] === null ? null : $out;
    }

    /**
     * The codes an item's barcode may be stored as for this GTIN-14: itself,
     * and the GTIN-13, -12 and -8 it holds when it starts with zeros.
     *
     * @return list<string>
     */
    public static function gtinForms(string $gtin): array
    {
        $forms = [$gtin];
        foreach ([13, 12, 8] as $length) {
            $cut = 14 - $length;
            if (str_repeat('0', $cut) === substr($gtin, 0, $cut)) {
                $forms[] = substr($gtin, $cut);
            }
        }

        return $forms;
    }

    /** @return list<array{0: string, 1: string}>|null */
    private static function bracketed(string $text): ?array
    {
        if (preg_match_all('/\((\d{2,4})\)([^(]*)/', $text, $m, PREG_SET_ORDER) === 0) {
            return null;
        }
        $fields = [];
        foreach ($m as $part) {
            $fields[] = [$part[1], trim(str_replace(self::GS, '', $part[2]))];
        }

        return $fields;
    }

    /** @return list<array{0: string, 1: string}>|null */
    private static function elementString(string $text): ?array
    {
        // A GS1 element string starts with an application identifier; a plain number is a plain barcode.
        if (!preg_match('/^(01|02)\d{14}/', $text)) {
            return null;
        }
        $fields = [];
        $at = 0;
        $length = strlen($text);
        while ($at < $length) {
            if ($text[$at] === self::GS) {
                $at++;
                continue;
            }
            $ai = null;
            foreach ([2, 3] as $size) {
                $candidate = substr($text, $at, $size);
                if (isset(self::FIXED[$candidate]) || isset(self::VARIABLE[$candidate])) {
                    $ai = $candidate;
                    break;
                }
            }
            if ($ai === null) {
                // An identifier this reader doesn't know: its length is unknown, so stop here with what was read.
                break;
            }
            $at += strlen($ai);
            if (isset(self::FIXED[$ai])) {
                $data = substr($text, $at, self::FIXED[$ai]);
                if (strlen($data) !== self::FIXED[$ai]) {
                    return null;
                }
                $at += self::FIXED[$ai];
            } else {
                $end = strpos($text, self::GS, $at);
                $data = substr($text, $at, ($end === false ? $length : $end) - $at);
                if ($data === '' || strlen($data) > (self::VARIABLE[$ai] ?? 0)) {
                    return null;
                }
                $at += strlen($data);
            }
            $fields[] = [$ai, $data];
        }

        return $fields;
    }

    /** YYMMDD as a date; DD 00 is the month's last day for an expiry. The century is the one that puts it nearest today. */
    private static function date(string $data, bool $expiry): ?string
    {
        if (preg_match('/^(\d{2})(\d{2})(\d{2})$/', $data, $m) !== 1) {
            return null;
        }
        [$yy, $mm, $dd] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        if ($mm < 1 || $mm > 12) {
            return null;
        }
        // GS1 General Specifications 7.12: within 49 years back and 50 forward.
        $now = (int) gmdate('Y');
        $year = intdiv($now, 100) * 100 + $yy;
        if ($year - $now > 50) {
            $year -= 100;
        } elseif ($now - $year > 49) {
            $year += 100;
        }
        if ($dd === 0) {
            if (!$expiry) {
                return null;
            }
            $dd = (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $mm)))->format('t');
        }
        if (!checkdate($mm, $dd, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $mm, $dd);
    }
}
