<?php

declare(strict_types=1);

namespace Tests\Unit\Core\i18n;

use PHPUnit\Framework\TestCase;

/**
 * Numbers stay Western digits in every language (CLAUDE.md, decision register):
 * "8 أحرف", not "٨ أحرف". Thirteen Arabic labels had Eastern Arabic or Persian
 * digits (and ٪) until the 0.2.29 screen check found them on Branding and the
 * sign-in screens; this keeps a hand-written translation from bringing them back.
 */
final class CatalogueDigitsTest extends TestCase
{
    public function testNoTranslationUsesEasternArabicOrPersianDigits(): void
    {
        $files = glob(dirname(__DIR__, 4) . '/database/i18n/*/*.json') ?: [];
        self::assertNotEmpty($files, 'No translated catalogues found under database/i18n/<language>/.');

        $offending = [];
        foreach ($files as $file) {
            $catalogue = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            foreach ($catalogue['keys'] ?? [] as $key => $text) {
                if (is_string($text) && preg_match('/[\x{0660}-\x{0669}\x{06F0}-\x{06F9}\x{066A}]/u', $text) === 1) {
                    $offending[] = basename(dirname($file)) . '/' . basename($file) . ' ' . $key;
                }
            }
        }

        self::assertSame([], $offending, 'Use Western digits (0-9) and % in translations: ' . implode(', ', $offending));
    }
}
