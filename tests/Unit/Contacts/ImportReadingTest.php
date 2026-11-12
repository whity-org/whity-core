<?php

declare(strict_types=1);

namespace Tests\Unit\Contacts;

use PHPUnit\Framework\TestCase;
use Whity\Core\Contacts\ContactRejectedException;
use Whity\Core\Contacts\Import\ColumnMatcher;
use Whity\Core\Contacts\Import\SpreadsheetReader;
use Whity\Core\Contacts\PhoneNumber;
use ZipArchive;

/**
 * Reading a spreadsheet, finding its header and matching its columns
 * (WHIT-953, `TplImport` steps 1 and 2).
 */
final class ImportReadingTest extends TestCase
{
    public function testCsvWithSemicolonsAndATitleRowAbove(): void
    {
        $csv = "\u{FEFF}Example Bakery customers, 2026\n\nName;Mobile;Email\nAmro Saleh;079 123 4567;amro@example.com\n;;\nAwss Haddad;00962791112222;\n";
        $rows = SpreadsheetReader::read($csv, 'customers.csv');

        self::assertSame(['Example Bakery customers, 2026'], $rows[0]);
        self::assertSame(1, SpreadsheetReader::headerIndex($rows));
        self::assertSame(['Name', 'Mobile', 'Email'], $rows[1]);
        self::assertCount(4, $rows, 'blank rows are dropped');
    }

    public function testXlsxWithSharedAndInlineStringsAndLongNumbers(): void
    {
        $rows = SpreadsheetReader::read(self::xlsx(), 'suppliers.xlsx');

        self::assertSame(['Name', 'Tax number', 'Type'], $rows[0]);
        self::assertSame(['Ahmad Khalil Supplies', '12345678901', 'supplier'], $rows[1]);
        self::assertSame(['Emad Nasser', '', 'both'], $rows[2]);
    }

    public function testOldExcelAndEmptyFilesAreRefusedInPlainWords(): void
    {
        try {
            SpreadsheetReader::read('whatever', 'old.xls');
            self::fail('an .xls was read');
        } catch (ContactRejectedException $e) {
            self::assertStringContainsString('.xlsx or CSV', $e->clientMessage);
        }
        $this->expectException(ContactRejectedException::class);
        SpreadsheetReader::read('', 'empty.csv');
    }

    public function testMatcherSuggestsByNameThenContentAndSkipsEmptyColumns(): void
    {
        $columns = ColumnMatcher::suggest(
            ['الاسم', 'Customer phone', 'Blank', 'Contact', ''],
            [
                ['Example Bakery', '0791234567', '', 'Amro Saleh', 'ibrahim@example.com'],
                ['Corner Café', '0797654321', '', 'Ibrahim Mansour', 'emad@example.com'],
            ],
        );

        self::assertSame(['target' => 'name', 'confidence' => 'sure'], $columns[0]['suggestion']);
        self::assertSame(['target' => 'phone', 'confidence' => 'doubtful'], $columns[1]['suggestion']);
        self::assertTrue($columns[2]['empty']);
        self::assertNull($columns[2]['suggestion']);
        self::assertSame(['target' => 'contact_person', 'confidence' => 'sure'], $columns[3]['suggestion']);
        self::assertSame(['target' => 'email', 'confidence' => 'doubtful'], $columns[4]['suggestion']);
        self::assertSame(['0791234567', '0797654321'], $columns[1]['examples']);
    }

    public function testMatcherOffersTheWorkspacesOwnFields(): void
    {
        $columns = ColumnMatcher::suggest(['Name', 'Preferred branch'], [['A', 'Abdoun']], ['field:preferred_branch' => ['Preferred branch']]);

        self::assertSame('field:preferred_branch', $columns[1]['suggestion']['target'] ?? null);
    }

    public function testPhoneNumbersAreTidied(): void
    {
        self::assertSame(['value' => '+962791234567', 'fixed' => true], PhoneNumber::tidy('079 123 4567', '+962'));
        self::assertSame(['value' => '+962791234567', 'fixed' => false], PhoneNumber::tidy('00962-79-123-4567', '962'));
        self::assertSame(['value' => '0791234567', 'fixed' => false], PhoneNumber::tidy('٠٧٩١٢٣٤٥٦٧'));
        self::assertNull(PhoneNumber::tidy('call me'));
        self::assertNull(PhoneNumber::countryCode('+0'));
    }

    private static function xlsx(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx-test-');
        self::assertIsString($path);
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        $ns = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
        $rel = 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
        $zip->addFromString('xl/workbook.xml', "<workbook {$ns} {$rel}><sheets><sheet name=\"Suppliers\" sheetId=\"1\" r:id=\"rId1\"/></sheets></workbook>");
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="worksheet" Target="worksheets/data.xml"/></Relationships>');
        $zip->addFromString('xl/sharedStrings.xml', "<sst {$ns}><si><t>Name</t></si><si><r><t>Tax </t></r><r><t>number</t></r></si><si><t>supplier</t></si></sst>");
        $zip->addFromString('xl/worksheets/data.xml', "<worksheet {$ns}><sheetData>"
            . '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="inlineStr"><is><t>Type</t></is></c></row>'
            . '<row r="2"><c r="A2" t="inlineStr"><is><t>Ahmad Khalil Supplies</t></is></c><c r="B2"><v>1.2345678901E10</v></c><c r="C2" t="s"><v>2</v></c></row>'
            . '<row r="3"><c r="A3" t="str"><v>Emad Nasser</v></c><c r="C3" t="inlineStr"><is><t>both</t></is></c></row>'
            . '</sheetData></worksheet>');
        $zip->close();
        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }
}
