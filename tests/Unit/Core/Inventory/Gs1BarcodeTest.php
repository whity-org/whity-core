<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Inventory;

use PHPUnit\Framework\TestCase;
use Whity\Core\Inventory\Gs1Barcode;

/**
 * Reading GS1 barcodes from a scanner (WHIT-1235, design 31 §3.5): the raw
 * element string with group separators, the bracketed form, the symbology
 * prefix, dates with day 00, and plain barcodes left alone.
 */
final class Gs1BarcodeTest extends TestCase
{
    public function testTheRawStringAScannerSendsIsRead(): void
    {
        $gs = "\x1D";
        $read = Gs1Barcode::parse(']d20106251234567890' . '17' . '270331' . '10' . 'B-0412' . $gs . '11' . '261101' . '30' . '12');

        self::assertSame([
            'gtin' => '06251234567890', 'lot' => 'B-0412', 'expires_on' => '2027-03-31', 'made_on' => '2026-11-01', 'serial' => null, 'count' => 12,
        ], $read);
    }

    public function testTheBracketedFormIsRead(): void
    {
        $read = Gs1Barcode::parse('(01)06251234567890(21)SN-0007(15)270400');

        self::assertSame('06251234567890', $read['gtin'] ?? null);
        self::assertSame('SN-0007', $read['serial'] ?? null);
        self::assertSame('2027-04-30', $read['expires_on'] ?? null, 'day 00 is the last day of the month');
    }

    public function testAUseByDateWinsOverABestBeforeDate(): void
    {
        self::assertSame('2027-01-10', Gs1Barcode::parse('(01)06251234567890(15)270120(17)270110')['expires_on'] ?? null);
    }

    public function testArabicIndicDigitsAreReadAsWesternOnes(): void
    {
        self::assertSame('06251234567890', Gs1Barcode::parse('(٠١)٠٦٢٥١٢٣٤٥٦٧٨٩٠')['gtin'] ?? null);
    }

    public function testAPlainBarcodeOrSkuIsNotGs1(): void
    {
        self::assertNull(Gs1Barcode::parse('6251234567890'));
        self::assertNull(Gs1Barcode::parse('FLR-25'));
        self::assertNull(Gs1Barcode::parse('(10)B-0412'), 'no GTIN, nothing to find the item by');
        self::assertNull(Gs1Barcode::parse('(01)0625123456789(17)270331'), 'a GTIN is 14 digits');
        self::assertNull(Gs1Barcode::parse('(01)06251234567890(17)271331'), 'month 13');
    }

    public function testAGtinIsLookedForInItsShorterForms(): void
    {
        self::assertSame(['06251234567890', '6251234567890'], Gs1Barcode::gtinForms('06251234567890'));
        self::assertSame(['00000012345670', '0000012345670', '000012345670', '12345670'], Gs1Barcode::gtinForms('00000012345670'));
    }
}
