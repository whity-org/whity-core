<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Tenant;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Whity\Core\Tenant\HandleRules;
use Whity\Core\Tenant\ReservedHandles;

/**
 * The shape of a workspace @handle (design section 36, HD.1–HD.3).
 */
final class HandleRulesTest extends TestCase
{
    public function testNormalizeLowersTrimsAndDropsTheAt(): void
    {
        self::assertSame('aau-irb', HandleRules::normalize('  @AAU-IRB '));
    }

    /** @return iterable<string, array{0: string, 1: ?string}> */
    public static function shapes(): iterable
    {
        yield 'live almolakhas' => ['almolakhas', null];
        yield 'live mcp-check' => ['mcp-check', null];
        yield 'live aau-irb' => ['aau-irb', null];
        yield 'digits inside' => ['bakery-24', null];
        yield 'too short' => ['ab', HandleRules::TOO_SHORT];
        yield 'too long' => [str_repeat('a', 31), HandleRules::TOO_LONG];
        yield 'thirty is fine' => [str_repeat('a', 30), null];
        yield 'underscore' => ['my_bakery', HandleRules::CHARACTERS];
        yield 'dot' => ['my.bakery', HandleRules::CHARACTERS];
        yield 'at sign' => ['my@bakery', HandleRules::CHARACTERS];
        yield 'arabic' => ['مخبز', HandleRules::CHARACTERS];
        yield 'leading digit' => ['24-bakery', HandleRules::START];
        yield 'leading dash' => ['-bakery', HandleRules::START];
        yield 'trailing dash' => ['bakery-', HandleRules::END];
        yield 'double dash' => ['my--bakery', HandleRules::DOUBLE_DASH];
    }

    #[DataProvider('shapes')]
    public function testShapeRules(string $handle, ?string $problem): void
    {
        self::assertSame($problem, HandleRules::shapeProblem($handle));
    }

    public function testOnlyWhityUsesTheWsPrefix(): void
    {
        $made = HandleRules::generate();

        self::assertTrue(HandleRules::isGenerated($made));
        self::assertNull(HandleRules::shapeProblem($made));
        self::assertSame(HandleRules::GENERATED, HandleRules::choosableProblem('ws-bakery'));
        self::assertFalse(HandleRules::isGenerated('ws-bakery'));
    }

    public function testSuggestionsFromLatinAndArabicNames(): void
    {
        self::assertSame('example-bakery', HandleRules::suggest('Example Bakery'));
        // Letters only: Arabic short vowels aren't written, so the person
        // usually tidies the suggestion.
        self::assertSame('mkhbz-almthal', HandleRules::suggest('مخبز المثال'));
        self::assertSame('aau-irb', HandleRules::suggest('AAU · IRB'));
        // Short vowels and shadda are skipped (the doubled m is not written).
        self::assertSame('aman', HandleRules::suggest('عمّان'));
        self::assertSame('', HandleRules::suggest('!!!'));
        self::assertSame('', HandleRules::suggest('AB'));
    }

    public function testASuggestionAlwaysHasAValidShape(): void
    {
        foreach (['24 Hour Bakery', 'ws-shop', '---x---y---', str_repeat('Long name ', 10), 'Café Noir'] as $name) {
            $handle = HandleRules::suggest($name);
            if ($handle !== '') {
                self::assertNull(HandleRules::choosableProblem($handle), "{$name} gave {$handle}");
            }
        }
    }

    public function testReservedWords(): void
    {
        foreach (['admin', 'api', 'book', 'system', 'everyone', 'whity', 'my-whity-shop', 'workspaces'] as $word) {
            self::assertTrue(ReservedHandles::isReserved($word), $word);
        }
        self::assertFalse(ReservedHandles::isReserved('aau-irb'));
        self::assertTrue(ReservedHandles::isReserved('acme', ['acme']));
    }

    public function testTheOperatorListIsOnePerLineOrComma(): void
    {
        self::assertSame(['acme', 'brand-x'], ReservedHandles::parseOperatorList("Acme\n@brand-x, acme\n\n"));
    }
}
