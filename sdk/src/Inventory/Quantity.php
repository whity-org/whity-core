<?php

declare(strict_types=1);

namespace Whity\Sdk\Inventory;

/**
 * An exact quantity of stock (SDK 1.126, WHIT-1232, register IV.3).
 *
 * Inventory never uses floats. A quantity is a whole number of a unit's
 * smallest STEP: a unit with 3 decimals (kg) counts in thousandths (grams), a
 * unit with 0 decimals (piece) counts whole pieces. Text in and out is a plain
 * decimal with a dot and Western digits ("2.5", "-12.000"); Arabic-Indic
 * digits are read as Western ones.
 *
 * Converting between units is a whole-number fraction ({@see self::convert()}),
 * rounded once, half away from zero, at the edge where a person typed it.
 */
final class Quantity
{
    /** The most decimals a unit may keep. */
    public const MAX_DECIMALS = 6;

    public function __construct(
        /** Whole steps of the unit: 2500 for 2.5 kg when kg keeps 3 decimals. */
        public readonly int $steps,
        /** How many decimals the unit keeps. */
        public readonly int $decimals,
    ) {
        if ($decimals < 0 || $decimals > self::MAX_DECIMALS) {
            throw new \InvalidArgumentException('decimals must be 0 to ' . self::MAX_DECIMALS);
        }
    }

    /**
     * Reads "2.5" as 2500 steps of a 3-decimal unit. Refuses text that isn't a
     * number, and more decimals than the unit keeps.
     *
     * @throws \InvalidArgumentException
     */
    public static function parse(string $text, int $decimals): self
    {
        $text = trim(strtr($text, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٫' => '.', '،' => '.',
        ]));
        if (preg_match('/^(-?)(\d{1,15})(?:\.(\d+))?$/', $text, $m) !== 1) {
            throw new \InvalidArgumentException('"' . $text . '" is not a quantity');
        }
        $fraction = $m[3] ?? '';
        $fraction = rtrim($fraction, '0');
        if (strlen($fraction) > $decimals) {
            throw new \InvalidArgumentException('"' . $text . '" has more than ' . $decimals . ' decimals');
        }
        $steps = (int) $m[2] * 10 ** $decimals + (int) str_pad($fraction, $decimals, '0');

        return new self($m[1] === '-' ? -$steps : $steps, $decimals);
    }

    /** "2.500" for 2500 steps of a 3-decimal unit. */
    public function toDecimal(): string
    {
        $abs = abs($this->steps);
        $sign = $this->steps < 0 ? '-' : '';
        if ($this->decimals === 0) {
            return $sign . $abs;
        }
        $scale = 10 ** $this->decimals;

        return $sign . intdiv($abs, $scale) . '.' . str_pad((string) ($abs % $scale), $this->decimals, '0', STR_PAD_LEFT);
    }

    public function isZero(): bool
    {
        return $this->steps === 0;
    }

    public function isNegative(): bool
    {
        return $this->steps < 0;
    }

    /**
     * Steps of one unit as steps of another: `steps × num / den`, scaled by
     * the two units' decimals, rounded half away from zero.
     *
     * `num / den` is how many of the target unit one source unit is (a 25 kg
     * bag into kg: 25 / 1).
     */
    public static function convert(int $steps, int $fromDecimals, int $num, int $den, int $toDecimals): int
    {
        if ($num <= 0 || $den <= 0) {
            throw new \InvalidArgumentException('A conversion is a positive fraction');
        }
        $numerator = $steps * $num * 10 ** $toDecimals;
        $denominator = $den * 10 ** $fromDecimals;
        $whole = intdiv(abs($numerator), $denominator);
        if ((abs($numerator) % $denominator) * 2 >= $denominator) {
            $whole++;
        }

        return $numerator < 0 ? -$whole : $whole;
    }
}
