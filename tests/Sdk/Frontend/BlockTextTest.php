<?php

declare(strict_types=1);

namespace Tests\Sdk\Frontend;

use PHPUnit\Framework\TestCase;
use Whity\Core\i18n\SchemaLabels;
use Whity\Sdk\Frontend\Blocks\BlockText;
use Whity\Sdk\Frontend\Blocks\BlockValidator;

/**
 * WHIT-984 (SDK 1.101): visible text in a block screen may be `{en, ar}`.
 */
final class BlockTextTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private static function tree(): array
    {
        return [
            [
                'type' => 'section',
                'title' => ['en' => 'Stock', 'ar' => 'المخزون'],
                'children' => [
                    ['type' => 'heading', 'level' => 2, 'text' => ['en' => 'Items', 'ar' => 'الأصناف']],
                    ['type' => 'text', 'value' => ['en' => 'What you hold.', 'ar' => 'ما لديك.']],
                    [
                        'type' => 'form',
                        'submit' => ['endpoint' => '/api/demo/items', 'method' => 'POST'],
                        'children' => [
                            [
                                'type' => 'select',
                                'name' => 'unit',
                                'label' => ['en' => 'Unit', 'ar' => 'الوحدة'],
                                'options' => [
                                    ['value' => 'kg', 'label' => ['en' => 'Kilogram', 'ar' => 'كيلوغرام']],
                                    ['value' => 'each', 'label' => ['en' => 'Each']],
                                ],
                            ],
                            ['type' => 'submitButton', 'label' => ['en' => 'Save', 'ar' => 'حفظ']],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function testATreeResolvesToTheReadersLanguageAndFallsBack(): void
    {
        $ar = BlockText::resolve(self::tree(), 'ar-JO');
        $section = $ar[0];
        self::assertSame('المخزون', $section['title']);
        self::assertSame('الأصناف', $section['children'][0]['text']);
        self::assertSame('ما لديك.', $section['children'][1]['value']);
        $select = $section['children'][2]['children'][0];
        self::assertSame('الوحدة', $select['label']);
        self::assertSame('kg', $select['options'][0]['value'], 'an option value is submitted, never translated');
        self::assertSame('كيلوغرام', $select['options'][0]['label']);
        self::assertSame('Each', $select['options'][1]['label'], 'falls back to the other language');

        $en = BlockText::resolve(self::tree(), 'en');
        self::assertSame('Save', $en[0]['children'][2]['children'][1]['label']);
    }

    public function testPlainStringsAreUntouched(): void
    {
        $tree = [['type' => 'heading', 'level' => 2, 'text' => 'Items']];

        self::assertSame($tree, BlockText::resolve($tree, 'ar'));
    }

    public function testTheValidatorAcceptsLocalizedText(): void
    {
        $result = BlockValidator::validate(self::tree());

        self::assertTrue($result['ok'], implode("\n", $result['errors']));
    }

    public function testTheValidatorRefusesLocalizedTextWithNoWords(): void
    {
        $result = BlockValidator::validate([
            ['type' => 'heading', 'level' => 2, 'text' => ['en' => ' ', 'ar' => '']],
        ]);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('blocks[0].text: localized text needs English or Arabic', implode("\n", $result['errors']));
    }

    public function testAnObjectOfAnotherShapeIsStillRefused(): void
    {
        $result = BlockValidator::validate([
            ['type' => 'heading', 'level' => 2, 'text' => ['fr' => 'Articles']],
        ]);

        self::assertFalse($result['ok'], 'only en and ar are localized text');
    }

    public function testTheFieldsMatchTheOnesCoreTranslatesForItsOwnScreens(): void
    {
        self::assertSame(SchemaLabels::TRANSLATABLE, BlockText::FIELDS);
    }
}
