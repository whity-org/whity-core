<?php

declare(strict_types=1);

namespace Tests\Sdk;

use PHPUnit\Framework\TestCase;
use Whity\Core\Inventory\UnitCatalog;
use Whity\Sdk\Inventory\Quantity;

/**
 * Exact quantities (SDK 1.127, WHIT-1232, register IV.3): whole steps of a
 * unit, conversions as whole-number fractions, rounding once, half away from
 * zero. No float ever touches a quantity.
 */
final class InventoryQuantityTest extends TestCase
{
    public function testTextBecomesWholeStepsAndBack(): void
    {
        self::assertSame(2500, Quantity::parse('2.5', 3)->steps);
        self::assertSame(-12000, Quantity::parse('-12', 3)->steps);
        self::assertSame(7, Quantity::parse('٧', 0)->steps, 'Arabic-Indic digits read as Western ones');
        self::assertSame(1500, Quantity::parse('١٫٥', 3)->steps);
        self::assertSame('2.500', (new Quantity(2500, 3))->toDecimal());
        self::assertSame('-0.050', (new Quantity(-50, 3))->toDecimal());
        self::assertSame('12', (new Quantity(12, 0))->toDecimal());
        self::assertSame(3, Quantity::parse('3.000', 0)->steps, 'trailing zeros are not extra decimals');
    }

    public function testBadTextIsRefused(): void
    {
        $refused = 0;
        foreach (['', 'abc', '1,5', '1.2.3', '1e3', '0.0005'] as $text) {
            try {
                Quantity::parse($text, 3);
                self::fail('accepted "' . $text . '"');
            } catch (\InvalidArgumentException) {
                $refused++;
            }
        }
        self::assertSame(6, $refused);
    }

    public function testConversionIsExactAndRoundsOnceHalfAwayFromZero(): void
    {
        // 18 bags of 25 kg into kg (3 decimals).
        self::assertSame(450000, Quantity::convert(18, 0, 25, 1, 3));
        // 750 g into kg: 1/1000 each.
        self::assertSame(750, Quantity::convert(750, 0, 1, 1000, 3));
        // 1 g into a 0-decimal kg: 0.001 rounds to 0; 500 g to 1 (half away from zero).
        self::assertSame(0, Quantity::convert(1, 0, 1, 1000, 0));
        self::assertSame(1, Quantity::convert(500, 0, 1, 1000, 0));
        self::assertSame(-1, Quantity::convert(-500, 0, 1, 1000, 0));
        // 1 lb is 45359237/100000000 kg exactly.
        self::assertSame(454, Quantity::convert(1, 0, 45359237, 100000000, 3));
    }

    public function testFractionsAreReducedAndPrinted(): void
    {
        self::assertSame([25, 1], UnitCatalog::fraction('25', 'per'));
        self::assertSame([1, 2], UnitCatalog::fraction('0.5', 'per'));
        self::assertSame([1, 8], UnitCatalog::fraction('0.125', 'per'));
        self::assertSame('0.125', UnitCatalog::fractionText(1, 8));
        self::assertSame('1/3', UnitCatalog::fractionText(1, 3));
    }
}
