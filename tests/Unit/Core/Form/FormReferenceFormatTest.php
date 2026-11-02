<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Form;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Whity\Core\Form\FormReferenceFormat;
use Whity\Core\Form\FormRejectedException;

final class FormReferenceFormatTest extends TestCase
{
    private static function at(string $when): \DateTimeImmutable
    {
        return new \DateTimeImmutable($when, new \DateTimeZone('UTC'));
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: string}>
     */
    public static function rendered(): array
    {
        return [
            'year and padding' => ['BAKE-{YYYY}-{SEQ:4}', 7, 'BAKE-2026-0007'],
            'short year and month' => ['{YY}{MM}/{SEQ}', 12, '2611/12'],
            'a number wider than the padding' => ['R{SEQ:2}', 1234, 'R1234'],
            'no padding' => ['{SEQ}', 3, '3'],
            'arabic text' => ['طلب-{YYYY}-{SEQ:3}', 5, 'طلب-2026-005'],
        ];
    }

    #[DataProvider('rendered')]
    public function testRendering(string $format, int $sequence, string $expected): void
    {
        self::assertSame($format, FormReferenceFormat::parse($format));
        self::assertSame($expected, FormReferenceFormat::render($format, $sequence, self::at('2026-11-02 10:00:00')));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function refused(): array
    {
        return [
            'not text' => [5],
            'no sequence' => ['BAKE-{YYYY}'],
            'two sequences' => ['{SEQ}-{SEQ:2}'],
            'an unknown token' => ['{YEAR}-{SEQ}'],
            'a stray brace' => ['A{-{SEQ}'],
            'padding out of range' => ['{SEQ:13}'],
            'padding of zero' => ['{SEQ:0}'],
            'a newline' => ["A\n{SEQ}"],
            'too long' => [str_repeat('x', 65) . '{SEQ}'],
        ];
    }

    #[DataProvider('refused')]
    public function testAnUnusableFormatIsRefused(mixed $format): void
    {
        $this->expectException(FormRejectedException::class);
        FormReferenceFormat::parse($format);
    }

    public function testBlankMeansNoReferences(): void
    {
        self::assertNull(FormReferenceFormat::parse(null));
        self::assertNull(FormReferenceFormat::parse('   '));
    }

    public function testAYearInTheFormatRestartsTheCounterAndOneWithoutDoesNot(): void
    {
        $last = self::at('2026-12-31 23:00:00');
        $next = self::at('2027-01-01 01:00:00');

        self::assertNotSame(
            FormReferenceFormat::counterName('R-{YYYY}-{SEQ}', $last),
            FormReferenceFormat::counterName('R-{YYYY}-{SEQ}', $next),
        );
        self::assertSame(
            FormReferenceFormat::counterName('R-{SEQ}', $last),
            FormReferenceFormat::counterName('R-{SEQ}', $next),
        );
    }

    public function testChangingOnlyThePaddingKeepsTheSameCounter(): void
    {
        $at = self::at('2026-11-02 10:00:00');

        // Otherwise both would count from 1 and R-1000 could be issued twice.
        self::assertSame(
            FormReferenceFormat::counterName('R-{SEQ:3}', $at),
            FormReferenceFormat::counterName('R-{SEQ:4}', $at),
        );
    }

    public function testTheCounterNameIsOneTheAllocatorAccepts(): void
    {
        $name = FormReferenceFormat::counterName('طلب-{SEQ}', self::at('2026-11-02 10:00:00'));

        self::assertMatchesRegularExpression('/^[a-z][a-z0-9_:-]*$/', $name);
    }

    public function testDatesAreUtc(): void
    {
        $at = new \DateTimeImmutable('2027-01-01 01:00:00', new \DateTimeZone('Asia/Amman'));

        self::assertSame('2026-1', FormReferenceFormat::render('{YYYY}-{SEQ}', 1, $at));
    }
}
