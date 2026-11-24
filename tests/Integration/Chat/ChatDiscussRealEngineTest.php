<?php

declare(strict_types=1);

namespace Tests\Integration\Chat;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\GrantedPermissions;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Chat\ChatException;
use Whity\Core\Chat\ChatRepository;
use Whity\Core\Chat\ChatService;
use Whity\Core\Chat\ChatViewer;
use Whity\Core\RBAC\CorePermissions;

/**
 * "Discuss in chat" from a board, a meeting or a record (merge map C2,
 * WHIT-1135): one group chat per linked thing, started the first time and
 * named after it, joined by whoever opens it next, and never shared across
 * workspaces.
 *
 * WHIT-1266 (#2231): only by people who may see the thing, the way its own
 * screen decides; nobody the chat's starter removed walks back in; and a
 * plugin's record, which core cannot check, gets a chat only its members are in.
 *
 * Example Bakery (1): Amro (10), Awss (11) and Ahmad (13). Example Florist (2): Emad (12).
 */
final class ChatDiscussRealEngineTest extends TestCase
{
    private const A = 1;
    private const B = 2;
    private const AMRO = 10;
    private const AWSS = 11;
    private const EMAD = 12;
    private const AHMAD = 13;

    private const STAFF = [CorePermissions::CALENDAR_READ, CorePermissions::CONVENING_READ, CorePermissions::TASKS_READ];

    private PDO $pdo;
    private ChatService $chat;
    private GrantedPermissions $permissions;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->seed();
        $this->permissions = new GrantedPermissions([
            self::AMRO => self::STAFF,
            self::AWSS => self::STAFF,
            self::EMAD => self::STAFF,
            self::AHMAD => [],
        ]);
        $this->chat = new ChatService(new ChatRepository($this->pdo), null, null, null, null, $this->permissions->chatRecordAccess($this->pdo));
    }

    public function testTheFirstOpenStartsTheChatNamedAfterTheRecord(): void
    {
        $found = $this->chat->discuss(self::A, ChatViewer::person(self::AMRO), ['type' => 'record', 'id' => 'booking:7', 'label' => 'Ramadan boxes order']);

        self::assertTrue($found['created']);
        $chat = $this->chat->show(self::A, ChatViewer::person(self::AMRO), $found['id']);
        self::assertSame('Ramadan boxes order', $chat['title']);
        self::assertSame(['type' => 'record', 'id' => 'booking:7', 'label' => 'Ramadan boxes order'], $chat['link']);
    }

    public function testTheNextPersonJoinsTheSameChat(): void
    {
        $link = ['type' => 'meeting', 'id' => '42', 'label' => 'Weekly stock check'];
        $first = $this->chat->discuss(self::A, ChatViewer::person(self::AMRO), $link);
        $again = $this->chat->discuss(self::A, ChatViewer::person(self::AMRO), $link);
        $awss = $this->chat->discuss(self::A, ChatViewer::person(self::AWSS), $link);

        self::assertSame([$first['id'], false], [$again['id'], $again['created']]);
        self::assertSame([$first['id'], false], [$awss['id'], $awss['created']]);
        self::assertSame([self::AMRO, self::AWSS], $this->people($first['id']), 'each person once');
        self::assertContains('Awss Haddad joined.', $this->lines($first['id']), 'the chat says who joined');
    }

    public function testSomeoneAddedToAOneToOneChatDoesNotGetItsHistory(): void
    {
        $amro = ChatViewer::person(self::AMRO);
        $id = $this->chat->directWithPerson(self::A, $amro, self::AWSS);
        $this->chat->post(self::A, $amro, $id, 'Only between the two of us');

        $this->chat->addPerson(self::A, $amro, $id, self::AHMAD);
        $this->chat->post(self::A, $amro, $id, 'Welcome, Ahmad');

        $ahmad = ChatViewer::person(self::AHMAD);
        $seen = array_map(static fn (array $m): string => (string) ($m['body'] ?? ''), $this->chat->messages(self::A, $ahmad, $id, null, null, 100));
        self::assertNotContains('Only between the two of us', $seen, 'the newcomer was handed the 1:1 history (TM-18)');
        self::assertContains('Welcome, Ahmad', $seen);
        self::assertContains('Only between the two of us', $this->lines($id), 'the two original people keep it');

        $listed = array_values(array_filter($this->chat->list(self::A, $ahmad), static fn (array $c): bool => (int) $c['id'] === $id));
        self::assertNotSame('Only between the two of us', $listed[0]['last_message']['body'] ?? null);
        self::assertLessThanOrEqual(2, $listed[0]['unread'], 'unread counted the history the newcomer cannot see');
    }

    public function testAnotherWorkspaceGetsItsOwnChat(): void
    {
        $link = ['type' => 'board', 'id' => 'b-1', 'label' => 'Orders'];
        $bakery = $this->chat->discuss(self::A, ChatViewer::person(self::AMRO), $link);
        $florist = $this->chat->discuss(self::B, ChatViewer::person(self::EMAD), $link);

        self::assertTrue($florist['created']);
        self::assertNotSame($bakery['id'], $florist['id']);
    }

    public function testAnInvalidLinkIsRefused(): void
    {
        $this->expectException(ChatException::class);
        $this->chat->discuss(self::A, ChatViewer::person(self::AMRO), ['type' => 'invoice', 'id' => '1', 'label' => 'INV-1']);
    }

    public function testSomeoneWhoMayNotSeeTheRecordIsNotLetIntoItsChat(): void
    {
        $booking = ['type' => 'record', 'id' => 'booking:7', 'label' => 'Ramadan boxes order'];
        $this->chat->discuss(self::A, ChatViewer::person(self::AMRO), $booking);

        // Ahmad holds none of calendar:read, convening:read or tasks:read.
        $this->notFound(fn () => $this->chat->discuss(self::A, ChatViewer::person(self::AHMAD), $booking));
        $this->notFound(fn () => $this->chat->discuss(self::A, ChatViewer::person(self::AHMAD), ['type' => 'meeting', 'id' => '42', 'label' => 'Board']));
        $this->notFound(fn () => $this->chat->discuss(self::A, ChatViewer::person(self::AHMAD), ['type' => 'board', 'id' => 'b-1', 'label' => 'Orders']));
        // A record that is not in the workspace, or an id that is not one.
        $this->notFound(fn () => $this->chat->discuss(self::A, ChatViewer::person(self::AWSS), ['type' => 'record', 'id' => 'booking:99', 'label' => 'x']));
        $this->notFound(fn () => $this->chat->discuss(self::B, ChatViewer::person(self::EMAD), $booking));
        $this->notFound(fn () => $this->chat->discuss(self::A, ChatViewer::person(self::AWSS), ['type' => 'record', 'id' => 'booking:7 OR 1', 'label' => 'x']));
        $this->notFound(fn () => $this->chat->discuss(self::A, ChatViewer::person(self::AWSS), ['type' => 'meeting', 'id' => '43', 'label' => 'x']));

        self::assertSame([self::AMRO], $this->people((int) $this->linked('record', 'booking:7')));
    }

    public function testADocumentFollowsItsVisibilityAndASubmissionItsReaders(): void
    {
        $this->pdo->exec("INSERT INTO documents (id, tenant_id, template_name, title, created_by, created_at) VALUES (12, 1, 'Letter', 'Salary letter', " . self::AMRO . ', CURRENT_TIMESTAMP)');
        $this->permissions->grants[self::AMRO][] = CorePermissions::DOCUMENTS_READ;
        $this->permissions->grants[self::AWSS][] = CorePermissions::DOCUMENTS_READ;
        $document = ['type' => 'record', 'id' => 'document:12', 'label' => 'Salary letter'];

        $this->chat->discuss(self::A, ChatViewer::person(self::AMRO), $document);
        // documents:read alone is the designer, not every issued document.
        $this->notFound(fn () => $this->chat->discuss(self::A, ChatViewer::person(self::AWSS), $document));
        $this->permissions->grants[self::AWSS][] = CorePermissions::DOCUMENTS_READ_ALL;
        self::assertFalse($this->chat->discuss(self::A, ChatViewer::person(self::AWSS), $document)['created']);

        $this->pdo->exec("INSERT INTO forms (id, tenant_id, form_key, name, status, version, created_at, updated_at) VALUES (3, 1, 'leave', 'Leave request', 'published', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $this->pdo->exec('INSERT INTO form_submissions (id, tenant_id, form_id, form_version, submitted_by_profile_id, submitted_at, created_at) VALUES (5, 1, 3, 1, ' . self::AHMAD . ', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
        $submission = ['type' => 'record', 'id' => 'form_submission:5', 'label' => 'Leave request'];
        self::assertTrue($this->chat->discuss(self::A, ChatViewer::person(self::AHMAD), $submission)['created'], 'who sent it');
        $this->notFound(fn () => $this->chat->discuss(self::A, ChatViewer::person(self::AWSS), $submission));
        $this->permissions->grants[self::AWSS][] = CorePermissions::FORMS_READ;
        self::assertFalse($this->chat->discuss(self::A, ChatViewer::person(self::AWSS), $submission)['created']);
    }

    public function testSomeoneRemovedDoesNotWalkBackInButSomeoneWhoLeftMay(): void
    {
        $link = ['type' => 'meeting', 'id' => '42', 'label' => 'Weekly stock check'];
        $id = $this->chat->discuss(self::A, ChatViewer::person(self::AMRO), $link)['id'];
        $this->chat->discuss(self::A, ChatViewer::person(self::AWSS), $link);

        $this->chat->removeMember(self::A, ChatViewer::person(self::AMRO), $id, $this->memberId($id, self::AWSS));
        $this->notFound(fn () => $this->chat->discuss(self::A, ChatViewer::person(self::AWSS), $link));
        self::assertSame([self::AMRO], $this->people($id));

        // A member may still add them back, which the chat shows.
        $this->chat->addPerson(self::A, ChatViewer::person(self::AMRO), $id, self::AWSS);
        self::assertSame([self::AMRO, self::AWSS], $this->people($id));

        // Leaving yourself is not being removed.
        $this->chat->removeMember(self::A, ChatViewer::person(self::AWSS), $id, $this->memberId($id, self::AWSS));
        self::assertSame($id, $this->chat->discuss(self::A, ChatViewer::person(self::AWSS), $link)['id']);
        self::assertSame([self::AMRO, self::AWSS], $this->people($id));
    }

    public function testAPluginRecordGetsAChatButNobodyJoinsItThroughDiscuss(): void
    {
        $link = ['type' => 'record', 'id' => 'keyhub.project:4', 'label' => 'Oven controller'];
        $found = $this->chat->discuss(self::A, ChatViewer::person(self::AMRO), $link);
        self::assertTrue($found['created']);
        self::assertSame($found['id'], $this->chat->discuss(self::A, ChatViewer::person(self::AMRO), $link)['id'], 'its members open it');

        $this->notFound(fn () => $this->chat->discuss(self::A, ChatViewer::person(self::AWSS), $link));
        $this->chat->addPerson(self::A, ChatViewer::person(self::AMRO), $found['id'], self::AWSS);
        self::assertSame($found['id'], $this->chat->discuss(self::A, ChatViewer::person(self::AWSS), $link)['id']);
    }

    public function testAChatCannotBeLinkedToARecordYouMayNotSee(): void
    {
        $this->notFound(fn () => $this->chat->createGroup(self::A, ChatViewer::person(self::AHMAD), 'Mine first', [], ['type' => 'record', 'id' => 'booking:7', 'label' => 'x']));
        $mine = $this->chat->createGroup(self::A, ChatViewer::person(self::AHMAD), 'Mine', [], null);
        $this->notFound(fn () => $this->chat->update(self::A, ChatViewer::person(self::AHMAD), $mine, null, true, ['type' => 'meeting', 'id' => '42', 'label' => 'x']));

        self::assertNull($this->linked('record', 'booking:7'));
        self::assertNull($this->linked('meeting', '42'));
    }

    private function notFound(callable $fn): void
    {
        try {
            $fn();
            self::fail('expected a 404');
        } catch (ChatException $e) {
            self::assertSame(404, $e->status(), $e->plain());
        }
    }

    /** @return list<int> */
    private function people(int $chatId): array
    {
        $people = array_column(array_filter(
            $this->chat->show(self::A, ChatViewer::person(self::AMRO), $chatId)['members'],
            static fn (array $m): bool => $m['type'] === 'person',
        ), 'profile_id');
        sort($people);

        return $people;
    }

    /** @return list<string> */
    private function lines(int $chatId): array
    {
        return array_values(array_map(static fn (array $m): string => (string) ($m['body'] ?? ''), $this->chat->show(self::A, ChatViewer::person(self::AMRO), $chatId)['messages']));
    }

    private function memberId(int $chatId, int $profileId): int
    {
        foreach ($this->chat->show(self::A, ChatViewer::person(self::AMRO), $chatId)['members'] as $m) {
            if ($m['type'] === 'person' && $m['profile_id'] === $profileId) {
                return (int) $m['member_id'];
            }
        }
        self::fail('not a member');
    }

    private function linked(string $type, string $id): ?int
    {
        return (new ChatRepository($this->pdo))->findLinked(self::A, $type, $id);
    }

    private function seed(): void
    {
        $pdo = $this->pdo;
        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name, slug) VALUES (0, 'system', 'system')");
        $pdo->exec('INSERT INTO tenants (id, name, slug) VALUES (' . self::A . ", 'Example Bakery', 'bakery'), (" . self::B . ", 'Example Florist', 'florist')");
        $stmt = $pdo->prepare("SELECT id FROM roles WHERE name = 'user' AND tenant_id IS NULL");
        $stmt->execute();
        $role = (int) $stmt->fetchColumn();
        $people = [self::AMRO => ['Amro Saleh', self::A], self::AWSS => ['Awss Haddad', self::A], self::EMAD => ['Emad Nasser', self::B], self::AHMAD => ['Ahmad Khalil', self::A]];
        foreach ($people as $id => [$name, $tenant]) {
            $pdo->exec("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                        VALUES ({$id}, '{$name}', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
            $pdo->exec("INSERT INTO memberships (profile_id, tenant_id, role_id, status, kind, created_at) VALUES ({$id}, {$tenant}, {$role}, 'active', 'member', CURRENT_TIMESTAMP)");
        }
        $pdo->exec('INSERT INTO calendar_services (id, tenant_id, name, duration_minutes, created_at, updated_at) VALUES (1, ' . self::A . ", 'Cake tasting', 75, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $pdo->exec('INSERT INTO calendar_bookings (id, tenant_id, service_id, profile_id, customer_name, starts_at, ends_at, blocks_from, blocks_until, status, source, created_at, updated_at)
            VALUES (7, ' . self::A . ', 1, ' . self::AWSS . ", 'Ibrahim Mansour', '2026-11-17 10:00:00', '2026-11-17 11:15:00', '2026-11-17 10:00:00', '2026-11-17 11:15:00', 'confirmed', 'staff', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $pdo->exec("INSERT INTO convening_bodies (id, tenant_id, body_key, name) VALUES (501, 1, 'board', 'Board')");
        $pdo->exec("INSERT INTO meetings (id, tenant_id, body_id, meeting_number, title, scheduled_at, status) VALUES (42, 1, 501, 1, 'Weekly stock check', '2026-11-16 07:00:00', 'scheduled')");
    }
}
