<?php

declare(strict_types=1);

namespace Tests\Sdk\Manifest;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Whity\Sdk\Manifest\ManifestValidator;
use Whity\Sdk\Manifest\PluginManifest;

/**
 * WHIT-995 (SDK 1.81): starter templates a workspace plugin ships under
 * `documents.starters`, checked with the rest of the manifest.
 */
final class DocumentStarterValidatorTest extends TestCase
{
    /** @return array<string, mixed> */
    public static function manifest(): array
    {
        $manifest = DocumentSlotValidatorTest::manifest();
        $manifest['documents']['starters'] = [
            'confirmation' => [
                'label' => ['en' => 'Order confirmation', 'ar' => 'تأكيد الطلب'],
                'slot' => 'order_confirmation',
                'page' => ['size' => 'A4', 'marginMm' => 16, 'letterhead' => true],
                'ownFields' => [['key' => 'note', 'label' => 'Note', 'sample' => 'Thank you']],
                'blocks' => [
                    ['id' => 'b1', 'type' => 'heading', 'level' => 1, 'content' => [['type' => 'text', 'text' => 'Order confirmation']]],
                    ['id' => 'b2', 'type' => 'paragraph', 'content' => [['type' => 'field', 'path' => 'CakeOrders.order.customer', 'label' => 'Customer']]],
                ],
            ],
            'price_list' => [
                'label' => ['en' => 'Price list', 'ar' => 'قائمة الأسعار'],
                'lang' => 'ar',
                'page' => ['size' => 'A4'],
                'ownFields' => [['key' => 'season', 'label' => 'الموسم', 'sample' => ['en' => 'Winter', 'ar' => 'الشتاء']]],
                'blocks' => [['id' => 'p1', 'type' => 'paragraph', 'content' => []]],
            ],
        ];

        return $manifest;
    }

    public function testTheBakeryStartersAreValid(): void
    {
        self::assertSame([], ManifestValidator::validate(self::manifest())['errors']);
        $starters = PluginManifest::fromArray(self::manifest())->documentStarters();
        self::assertSame(['confirmation', 'price_list'], array_keys($starters));
        self::assertSame('order_confirmation', $starters['confirmation']['slot']);
    }

    public function testAManifestWithoutStartersHasNone(): void
    {
        self::assertSame([], PluginManifest::fromArray(DocumentSlotValidatorTest::manifest())->documentStarters());
    }

    /** @return iterable<string, array{callable(array<string, mixed>): array<string, mixed>, string}> */
    public static function invalid(): iterable
    {
        yield 'starters not an object' => [
            static function (array $m): array { $m['documents']['starters'] = ['x']; return $m; },
            'documents.starters: must be an object of starter name to starter',
        ];
        yield 'bad starter name' => [
            static function (array $m): array {
                $m['documents']['starters']['Price-List'] = $m['documents']['starters']['price_list'];
                return $m;
            },
            'documents.starters.Price-List: starter names are lowercase letters, digits and underscores, up to 40',
        ];
        yield 'unknown key' => [
            static function (array $m): array { $m['documents']['starters']['price_list']['theme'] = 'x'; return $m; },
            "documents.starters.price_list: unknown key 'theme'",
        ];
        yield 'no label' => [
            static function (array $m): array { unset($m['documents']['starters']['price_list']['label']); return $m; },
            'documents.starters.price_list.label',
        ];
        yield 'unknown slot' => [
            static function (array $m): array { $m['documents']['starters']['price_list']['slot'] = 'invoice'; return $m; },
            'documents.starters.price_list.slot: must be a slot this manifest declares under documents.slots',
        ];
        yield 'two starters for one slot' => [
            static function (array $m): array { $m['documents']['starters']['price_list']['slot'] = 'order_confirmation'; return $m; },
            "documents.starters.price_list.slot: 'order_confirmation' already has a starter (confirmation)",
        ];
        yield 'a kind the slot does not take' => [
            static function (array $m): array { $m['documents']['starters']['confirmation']['kind'] = 'label'; return $m; },
            "documents.starters.confirmation.kind: slot 'order_confirmation' takes template",
        ];
        yield 'unknown record' => [
            static function (array $m): array { $m['documents']['starters']['price_list']['record'] = 'cake'; return $m; },
            'documents.starters.price_list.record: must be a record type this manifest declares',
        ];
        yield 'bad lang' => [
            static function (array $m): array { $m['documents']['starters']['price_list']['lang'] = 'fr'; return $m; },
            'documents.starters.price_list.lang: one of en, ar',
        ];
        yield 'no page' => [
            static function (array $m): array { unset($m['documents']['starters']['price_list']['page']); return $m; },
            'documents.starters.price_list.page: required',
        ];
        yield 'no blocks' => [
            static function (array $m): array { $m['documents']['starters']['price_list']['blocks'] = []; return $m; },
            'documents.starters.price_list.blocks: required, a non-empty list of editor blocks',
        ];
        yield 'a block with no id' => [
            static function (array $m): array { $m['documents']['starters']['price_list']['blocks'] = [['type' => 'paragraph']]; return $m; },
            'documents.starters.price_list.blocks.0: each block is an object with a string id and a string type',
        ];
        yield 'a repeated block id' => [
            static function (array $m): array {
                $m['documents']['starters']['price_list']['blocks'][] = ['id' => 'p1', 'type' => 'divider'];
                return $m;
            },
            "documents.starters.price_list.blocks.1: block id 'p1' is used twice",
        ];
        yield 'a bad own field' => [
            static function (array $m): array { $m['documents']['starters']['price_list']['ownFields'] = [['key' => 'Bad Key', 'label' => 'x']]; return $m; },
            'documents.starters.price_list.ownFields.0',
        ];
    }

    public function testASampleIsTextOrTextPerLanguage(): void
    {
        $m = self::manifest();
        $m['documents']['starters']['price_list']['ownFields'][0]['sample'] = ['fr' => 'Hiver'];
        self::assertNotSame([], ManifestValidator::validate($m)['errors'], 'only en and ar');
        $m['documents']['starters']['price_list']['ownFields'][0]['sample'] = ['ar' => str_repeat('س', 201)];
        self::assertNotSame([], ManifestValidator::validate($m)['errors'], 'at most 200 each');
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $mutate */
    #[DataProvider('invalid')]
    public function testItRefuses(callable $mutate, string $expected): void
    {
        $errors = ManifestValidator::validate($mutate(self::manifest()))['errors'];
        $matched = array_filter($errors, static fn (string $e): bool => str_starts_with($e, $expected));
        self::assertNotSame([], $matched, "Expected an error starting '{$expected}', got:\n" . implode("\n", $errors));
    }
}
