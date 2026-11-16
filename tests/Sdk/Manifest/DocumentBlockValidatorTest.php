<?php

declare(strict_types=1);

namespace Tests\Sdk\Manifest;

use PHPUnit\Framework\TestCase;
use Whity\Sdk\Documents\DocumentBlock;
use Whity\Sdk\Manifest\DocumentBlockValidator;
use Whity\Sdk\Manifest\ManifestValidator;

/**
 * SDK 1.103 (editor M7, E.17–E.20): a document block is a declaration
 * Whity's renderer draws. Its view uses display blocks only, names only its
 * own fields, and without a print view it can't go where documents print.
 */
final class DocumentBlockValidatorTest extends TestCase
{
    /** @return array<string, mixed> */
    public static function delivery(): array
    {
        return [
            'label' => ['en' => 'Delivery status', 'ar' => 'حالة التوصيل'],
            'description' => ['en' => 'Where an order is', 'ar' => 'أين الطلب'],
            'icon' => 'truck',
            'keywords' => ['shipping'],
            'fields' => [
                ['key' => 'order', 'label' => ['en' => 'Order', 'ar' => 'الطلب'], 'type' => 'text', 'required' => true],
                ['key' => 'stage', 'label' => ['en' => 'Stage', 'ar' => 'المرحلة'], 'type' => 'select', 'options' => [
                    ['value' => 'packed', 'label' => ['en' => 'Packed', 'ar' => 'مُعبأ']],
                    ['value' => 'out', 'label' => ['en' => 'Out for delivery', 'ar' => 'في الطريق']],
                ]],
            ],
            'view' => [['type' => 'keyValue', 'items' => [['label' => 'Order', 'value' => '{{data.order}}'], ['label' => 'Stage', 'value' => '{{data.stage}}']]]],
            'print' => [['type' => 'heading', 'level' => 3, 'text' => 'Order {{data.order}}'], ['type' => 'text', 'value' => '{{data.stage}}']],
            'allowedIn' => ['note', 'template'],
        ];
    }

    /** @return list<string> */
    private static function errors(mixed $blocks): array
    {
        $errors = [];
        DocumentBlockValidator::check(
            $blocks,
            static function (mixed $label, string $path, int $max, bool $required, array &$errs): void {
                ManifestValidator::checkLabel($label, $path, $max, $required, $errs);
            },
            $errors,
        );

        return $errors;
    }

    public function testAValidBlockPasses(): void
    {
        self::assertSame([], self::errors(['delivery_status' => self::delivery()]));
        $block = new DocumentBlock('delivery_status', self::delivery());
        self::assertSame('delivery_status', $block->name);
    }

    public function testTheViewUsesDisplayBlocksOnlyAndNamesOnlyItsFields(): void
    {
        $block = self::delivery();
        $block['view'] = [['type' => 'actionButton', 'label' => 'Go', 'action' => 'x'], ['type' => 'text', 'value' => '{{data.secret}}']];
        $errors = implode("\n", self::errors(['d' => $block]));
        self::assertStringContainsString("'actionButton' can't be used here", $errors);
        self::assertStringContainsString('{{data.secret}} names no field', $errors);
    }

    public function testAPrintViewDrawsOnlyWhatWhityRenderPrints(): void
    {
        $block = self::delivery();
        $block['print'] = [['type' => 'badge', 'variant' => 'info', 'label' => 'x']];
        self::assertStringContainsString("'badge' can't be used here", implode("\n", self::errors(['d' => $block])));
    }

    public function testWithoutAPrintViewItIsForNotesAndWikiOnly(): void
    {
        $block = self::delivery();
        unset($block['print']);
        self::assertSame(
            ['documents.blocks.d.allowedIn: a block without a print view is for notes and wiki pages only'],
            self::errors(['d' => $block]),
        );
        $block['allowedIn'] = ['note', 'wiki'];
        self::assertSame([], self::errors(['d' => $block]));
    }

    public function testFieldsAreChecked(): void
    {
        $block = self::delivery();
        $block['fields'][] = ['key' => 'order', 'label' => ['en' => 'Again', 'ar' => 'مرة أخرى'], 'type' => 'colour'];
        $block['fields'][] = ['key' => 'pick', 'label' => ['en' => 'Pick', 'ar' => 'اختر'], 'type' => 'select'];
        $errors = implode("\n", self::errors(['d' => $block]));
        self::assertStringContainsString("'order' is used twice", $errors);
        self::assertStringContainsString('type: one of text, longText, number, date, select', $errors);
        self::assertStringContainsString('a select needs 1 to 30 options', $errors);
    }

    public function testNamesAndUnknownKeysAreRefusedAndADocumentBlockSaysWhy(): void
    {
        $errors = self::errors(['Bad-Name' => self::delivery() + ['script' => 'x.js']]);
        self::assertContains('documents.blocks.Bad-Name: block names are lowercase letters, digits and underscores, up to 40', $errors);
        self::assertContains("documents.blocks.Bad-Name: unknown key 'script'", $errors);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("block 'd'.label: required");
        $block = self::delivery();
        unset($block['label']);
        new DocumentBlock('d', $block);
    }

    public function testAManifestCarriesBlocksUnderDocuments(): void
    {
        $result = ManifestValidator::validate([
            'manifest' => 'whity.plugin/1',
            'plugin' => ['name' => 'DeliveryTracker', 'version' => '1.0.0', 'label' => ['en' => 'Delivery Tracker', 'ar' => 'متتبع التوصيل']],
            'requires' => ['sdk' => '^1.103'],
            'documents' => ['blocks' => ['delivery_status' => self::delivery()]],
        ]);
        self::assertSame([], $result['errors']);
    }
}
