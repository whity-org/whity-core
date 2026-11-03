<?php

declare(strict_types=1);

namespace Whity\Core\Form;

use ZipArchive;

/**
 * Whether these bytes are really a Word document (.docx), asked by reading the
 * archive's index and never by unpacking it.
 *
 * A `.docx` is a ZIP, so its leading bytes also describe a `.jar`, a `.xlsx` and a
 * zip bomb, and {@see FormUploadPolicy} cannot decide from a signature as it does
 * for the other kinds. This class answers the question without writing anything
 * out of the archive, which is why it can be pointed at an anonymous caller's file:
 * a zip bomb only does harm when something inflates it.
 *
 * WHAT IT READS
 * -------------
 * The central directory, which is a list of names and declared sizes, and one
 * entry, `[Content_Types].xml`, read through a stream that stops at
 * {@see self::CONTENT_TYPES_MAX_BYTES} however large the entry claims to be. That
 * entry is searched as text and never parsed as XML, so there is no external-entity
 * surface.
 *
 * WHAT IT REFUSES
 * ---------------
 *  - A corrupt archive, or more entries than a real document has.
 *  - A declared total size past {@see self::DECLARED_MAX_BYTES}, or an entry whose
 *    name climbs out of the archive. Nothing here would follow one, but the people
 *    who download the file have tools that might.
 *  - Anything whose main part is not a Word document: a workbook, a deck, a
 *    template, a plain ZIP with a renamed extension.
 *  - MACROS, either way they can be carried. A macro-enabled document is the one
 *    kind of Word file that runs code on the machine of whoever opens it, and the
 *    person opening it is an office member who trusts the form.
 *
 * LEGACY `.doc` IS REFUSED. It is an OLE container shared with old Excel and
 * PowerPoint, can carry macros with nothing to tell from the outside, and this class
 * cannot look inside it. Any Word from the last fifteen years saves `.docx`.
 *
 * A document that passes can still hold anything a Word file may hold, including
 * links and embedded objects. This narrows what is accepted to a real document
 * without macros; it is not a virus scan, and the platform has none.
 *
 * When ZipArchive is missing the answer is no, so a host without the extension
 * refuses Word files rather than accepting them unchecked.
 *
 * Stateless — worker-safe.
 */
final class WordDocumentInspector
{
    /** A long report with many images has hundreds of entries, not thousands. */
    public const ENTRIES_MAX = 2000;

    /** Declared, uninflated total: 200 MiB. A 10 MiB upload claiming more is a bomb. */
    public const DECLARED_MAX_BYTES = 200 * 1024 * 1024;

    /** `[Content_Types].xml` is a few kilobytes in any real document. */
    private const CONTENT_TYPES_MAX_BYTES = 256 * 1024;

    private const WORD_MAIN_PART = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml';

    /**
     * Static helper only — never instantiated.
     */
    private function __construct()
    {
    }

    public static function isWordDocument(string $bytes): bool
    {
        if (!class_exists(ZipArchive::class)) {
            return false;
        }

        $path = tempnam(sys_get_temp_dir(), 'form-docx-');
        if ($path === false) {
            return false;
        }

        try {
            if (file_put_contents($path, $bytes) === false) {
                return false;
            }

            $zip = new ZipArchive();
            if ($zip->open($path, ZipArchive::CHECKCONS | ZipArchive::RDONLY) !== true) {
                return false;
            }

            try {
                return self::inspect($zip);
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($path);
        }
    }

    private static function inspect(ZipArchive $zip): bool
    {
        if ($zip->numFiles < 1 || $zip->numFiles > self::ENTRIES_MAX) {
            return false;
        }

        $declared = 0;
        $hasDocument = false;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                return false;
            }

            $name = strtolower(str_replace('\\', '/', (string) $stat['name']));
            if (str_starts_with($name, '/') || in_array('..', explode('/', $name), true)) {
                return false;
            }
            if (str_ends_with($name, 'vbaproject.bin')) {
                return false;
            }
            $hasDocument = $hasDocument || $name === 'word/document.xml';

            $declared += (int) $stat['size'];
            if ($declared > self::DECLARED_MAX_BYTES) {
                return false;
            }
        }

        if (!$hasDocument) {
            return false;
        }

        $types = self::readBounded($zip, '[Content_Types].xml');

        return $types !== null && str_contains($types, self::WORD_MAIN_PART);
    }

    private static function readBounded(ZipArchive $zip, string $entry): ?string
    {
        $stream = $zip->getStream($entry);
        if ($stream === false) {
            return null;
        }

        try {
            $read = stream_get_contents($stream, self::CONTENT_TYPES_MAX_BYTES);
        } finally {
            fclose($stream);
        }

        return is_string($read) ? $read : null;
    }
}
