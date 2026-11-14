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
            'too long' => [str_repeat('x', 161) . '{SEQ}'],
            // WHIT-1017.
            'an academic year with no month' => ['{AY}-{SEQ}'],
            'an academic year in month 13' => ['{AY:13}-{SEQ}'],
            'an answer with no question' => ['{ANSWER:}-{SEQ}'],
            'an answer with a capital in its key' => ['{ANSWER:Subject}-{SEQ}'],
            'an answer list with no equals sign' => ['{ANSWER:subject|human}-{SEQ}'],
            'a step value with a capital in its key' => ['{STEP:Meeting}-{SEQ}'],
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

    // -- WHIT-1017: academic year, answers and step values -------------------

    public function testTheIrbFormatReadsAsTheCommitteeWritesIt(): void
    {
        $format = '{ANSWER:subject|human=IRB-AAU|animal=AUB-AAU} {SEQ}/{STEP:meeting_no}/{AY:9}';
        self::assertSame($format, FormReferenceFormat::parse($format));
        self::assertTrue(FormReferenceFormat::usesStepValues($format));
        self::assertFalse(FormReferenceFormat::usesStepValues('R-{SEQ}'));

        $at = self::at('2026-11-14 10:00:00');
        self::assertSame(
            'IRB-AAU 12/7/2026-2027',
            FormReferenceFormat::render($format, 12, $at, ['subject' => 'human'], ['meeting_no' => '7']),
        );
        self::assertSame(
            'AUB-AAU 3/7/2026-2027',
            FormReferenceFormat::render($format, 3, $at, ['subject' => 'animal'], ['meeting_no' => '7']),
        );
    }

    public function testEachPrefixAndAcademicYearCountsOnItsOwnButAMeetingDoesNot(): void
    {
        $format = '{ANSWER:subject|human=IRB-AAU|animal=AUB-AAU} {SEQ}/{STEP:meeting_no}/{AY:9}';
        $at = self::at('2026-11-14 10:00:00');
        $human = FormReferenceFormat::counterName($format, $at, ['subject' => 'human']);

        self::assertNotSame($human, FormReferenceFormat::counterName($format, $at, ['subject' => 'animal']));
        self::assertSame($human, FormReferenceFormat::counterName($format, self::at('2027-08-31 23:00:00'), ['subject' => 'human']));
        self::assertNotSame($human, FormReferenceFormat::counterName($format, self::at('2027-09-01 01:00:00'), ['subject' => 'human']));
        self::assertMatchesRegularExpression('/^[a-z][a-z0-9_:-]*$/', $human);
    }

    public function testAFormatWithoutTheNewTokensKeepsItsCounter(): void
    {
        // The name existing series have used since migration 196.
        $at = self::at('2026-11-02 10:00:00');
        self::assertSame('form-reference:' . md5('BAKE-2026-{SEQ}'), FormReferenceFormat::counterName('BAKE-{YYYY}-{SEQ:4}', $at, ['subject' => 'x']));
    }

    public function testTheAcademicYearTurnsInItsStartMonth(): void
    {
        self::assertSame('2025-2026', FormReferenceFormat::render('{AY:9}', 1, self::at('2026-08-31 23:59:59')));
        self::assertSame('2026-2027', FormReferenceFormat::render('{AY:9}', 1, self::at('2026-09-01 00:00:00')));
        self::assertSame('2026-2027', FormReferenceFormat::render('{AY:1}', 1, self::at('2026-01-01 00:00:00')));
    }

    public function testAnAnswerPrintsAsItIsOrFromItsListOrNothing(): void
    {
        $at = self::at('2026-11-02 10:00:00');
        self::assertSame('human-1', FormReferenceFormat::render('{ANSWER:subject}-{SEQ}', 1, $at, ['subject' => 'human']));
        self::assertSame('-1', FormReferenceFormat::render('{ANSWER:subject}-{SEQ}', 1, $at));
        self::assertSame('plant-1', FormReferenceFormat::render('{ANSWER:subject|human=H}-{SEQ}', 1, $at, ['subject' => 'plant']));
        self::assertSame('X-1', FormReferenceFormat::render('{ANSWER:subject|human=H|*=X}-{SEQ}', 1, $at, ['subject' => 'plant']));
        self::assertSame('X-1', FormReferenceFormat::render('{ANSWER:subject|human=H|*=X}-{SEQ}', 1, $at));
        self::assertSame('yes-1', FormReferenceFormat::render('{ANSWER:ok}-{SEQ}', 1, $at, ['ok' => true]));
        self::assertSame('-1', FormReferenceFormat::render('{ANSWER:many}-{SEQ}', 1, $at, ['many' => ['a', 'b']]));
        self::assertSame(
            str_repeat('a', FormReferenceFormat::ANSWER_MAX) . '-1',
            FormReferenceFormat::render('{ANSWER:long}-{SEQ}', 1, $at, ['long' => str_repeat('a', 90)]),
            'one long answer cannot swallow the reference',
        );
        self::assertSame('a b-1', FormReferenceFormat::render('{ANSWER:t}-{SEQ}', 1, $at, ['t' => "a\n{b}"]));
    }

    public function testAStepValueThatWasNotEnteredPrintsNothing(): void
    {
        self::assertSame('1/', FormReferenceFormat::render('{SEQ}/{STEP:meeting_no}', 1, self::at('2026-11-02 10:00:00')));
    }
}
