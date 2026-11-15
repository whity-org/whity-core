<?php

declare(strict_types=1);

namespace Tests\Integration\Chat;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\ChatApiHandler;
use Whity\Core\Chat\ChatRepository;
use Whity\Core\Chat\ChatService;
use Whity\Core\Request;
use Whity\Core\Store\DatabaseSharedStore;
use Whity\Core\Tenant\TenantContext;

/**
 * The parts of a chat message on the real schema (design 3.5, UC.6–UC.12,
 * WHIT-1094; migration 282): replies quote what they answer, thread replies
 * stay out of the chat unless also sent there, reactions toggle per person,
 * pins are the chat's, only your own message can be edited or deleted and
 * deleting keeps "This message was deleted", search stays in the chat, seen
 * comes from read marks, typing shows the others, and each person keeps their
 * own layout.
 */
final class ChatMessagePartsRealEngineTest extends TestCase
{
    private const A = 1;
    private const AMRO = 10;
    private const AWSS = 11;
    private const EMAD = 12;

    private PDO $pdo;
    private ChatApiHandler $handler;
    private int $chat = 0;

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->pdo = SchemaFromMigrations::make(true);
        $this->seed();
        $this->handler = new ChatApiHandler(new ChatService(new ChatRepository($this->pdo), null, new DatabaseSharedStore($this->pdo)));
        TenantContext::setTenantId(self::A);
        $this->chat = (int) $this->ok($this->handler->create($this->as(self::AMRO, ['type' => 'group', 'title' => 'Abdali team', 'people' => [self::AWSS]])), 201)['id'];
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testAReplyQuotesTheMessageItAnswers(): void
    {
        $asked = $this->say(self::AWSS, 'Can someone look at the mixer?');
        $reply = $this->ok($this->handler->post($this->as(self::AMRO, ['body' => 'I will', 'reply_to' => $asked]), $this->id()), 201);

        self::assertSame(['id' => $asked, 'author_name' => 'Awss Haddad', 'body' => 'Can someone look at the mixer?', 'deleted' => false], $reply['reply_to']);

        // Only a message in the same chat can be quoted.
        $other = (int) $this->ok($this->handler->create($this->as(self::AMRO, ['type' => 'group', 'title' => 'Payroll', 'people' => []])), 201)['id'];
        $elsewhere = $this->ok($this->handler->post($this->as(self::AMRO, ['body' => 'Payroll line']), ['id' => (string) $other]), 201)['id'];
        self::assertSame(422, $this->handler->post($this->as(self::AMRO, ['body' => 'x', 'reply_to' => $elsewhere]), $this->id())->getStatusCode());
    }

    public function testThreadRepliesStayInTheThreadUnlessAlsoSentToTheChat(): void
    {
        $root = $this->say(self::AWSS, 'Oven 2 is broken');
        $this->ok($this->handler->post($this->as(self::AMRO, ['body' => 'Calling the technician', 'thread_root' => $root]), $this->id()), 201);
        $also = $this->ok($this->handler->post($this->as(self::AWSS, ['body' => 'He comes at 10', 'thread_root' => $root, 'also_to_chat' => true]), $this->id()), 201);
        // A reply to a reply goes under the same root: threads never nest.
        $nested = $this->ok($this->handler->post($this->as(self::AMRO, ['body' => 'Thanks', 'thread_root' => $also['id']]), $this->id()), 201);
        self::assertSame($root, $nested['thread_root_id']);

        $bodies = array_column($this->ok($this->handler->show($this->as(self::AMRO), $this->id()))['messages'], 'body');
        self::assertContains('He comes at 10', $bodies);
        self::assertNotContains('Calling the technician', $bodies);
        self::assertNotContains('Thanks', $bodies);

        $thread = $this->ok($this->handler->thread($this->as(self::AWSS), ['id' => (string) $root]));
        self::assertSame(['Calling the technician', 'He comes at 10', 'Thanks'], array_column($thread['replies'], 'body'));
        self::assertSame(3, $thread['root']['thread']['count']);
        self::assertSame(['Amro Saleh', 'Awss Haddad'], $thread['root']['thread']['people']);
    }

    public function testReactionsTogglePerPersonAndRefuseWords(): void
    {
        $m = $this->say(self::AWSS, 'Oven 2 is fixed');
        $this->ok($this->handler->react($this->as(self::AMRO, ['emoji' => '🎉']), ['id' => (string) $m]));
        $after = $this->ok($this->handler->react($this->as(self::AWSS, ['emoji' => '🎉']), ['id' => (string) $m]));
        self::assertSame([['emoji' => '🎉', 'count' => 2, 'mine' => true, 'people' => ['Amro Saleh', 'Awss Haddad']]], $after['reactions']);

        $back = $this->ok($this->handler->react($this->as(self::AMRO, ['emoji' => '🎉']), ['id' => (string) $m]));
        self::assertSame(1, $back['reactions'][0]['count']);
        self::assertFalse($back['reactions'][0]['mine']);

        self::assertSame(422, $this->handler->react($this->as(self::AMRO, ['emoji' => 'hello']), ['id' => (string) $m])->getStatusCode());
        self::assertSame(422, $this->handler->react($this->as(self::AMRO, ['emoji' => '<b>']), ['id' => (string) $m])->getStatusCode());
        // Emad is not in the chat: the message is not there for him.
        self::assertSame(404, $this->handler->react($this->as(self::EMAD, ['emoji' => '👍']), ['id' => (string) $m])->getStatusCode());
    }

    public function testOnlyYourOwnMessageCanBeEditedOrDeletedAndDeletingLeavesALine(): void
    {
        $mine = $this->say(self::AMRO, 'please confirm the time with Awss');
        $theirs = $this->say(self::AWSS, 'Yes, 10');

        $edited = $this->ok($this->handler->edit($this->as(self::AMRO, ['body' => 'please confirm the time with Awss and Emad']), ['id' => (string) $mine]));
        self::assertTrue($edited['edited']);
        self::assertSame('please confirm the time with Awss and Emad', $edited['body']);
        self::assertSame(403, $this->handler->edit($this->as(self::AMRO, ['body' => 'changed']), ['id' => (string) $theirs])->getStatusCode());
        self::assertSame(403, $this->handler->deleteMessage($this->as(self::AMRO), ['id' => (string) $theirs])->getStatusCode());

        $this->ok($this->handler->react($this->as(self::AWSS, ['emoji' => '👍']), ['id' => (string) $mine]));
        $this->ok($this->handler->pinMessage($this->as(self::AWSS, ['pinned' => true]), ['id' => (string) $mine]));
        $deleted = $this->ok($this->handler->deleteMessage($this->as(self::AMRO), ['id' => (string) $mine]));
        self::assertTrue($deleted['deleted']);
        self::assertSame('', $deleted['body']);
        self::assertSame([], $deleted['reactions']);
        self::assertFalse($deleted['pinned'], 'a deleted message leaves the pins');
        self::assertFalse($deleted['can_edit']);

        // It stays in the chat, in its place.
        $ids = array_column($this->ok($this->handler->show($this->as(self::AWSS), $this->id()))['messages'], 'id');
        self::assertContains($mine, $ids);
        self::assertSame(422, $this->handler->edit($this->as(self::AMRO, ['body' => 'again']), ['id' => (string) $mine])->getStatusCode());
    }

    public function testDeletingForEveryoneEndsAfterTwoDays(): void
    {
        $old = $this->say(self::AMRO, 'Last week');
        $this->pdo->prepare('UPDATE messages SET created_at = :at WHERE id = :id')->execute([':at' => gmdate('Y-m-d H:i:s', time() - 3 * 86400), ':id' => $old]);

        self::assertSame(422, $this->handler->deleteMessage($this->as(self::AMRO), ['id' => (string) $old])->getStatusCode());
        $shown = array_values(array_filter($this->ok($this->handler->show($this->as(self::AMRO), $this->id()))['messages'], static fn (array $m): bool => $m['id'] === $old))[0];
        self::assertFalse($shown['can_delete']);
        self::assertTrue($shown['can_edit']);
    }

    public function testPinsSearchSeenAndTypingComeWithTheState(): void
    {
        $pin = $this->say(self::AMRO, 'Christmas orders close on 20 December');
        $this->say(self::AWSS, 'Noted, CHRISTMAS boxes are ready');
        $this->ok($this->handler->pinMessage($this->as(self::AWSS, ['pinned' => true]), ['id' => (string) $pin]));
        $this->ok($this->handler->typing($this->as(self::AWSS), $this->id()));

        $state = $this->ok($this->handler->state($this->as(self::AMRO, [], 'GET', '/x?after=0&since=2020-01-01T00:00:00Z'), $this->id()));
        self::assertSame([$pin], array_column($state['pinned'], 'id'));
        self::assertSame(['Awss Haddad'], $state['typing']);
        self::assertNotEmpty($state['changed'], 'the pinned message changed');
        $marks = array_column($state['read_marks'], 'last_read_message_id', 'name');
        self::assertGreaterThanOrEqual($pin, $marks['Amro Saleh']);

        // Awss does not see themself typing.
        self::assertSame([], $this->ok($this->handler->state($this->as(self::AWSS, [], 'GET', '/x?after=0'), $this->id()))['typing']);

        $found = $this->ok($this->handler->search($this->as(self::AMRO, [], 'GET', '/x?q=christmas'), $this->id()));
        self::assertCount(2, $found);
        $byAwss = $this->ok($this->handler->search($this->as(self::AMRO, [], 'GET', '/x?q=christmas&from=' . self::AWSS), $this->id()));
        self::assertSame(['Noted, CHRISTMAS boxes are ready'], array_column($byAwss, 'body'));
        self::assertSame(404, $this->handler->search($this->as(self::EMAD, [], 'GET', '/x?q=christmas'), $this->id())->getStatusCode());
    }

    public function testEveryoneIsAMentionInGroupsOnly(): void
    {
        $posted = $this->ok($this->handler->post($this->as(self::AMRO, ['body' => 'Thanks @everyone', 'mentions' => [['type' => 'everyone', 'id' => 0]]]), $this->id()), 201);
        self::assertSame([['type' => 'everyone', 'id' => 0, 'name' => 'everyone']], $posted['mentions']);

        $direct = (int) $this->ok($this->handler->create($this->as(self::AMRO, ['type' => 'direct', 'profile_id' => self::AWSS])), 201)['id'];
        $one = $this->ok($this->handler->post($this->as(self::AMRO, ['body' => 'Hi @everyone', 'mentions' => [['type' => 'everyone', 'id' => 0]]]), ['id' => (string) $direct]), 201);
        self::assertSame([], $one['mentions']);
    }

    public function testEachPersonChoosesBubblesOrRows(): void
    {
        self::assertSame('bubbles', $this->ok($this->handler->preferences($this->as(self::AMRO)))['layout']);
        self::assertSame('rows', $this->ok($this->handler->preferences($this->as(self::AMRO, ['layout' => 'rows'], 'PUT')))['layout']);
        self::assertSame('rows', $this->ok($this->handler->preferences($this->as(self::AMRO)))['layout']);
        self::assertSame('bubbles', $this->ok($this->handler->preferences($this->as(self::AWSS)))['layout']);
        self::assertSame(422, $this->handler->preferences($this->as(self::AMRO, ['layout' => 'cards'], 'PUT'))->getStatusCode());
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function say(int $who, string $body): int
    {
        return (int) $this->ok($this->handler->post($this->as($who, ['body' => $body]), $this->id()), 201)['id'];
    }

    /** @return array<string, string> */
    private function id(): array
    {
        return ['id' => (string) $this->chat];
    }

    /** @param array<string, mixed> $body */
    private function as(int $profileId, array $body = [], ?string $method = null, string $path = '/x'): Request
    {
        $method ??= $body === [] ? 'GET' : 'POST';
        $request = new Request($method, $path, ['Content-Type' => 'application/json'], $body === [] ? '' : (string) json_encode($body));
        $request->user = (object) ['profile_id' => $profileId];

        return $request;
    }

    private function ok(\Whity\Sdk\Http\Response $response, int $status = 200): mixed
    {
        self::assertSame($status, $response->getStatusCode(), (string) $response->getBody());

        return json_decode((string) $response->getBody(), true)['data'];
    }

    private function seed(): void
    {
        $pdo = $this->pdo;
        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name, slug) VALUES (0, 'system', 'system')");
        $pdo->exec('INSERT INTO tenants (id, name, slug) VALUES (' . self::A . ", 'Example Bakery', 'bakery')");
        $stmt = $pdo->prepare("SELECT id FROM roles WHERE name = 'user' AND tenant_id IS NULL");
        $stmt->execute();
        $role = (int) $stmt->fetchColumn();
        foreach ([self::AMRO => 'Amro Saleh', self::AWSS => 'Awss Haddad', self::EMAD => 'Emad Nasser'] as $id => $name) {
            $pdo->exec("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                        VALUES ({$id}, '{$name}', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
            $pdo->exec("INSERT INTO memberships (profile_id, tenant_id, role_id, status, kind, created_at) VALUES ({$id}, " . self::A . ", {$role}, 'active', 'member', CURRENT_TIMESTAMP)");
        }
    }
}
