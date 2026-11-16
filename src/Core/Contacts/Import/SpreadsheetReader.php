<?php

declare(strict_types=1);

namespace Whity\Core\Contacts\Import;

use Whity\Core\Contacts\ContactRejectedException;
use ZipArchive;

/**
 * Reads the first sheet of an Excel workbook (.xlsx) or a CSV file into rows
 * of text, and finds the header row (WHIT-953, design 3.3 A8, `TplImport`).
 *
 * CSV: UTF-8 (a byte-order mark is dropped), commas, semicolons or tabs,
 * recognised from the first lines, as the label-sheet reader does.
 *
 * Excel: read with ext-zip and the XML in the package, no library. Shared
 * strings, inline strings, numbers and booleans are read; formulas give their
 * cached value. Entries are size-checked before they are read, so a small zip
 * that unpacks to gigabytes is refused. Older .xls files are not read; the
 * person is asked to save as .xlsx or CSV.
 *
 * Stateless.
 */
final class SpreadsheetReader
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_ROWS = 5000;
    public const MAX_COLUMNS = 60;
    private const MAX_CELL = 4000;
    private const MAX_UNPACKED = 64 * 1024 * 1024;

    /**
     * @return list<list<string>> every row with at least one value, cells trimmed
     */
    public static function read(string $bytes, string $fileName): array
    {
        if ($bytes === '') {
            throw new ContactRejectedException('The file is empty');
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new ContactRejectedException('The file is too large; the limit is 5 MB');
        }
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if ($extension === 'xls') {
            throw new ContactRejectedException('Older Excel files (.xls) can\'t be read. Save it as .xlsx or CSV and try again.');
        }
        $rows = str_starts_with($bytes, "PK\x03\x04") ? self::xlsx($bytes) : self::csv($bytes);

        $kept = [];
        foreach ($rows as $row) {
            $row = array_map(static fn (string $c): string => mb_substr(trim($c), 0, self::MAX_CELL), array_slice($row, 0, self::MAX_COLUMNS));
            if (implode('', $row) === '') {
                continue;
            }
            $kept[] = $row;
            if (count($kept) > self::MAX_ROWS + 10) {
                throw new ContactRejectedException('The file has more than ' . self::MAX_ROWS . ' rows; import it in parts');
            }
        }
        if ($kept === []) {
            throw new ContactRejectedException('The file has no rows');
        }

        return $kept;
    }

    /**
     * Where the header is: the first of the top ten rows with at least two
     * values that are mostly words, not numbers. Titles above a table ("Our
     * customers, 2026") are passed over.
     *
     * @param list<list<string>> $rows
     */
    public static function headerIndex(array $rows): int
    {
        foreach (array_slice($rows, 0, 10) as $i => $row) {
            $filled = array_values(array_filter($row, static fn (string $c): bool => $c !== ''));
            if (count($filled) < 2) {
                continue;
            }
            $words = count(array_filter($filled, static fn (string $c): bool => preg_match('/^[\d\s\.\,\+\-\/:]+$/u', $c) !== 1));
            if ($words >= count($filled) * 0.6) {
                return $i;
            }
        }

        return 0;
    }

    /** @return list<list<string>> */
    private static function csv(string $bytes): array
    {
        if (str_starts_with($bytes, "\u{FEFF}")) {
            $bytes = substr($bytes, 3);
        }
        if (!mb_check_encoding($bytes, 'UTF-8')) {
            // Spreadsheets in some locales still save CSV as Windows-1256 or 1252.
            $converted = @mb_convert_encoding($bytes, 'UTF-8', 'Windows-1256');
            if (!mb_check_encoding($converted, 'UTF-8')) {
                throw new ContactRejectedException('The CSV must be saved as UTF-8');
            }
            $bytes = $converted;
        }
        $sample = implode("\n", array_slice(preg_split('/\r\n|\r|\n/', $bytes, 6) ?: [], 0, 5));
        $delimiter = ',';
        $most = substr_count($sample, ',');
        foreach ([';', "\t"] as $candidate) {
            if (substr_count($sample, $candidate) > $most) {
                $delimiter = $candidate;
                $most = substr_count($sample, $candidate);
            }
        }

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('temp stream');
        }
        fwrite($stream, $bytes);
        rewind($stream);
        $rows = [];
        while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            if ($cells === [null]) {
                continue;
            }
            $rows[] = array_map(static fn (?string $c): string => (string) $c, $cells);
            if (count($rows) > self::MAX_ROWS + 50) {
                break;
            }
        }
        fclose($stream);

        return $rows;
    }

    /** @return list<list<string>> */
    private static function xlsx(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'whity-xlsx-');
        if ($path === false) {
            throw new \RuntimeException('temp file');
        }
        try {
            file_put_contents($path, $bytes);
            $zip = new ZipArchive();
            if ($zip->open($path, ZipArchive::RDONLY) !== true) {
                throw new ContactRejectedException('The Excel file could not be opened');
            }
            try {
                $sheetPath = self::firstSheetPath($zip);
                $strings = self::sharedStrings($zip);
                $xml = self::entry($zip, $sheetPath);
                if ($xml === null) {
                    throw new ContactRejectedException('The Excel file has no sheet');
                }

                return self::sheetRows($xml, $strings);
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($path);
        }
    }

    private static function firstSheetPath(ZipArchive $zip): string
    {
        $workbook = self::entry($zip, 'xl/workbook.xml');
        $rels = self::entry($zip, 'xl/_rels/workbook.xml.rels');
        if ($workbook !== null && $rels !== null) {
            $wb = self::xml($workbook);
            $rel = self::xml($rels);
            $sheet = $wb?->sheets?->sheet[0] ?? null;
            if ($sheet !== null && $rel !== null) {
                $rid = (string) ($sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] ?? '');
                foreach ($rel->Relationship as $r) {
                    if ((string) $r['Id'] === $rid) {
                        $target = ltrim((string) $r['Target'], '/');

                        return str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
                    }
                }
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    /** @return list<string> */
    private static function sharedStrings(ZipArchive $zip): array
    {
        $xml = self::entry($zip, 'xl/sharedStrings.xml');
        if ($xml === null) {
            return [];
        }
        $doc = self::xml($xml);
        $out = [];
        foreach ($doc->si ?? [] as $si) {
            $out[] = self::text($si);
        }

        return $out;
    }

    /**
     * @param list<string> $strings
     * @return list<list<string>>
     */
    private static function sheetRows(string $xml, array $strings): array
    {
        $doc = self::xml($xml);
        if ($doc === null) {
            throw new ContactRejectedException('The Excel sheet could not be read');
        }
        $rows = [];
        foreach ($doc->sheetData->row ?? [] as $row) {
            $cells = [];
            $next = 0;
            foreach ($row->c as $c) {
                $ref = (string) $c['r'];
                $index = $ref !== '' ? self::columnIndex($ref) : $next;
                if ($index >= self::MAX_COLUMNS) {
                    continue;
                }
                $next = $index + 1;
                $type = (string) $c['t'];
                $value = match ($type) {
                    's' => $strings[(int) $c->v] ?? '',
                    'inlineStr' => self::text($c->is),
                    'b' => ((string) $c->v) === '1' ? 'TRUE' : 'FALSE',
                    default => (string) $c->v,
                };
                if ($type === '' && is_numeric($value) && str_contains($value, 'E')) {
                    // Long numbers (phone numbers, tax numbers) come back in
                    // scientific notation; write them out in full.
                    $value = rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.');
                }
                $cells[$index] = $value;
            }
            if ($cells === []) {
                continue;
            }
            $line = array_fill(0, max(array_keys($cells)) + 1, '');
            foreach ($cells as $i => $v) {
                $line[$i] = $v;
            }
            $rows[] = array_values($line);
            if (count($rows) > self::MAX_ROWS + 50) {
                break;
            }
        }

        return $rows;
    }

    private static function columnIndex(string $ref): int
    {
        $letters = (string) preg_replace('/[^A-Z]/', '', strtoupper($ref));
        $n = 0;
        foreach (str_split($letters) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return max(0, $n - 1);
    }

    private static function text(?\SimpleXMLElement $node): string
    {
        if ($node === null) {
            return '';
        }
        if (isset($node->t)) {
            $out = (string) $node->t;
        } else {
            $out = '';
        }
        foreach ($node->r ?? [] as $run) {
            $out .= (string) $run->t;
        }

        return $out;
    }

    private static function entry(ZipArchive $zip, string $name): ?string
    {
        $stat = $zip->statName($name);
        if ($stat === false) {
            return null;
        }
        if ((int) $stat['size'] > self::MAX_UNPACKED) {
            throw new ContactRejectedException('The Excel file is too large to read');
        }
        $data = $zip->getFromName($name);

        return $data === false ? null : $data;
    }

    private static function xml(string $xml): ?\SimpleXMLElement
    {
        if (preg_match('/<!DOCTYPE/i', $xml) === 1) {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);

            return $doc === false ? null : $doc;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
