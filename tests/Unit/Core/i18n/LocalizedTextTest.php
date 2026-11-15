<?php

declare(strict_types=1);

namespace Tests\Unit\Core\i18n;

use PHPUnit\Framework\TestCase;
use Whity\Core\i18n\LocalizedText;
use Whity\Core\i18n\LocalizedTextRejected;

final class LocalizedTextTest extends TestCase
{
    public function testNormalizeTrimsAndDropsBlankLanguages(): void
    {
        $this->assertSame(
            ['ar' => 'جامعة', 'en' => 'University'],
            LocalizedText::normalize(['ar' => ' جامعة ', 'en' => 'University', 'fr' => '  ', 'de' => null], 'names')
        );
    }

    public function testNormalizeRefusesABareStringUnlessToldItsLanguage(): void
    {
        $this->assertSame(['ar' => 'جامعة'], LocalizedText::normalize(' جامعة ', 'name', bareLanguage: 'ar'));

        $this->expectException(LocalizedTextRejected::class);
        LocalizedText::normalize('University', 'names');
    }

    public function testNormalizeRefusesABlankBareString(): void
    {
        $this->expectException(LocalizedTextRejected::class);
        LocalizedText::normalize('   ', 'name', bareLanguage: 'en');
    }

    public function testNormalizeRefusesABadLanguageCode(): void
    {
        $this->expectException(LocalizedTextRejected::class);
        LocalizedText::normalize(['English' => 'University'], 'names');
    }

    public function testNormalizeRefusesTextThatIsNotText(): void
    {
        $this->expectException(LocalizedTextRejected::class);
        LocalizedText::normalize(['en' => 5], 'names');
    }

    public function testNormalizeRefusesTextThatIsTooLong(): void
    {
        $this->expectException(LocalizedTextRejected::class);
        LocalizedText::normalize(['en' => str_repeat('a', LocalizedText::MAX_LENGTH + 1)], 'names');
    }

    public function testNormalizeTakesAnotherLimitOrNone(): void
    {
        $long = str_repeat('a', 300);
        $this->assertSame(['en' => $long], LocalizedText::normalize(['en' => $long], 'name', maxLength: null));

        $this->expectException(LocalizedTextRejected::class);
        LocalizedText::normalize(['en' => 'abcdef'], 'name', maxLength: 5);
    }

    public function testNormalizeRefusesTooManyLanguages(): void
    {
        $many = [];
        foreach (range(1, LocalizedText::MAX_LANGUAGES + 1) as $i) {
            $many['x' . chr(96 + $i)] = 'text';
        }

        $this->expectException(LocalizedTextRejected::class);
        LocalizedText::normalize($many, 'names');
    }

    public function testARequiredTextNeedsOneLanguage(): void
    {
        $this->assertSame([], LocalizedText::normalize(['en' => ' '], 'names'));

        $this->expectException(LocalizedTextRejected::class);
        LocalizedText::normalize(['en' => ' '], 'names', required: true);
    }

    public function testAFixedLanguageSetKeepsItsOrderAndIgnoresTheRest(): void
    {
        $this->assertSame(
            ['ar' => 'اسم', 'en' => 'Name'],
            LocalizedText::normalize(
                ['en' => 'Name', 'fr' => 'Nom', 'English' => 'x', 'ar' => ' اسم ', 'de' => 5],
                'label',
                languages: LocalizedText::BILINGUAL,
            )
        );
    }

    public function testAFixedLanguageSetNamesItsLanguagesWhenEmpty(): void
    {
        $this->expectException(LocalizedTextRejected::class);
        $this->expectExceptionMessage("label must carry at least one of 'ar' or 'en'.");
        LocalizedText::normalize(['fr' => 'Nom'], 'label', languages: LocalizedText::BILINGUAL, required: true);
    }

    public function testALabelReadsABareStringAsEnglish(): void
    {
        $this->assertSame(['en' => 'Equipment request'], LocalizedText::label(' Equipment request ', 'name'));
        $this->assertSame(['ar' => 'طلب معدات'], LocalizedText::label(['ar' => 'طلب معدات'], 'name'));
        $this->assertSame(['en' => 'Equipment request'], LocalizedText::label(['ar' => '   ', 'en' => 'Equipment request'], 'name'));
    }

    public function testALabelWithNoArabicOrEnglishIsRefused(): void
    {
        $this->expectException(LocalizedTextRejected::class);
        LocalizedText::label(['fr' => 'Demande'], 'name');
    }

    public function testABlankLabelIsRefused(): void
    {
        $this->expectException(LocalizedTextRejected::class);
        LocalizedText::label('   ', 'name');
    }

    public function testAnOptionalLabelClearsOnBlankAndKeepsTheArabicForABareString(): void
    {
        $this->assertNull(LocalizedText::optionalLabel(null, 'help_text', 10));
        $this->assertNull(LocalizedText::optionalLabel('  ', 'help_text', 10));
        $this->assertNull(LocalizedText::optionalLabel([], 'help_text', 10));
        $this->assertSame('{"en":"Help","ar":"مساعدة"}', LocalizedText::optionalLabel('Help', 'help_text', 10, ['ar' => 'مساعدة', 'en' => 'Old']));
        $this->assertSame('{"en":"Help"}', LocalizedText::optionalLabel(['en' => 'Help'], 'help_text', 10, ['ar' => 'مساعدة']));
    }

    public function testAnOptionalLabelHasALimitPerLanguage(): void
    {
        $this->expectException(LocalizedTextRejected::class);
        $this->expectExceptionMessage('help_text must be 4 characters or fewer.');
        LocalizedText::optionalLabel(['ar' => 'مساعدة'], 'help_text', 4);
    }

    public function testEncodeKeepsArabicReadable(): void
    {
        $this->assertSame('{"ar":"مجلس","en":"Board/1"}', LocalizedText::encode(['ar' => 'مجلس', 'en' => 'Board/1']));
    }

    public function testDecodeNeverThrows(): void
    {
        $this->assertSame([], LocalizedText::decode(null));
        $this->assertSame([], LocalizedText::decode('  '));
        $this->assertSame(['en' => 'Legacy name'], LocalizedText::decode('Legacy name'));
        $this->assertSame(['ar' => 'Legacy name'], LocalizedText::decode('Legacy name', 'ar'));
        $this->assertSame(
            ['ar' => 'مجلس', 'fr' => 'Conseil'],
            LocalizedText::decode('{"ar":"مجلس","en":"","fr":"Conseil","de":5}')
        );
        $this->assertSame(['en' => 'Board'], LocalizedText::decode('{"fr":"Conseil","en":"Board"}', 'en', LocalizedText::BILINGUAL));
        $this->assertSame([], LocalizedText::decode('{}'));
    }

    public function testResolveFallsBackToTheOriginalNotToEnglish(): void
    {
        $names = ['ar' => 'جامعة', 'en' => 'University'];

        $this->assertSame('University', LocalizedText::resolve($names, 'en', 'ar'));
        $this->assertSame('جامعة', LocalizedText::resolve($names, 'fr', 'ar'));
        $this->assertSame('جامعة', LocalizedText::resolve(['ar' => 'جامعة'], 'en', 'ar'));
        $this->assertSame('', LocalizedText::resolve([], 'en', 'ar'));
    }

    public function testResolveWithoutAnOriginalReadsEnglishThenArabicThenAnything(): void
    {
        $this->assertSame('Board', LocalizedText::resolve(['ar' => 'مجلس', 'en' => 'Board'], 'fr'));
        $this->assertSame('مجلس', LocalizedText::resolve(['fr' => 'Conseil', 'ar' => 'مجلس'], 'de'));
        $this->assertSame('Conseil', LocalizedText::resolve(['fr' => 'Conseil', 'en' => ' '], 'ar'));
        $this->assertSame('مجلس', LocalizedText::resolve(['en' => 'Board', 'ar' => 'مجلس'], 'ar'));
        $this->assertSame('Board', LocalizedText::resolve(['ar' => 'مجلس', 'en' => 'Board']));
    }

    public function testResolveTakesABareStringAndAFallback(): void
    {
        $this->assertSame('Legacy', LocalizedText::resolve(' Legacy ', 'ar'));
        $this->assertSame('Untitled', LocalizedText::resolve('  ', 'ar', fallback: 'Untitled'));
        $this->assertSame('Untitled', LocalizedText::resolve(null, 'ar', fallback: 'Untitled'));
        $this->assertSame('Untitled', LocalizedText::resolve(['en' => ''], 'en', fallback: 'Untitled'));
    }

    public function testGuessLanguageReadsArabicLetters(): void
    {
        $this->assertSame('ar', LocalizedText::guessLanguage('جامعة عمان الأهلية'));
        $this->assertSame('ar', LocalizedText::guessLanguage('AAU جامعة'));
        $this->assertSame('en', LocalizedText::guessLanguage('Example Bakery'));
    }

    public function testMergePutsTheOriginalFirstAndWinsOverAStaleCopy(): void
    {
        $this->assertSame(
            ['ar' => 'جامعة', 'en' => 'University'],
            LocalizedText::merge('جامعة', 'ar', ['en' => 'University', 'ar' => 'old'])
        );
    }

    public function testFromBodySplitsTheOriginalFromTheOthers(): void
    {
        $this->assertSame(
            ['name' => 'جامعة', 'language' => 'ar', 'others' => ['en' => 'University']],
            LocalizedText::fromBody(['names' => ['ar' => 'جامعة', 'en' => 'University']], 'Old', 'ar', [], 255)
        );
    }

    public function testFromBodyNeedsTheOriginal(): void
    {
        $this->expectException(LocalizedTextRejected::class);
        LocalizedText::fromBody(['names' => ['en' => 'University']], 'Old', 'ar', [], 255);
    }
}
