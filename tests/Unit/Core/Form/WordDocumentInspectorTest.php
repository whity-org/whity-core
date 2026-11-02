<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Form;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Whity\Core\Form\FormRejectedException;
use Whity\Core\Form\FormUploadPolicy;
use Whity\Core\Form\WordDocumentInspector;
use ZipArchive;

/**
 * Every archive here is built in the test, with invented content.
 */
final class WordDocumentInspectorTest extends TestCase
{
    private const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    private const WORD_TYPES = '<?xml version="1.0"?><Types><Override PartName="/word/document.xml" '
        . 'ContentType="' . self::DOCX . '.main+xml"/></Types>';

    /**
     * @param array<string, string> $entries
     */
    private static function zip(array $entries): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'test-docx-');
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::OVERWRITE) === true);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    private static function realDocx(): string
    {
        return self::zip([
            '[Content_Types].xml' => self::WORD_TYPES,
            '_rels/.rels' => '<Relationships/>',
            'word/document.xml' => '<w:document>Example Bakery supplier form</w:document>',
        ]);
    }

    public function testARealDocumentIsAccepted(): void
    {
        self::assertTrue(WordDocumentInspector::isWordDocument(self::realDocx()));
    }

    /**
     * @return array<string, array{0: array<string, string>}>
     */
    public static function refusedArchives(): array
    {
        $main = ['[Content_Types].xml' => self::WORD_TYPES, 'word/document.xml' => '<w:document/>'];

        return [
            'a plain zip' => [['notes.txt' => 'hello']],
            'no content types' => [['word/document.xml' => '<w:document/>']],
            'a workbook' => [[
                '[Content_Types].xml' => '<Types><Override ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/></Types>',
                'xl/workbook.xml' => '<workbook/>',
            ]],
            'content types naming word but no document' => [['[Content_Types].xml' => self::WORD_TYPES]],
            'a macro-enabled document' => [[
                '[Content_Types].xml' => '<Types><Override ContentType="application/vnd.ms-word.document.macroEnabled.main+xml"/></Types>',
                'word/document.xml' => '<w:document/>',
            ]],
            'macros hiding under the word content type' => [$main + ['word/vbaProject.bin' => 'x']],
            'an entry climbing out' => [$main + ['../../evil.txt' => 'x']],
        ];
    }

    /**
     * @param array<string, string> $entries
     */
    #[DataProvider('refusedArchives')]
    public function testAnArchiveThatIsNotASafeDocumentIsRefused(array $entries): void
    {
        self::assertFalse(WordDocumentInspector::isWordDocument(self::zip($entries)));
    }

    public function testAnArchiveClaimingToInflatePastTheCeilingIsRefusedWithoutInflatingIt(): void
    {
        // Highly compressible, so a small upload that declares a large size is the
        // real shape of a bomb. A sparse file keeps the test from needing the memory.
        $huge = (string) tempnam(sys_get_temp_dir(), 'test-huge-');
        $handle = fopen($huge, 'r+');
        self::assertIsResource($handle);
        ftruncate($handle, WordDocumentInspector::DECLARED_MAX_BYTES + 1);
        fclose($handle);

        $path = (string) tempnam(sys_get_temp_dir(), 'test-bomb-');
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::OVERWRITE) === true);
        $zip->addFromString('[Content_Types].xml', self::WORD_TYPES);
        $zip->addFromString('word/document.xml', '<w:document/>');
        $zip->addFile($huge, 'word/media/huge.bin');
        $zip->close();
        $bytes = (string) file_get_contents($path);
        unlink($path);
        unlink($huge);

        self::assertLessThan(FormUploadPolicy::MAX_BYTES, strlen($bytes));
        self::assertFalse(WordDocumentInspector::isWordDocument($bytes));
    }

    public function testTooManyEntriesIsRefused(): void
    {
        $entries = ['[Content_Types].xml' => self::WORD_TYPES, 'word/document.xml' => '<w:document/>'];
        for ($i = 0; $i < WordDocumentInspector::ENTRIES_MAX; $i++) {
            $entries["word/media/{$i}.txt"] = 'x';
        }

        self::assertFalse(WordDocumentInspector::isWordDocument(self::zip($entries)));
    }

    public function testGarbageAfterTheSignatureIsRefused(): void
    {
        self::assertFalse(WordDocumentInspector::isWordDocument("PK\x03\x04\x14\x00\x00\x00zip"));
    }

    public function testNoTemporaryFileIsLeftBehind(): void
    {
        $count = static fn (): int => count(glob(sys_get_temp_dir() . '/form-docx-*') ?: []);
        $before = $count();

        WordDocumentInspector::isWordDocument(self::realDocx());
        WordDocumentInspector::isWordDocument('PK not a zip');

        self::assertSame($before, $count());
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function honestDeclarations(): array
    {
        return [
            'the real type' => [self::DOCX],
            'nothing' => [null],
            'unknown to the browser' => ['application/octet-stream'],
            // Browsers on a machine with no Office installed call it what it is.
            'a zip' => ['application/zip'],
            'a zip, the Windows spelling' => ['application/x-zip-compressed'],
        ];
    }

    #[DataProvider('honestDeclarations')]
    public function testThePolicyStoresItUnderTheWordTypeAndExtension(?string $declared): void
    {
        $type = FormUploadPolicy::assertAcceptable(self::realDocx(), $declared, FormUploadPolicy::MAX_BYTES);

        self::assertSame(self::DOCX, $type);
        self::assertSame('docx', FormUploadPolicy::extensionFor($type));
    }

    public function testADocumentDeclaringItselfSomethingElseIsRefused(): void
    {
        $this->expectException(FormRejectedException::class);

        FormUploadPolicy::assertAcceptable(self::realDocx(), 'application/pdf', FormUploadPolicy::MAX_BYTES);
    }

    public function testAZipDeclaringItselfAWordDocumentIsRefused(): void
    {
        try {
            FormUploadPolicy::assertAcceptable(self::zip(['a.txt' => 'x']), self::DOCX, FormUploadPolicy::MAX_BYTES);
            self::fail('a plain zip must not pass for a document');
        } catch (FormRejectedException $e) {
            self::assertSame('file_kind_refused', $e->reason);
        }
    }

    public function testAZipLabelCannotExcuseAnotherKind(): void
    {
        $this->expectException(FormRejectedException::class);

        FormUploadPolicy::assertAcceptable("%PDF-1.7\n", 'application/zip', FormUploadPolicy::MAX_BYTES);
    }
}
