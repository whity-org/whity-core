<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Contacts\CustomerActivity;
use Whity\Core\Contacts\CustomerContacts;
use Whity\Core\RBAC\CorePermissions;

/**
 * Contacts as the one customer record (merge map, plumbing; migration 291).
 *
 * Example Bakery (1) has a customer, Ibrahim Mansour, who books, chats and asks
 * to join. Each of those finds his one contact. Example Florist (2) keeps its
 * own contacts.
 */
final class CustomerContactsRealEngineTest extends TestCase
{
    private PDO $pdo;
    private CustomerContacts $contacts;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'bakery'), (2, 'Example Florist', 'florist')");
        $this->pdo->exec("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                          VALUES (13, 'Ibrahim Mansour', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $this->contacts = new CustomerContacts($this->pdo);
    }

    public function testTheSameCustomerIsFoundByEmailThenByMobile(): void
    {
        $first = $this->contacts->forCustomer(1, 'Ibrahim Mansour', 'Ibrahim@Example.com', '');
        self::assertNotNull($first);
        self::assertSame($first, $this->contacts->forCustomer(1, 'I. Mansour', 'ibrahim@example.com', ''), 'email, any case');

        $this->pdo->exec("UPDATE contacts SET mobile = '+962790000013' WHERE id = {$first}");
        self::assertSame($first, $this->contacts->forCustomer(1, 'Ibrahim', '', '+962 79 000 0013'), 'mobile, however it is written');
        self::assertSame(1, (int) $this->q('SELECT COUNT(*) FROM contacts')->fetchColumn());
    }

    public function testANameAloneMatchesNobodyAndAnotherWorkspaceHasItsOwn(): void
    {
        self::assertNull($this->contacts->forCustomer(1, 'Ibrahim Mansour', '', ''), 'a name alone would merge different people');
        $bakery = $this->contacts->forCustomer(1, 'Ibrahim Mansour', 'ibrahim@example.com', '');
        $florist = $this->contacts->forCustomer(2, 'Ibrahim Mansour', 'ibrahim@example.com', '');
        self::assertNotSame($bakery, $florist);
    }

    public function testTheAccountFindsTheContactWithoutAnEmail(): void
    {
        $id = $this->contacts->forCustomer(1, 'Ibrahim Mansour', 'ibrahim@example.com', '', 13);
        self::assertSame($id, $this->contacts->forCustomer(1, 'Ibrahim Mansour', '', '', 13));
    }

    public function testTheActivityShowsOnlyWhatTheReaderMayRead(): void
    {
        $contact = (int) $this->contacts->forCustomer(1, 'Ibrahim Mansour', 'ibrahim@example.com', '', 13);
        $this->pdo->exec("INSERT INTO customer_signups (tenant_id, email, display_name, status, profile_id, expires_at, created_at, contact_id)
                          VALUES (1, 'ibrahim@example.com', 'Ibrahim Mansour', 'pending', 13, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, {$contact})");
        $activity = new CustomerActivity($this->pdo);

        $all = $activity->forContact(1, $contact, static fn (string $p): bool => true);
        self::assertSame(['bookings', 'chats', 'forms', 'join_requests'], array_keys($all));
        self::assertSame('Ibrahim Mansour', $all['join_requests'][0]['title']);
        self::assertSame('pending', $all['join_requests'][0]['status']);

        $calendarOnly = $activity->forContact(1, $contact, static fn (string $p): bool => $p === CorePermissions::CALENDAR_READ);
        self::assertSame(['bookings'], array_keys($calendarOnly), 'a section the reader can\'t see is left out');

        self::assertSame([], $activity->forContact(2, $contact, static fn (string $p): bool => true), 'another workspace\'s contact is nobody');
    }

    private function q(string $sql): \PDOStatement
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt);

        return $stmt;
    }
}
