<?php

declare(strict_types=1);

namespace Tests\Sdk;

use PHPUnit\Framework\TestCase;
use Whity\Core\Contacts\PluginContactFields;
use Whity\Sdk\Contacts\Contact;
use Whity\Sdk\Contacts\ContactRejected;
use Whity\Sdk\Manifest\ContactFieldValidator;

/**
 * SDK 1.119 (WHIT-1209, #2131): the Contacts value class, the check of a
 * plugin's contact fields, and how the host names them under the plugin.
 */
final class ContactsSdkTest extends TestCase
{
    public function testAKeyIsNamedUnderThePluginAndNeverUnderAnother(): void
    {
        self::assertSame('keyhub.supplier_code', ContactFieldValidator::qualifiedKey('KeyHub', 'supplier_code'));
        self::assertSame('keyhub.supplier_code', ContactFieldValidator::qualifiedKey('KeyHub', 'keyhub.supplier_code'));
        self::assertSame('front_office.sku', ContactFieldValidator::qualifiedKey('Front-Office', 'sku'));
        self::assertSame('keyhub.code', ContactFieldValidator::qualifiedKey('Acme\\Plugins\\KeyHub', 'code'));

        self::assertNull(ContactFieldValidator::qualifiedKey('KeyHub', 'acme.supplier_code'), 'another plugin\'s name');
        self::assertNull(ContactFieldValidator::qualifiedKey('KeyHub', 'keyhub.a.b'));
        self::assertNull(ContactFieldValidator::qualifiedKey('KeyHub', 'Supplier Code'));
        self::assertNull(ContactFieldValidator::qualifiedKey('KeyHub', '1code'));
        self::assertNull(ContactFieldValidator::qualifiedKey('KeyHub', str_repeat('a', 60)), 'longer than the column');
        self::assertNull(ContactFieldValidator::qualifiedKey('!!!', 'code'), 'no usable plugin name');
        self::assertNull(ContactFieldValidator::qualifiedKey('KeyHub', 42));
    }

    public function testADeclaredFieldIsChecked(): void
    {
        $ok = ['key' => 'supplier_code', 'label' => ['en' => 'Supplier code', 'ar' => 'رمز المورّد'], 'type' => 'text'];
        self::assertSame([], ContactFieldValidator::errors('KeyHub', $ok));
        self::assertSame([], ContactFieldValidator::errors('KeyHub', $ok + ['required' => false, 'inList' => true, 'editPermission' => 'keyhub:purchasing']));
        self::assertSame([], ContactFieldValidator::errors('KeyHub', ['type' => 'select', 'options' => ['Distributor']] + $ok));

        self::assertNotSame([], ContactFieldValidator::errors('KeyHub', ['label' => ['en' => 'Supplier code']] + $ok), 'Arabic is required');
        self::assertNotSame([], ContactFieldValidator::errors('KeyHub', ['type' => 'file'] + $ok));
        self::assertNotSame([], ContactFieldValidator::errors('KeyHub', ['type' => 'select'] + $ok), 'a choice field needs choices');
        self::assertNotSame([], ContactFieldValidator::errors('KeyHub', ['type' => 'multiselect', 'options' => ['', 'A']] + $ok));
        self::assertNotSame([], ContactFieldValidator::errors('KeyHub', ['inList' => 'yes'] + $ok));
        self::assertNotSame([], ContactFieldValidator::errors('KeyHub', ['editPermission' => 'purchasing'] + $ok));
        self::assertNotSame([], ContactFieldValidator::errors('KeyHub', ['key' => 'acme.code'] + $ok));
        self::assertNotSame([], ContactFieldValidator::errors('KeyHub', 'supplier_code'));
    }

    public function testTheHostKeepsTheGoodFieldsAndDropsTheBadOnesWithAReason(): void
    {
        $why = [];
        $fields = PluginContactFields::normalize('KeyHub', [
            ['key' => 'supplier_code', 'label' => ['en' => ' Supplier code ', 'ar' => 'رمز المورّد'], 'type' => 'text', 'inList' => true],
            ['key' => 'keyhub.supplier_code', 'label' => ['en' => 'Again', 'ar' => 'مرة أخرى'], 'type' => 'text'],
            ['key' => 'kind', 'label' => ['en' => 'Kind', 'ar' => 'النوع'], 'type' => 'select', 'options' => ['Distributor', 'distributor', ' PCB house ']],
            ['key' => 'broken', 'label' => 'Broken', 'type' => 'text'],
        ], static function (string $reason) use (&$why): void {
            $why[] = $reason;
        });

        self::assertSame(['keyhub.supplier_code', 'keyhub.kind'], array_column($fields, 'key'));
        self::assertSame(['en' => 'Supplier code', 'ar' => 'رمز المورّد'], $fields[0]['label']);
        self::assertTrue($fields[0]['in_list']);
        self::assertFalse($fields[0]['required']);
        self::assertNull($fields[0]['edit_permission']);
        self::assertSame(['Distributor', 'PCB house'], $fields[1]['options']);
        self::assertCount(2, $why);
        self::assertStringContainsString('declared twice', $why[0]);
        self::assertStringContainsString('field 3 dropped', $why[1]);
    }

    public function testAPluginMayDeclareAtMostThirtyFields(): void
    {
        $many = [];
        for ($i = 0; $i < ContactFieldValidator::MAX_FIELDS + 2; $i++) {
            $many[] = ['key' => 'f' . $i, 'label' => ['en' => 'F' . $i, 'ar' => 'ح' . $i], 'type' => 'text'];
        }
        self::assertCount(ContactFieldValidator::MAX_FIELDS, PluginContactFields::normalize('KeyHub', $many));
    }

    public function testAContactReadsTheApiShape(): void
    {
        $contact = Contact::fromArray([
            'id' => 7,
            'name' => 'Example Components',
            'email' => 'orders@example.com',
            'is_customer' => false,
            'is_supplier' => true,
            'profile_id' => null,
            'tags' => [['id' => 3, 'name' => 'Electronics']],
            'fields' => (object) ['keyhub.supplier_code' => 'DK-0042', 'keyhub.preferred' => true],
            'archived' => false,
            'created_at' => '2026-11-18 09:00:00',
        ]);

        self::assertSame(7, $contact->id);
        self::assertTrue($contact->isSupplier);
        self::assertFalse($contact->isCustomer);
        self::assertSame(['Electronics'], $contact->tags);
        self::assertSame('DK-0042', $contact->field('keyhub.supplier_code'));
        self::assertTrue($contact->field('keyhub.preferred'));
        self::assertNull($contact->field('keyhub.missing'));
        self::assertSame('', $contact->phone);
        self::assertSame('2026-11-18 09:00:00', $contact->createdAt);
    }

    public function testARejectionCarriesItsStatusAndField(): void
    {
        $e = new ContactRejected('That email address doesn\'t look right', 422, 'email');
        self::assertSame(422, $e->status);
        self::assertSame(422, $e->getCode());
        self::assertSame('email', $e->field);
    }
}
