<?php

declare(strict_types=1);

namespace Tests\Core\Money;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Whity\Core\Money\AmountInWords;

/**
 * The expected words are what the design's `Whity.amountInWords` writes (section
 * 16). Passing means matching the design, not that an accountant has reviewed the
 * Arabic: section 16 still asks for that.
 */
final class AmountInWordsTest extends TestCase
{
    /** @return iterable<string, array{int, string, string}> */
    public static function amounts(): iterable
    {
        yield 'zero' => [0, 'Zero Jordanian dinars', 'صفر دينار أردني'];
        yield 'one dinar' => [1000, 'One Jordanian dinar', 'دينار أردني واحد'];
        yield 'two dinars' => [2000, 'Two Jordanian dinars', 'ديناران أردنيان'];
        yield 'three dinars' => [3000, 'Three Jordanian dinars', 'ثلاثة دنانير أردنية'];
        yield 'eleven dinars' => [11000, 'Eleven Jordanian dinars', 'أحد عشر ديناراً أردنياً'];
        yield 'only fils' => [500, 'Five hundred fils', 'خمسمئة فلس'];
        yield 'one fils' => [1, 'One fils', 'فلس واحد'];
        yield '203' => [203000, 'Two hundred and three Jordanian dinars', 'مئتان وثلاثة دنانير أردنية'];
        yield '244.500' => [244500, 'Two hundred and forty-four Jordanian dinars and five hundred fils', 'مئتان وأربعة وأربعون ديناراً أردنياً وخمسمئة فلس'];
        yield '301.600' => [301600, 'Three hundred and one Jordanian dinars and six hundred fils', 'ثلاثمئة وواحد دينار أردني وستمئة فلس'];
        yield 'one thousand' => [1000000, 'One thousand Jordanian dinars', 'ألف دينار أردني'];
        yield 'two thousand' => [2000000, 'Two thousand Jordanian dinars', 'ألفا دينار أردني'];
        yield '2005' => [2005000, 'Two thousand and five Jordanian dinars', 'ألفان وخمسة دنانير أردنية'];
        yield '5000' => [5000000, 'Five thousand Jordanian dinars', 'خمسة آلاف دينار أردني'];
        yield 'a million' => [1000000000, 'One million Jordanian dinars', 'مليون دينار أردني'];
    }

    #[DataProvider('amounts')]
    public function testWritesBothLanguages(int $fils, string $english, string $arabic): void
    {
        self::assertSame($english, AmountInWords::write($fils, 'JOD', 'en'));
        self::assertSame($arabic, AmountInWords::write($fils, 'JOD', 'ar'));
    }

    public function testANegativeAmountIsWrittenByItsSize(): void
    {
        self::assertSame('Five Jordanian dinars', AmountInWords::write(-5000, 'JOD', 'en'));
    }

    /** @return iterable<string, array{int, string, string, string}> */
    public static function otherCurrencies(): iterable
    {
        // CU.3's example, and the rest as the design's bundle writes them.
        yield 'USD 301.60' => [30160, 'USD', 'Three hundred and one US dollars and sixty cents', 'ثلاثمئة وواحد دولار أمريكي وستون سنتاً'];
        yield 'USD 18,652.35' => [1865235, 'USD', 'Eighteen thousand six hundred and fifty-two US dollars and thirty-five cents', 'ثمانية عشر ألفاً وستمئة واثنان وخمسون دولاراً أمريكياً وخمسة وثلاثون سنتاً'];
        yield 'USD one' => [100, 'USD', 'One US dollar', 'دولار أمريكي واحد'];
        yield 'USD two' => [200, 'USD', 'Two US dollars', 'دولاران أمريكيان'];
        yield 'USD only cents' => [60, 'USD', 'Sixty cents', 'ستون سنتاً'];
        yield 'USD one cent' => [1, 'USD', 'One cent', 'سنت واحد'];
        yield 'EUR 12.50' => [1250, 'EUR', 'Twelve euros and fifty cents', 'اثنا عشر يورو وخمسون سنتاً'];
        yield 'SAR 99.99' => [9999, 'SAR', 'Ninety-nine Saudi riyals and ninety-nine halalas', 'تسعة وتسعون ريالاً سعودياً وتسعة وتسعون هللة'];
        yield 'AED 3.50' => [350, 'AED', 'Three UAE dirhams and fifty fils', 'ثلاثة دراهم إماراتية وخمسون فلساً'];
    }

    #[DataProvider('otherCurrencies')]
    public function testWritesEachCurrencyWithItsOwnSubUnit(int $minor, string $currency, string $english, string $arabic): void
    {
        self::assertSame($english, AmountInWords::write($minor, $currency, 'en'));
        self::assertSame($arabic, AmountInWords::write($minor, $currency, 'ar'));
    }

    public function testACurrencyWithoutWordsAnswersNull(): void
    {
        self::assertNull(AmountInWords::write(5000, 'GBP', 'en'));
        self::assertNull(AmountInWords::write(5000, 'JOD', 'fr'));
        self::assertTrue(AmountInWords::supports('usd'));
        self::assertFalse(AmountInWords::supports('GBP'));
    }

    public function testBeyondWhatTheWordsCoverIsNull(): void
    {
        self::assertNotNull(AmountInWords::write(999_999_999_999, 'JOD', 'ar'));
        self::assertNull(AmountInWords::write(1_000_000_000_000, 'JOD', 'ar'));
    }
}
