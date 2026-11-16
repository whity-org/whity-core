<?php

declare(strict_types=1);

namespace Tests\Integration\Chat;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\ChatApiHandler;
use Whity\Core\Chat\ChatInbox;
use Whity\Core\Chat\ChatRepository;
use Whity\Core\Chat\ChatService;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Rbac\PermissionResolver;

/**
 * One chat on the real schema (design 3.5, UC.1, WHIT-1093; migration 281):
 * team chats and customer conversations in one list, customer conversations
 * only for people who may read them, pins and read marks per person, and
 * nobody pinning or reading what they can't see.
 */
final class ChatInboxRealEngineTest extends TestCase
{
    private const A = 1;
    private const B = 2;
    private const AMRO = 10;
    private const AHMAD = 11;
    private const OTHER_WORKSPACE = 13;

    private PDO $pdo;
    private ChatApiHandler $handler;
    /** @var list<int> people who hold messages.read */
    private array $readers = [self::AMRO];

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->pdo = SchemaFromMigrations::make(true);
        $this->seed();
        $service = new ChatService(new ChatRepository($this->pdo));
        $readers = &$this->readers;
        $permissions = new class ($readers) implements PermissionResolver {
            /** @param list<int> $readers */
            public function __construct(private array &$readers)
            {
            }

            public function hasPermission(int $profileId, int $tenantId, string $permission, ?string $resourceType = null, ?int $resourceId = null): bool
            {
                return $permission === CorePermissions::MESSAGES_READ && in_array($profileId, $this->readers, true);
            }

            public function hasRole(int $profileId, int $tenantId, string $role, ?string $resourceType = null, ?int $resourceId = null): bool
            {
                return false;
            }

            public function effectivePermissions(int $profileId, int $tenantId, ?string $resourceType = null, ?int $resourceId = null): array
            {
                return [];
            }
        };
        $this->handler = new ChatApiHandler($service, new ChatInbox($this->pdo, $service), $permissions);
        TenantContext::setTenantId(self::A);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testTeamChatsAndCustomerConversationsShareOneListNewestFirst(): void
    {
        $group = $this->ok($this->handler->create($this->person(self::AMRO, ['type' => 'group', 'title' => 'Abdali team', 'people' => [self::AHMAD]])), 201);
        $this->setActivity((int) $group['id'], '-2 hours');
        $customer = $this->customerConversation('Ahmad Khalil', '-5 minutes');

        $body = $this->body($this->handler->inbox($this->person(self::AMRO)));
        self::assertTrue($body['customers']);
        self::assertSame([$customer, (int) $group['id']], array_column($body['data'], 'id'));
        self::assertSame(['customer', 'team'], array_column($body['data'], 'kind'));

        $item = $body['data'][0];
        self::assertSame('Ahmad Khalil', $item['title']);
        self::assertSame('webchat', $item['channel']);
        self::assertSame(2, $item['unread'], 'two messages from the customer, nobody answered yet');
        self::assertNotNull($item['waiting_since']);
        self::assertSame('customer', $item['last_message']['from']);
    }

    public function testSomeoneWhoMayNotReadCustomerConversationsSeesOnlyTheirTeamChats(): void
    {
        $this->ok($this->handler->create($this->person(self::AMRO, ['type' => 'group', 'title' => 'Abdali team', 'people' => [self::AHMAD]])), 201);
        $customer = $this->customerConversation('Ahmad Khalil', '-5 minutes');

        $body = $this->body($this->handler->inbox($this->person(self::AHMAD)));
        self::assertFalse($body['customers']);
        self::assertSame(['team'], array_column($body['data'], 'kind'));

        // Nor can they pin or read it.
        self::assertSame(404, $this->handler->pin($this->person(self::AHMAD, ['pinned' => true]), ['id' => (string) $customer])->getStatusCode());
        self::assertSame(404, $this->handler->readCustomer($this->person(self::AHMAD, ['message_id' => 1]), ['id' => (string) $customer])->getStatusCode());
    }

    public function testAnsweringOrReadingClearsUnreadForThatPersonOnly(): void
    {
        $customer = $this->customerConversation('Ahmad Khalil', '-5 minutes');
        $this->readers = [self::AMRO, self::AHMAD];

        // A reply from anyone on the team means it was read.
        $this->message($customer, 'outbound', 'Yes, six left.', self::AHMAD);
        self::assertSame(0, $this->unread(self::AMRO, $customer));

        $last = $this->message($customer, 'inbound', 'And a box of maamoul?', null);
        self::assertSame(1, $this->unread(self::AMRO, $customer));
        self::assertSame(1, $this->unread(self::AHMAD, $customer));

        $this->ok($this->handler->readCustomer($this->person(self::AMRO, ['message_id' => $last]), ['id' => (string) $customer]));
        self::assertSame(0, $this->unread(self::AMRO, $customer));
        self::assertSame(1, $this->unread(self::AHMAD, $customer), 'Amro reading it does not read it for Ahmad');

        // A read mark never goes back.
        $this->ok($this->handler->readCustomer($this->person(self::AMRO, ['message_id' => 1]), ['id' => (string) $customer]));
        self::assertSame(0, $this->unread(self::AMRO, $customer));
    }

    public function testPinsArePerPersonAndOnlyOnChatsTheyCanSee(): void
    {
        $group = $this->ok($this->handler->create($this->person(self::AMRO, ['type' => 'group', 'title' => 'Abdali team', 'people' => [self::AHMAD]])), 201);
        $private = $this->ok($this->handler->create($this->person(self::AHMAD, ['type' => 'group', 'title' => 'Payroll', 'people' => []])), 201);
        $customer = $this->customerConversation('Ahmad Khalil', '-5 minutes');

        $this->ok($this->handler->pin($this->person(self::AMRO, ['pinned' => true]), ['id' => (string) $group['id']]));
        $this->ok($this->handler->pin($this->person(self::AMRO, ['pinned' => true]), ['id' => (string) $customer]));
        self::assertSame(404, $this->handler->pin($this->person(self::AMRO, ['pinned' => true]), ['id' => (string) $private['id']])->getStatusCode());

        $pinned = static fn (array $data): array => array_values(array_map(static fn (array $i): int => $i['id'], array_filter($data, static fn (array $i): bool => $i['pinned'])));
        $amro = $this->body($this->handler->inbox($this->person(self::AMRO)))['data'];
        self::assertEqualsCanonicalizing([(int) $group['id'], $customer], $pinned($amro));
        $ahmad = $this->body($this->handler->inbox($this->person(self::AHMAD)))['data'];
        self::assertSame([], $pinned($ahmad), 'Amro\'s pins are his own');

        $this->ok($this->handler->pin($this->person(self::AMRO, ['pinned' => false]), ['id' => (string) $customer]));
        $amro = $this->body($this->handler->inbox($this->person(self::AMRO)))['data'];
        self::assertSame([(int) $group['id']], $pinned($amro));
    }

    public function testClosedConversationsLeaveTheListAfterThirtyDaysUnlessPinned(): void
    {
        $recent = $this->customerConversation('Example Clinic', '-3 days', 'closed');
        $old = $this->customerConversation('Example Café', '-40 days', 'closed');
        $kept = $this->customerConversation('Example Florist', '-60 days', 'closed');
        $this->ok($this->handler->pin($this->person(self::AMRO, ['pinned' => true]), ['id' => (string) $kept]));

        $ids = array_column($this->body($this->handler->inbox($this->person(self::AMRO)))['data'], 'id');
        self::assertContains($recent, $ids);
        self::assertNotContains($old, $ids);
        self::assertContains($kept, $ids);
        $closed = array_values(array_filter($this->body($this->handler->inbox($this->person(self::AMRO)))['data'], static fn (array $i): bool => $i['id'] === $recent))[0];
        self::assertSame(0, $closed['unread'], 'a closed conversation has nothing waiting');
    }

    public function testAnotherWorkspaceNeverSeesThem(): void
    {
        $customer = $this->customerConversation('Ahmad Khalil', '-5 minutes');
        $this->readers[] = self::OTHER_WORKSPACE;
        TenantContext::reset();
        TenantContext::setTenantId(self::B);

        self::assertSame([], $this->body($this->handler->inbox($this->person(self::OTHER_WORKSPACE)))['data']);
        self::assertSame(404, $this->handler->pin($this->person(self::OTHER_WORKSPACE, ['pinned' => true]), ['id' => (string) $customer])->getStatusCode());
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function unread(int $profileId, int $conversationId): int
    {
        foreach ($this->body($this->handler->inbox($this->person($profileId)))['data'] as $item) {
            if ($item['id'] === $conversationId) {
                return $item['unread'];
            }
        }
        self::fail('conversation not in the list');
    }

    private function customerConversation(string $name, string $at, string $status = 'open'): int
    {
        $when = gmdate('Y-m-d H:i:s', (int) strtotime($at));
        $stmt = $this->pdo->prepare("INSERT INTO conversations (tenant_id, channel, status, contact_name, contact_address, last_message_at, last_inbound_at, created_at, updated_at, kind)
            VALUES (:tid, 'webchat', :status, :name, :address, :at, :at2, :at3, :at4, 'customer')");
        $stmt->execute([':tid' => self::A, ':status' => $status, ':name' => $name, ':address' => 'visitor:' . md5($name), ':at' => $when, ':at2' => $when, ':at3' => $when, ':at4' => $when]);
        $id = (int) $this->pdo->lastInsertId();
        $this->message($id, 'inbound', 'Hello, do you still have croissants?', null);
        $this->message($id, 'inbound', 'Six, please.', null);

        return $id;
    }

    private function message(int $conversationId, string $kind, string $body, ?int $author): int
    {
        $status = ['inbound' => 'received', 'outbound' => 'sent', 'note' => 'noted'][$kind];
        $stmt = $this->pdo->prepare('INSERT INTO messages (tenant_id, conversation_id, kind, author_profile_id, body_text, status, created_at)
            VALUES (:tid, :cid, :kind, :author, :body, :status, CURRENT_TIMESTAMP)');
        $stmt->execute([':tid' => self::A, ':cid' => $conversationId, ':kind' => $kind, ':author' => $author, ':body' => $body, ':status' => $status]);

        return (int) $this->pdo->lastInsertId();
    }

    private function setActivity(int $conversationId, string $at): void
    {
        $stmt = $this->pdo->prepare('UPDATE conversations SET last_message_at = :at WHERE id = :id');
        $stmt->execute([':at' => gmdate('Y-m-d H:i:s', (int) strtotime($at)), ':id' => $conversationId]);
    }

    /** @param array<string, mixed> $body */
    private function person(int $profileId, array $body = []): Request
    {
        $request = new Request($body === [] ? 'GET' : 'POST', '/x', ['Content-Type' => 'application/json'], $body === [] ? '' : (string) json_encode($body));
        $request->user = (object) ['profile_id' => $profileId];

        return $request;
    }

    /** @return mixed the response's data, after checking its status */
    private function ok(\Whity\Sdk\Http\Response $response, int $status = 200): mixed
    {
        return $this->body($response, $status)['data'];
    }

    /** @return array<string, mixed> */
    private function body(\Whity\Sdk\Http\Response $response, int $status = 200): array
    {
        self::assertSame($status, $response->getStatusCode(), (string) $response->getBody());

        return json_decode((string) $response->getBody(), true);
    }

    private function seed(): void
    {
        $pdo = $this->pdo;
        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name, slug) VALUES (0, 'system', 'system')");
        $pdo->exec('INSERT INTO tenants (id, name, slug) VALUES (' . self::A . ", 'Example Bakery', 'bakery'), (" . self::B . ", 'Example Florist', 'florist')");
        $stmt = $pdo->prepare("SELECT id FROM roles WHERE name = 'user' AND tenant_id IS NULL");
        $stmt->execute();
        $role = (int) $stmt->fetchColumn();

        foreach ([self::AMRO => 'Amro Saleh', self::AHMAD => 'Awss Haddad', self::OTHER_WORKSPACE => 'Emad Nasser'] as $id => $name) {
            $pdo->exec("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                        VALUES ({$id}, '{$name}', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        }
        $pdo->exec('INSERT INTO memberships (profile_id, tenant_id, role_id, status, kind, created_at) VALUES
            (' . self::AMRO . ', ' . self::A . ", {$role}, 'active', 'member', CURRENT_TIMESTAMP),
            (" . self::AHMAD . ', ' . self::A . ", {$role}, 'active', 'member', CURRENT_TIMESTAMP),
            (" . self::OTHER_WORKSPACE . ', ' . self::B . ", {$role}, 'active', 'member', CURRENT_TIMESTAMP)");
    }
}
