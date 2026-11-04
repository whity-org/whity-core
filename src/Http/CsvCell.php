<?php

declare(strict_types=1);

namespace Whity\Http;

/**
 * Makes a value safe to put in a CSV that a person will open in a spreadsheet.
 *
 * A cell starting with `=`, `+`, `-` or `@` runs as a formula in Excel, LibreOffice
 * and Google Sheets. Anybody who can choose a display name, a role name or a
 * record title could otherwise plant one in an export an administrator opens later,
 * and a formula can reach out over the network with the rest of the sheet. So such
 * a cell gets a leading apostrophe, which spreadsheets read as "this is text".
 *
 * Leading spaces, control characters and invisible format characters don't stop a
 * spreadsheet evaluating what follows, so they don't stop the check either; nor do
 * the full-width forms of the four characters, which some versions normalise.
 */
final class CsvCell
{
    public static function safe(string $value): string
    {
        // \p{C} is control and format characters (tabs, bidi marks, zero width),
        // \p{Z} is separators (spaces of every kind).
        $visible = (string) preg_replace('/^[\p{C}\p{Z}]+/u', '', $value);
        if ($visible === '' && $value !== '' && preg_match('//u', $value) !== 1) {
            // Not valid UTF-8, so the check above couldn't read it. Don't guess.
            return "'" . $value;
        }

        $first = mb_substr($visible, 0, 1, 'UTF-8');

        return in_array($first, ['=', '+', '-', '@', '＝', '＋', '－', '＠'], true) ? "'" . $value : $value;
    }

    /**
     * @param resource   $stream
     * @param list<string> $cells
     */
    public static function writeRow($stream, array $cells): void
    {
        fputcsv($stream, array_map(self::safe(...), $cells), ',', '"', '', "\r\n");
    }
}
