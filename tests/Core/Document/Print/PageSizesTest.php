<?php

declare(strict_types=1);

namespace Tests\Core\Document\Print;

use PHPUnit\Framework\TestCase;
use Whity\Core\Document\Print\PageSizes;

/**
 * The papers a document prints on, including the 80 mm roll a plugin's
 * receipt names (0.2.29).
 */
final class PageSizesTest extends TestCase
{
    public function testTheRollIsEightyMillimetresWideWithSmallMargins(): void
    {
        $roll = PageSizes::resolve('80mm');

        self::assertSame(80.0, $roll['widthMm']);
        self::assertSame(4.0, $roll['marginMm']);
        self::assertGreaterThan(297.0, $roll['heightMm'], 'long enough for a receipt to fit one page');
    }

    public function testAnUnknownOrMissingSizeIsA4(): void
    {
        self::assertSame(PageSizes::resolve('A4'), PageSizes::resolve(null));
        self::assertSame(PageSizes::resolve('A4'), PageSizes::resolve('A3'));
        self::assertSame(210.0, PageSizes::resolve(null)['widthMm']);
    }

    public function testTheNames(): void
    {
        self::assertSame(['A4', 'A5', 'Letter', '80mm'], PageSizes::names());
    }
}
