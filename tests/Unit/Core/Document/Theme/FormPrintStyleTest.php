<?php

declare(strict_types=1);

namespace Whity\Tests\Unit\Core\Document\Theme;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Whity\Core\Document\Theme\DocumentThemeTokens;
use Whity\Core\Document\Theme\FormPrintStyle;

/**
 * Design 3.4, FP.2-FP.10: a theme's `form` options, and the plan gate that
 * turns them back into the standard layout on a free workspace.
 */
final class FormPrintStyleTest extends TestCase
{
    private const MARK = ['src' => 'data:image/png;base64,iVBORw0KGgo=', 'alt' => 'ISO 9001:2015'];

    public function testTheStandardLayoutIsWhatMissingKeysTake(): void
    {
        $style = FormPrintStyle::resolve(null, true);

        self::assertSame('letterhead', $style['header']);
        self::assertSame('decisionLine', $style['officeDetails']);
        self::assertSame('topAndEnd', $style['decision']);
        self::assertSame('mark', $style['sectionStyle'], 'FP.4: B, the Whity mark');
        self::assertSame('stacked', $style['questions']);
        self::assertSame('countAndContinued', $style['longAnswers']);
        self::assertSame('allOptions', $style['choices']);
        self::assertSame('word', $style['yesNo']);
        self::assertSame('collapseAndMark', $style['empty']);
        self::assertSame('confirmedAndDrawn', $style['signatures']);
        self::assertSame('list', $style['approvals']);
        self::assertSame(['reference' => true, 'pageCount' => true, 'marks' => true, 'qrEveryPage' => false], $style['footer']);
        self::assertSame([], $style['marks']);
        self::assertFalse($style['custom']);
    }

    public function testAPaidWorkspacePrintsItsChoices(): void
    {
        $style = FormPrintStyle::resolve([
            'sectionStyle' => 'tab',
            'questions' => 'twoColumns',
            'footer' => ['qrEveryPage' => true, 'reference' => false],
            'marks' => [self::MARK],
        ], true);

        self::assertSame('tab', $style['sectionStyle']);
        self::assertSame('twoColumns', $style['questions']);
        self::assertTrue($style['footer']['qrEveryPage']);
        self::assertFalse($style['footer']['reference']);
        self::assertTrue($style['footer']['pageCount'], 'page x of y always prints');
        self::assertSame([self::MARK], $style['marks']);
        self::assertTrue($style['custom']);
    }

    public function testAFreeWorkspacePrintsTheStandardLayoutWithItsMarks(): void
    {
        $style = FormPrintStyle::resolve([
            'sectionStyle' => 'plane',
            'approvals' => 'cards',
            'footer' => ['qrEveryPage' => true],
            'marks' => [self::MARK],
        ], false);

        self::assertSame('mark', $style['sectionStyle'], 'FP.4: free workspaces always use B');
        self::assertSame('list', $style['approvals']);
        self::assertFalse($style['footer']['qrEveryPage']);
        self::assertSame([self::MARK], $style['marks'], 'FP.3: the standard footer carries the client\'s marks');
        self::assertFalse($style['custom']);
    }

    public function testAnInvalidStoredValueFallsBackToTheStandard(): void
    {
        self::assertSame('mark', FormPrintStyle::resolve(['sectionStyle' => 'neon'], true)['sectionStyle']);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function invalid(): iterable
    {
        yield 'a list' => [['mark'], 'form'];
        yield 'an unknown option' => [['font' => 'serif'], 'form.font'];
        yield 'an unknown value' => [['sectionStyle' => 'G'], 'form.sectionStyle'];
        yield 'a footer part that is not a boolean' => [['footer' => ['marks' => 'yes']], 'form.footer.marks'];
        yield 'turning page x of y off' => [['footer' => ['pageCount' => false]], 'form.footer.pageCount'];
        yield 'four marks' => [['marks' => array_fill(0, 4, self::MARK)], 'form.marks'];
        yield 'a mark from the web' => [['marks' => [['src' => 'https://example.com/iso.png', 'alt' => '']]], 'form.marks'];
        yield 'an SVG mark' => [['marks' => [['src' => 'data:image/svg+xml;base64,PHN2Zz4=', 'alt' => '']]], 'form.marks'];
        yield 'a mark over 300 KB' => [['marks' => [['src' => 'data:image/png;base64,' . str_repeat('A', 420000), 'alt' => '']]], 'form.marks'];
        yield 'a mark with extra keys' => [['marks' => [self::MARK + ['width' => 3]]], 'form.marks'];
    }

    #[DataProvider('invalid')]
    public function testRefusesWhatItCannotPrint(mixed $form, string $path): void
    {
        self::assertArrayHasKey($path, FormPrintStyle::errors($form));
    }

    public function testAThemesTokensAcceptTheFormOptionsAndTheLetterheadLayout(): void
    {
        $tokens = [
            'accent' => '#7a1f3d',
            'letterhead' => ['layout' => 'bilingual'],
            'form' => ['header' => 'bilingual', 'marks' => [self::MARK], 'footer' => ['pageCount' => true]],
        ];
        self::assertSame([], DocumentThemeTokens::errors($tokens));
        self::assertArrayHasKey('letterhead.layout', DocumentThemeTokens::errors(['letterhead' => ['layout' => 'end']]));
        self::assertArrayHasKey('form', DocumentThemeTokens::errors(['form' => ['x'] ], overridesOnly: true), 'a document may not change form options');
    }

    public function testMergingKeepsTheFormKeysALayerDoesNotSet(): void
    {
        $merged = DocumentThemeTokens::merge(['form' => ['header' => 'centred', 'questions' => 'twoColumns']], ['form' => ['questions' => 'stacked']]);

        self::assertSame(['header' => 'centred', 'questions' => 'stacked'], $merged['form']);
    }

    public function testTheSchemaNamesEveryOptionInBothLanguages(): void
    {
        $schema = FormPrintStyle::schema();

        self::assertSame('documents.form_printouts', $schema['entitlement']);
        self::assertCount(count(FormPrintStyle::OPTIONS), $schema['options']);
        foreach ($schema['options'] as $option) {
            self::assertNotSame('', $option['labelAr']);
            foreach ($option['values'] as $value) {
                self::assertNotSame('', $value['label']);
                self::assertNotSame('', $value['labelAr']);
            }
        }
    }
}
