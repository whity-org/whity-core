<?php

declare(strict_types=1);

namespace Tests\Integration\Contacts;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Contacts\ContactRejectedException;
use Whity\Core\Contacts\ContactRepository;
use Whity\Core\Contacts\ContactService;
use Whity\Core\Contacts\ContactTags;
use Whity\Core\Contacts\Import\ContactImportRepository;
use Whity\Core\Contacts\Import\ContactImportService;

/**
 * Contacts and their import against the real schema (WHIT-953): tags, the
 * link to a person, tenant isolation, and an import that changes nothing
 * until it runs and can be undone.
 *
 * Example Bakery is tenant 1; tenant 2 is another workspace.
 */
final class ContactsRealEngineTest extends TestCase
{
    private PDO $pdo;
    private ContactService $contacts;
    private ContactImportService $imports;
    private ContactImportRepository $importRows;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Other Bakery', 'other-bakery')");
        $this->pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES (901, 'staff', '', NULL, CURRENT_TIMESTAMP)");
        foreach ([11 => 1, 31 => 1, 21 => 2] as $profile => $tenant) {
            $this->pdo->exec(
                "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                 VALUES ({$profile}, 'p{$profile}', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
            );
            $this->pdo->exec(
                "INSERT INTO memberships (profile_id, tenant_id, role_id, status, kind, created_at)
                 VALUES ({$profile}, {$tenant}, 901, 'active', 'member', CURRENT_TIMESTAMP)"
            );
        }
        $this->pdo->exec("INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at) VALUES (31, 'ibrahim@example.com', true, true, CURRENT_TIMESTAMP)");

        $repo = new ContactRepository($this->pdo);
        $tags = new ContactTags($this->pdo);
        $this->contacts = new ContactService($repo, $tags);
        $this->importRows = new ContactImportRepository($this->pdo);
        $this->imports = new ContactImportService($this->pdo, $repo, $tags, $this->importRows);
    }

    public function testAContactIsACustomerSupplierOrBothWithTags(): void
    {
        $bakery = $this->contacts->create(1, ['name' => 'Corner Café', 'is_supplier' => true, 'mobile' => '079 123 4567', 'tags' => ['Wholesale', 'wholesale', 'VIP']], 11);

        self::assertTrue($bakery['is_customer']);
        self::assertTrue($bakery['is_supplier']);
        self::assertSame('0791234567', $bakery['mobile']);
        self::assertSame(['VIP', 'Wholesale'], array_column($bakery['tags'], 'name'));

        $this->contacts->create(1, ['name' => 'Ahmad Khalil Supplies', 'is_customer' => false, 'is_supplier' => true], 11);
        self::assertSame(1, $this->contacts->list(1, ['kind' => 'customer'], 50, 0)['total']);
        self::assertSame(2, $this->contacts->list(1, ['kind' => 'supplier'], 50, 0)['total']);
        $vip = $bakery['tags'][0]['id'];
        self::assertSame(['Corner Café'], array_column($this->contacts->list(1, ['tag_id' => $vip], 50, 0)['data'], 'name'));

        $updated = $this->contacts->update(1, $bakery['id'], ['tags' => ['VIP']]);
        self::assertSame(['VIP'], array_column($updated['tags'], 'name'));

        $this->expectException(ContactRejectedException::class);
        $this->contacts->create(1, ['name' => 'Nobody', 'is_customer' => false], 11);
    }

    public function testANewContactIsLinkedToThePersonWithThatEmail(): void
    {
        $linked = $this->contacts->create(1, ['name' => 'Ibrahim Mansour', 'email' => 'Ibrahim@example.com'], 11);
        self::assertSame(31, $linked['profile_id']);
        self::assertSame('p31', $linked['profile']['display_name']);

        // Not a member of tenant 2, so not linked there.
        $other = $this->contacts->create(2, ['name' => 'Ibrahim Mansour', 'email' => 'ibrahim@example.com'], 21);
        self::assertNull($other['profile_id']);

        try {
            $this->contacts->update(1, $linked['id'], ['profile_id' => 21]);
            self::fail('linked to someone outside the workspace');
        } catch (ContactRejectedException $e) {
            self::assertSame('profile_id', $e->extra['field']);
        }
    }

    public function testAnotherWorkspaceCannotSeeOrChangeAContact(): void
    {
        $id = $this->contacts->create(1, ['name' => 'Example Bakery'], 11)['id'];

        self::assertSame(0, $this->contacts->list(2, [], 50, 0)['total']);
        foreach ([
            fn () => $this->contacts->show(2, $id),
            fn () => $this->contacts->update(2, $id, ['name' => 'Taken']),
            fn () => $this->contacts->archive(2, $id, true),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('reached another workspace\'s contact');
            } catch (ContactRejectedException $e) {
                self::assertSame(404, $e->status);
            }
        }
        self::assertSame('Example Bakery', $this->contacts->show(1, $id)['name']);
    }

    public function testAnImportShowsWhatItWillDoChangesNothingUntilRunAndUndoes(): void
    {
        $existing = $this->contacts->create(1, ['name' => 'Corner Café', 'mobile' => '+962791234567', 'email' => ''], 11);
        $csv = "Name,Mobile,Email,Tags,Type\n"
            . "Corner Café,079 123 4567,cafe@example.com,Wholesale,\n"
            . "Example Bakery,0797654321,,Wholesale;VIP,customer\n"
            . ",0790000000,,,\n"
            . "Bad Email Ltd,0791111111,not-an-email,,\n"
            . "Example Bakery again,0797654321,,,\n"
            . "Odd Kind,0792222222,,,partner\n";

        $import = $this->imports->upload(1, 11, 'customers.csv', $csv);
        self::assertSame('uploaded', $import['status']);
        self::assertSame(6, $import['row_count']);
        self::assertSame('name', $import['columns'][0]['suggestion']['target']);
        self::assertSame('type', $import['columns'][4]['suggestion']['target']);

        $checked = $this->imports->check(1, $import['id'], [
            'mapping' => ['0' => 'name', '1' => 'mobile', '2' => 'email', '3' => 'tags', '4' => 'type'],
            'country_code' => '+962',
            'add_tags' => ['Imported 2026'],
        ]);
        $summary = $checked['summary'];
        self::assertSame('mobile', $checked['options']['match_by']);
        self::assertSame([1, 1, 4], [$summary['create'], $summary['update'], $summary['skip']]);
        $reasons = array_column(array_filter($summary['rows'], static fn ($r) => $r['action'] === 'skip'), 'reason', 'row');
        self::assertSame([4 => 'No name', 5 => 'The email address doesn\'t look right', 6 => 'Same contact as row 3', 7 => 'Customer or supplier is not one Whity understands'], $reasons);
        self::assertContains(['row' => 2, 'column' => 'mobile', 'from' => '079 123 4567', 'to' => '+962791234567'], $summary['fixes']);
        self::assertSame(['Imported 2026', 'Wholesale', 'VIP'], $summary['tags']);

        // Nothing changed yet.
        self::assertSame(1, $this->contacts->list(1, [], 50, 0)['total']);
        self::assertSame('', $this->contacts->show(1, $existing['id'])['email']);
        self::assertStringContainsString('No name', $this->imports->skippedCsv(1, $import['id']));

        $ran = $this->imports->run(1, $import['id'], 11);
        self::assertSame('imported', $ran['status']);
        self::assertTrue($ran['can_undo']);
        self::assertSame(2, $this->contacts->list(1, [], 50, 0)['total']);
        $cafe = $this->contacts->show(1, $existing['id']);
        self::assertSame('cafe@example.com', $cafe['email']);
        self::assertSame(['Imported 2026', 'Wholesale'], array_column($cafe['tags'], 'name'));

        try {
            $this->imports->run(1, $import['id'], 11);
            self::fail('an import ran twice');
        } catch (ContactRejectedException $e) {
            self::assertSame(409, $e->status);
        }

        // Another workspace can't undo it.
        try {
            $this->imports->undo(2, $import['id']);
            self::fail('another workspace undid the import');
        } catch (ContactRejectedException $e) {
            self::assertSame(404, $e->status);
        }

        $undone = $this->imports->undo(1, $import['id']);
        self::assertSame('undone', $undone['status']);
        self::assertSame(1, $this->contacts->list(1, [], 50, 0)['total']);
        $cafe = $this->contacts->show(1, $existing['id']);
        self::assertSame('', $cafe['email']);
        self::assertSame([], $cafe['tags']);
    }

    public function testAnImportCanOnlyBeUndoneFor24Hours(): void
    {
        $import = $this->imports->upload(1, 11, 'one.csv', "Name\nExample Bakery\n");
        $this->imports->check(1, $import['id'], ['mapping' => ['0' => 'name']]);
        $this->imports->run(1, $import['id'], 11);
        $this->pdo->exec("UPDATE contact_imports SET imported_at = '2020-01-01 00:00:00' WHERE id = " . (int) $import['id']);

        self::assertFalse($this->imports->show(1, $import['id'])['can_undo']);
        $this->expectException(ContactRejectedException::class);
        $this->imports->undo(1, $import['id']);
    }

    public function testACheckNeedsTheNameColumn(): void
    {
        $import = $this->imports->upload(1, 11, 'one.csv', "Name,Mobile\nExample Bakery,0791234567\n");
        $this->expectException(ContactRejectedException::class);
        $this->expectExceptionMessage('name');
        $this->imports->check(1, $import['id'], ['mapping' => ['1' => 'mobile']]);
    }
}
