<?php

declare(strict_types=1);

namespace Tests\Unit\Core\i18n;

use PHPUnit\Framework\TestCase;
use Whity\Core\i18n\LocalizedTextRejected;
use Whity\Core\i18n\LocalizedTexts;

final class LocalizedTextsTest extends TestCase
{
    public function testNormalizeTrimsAndDropsBlankLanguages(): void
    {
        $this->assertSame(
            ['ar' => 'جامعة', 'en' => 'University'],
            LocalizedTexts::normalize(['ar' => ' جامعة ', 'en' => 'University', 'fr' => '  ', 'de' => null], 'names')
        );
    }

    public function testNormalizeRefusesWhatIsNotAMap(): void
    {
        $this->expectException(LocalizedTextRejected::class);
        LocalizedTexts::normalize('University', 'names');
    }

    public function testNormalizeRefusesABadLanguageCode(): void
    {
        $this->expectException(LocalizedTextRejected::class);
        LocalizedTexts::normalize(['English' => 'University'], 'names');
    }

    public function testNormalizeRefusesTextThatIsTooLong(): void
    {
        $this->expectException(LocalizedTextRejected::class);
        LocalizedTexts::normalize(['en' => str_repeat('a', LocalizedTexts::MAX_LENGTH + 1)], 'names');
    }

    public function testGuessLanguageReadsArabicLetters(): void
    {
        $this->assertSame('ar', LocalizedTexts::guessLanguage('جامعة عمان الأهلية'));
        $this->assertSame('ar', LocalizedTexts::guessLanguage('AAU جامعة'));
        $this->assertSame('en', LocalizedTexts::guessLanguage('Example Bakery'));
    }

    public function testMergePutsTheOriginalFirstAndWinsOverAStaleCopy(): void
    {
        $this->assertSame(
            ['ar' => 'جامعة', 'en' => 'University'],
            LocalizedTexts::merge('جامعة', 'ar', ['en' => 'University', 'ar' => 'old'])
        );
    }

    public function testResolveFallsBackToTheOriginalNotToEnglish(): void
    {
        $names = ['ar' => 'جامعة', 'en' => 'University'];

        $this->assertSame('University', LocalizedTexts::resolve($names, 'en', 'ar'));
        $this->assertSame('جامعة', LocalizedTexts::resolve($names, 'fr', 'ar'));
        $this->assertSame('جامعة', LocalizedTexts::resolve(['ar' => 'جامعة'], 'en', 'ar'));
        $this->assertSame('', LocalizedTexts::resolve([], 'en', 'ar'));
    }
}
