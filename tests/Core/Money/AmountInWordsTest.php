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

    public function testNoOtherCurrencyIsGivenDinarWords(): void
    {
        self::assertNull(AmountInWords::write(5000, 'USD', 'en'));
        self::assertNull(AmountInWords::write(5000, 'JOD', 'fr'));
    }

    public function testBeyondWhatTheWordsCoverIsNull(): void
    {
        self::assertNotNull(AmountInWords::write(999_999_999_999, 'JOD', 'ar'));
        self::assertNull(AmountInWords::write(1_000_000_000_000, 'JOD', 'ar'));
    }
}
