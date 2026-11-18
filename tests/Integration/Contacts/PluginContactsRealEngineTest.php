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
use Whity\Core\Contacts\HostContacts;
use Whity\Core\Contacts\Import\ContactImportRepository;
use Whity\Core\Contacts\Import\ContactImportService;
use Whity\Core\Contacts\PluginContactFields;
use Whity\Core\CustomFields\CustomFieldImportTargets;
use Whity\Core\CustomFields\CustomFieldRecordType;
use Whity\Core\CustomFields\CustomFieldRecordTypes;
use Whity\Core\CustomFields\CustomFieldRepository;
use Whity\Core\CustomFields\CustomFieldService;
use Whity\Sdk\Contacts\ContactRejected;
use Whity\Sdk\Contacts\Contacts;

/**
 * A plugin keeps its suppliers as core contacts (SDK 1.119, WHIT-1209, #2131).
 *
 * KeyHub declares its own fields on `contact`; the workspace gets them when
 * the plugin is on, loses sight of them (values kept) when it is off, and the
 * plugin creates, updates and finds a supplier by its own field through
 * {@see Contacts}, with no HTTP call and no session.
 *
 * Example Bakery is tenant 1 (Amro Saleh, 11, can write contacts; Awss Haddad,
 * 12, can only read); tenant 2 is another workspace (Ahmad Khalil, 21, can write).
 */
final class PluginContactsRealEngineTest extends TestCase
{
    private PDO $pdo;
    private CustomFieldService $fields;
    private ContactService $service;
    private ContactImportService $imports;
    private PluginContactFields $pluginFields;
    private Contacts $contacts;

    /** @var array<int, bool> tenant id => KeyHub on */
    private array $on = [1 => true, 2 => true];

    private bool $loaded = true;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Other Bakery', 'other-bakery')");
        $this->pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES (901, 'staff', '', NULL, CURRENT_TIMESTAMP)");
        foreach ([11 => 1, 12 => 1, 21 => 2] as $profile => $tenant) {
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
        $this->service = new ContactService($repo, $tags, $this->fields);
        $this->imports = new ContactImportService($this->pdo, $repo, $tags, new ContactImportRepository($this->pdo));
        $this->imports->addTargets(new CustomFieldImportTargets($this->fields, $fieldRepo, 'contact'));

        $keyhub = PluginContactFields::normalize('KeyHub', [
            ['key' => 'supplier_code', 'label' => ['en' => 'Supplier code', 'ar' => 'رمز المورّد'], 'type' => 'text', 'inList' => true],
            ['key' => 'keyhub.distributor_account', 'label' => ['en' => 'Distributor account', 'ar' => 'حساب الموزّع'], 'type' => 'text'],
            ['key' => 'supplier_kind', 'label' => ['en' => 'Supplier kind', 'ar' => 'نوع المورّد'], 'type' => 'select', 'options' => ['Distributor', 'PCB house', 'Manufacturer']],
        ]);
        self::assertCount(3, $keyhub);
        $this->pluginFields = new PluginContactFields(
            $this->fields,
            fn (int $tenantId): ?array => !$this->loaded ? null : (($this->on[$tenantId] ?? false) ? ['KeyHub' => $keyhub] : []),
        );
        $this->fields->useOwnedFieldSync($this->pluginFields->syncer());

        $writers = [11 => true, 21 => true];
        $this->contacts = new HostContacts(
            $this->service,
            $repo,
            $tags,
            $this->fields,
            static fn (int $profileId, string $permission, int $tenantId): bool => $permission === 'contacts:write' && ($writers[$profileId] ?? false),
        );
    }

    public function testAPluginCreatesUpdatesAndFindsASupplierByItsOwnField(): void
    {
        $digikey = $this->contacts->create(1, [
            'name' => 'Example Components',
            'is_customer' => false,
            'is_supplier' => true,
            'email' => 'orders@example.com',
            'tags' => ['Electronics'],
            'fields' => ['keyhub.supplier_code' => 'DK-0042', 'keyhub.supplier_kind' => 'distributor'],
        ], 11);
        self::assertTrue($digikey->isSupplier);
        self::assertFalse($digikey->isCustomer);
        self::assertSame(['Electronics'], $digikey->tags);
        self::assertSame('DK-0042', $digikey->field('keyhub.supplier_code'));
        self::assertSame('Distributor', $digikey->field('keyhub.supplier_kind'), 'checked like any field: the choice as declared');
        self::assertSame(11, $this->number('SELECT created_by_profile_id FROM contacts WHERE id = ' . $digikey->id), 'the contact records who added it, as over HTTP');

        $this->contacts->create(1, ['name' => 'Example Boards', 'is_supplier' => true, 'fields' => ['keyhub.supplier_code' => 'PCB-7']], 11);
        $this->contacts->create(1, ['name' => 'Example Café'], 11);

        $updated = $this->contacts->update(1, $digikey->id, ['fields' => ['keyhub.distributor_account' => 'ACC-991']], 11);
        self::assertSame('ACC-991', $updated->field('keyhub.distributor_account'));
        self::assertSame('DK-0042', $updated->field('keyhub.supplier_code'), 'fields left out are untouched');

        $found = $this->contacts->findByField(1, 'keyhub.supplier_code', 'dk-0042');
        self::assertNotNull($found);
        self::assertSame($digikey->id, $found->id);
        self::assertNull($this->contacts->findByField(1, 'keyhub.supplier_code', 'NOPE'));
        self::assertNull($this->contacts->findByField(1, 'acme.code', 'DK-0042'), 'no such field: nobody');
        self::assertNull($this->contacts->findByField(2, 'keyhub.supplier_code', 'DK-0042'), 'another workspace finds nothing');

        self::assertSame(['Example Boards', 'Example Components'], array_map(static fn ($c) => $c->name, $this->contacts->list(1, ['is_supplier' => true])));
        self::assertSame(['Example Café'], array_map(static fn ($c) => $c->name, $this->contacts->list(1, ['is_supplier' => false])));
        self::assertSame(['Example Components'], array_map(static fn ($c) => $c->name, $this->contacts->list(1, ['field' => ['keyhub.supplier_kind' => 'Distributor']])));
        self::assertSame(['Example Components'], array_map(static fn ($c) => $c->name, $this->contacts->list(1, ['tag' => 'electronics'])));
        self::assertSame([], $this->contacts->list(1, ['tag' => 'No such tag']));
        self::assertCount(1, $this->contacts->list(1, [], 1, 1));

        self::assertSame($digikey->id, $this->contacts->get(1, $digikey->id)?->id);
        self::assertNull($this->contacts->get(2, $digikey->id), 'another workspace sees nothing');
    }

    public function testThePluginsFieldsShowOnTheScreensAndInImportsWithoutCountingTowardTheLimit(): void
    {
        $defs = $this->fields->definitions(1, 'contact');
        self::assertSame(['keyhub.supplier_code', 'keyhub.distributor_account', 'keyhub.supplier_kind'], array_column($defs, 'key'));
        self::assertSame(['KeyHub'], array_values(array_unique(array_column($defs, 'owner_plugin'))));
        self::assertSame(['en' => 'Supplier code', 'ar' => 'رمز المورّد'], $defs[0]['label']);
        self::assertTrue($defs[0]['show_in_list']);
        self::assertSame('{{contact.keyhub.supplier_code}}', $defs[0]['merge_field']);

        $import = $this->imports->upload(1, 11, 'suppliers.csv', "Name,Supplier code,رمز المورّد\nExample Components,DK-1,\n");
        self::assertSame('field:keyhub.supplier_code', $import['columns'][1]['suggestion']['target']);

        try {
            $this->fields->create(1, ['record_type' => 'contact', 'key' => 'keyhub.sneaky', 'label' => 'Sneaky', 'kind' => 'text'], 11);
            self::fail('a person made a key under a plugin');
        } catch (ContactRejectedException $e) {
            self::assertSame('key', $e->extra['field']);
        }
        for ($i = 1; $i <= CustomFieldService::MAX_FIELDS; $i++) {
            $this->fields->create(1, ['record_type' => 'contact', 'label' => 'Own field ' . $i, 'kind' => 'text'], 11);
        }
        self::assertCount(CustomFieldService::MAX_FIELDS + 3, $this->fields->definitions(1, 'contact'));
        try {
            $this->fields->create(1, ['record_type' => 'contact', 'label' => 'One too many', 'kind' => 'text'], 11);
            self::fail('the workspace limit still holds for its own fields');
        } catch (ContactRejectedException $e) {
            self::assertSame(422, $e->status);
        }
    }

    public function testTurningThePluginOffHidesItsFieldsAndKeepsTheValues(): void
    {
        $supplier = $this->contacts->create(1, ['name' => 'Example Components', 'is_supplier' => true, 'fields' => ['keyhub.supplier_code' => 'DK-0042']], 11);
        $code = $this->fields->definitions(1, 'contact')[0];
        $this->fields->update(1, $code['id'], ['label' => ['en' => 'Our supplier no.', 'ar' => 'رقم المورّد لدينا'], 'show_in_list' => false]);

        try {
            $this->fields->archive(1, $code['id'], true);
            self::fail('a person archived a plugin field');
        } catch (ContactRejectedException $e) {
            self::assertSame(409, $e->status);
        }

        $this->on[1] = false;
        $result = $this->pluginFields->reconcile(1);
        self::assertCount(3, $result['archived']);
        self::assertSame([], $this->fields->definitions(1, 'contact'));
        self::assertSame([], (array) $this->service->show(1, $supplier->id)['fields'], 'hidden, not shown');
        self::assertSame(1, $this->number('SELECT COUNT(*) FROM custom_field_values'), 'the value is kept');
        try {
            $this->fields->archive(1, $code['id'], false);
            self::fail('a person restored a plugin field while the plugin is off');
        } catch (ContactRejectedException $e) {
            self::assertSame(409, $e->status);
        }
        self::assertSame(3, count($this->fields->definitions(2, 'contact')), 'another workspace keeps its own');

        $this->on[1] = true;
        $this->pluginFields->reconcile(1);
        self::assertSame('DK-0042', $this->contacts->get(1, $supplier->id)?->field('keyhub.supplier_code'), 'back with its value');
        $code = $this->fields->definitions(1, 'contact')[0];
        self::assertSame('Our supplier no.', $code['label']['en'], 'what the workspace changed is not overwritten');
        self::assertFalse($code['show_in_list']);
        self::assertCount(3, $this->fields->definitions(1, 'contact', true), 'made once, never twice');
    }

    public function testNotKnowingWhichPluginsAreOnChangesNothing(): void
    {
        $this->fields->definitions(1, 'contact');
        $this->loaded = false;
        self::assertSame(['made' => [], 'archived' => []], $this->pluginFields->reconcile(1));
        $this->loaded = true;
        self::assertCount(3, $this->fields->definitions(1, 'contact'));
    }

    public function testAWorkspaceIsCheckedOnceInAWhileNotOnEveryRead(): void
    {
        $now = 1000.0;
        $calls = 0;
        $plugin = new PluginContactFields($this->fields, static function () use (&$calls): array {
            $calls++;

            return [];
        }, static function () use (&$now): float {
            return $now;
        });
        $plugin->ensure(1);
        $plugin->ensure(1);
        self::assertSame(1, $calls);
        $now += PluginContactFields::TTL_SECONDS;
        $plugin->ensure(1);
        self::assertSame(2, $calls);
        $plugin->reconcile(1);
        self::assertSame(3, $calls, 'a switch always checks');
        $plugin->ensure(0);
        self::assertSame(3, $calls, 'never the system workspace');
    }

    public function testWritesNeedSomeoneInTheWorkspaceWhoCanChangeContacts(): void
    {
        foreach ([[1, 21], [1, 12], [1, 0], [0, 11]] as [$tenant, $profile]) {
            try {
                $this->contacts->create($tenant, ['name' => 'Example Components'], $profile);
                self::fail('created as ' . $profile . ' in ' . $tenant);
            } catch (ContactRejected $e) {
                self::assertSame(403, $e->status);
            }
        }
        self::assertSame(0, $this->number('SELECT COUNT(*) FROM contacts'));

        try {
            $this->contacts->create(1, ['name' => 'Example Components', 'email' => 'not an email'], 11);
            self::fail('a bad email was saved');
        } catch (ContactRejected $e) {
            self::assertSame(422, $e->status);
            self::assertSame('email', $e->field);
        }
        try {
            $this->contacts->create(1, ['name' => 'Example Components', 'fields' => ['keyhub.supplier_kind' => 'Wholesaler']], 11);
            self::fail('a choice that is not offered was saved');
        } catch (ContactRejected $e) {
            self::assertSame('fields.keyhub.supplier_kind', $e->field);
        }

        $other = $this->contacts->create(2, ['name' => 'Other Components'], 21);
        try {
            $this->contacts->update(1, $other->id, ['name' => 'Taken'], 11);
            self::fail('changed a contact in another workspace');
        } catch (ContactRejected $e) {
            self::assertSame(404, $e->status);
        }
        self::assertSame('Other Components', $this->contacts->get(2, $other->id)?->name);
    }

    private function number(string $sql): int
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt);

        return (int) $stmt->fetchColumn();
    }
}
