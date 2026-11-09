<?php

declare(strict_types=1);

namespace Tests\Sdk\Manifest;

use PHPUnit\Framework\TestCase;
use Whity\Sdk\Manifest\HomeCardValidator;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `homeCard` in `plugin.json` (SDK 1.62, WHIT-872).
 */
final class HomeCardValidatorTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function card(array $overrides = []): array
    {
        return $overrides + [
            'label' => ['en' => 'Stock', 'ar' => 'المخزون'],
            'permission' => 'inventory:read',
            'source' => '/api/inventory/home-card',
        ];
    }

    public function testASourceCardIsValid(): void
    {
        self::assertSame([], HomeCardValidator::errors(self::card(['screen' => 'inventory', 'icon' => 'package'])));
    }

    public function testExactlyOneOfSourceAndRecord(): void
    {
        $both = self::card(['record' => 'order']);
        self::assertNotSame([], HomeCardValidator::errors($both));

        $neither = self::card();
        unset($neither['source']);
        self::assertNotSame([], HomeCardValidator::errors($neither));
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function badCards(): iterable
    {
        yield 'no Arabic label' => [self::card(['label' => ['en' => 'Stock']])];
        yield 'a label too long' => [self::card(['label' => ['en' => str_repeat('x', 61), 'ar' => 'م']])];
        yield 'no permission' => [array_diff_key(self::card(), ['permission' => 1])];
        yield 'not a permission slug' => [self::card(['permission' => 'admin'])];
        yield 'a source outside /api' => [self::card(['source' => 'https://example.com/x'])];
        yield 'a source with a parameter' => [self::card(['source' => '/api/inventory/{id}'])];
        yield 'an unknown key' => [self::card(['html' => '<b>x</b>'])];
        yield 'a bad icon' => [self::card(['icon' => 'Not An Icon'])];
    }

    /**
     * @param array<string, mixed> $card
     */
    #[DataProvider('badCards')]
    public function testABadCardNamesWhatIsWrong(array $card): void
    {
        self::assertNotSame([], HomeCardValidator::errors($card));
    }

    public function testARecordAndAScreenMustBeInTheManifest(): void
    {
        $card = array_diff_key(self::card(['record' => 'order', 'screen' => 'orders']), ['source' => 1]);

        $errors = [];
        HomeCardValidator::check($card, ['order' => []], [['id' => 'orders']], $errors);
        self::assertSame([], $errors);

        $errors = [];
        HomeCardValidator::check($card, ['invoice' => []], [['id' => 'invoices']], $errors);
        self::assertCount(2, $errors);
    }

    public function testNoCardIsFine(): void
    {
        $errors = [];
        HomeCardValidator::check(null, null, null, $errors);
        self::assertSame([], $errors);
    }
}
