<?php

declare(strict_types=1);

namespace Tests\Integration\Contacts;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Contacts\ContactFieldProvider;
use Whity\Core\Contacts\ContactRejectedException;
use Whity\Core\Contacts\ContactRepository;
use Whity\Core\Contacts\ContactService;
use Whity\Core\Contacts\ContactTags;
use Whity\Core\Contacts\Import\ContactImportRepository;
use Whity\Core\Contacts\Import\ContactImportService;
use Whity\Core\CustomFields\CustomFieldImportTargets;
use Whity\Core\CustomFields\CustomFieldRecordType;
use Whity\Core\CustomFields\CustomFieldRecordTypes;
use Whity\Core\CustomFields\CustomFieldRepository;
use Whity\Core\CustomFields\CustomFieldService;

/**
 * The workspace's own fields on contacts (WHIT-954, design 3.3 A9): a new
 * field is usable at once on the record, in list filters, in imports and as a
 * document merge field, and stays inside its workspace.
 *
 * Example Bakery is tenant 1; tenant 2 is another workspace.
 */
final class CustomFieldsRealEngineTest extends TestCase
{
    private PDO $pdo;
    private CustomFieldService $fields;
    private ContactService $contacts;
    private ContactImportService $imports;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Other Bakery', 'other-bakery')");
        $this->pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES (901, 'staff', '', NULL, CURRENT_TIMESTAMP)");
        foreach ([11 => 1, 21 => 2] as $profile => $tenant) {
            $this->pdo->exec(
                "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                 VALUES ({$profile}, 'p{$profile}', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
            );
            $this->pdo->exec(
                "INSERT INTO memberships (profile_id, tenant_id, role_id, status, kind, created_at)
                 VALUES ({$profile}, {$tenant}, 901, 'active', 'member', CURRENT_TIMESTAMP)"
            );
        }

        $repo = new ContactRepository($this->pdo);
        $types = new CustomFieldRecordTypes();
        $types->register(new CustomFieldRecordType('contact', 'Contacts', 'جهات الاتصال', 'contacts:read', 'contacts:write',
            static fn (int $t, int $id): bool => $repo->find($t, $id) !== null, ContactFieldProvider::reservedKeys()));
        $fieldRepo = new CustomFieldRepository($this->pdo);
        $this->fields = new CustomFieldService($this->pdo, $fieldRepo, $types);
        $tags = new ContactTags($this->pdo);
        $this->contacts = new ContactService($repo, $tags, $this->fields);
        $this->imports = new ContactImportService($this->pdo, $repo, $tags, new ContactImportRepository($this->pdo));
        $this->imports->addTargets(new CustomFieldImportTargets($this->fields, $fieldRepo, 'contact'));
    }

    public function testANewFieldIsUsableAtOnceOnTheRecordAndInFilters(): void
    {
        $branch = $this->fields->create(1, ['record_type' => 'contact', 'label' => ['en' => 'Preferred branch', 'ar' => 'الفرع المفضل'], 'kind' => 'select', 'options' => "Abdoun\nSweifieh\nEither", 'show_in_list' => true], 11);
        self::assertSame('preferred_branch', $branch['key']);
        self::assertSame('{{contact.preferred_branch}}', $branch['merge_field']);
        self::assertSame(['Abdoun', 'Sweifieh', 'Either'], $branch['options']);
        $trays = $this->fields->create(1, ['record_type' => 'contact', 'label' => 'Tray sizes', 'kind' => 'multiselect', 'options' => ['Small', 'Large']], 11);

        $cafe = $this->contacts->create(1, ['name' => 'Example Café', 'fields' => ['preferred_branch' => 'abdoun', 'tray_sizes' => ['Large', 'Small']]], 11);
        $this->contacts->create(1, ['name' => 'Example Mart', 'fields' => ['preferred_branch' => 'Sweifieh', 'tray_sizes' => ['Small']]], 11);
        self::assertSame(['preferred_branch' => 'Abdoun', 'tray_sizes' => ['Small', 'Large']], $cafe['fields']);

        self::assertSame(['Example Café'], array_column($this->contacts->list(1, [], 50, 0, ['preferred_branch' => 'Abdoun'])['data'], 'name'));
        self::assertSame(['Example Café'], array_column($this->contacts->list(1, [], 50, 0, ['tray_sizes' => 'Large'])['data'], 'name'));
        self::assertSame(2, $this->contacts->list(1, [], 50, 0, ['tray_sizes' => 'Small'])['total']);

        try {
            $this->contacts->update(1, $cafe['id'], ['fields' => ['preferred_branch' => 'Mars']]);
            self::fail('a choice that is not offered was saved');
        } catch (ContactRejectedException $e) {
            self::assertSame('fields.preferred_branch', $e->extra['field']);
        }
        $cleared = $this->contacts->update(1, $cafe['id'], ['fields' => ['preferred_branch' => null]]);
        self::assertSame(['tray_sizes' => ['Small', 'Large']], $cleared['fields']);
        self::assertSame(2, $this->fields->show(1, $trays['id'])['used_by']);
    }

    public function testRequiredAndGuardedFieldsAndReservedKeys(): void
    {
        $this->fields->create(1, ['record_type' => 'contact', 'label' => 'Allergies to note', 'kind' => 'text', 'required' => true], 11);
        $this->fields->create(1, ['record_type' => 'contact', 'label' => 'Credit limit', 'kind' => 'number', 'edit_permission' => 'contacts:import'], 11);

        try {
            $this->contacts->create(1, ['name' => 'Example Dairy'], 11);
            self::fail('a required field was left out');
        } catch (ContactRejectedException $e) {
            self::assertSame('fields.allergies_to_note', $e->extra['field']);
        }
        self::assertSame(0, $this->contacts->list(1, [], 50, 0)['total'], 'the refused contact was rolled back');

        try {
            $this->contacts->create(1, ['name' => 'Example Dairy', 'fields' => ['allergies_to_note' => 'Nuts', 'credit_limit' => '500']], 11, static fn (): bool => false);
            self::fail('a guarded field was set without its permission');
        } catch (ContactRejectedException $e) {
            self::assertSame(403, $e->status);
        }
        $ok = $this->contacts->create(1, ['name' => 'Example Dairy', 'fields' => ['allergies_to_note' => 'Nuts', 'credit_limit' => '1,500.250']], 11, static fn (string $p): bool => $p === 'contacts:import');
        self::assertSame('1500.250', $ok['fields']['credit_limit']);

        $this->expectException(ContactRejectedException::class);
        $this->fields->create(1, ['record_type' => 'contact', 'label' => 'Tax number', 'kind' => 'text'], 11);
    }

    public function testFieldsStayInTheirWorkspace(): void
    {
        $this->fields->create(1, ['record_type' => 'contact', 'label' => 'Preferred branch', 'kind' => 'text'], 11);
        self::assertSame([], $this->fields->definitions(2, 'contact'));
        self::assertSame(1, $this->fields->recordTypes(1)[0]['count']);
        self::assertSame(0, $this->fields->recordTypes(2)[0]['count']);

        $this->expectException(ContactRejectedException::class);
        $this->contacts->create(2, ['name' => 'Other Café', 'fields' => ['preferred_branch' => 'Abdoun']], 21);
    }

    public function testAnImportFillsTheWorkspacesFieldsAndUndoPutsThemBack(): void
    {
        $this->fields->create(1, ['record_type' => 'contact', 'label' => 'Preferred branch', 'kind' => 'select', 'options' => ['Abdoun', 'Sweifieh']], 11);
        $cafe = $this->contacts->create(1, ['name' => 'Example Café', 'tax_number' => '111', 'fields' => ['preferred_branch' => 'Sweifieh']], 11);

        $import = $this->imports->upload(1, 11, 'c.csv', "Name,Tax number,Preferred branch\nExample Café,111,Abdoun\nExample Mart,222,Mars\n");
        self::assertSame('field:preferred_branch', $import['columns'][2]['suggestion']['target']);
        $checked = $this->imports->check(1, $import['id'], ['mapping' => ['0' => 'name', '1' => 'tax_number', '2' => 'field:preferred_branch']]);
        self::assertSame([0, 1, 1], [$checked['summary']['create'], $checked['summary']['update'], $checked['summary']['skip']]);

        $this->imports->run(1, $import['id'], 11);
        self::assertSame('Abdoun', $this->contacts->show(1, $cafe['id'])['fields']['preferred_branch']);
        $this->imports->undo(1, $import['id']);
        self::assertSame('Sweifieh', $this->contacts->show(1, $cafe['id'])['fields']['preferred_branch']);
    }

    public function testADocumentMergesTheWorkspacesFields(): void
    {
        $this->fields->create(1, ['record_type' => 'contact', 'label' => 'Tray sizes', 'kind' => 'multiselect', 'options' => ['Small', 'Large']], 11);
        $cafe = $this->contacts->create(1, ['name' => 'Example Café', 'tax_number' => '12345678', 'fields' => ['tray_sizes' => 'Small, Large']], 11);
        $provider = new ContactFieldProvider($this->pdo);

        $paths = array_merge(...array_map(static fn (array $g): array => array_column($g['items'], 'path'), $provider->groups(1)));
        self::assertContains('contact.tray_sizes', $paths);
        self::assertContains('contact.tax_number', $paths);

        $can = static fn (string $p): bool => $p === 'contacts:read';
        $values = $provider->values(1, 11, $can, (string) $cafe['id']);
        self::assertSame('Small, Large', $values['values']['contact.tray_sizes'] ?? null);
        self::assertSame('12345678', $values['values']['contact.tax_number']);
        self::assertNull($provider->values(2, 21, $can, (string) $cafe['id']), 'another workspace reads nothing');
        self::assertNull($provider->values(1, 11, static fn (): bool => false, (string) $cafe['id']), 'without contacts:read, nothing');
    }
}
