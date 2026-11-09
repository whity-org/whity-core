<?php

declare(strict_types=1);

namespace Tests\Sdk\I18n;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Whity\Sdk\I18n\LocalizedText;

/**
 * The translatable-field contract (#521). The web CRUD screens read the marker
 * and the object shape directly, so these values are a published contract.
 */
final class LocalizedTextTest extends TestCase
{
    public function testSchemaIsTheShapeTheScreensRecognize(): void
    {
        self::assertSame(
            [
                'type' => 'object',
                'x-whity-localized-text' => true,
                'properties' => ['ar' => ['type' => 'string'], 'en' => ['type' => 'string']],
            ],
            LocalizedText::schema()
        );
        self::assertTrue(LocalizedText::isSchema(LocalizedText::schema()));
    }

    public function testSchemaCanBeExtendedWithSiblingKeys(): void
    {
        self::assertTrue(LocalizedText::isSchema(LocalizedText::schema() + ['nullable' => true]));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function notMarkedSchemas(): iterable
    {
        yield 'no marker' => [['type' => 'object', 'properties' => []]];
        yield 'marker false' => [['type' => 'object', 'x-whity-localized-text' => false]];
        yield 'marker truthy but not true' => [['type' => 'object', 'x-whity-localized-text' => 1]];
        yield 'a string node' => [['type' => 'string', 'x-whity-localized-text' => true]];
    }

    /**
     * @param array<string, mixed> $node
     */
    #[DataProvider('notMarkedSchemas')]
    public function testIsSchemaRefusesOtherNodes(array $node): void
    {
        self::assertFalse(LocalizedText::isSchema($node));
    }

    public function testNormalizeTrimsAndKeepsBothLanguagesInOrder(): void
    {
        self::assertSame(
            ['ar' => 'فاتورة', 'en' => 'Invoice'],
            LocalizedText::normalize(['en' => ' Invoice ', 'ar' => 'فاتورة'])
        );
    }

    public function testNormalizeDropsBlankAndNullLanguages(): void
    {
        self::assertSame(['ar' => 'فاتورة'], LocalizedText::normalize(['ar' => 'فاتورة', 'en' => '   ']));
        self::assertSame(['en' => 'Invoice'], LocalizedText::normalize(['ar' => null, 'en' => 'Invoice']));
        self::assertSame([], LocalizedText::normalize(['ar' => '', 'en' => '']));
        self::assertSame([], LocalizedText::normalize([]));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function wrongShapes(): iterable
    {
        yield 'a plain string' => ['Invoice'];
        yield 'null' => [null];
        yield 'a list' => [['Invoice', 'فاتورة']];
        yield 'an unknown language' => [['fr' => 'Facture', 'en' => 'Invoice']];
        yield 'a number as text' => [['en' => 42]];
        yield 'nested text' => [['en' => ['Invoice']]];
    }

    #[DataProvider('wrongShapes')]
    public function testNormalizeRefusesOtherShapes(mixed $raw): void
    {
        self::assertNull(LocalizedText::normalize($raw));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string|null}>
     */
    public static function resolutions(): iterable
    {
        $both = ['ar' => 'فاتورة', 'en' => 'Invoice'];

        yield 'arabic reader' => [$both, 'ar', 'فاتورة'];
        yield 'english reader' => [$both, 'en', 'Invoice'];
        yield 'regional arabic' => [$both, 'ar-JO', 'فاتورة'];
        yield 'upper-case code' => [$both, 'AR', 'فاتورة'];
        yield 'another language reads english first' => [$both, 'fr', 'Invoice'];
        yield 'arabic reader, english only' => [['en' => 'Invoice'], 'ar', 'Invoice'];
        yield 'english reader, arabic only' => [['ar' => 'فاتورة'], 'en', 'فاتورة'];
        yield 'blank preferred language falls back' => [['ar' => '  ', 'en' => 'Invoice'], 'ar', 'Invoice'];
        yield 'nothing written' => [[], 'ar', null];
        yield 'all blank' => [['ar' => '', 'en' => ' '], 'en', null];
    }

    /**
     * @param array<string, mixed> $value
     */
    #[DataProvider('resolutions')]
    public function testResolveShowsTheReadersLanguageThenTheOther(array $value, string $language, ?string $expected): void
    {
        self::assertSame($expected, LocalizedText::resolve($value, $language));
    }
}
