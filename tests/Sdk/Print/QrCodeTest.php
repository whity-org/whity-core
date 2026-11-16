<?php

declare(strict_types=1);

namespace Tests\Sdk\Print;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Whity\Sdk\Print\QrCode;

/**
 * The encoder against known-good codes. The hashes are of codes that were
 * rendered and decoded back to their exact text with OpenCV's
 * QRCodeDetectorAruco (12 Nov 2026), along with 24 more covering versions 1
 * to 39 and all four levels; version and mask also agree with segno 1.6.6.
 */
final class QrCodeTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: int, 2: int, 3: int, 4: string}> */
    public static function known(): iterable
    {
        yield 'short text, M' => ['hello', QrCode::ECC_M, 1, 0, '52a7aa67e7296ede539d6be86579c7180e3b6d417445712ae8bd314c1818458a'];
        yield 'a link, M' => ['https://example.whity.dev/v/AB12CD', QrCode::ECC_M, 3, 3, '56f2296f59f31c0616c34eb56ae4661e993ba491d42e2a5153af7c064a230977'];
        yield 'JoFotara-sized base64, M' => [str_repeat('AQ3EjBpvc', 30), QrCode::ECC_M, 12, 2, 'e3161a1299445cead1cb2ce2559e40b16a55c0cab7cff3db34b1131e87633b16'];
        yield 'Arabic UTF-8, H' => ['فاتورة 8.250 JOD', QrCode::ECC_H, 3, 1, '1612b6d8f18371baabc599051b4f8840dfc92d3e19745eab7dda20cc6b8910ed'];
    }

    #[DataProvider('known')]
    public function testMatchesKnownGoodCodes(string $data, int $ecl, int $version, int $mask, string $sha): void
    {
        $q = QrCode::encode($data, $ecl);

        self::assertSame($version, $q->version);
        self::assertSame($mask, $q->mask);
        self::assertSame($sha, hash('sha256', implode("\n", self::rows($q))));
    }

    public function testFinderPatternsAndSvg(): void
    {
        $q = QrCode::encode('hello');
        foreach ([[0, 0], [$q->size - 7, 0], [0, $q->size - 7]] as [$x, $y]) {
            self::assertTrue($q->module($x, $y));
            self::assertTrue($q->module($x + 6, $y + 6));
            self::assertFalse($q->module($x + 1, $y + 1));
            self::assertTrue($q->module($x + 3, $y + 3));
        }
        $svg = $q->toSvg();
        self::assertStringStartsWith('<svg', $svg);
        self::assertStringContainsString('viewBox="0 0 29 29"', $svg);
        self::assertStringStartsWith('data:image/svg+xml;base64,', $q->toDataUri());
    }

    public function testRefusesWhatCannotFit(): void
    {
        $this->expectException(\LengthException::class);
        QrCode::encode(str_repeat('x', 3000), QrCode::ECC_H);
    }

    /** @return list<string> */
    private static function rows(QrCode $q): array
    {
        $rows = [];
        for ($y = 0; $y < $q->size; $y++) {
            $r = '';
            for ($x = 0; $x < $q->size; $x++) {
                $r .= $q->module($x, $y) ? '1' : '0';
            }
            $rows[] = $r;
        }

        return $rows;
    }
}
