<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Import;

use PHPUnit\Framework\TestCase;
use Whity\Core\Contacts\Import\ContactImportService;
use Whity\Core\Import\CoreImportKinds;
use Whity\Core\Import\ImportKind;
use Whity\Core\Import\ImportKinds;
use Whity\Core\Import\ImportOption;
use Whity\Core\Inventory\Import\InventoryImports;
use Whity\Core\RBAC\CorePermissions;

/**
 * The import kinds the shared screen draws (WHIT-1326, design section 34
 * §6.5): core's four, filtered by permission and area, each describing its
 * targets in both languages.
 */
final class ImportKindsTest extends TestCase
{
    private function kinds(): ImportKinds
    {
        $kinds = new ImportKinds();
        CoreImportKinds::register($kinds, null, null);

        return $kinds;
    }

    public function testCoreRegistersItsFourKindsInOrder(): void
    {
        $keys = array_map(static fn (ImportKind $k): string => $k->key, $this->kinds()->all());

        self::assertSame(['contacts', 'inventory.items', 'inventory.opening_stock', 'inventory.supplier_prices'], $keys);
    }

    public function testAKindIsOfferedOnlyWithEveryPermissionItNeeds(): void
    {
        $holds = [CorePermissions::INVENTORY_WRITE];
        $may = static fn (string $p): bool => in_array($p, $holds, true);

        $keys = array_column($this->kinds()->forPerson(7, $may), 'key');

        // Opening stock also needs stock:adjust; contacts need contacts:import.
        self::assertSame(['inventory.items', 'inventory.supplier_prices'], $keys);
    }

    public function testAreaKeepsOneListPagesKinds(): void
    {
        $all = static fn (string $p): bool => true;

        self::assertSame(['contacts'], array_column($this->kinds()->forPerson(7, $all, 'contacts'), 'key'));
        self::assertCount(3, $this->kinds()->forPerson(7, $all, 'inventory'));
    }

    public function testEveryTargetHasAnArabicNameAndMatchesTheEngine(): void
    {
        $engine = [
            'contacts' => array_keys(ContactImportService::TARGETS),
            'inventory.items' => array_column(InventoryImports::targets('items'), 'key'),
            'inventory.opening_stock' => array_column(InventoryImports::targets('opening_stock'), 'key'),
            'inventory.supplier_prices' => array_column(InventoryImports::targets('supplier_prices'), 'key'),
        ];
        foreach ($this->kinds()->all() as $kind) {
            self::assertSame($engine[$kind->key], array_keys($kind->targets), $kind->key . ' targets drift from its engine');
            foreach ($kind->targets as $key => $label) {
                self::assertNotSame('', trim($label['ar']), $kind->key . '.' . $key . ' has no Arabic name');
                self::assertMatchesRegularExpression('/\p{Arabic}/u', $label['ar'], $kind->key . '.' . $key);
            }
            self::assertStringContainsString('{count}', $kind->words['run']['en']);
            self::assertStringContainsString('{count}', $kind->words['run']['ar']);
        }
    }

    public function testTheApiShapeCarriesPathOptionsAndChoices(): void
    {
        $kinds = new ImportKinds();
        $kinds->register(new ImportKind(
            key: 'example.things',
            label: ['en' => 'Things', 'ar' => 'أشياء'],
            description: ['en' => 'Things.', 'ar' => 'أشياء.'],
            permissions: [],
            path: '/api/v1/example/imports',
            area: 'example',
            back: ['href' => '/example', 'label' => ['en' => 'Back', 'ar' => 'رجوع']],
            targets: ['code' => ['en' => 'Code', 'ar' => 'الرمز'], 'shelf' => ['en' => 'Shelf', 'ar' => 'الرف']],
            required: ['code'],
            options: [new ImportOption(
                key: 'shelf_id',
                type: 'select',
                label: ['en' => 'Shelf', 'ar' => 'الرف'],
                source: static fn (int $tenantId): array => [['value' => $tenantId * 10, 'label' => ['en' => 'Top', 'ar' => 'الأعلى']]],
                requiredUnless: 'shelf',
                valueType: 'int',
            )],
            words: ['title' => ['en' => 'Import things', 'ar' => 'استيراد الأشياء'], 'create' => ['en' => 'New', 'ar' => 'جديد'], 'update' => ['en' => 'Updated', 'ar' => 'محدّث'], 'run' => ['en' => 'Import {count}', 'ar' => 'استيراد {count}']],
        ));

        $api = $kinds->forPerson(4, static fn (string $p): bool => false)[0];

        self::assertSame('/api/v1/example/imports', $api['path']);
        self::assertSame(['code'], $api['required']);
        self::assertSame('shelf', $api['options'][0]['required_unless']);
        self::assertSame([['value' => 40, 'label' => ['en' => 'Top', 'ar' => 'الأعلى']]], $api['options'][0]['choices']);
        self::assertSame(['key' => 'code', 'label' => ['en' => 'Code', 'ar' => 'الرمز'], 'hint' => null], $api['targets'][0]);
    }

    public function testAKindIsRegisteredOnce(): void
    {
        $kinds = $this->kinds();

        $this->expectException(\LogicException::class);
        $kinds->register(CoreImportKinds::contacts());
    }

    public function testARequiredTargetMustBeOneOfItsTargets(): void
    {
        $this->expectException(\LogicException::class);
        new ImportKind(
            key: 'example.bad',
            label: ['en' => 'Bad', 'ar' => 'سيئ'],
            description: ['en' => '', 'ar' => ''],
            permissions: [],
            path: '/api/v1/example',
            area: 'example',
            back: ['href' => '/', 'label' => ['en' => '', 'ar' => '']],
            targets: [],
            required: ['code'],
            options: [],
            words: ['title' => ['en' => '', 'ar' => ''], 'create' => ['en' => '', 'ar' => ''], 'update' => ['en' => '', 'ar' => ''], 'run' => ['en' => '', 'ar' => '']],
        );
    }
}
