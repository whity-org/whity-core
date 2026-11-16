<?php

declare(strict_types=1);

namespace Tests\Integration\Chat;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Chat\ChatBlocking;
use Whity\Core\Chat\ChatBots;
use Whity\Core\Chat\ChatException;
use Whity\Core\Mail\OutgoingEmail;
use Whity\Core\Mail\ThreadedMailer;
use Whity\Core\Messaging\CoreMessaging;
use Whity\Core\Messaging\MessageChannelRegistry;
use Whity\Core\Messaging\MessagingService;
use Whity\Core\Messaging\WebChat\WebChatChannel;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Sdk\Messaging\InboundMessage;

/**
 * Bots and blocking on the real schema (design 3.5, UC.4, UC.12, WHIT-1097;
 * migration 283): a blocked sender's message is dropped and past messages
 * stay; the welcome bot answers a first message, the away bot answers outside
 * hours once a day, and hand-over gives new conversations to people in turn,
 * only people who work here.
 */
final class ChatBotsAndBlockingRealEngineTest extends TestCase
{
    private const A = 1;
    private const AMRO = 10;
    private const AWSS = 11;
    private const CUSTOMER = 12;

    private PDO $pdo;
    private MessagingService $messaging;
    private ChatBots $bots;
    private ChatBlocking $blocking;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->seed();
        $mailer = new class () implements ThreadedMailer {
            public function sendThreaded(OutgoingEmail $email): void
            {
            }
        };
        $this->messaging = CoreMessaging::build(
            $this->pdo,
            new MessageChannelRegistry(),
            $mailer,
            new EncryptedSecretStore(['v1' => str_repeat('k', 32)], 'v1'),
            static fn (string $name, array $payload, array $opts): int => 1,
            null,
            'https://app.whity.test',
        );
        $this->bots = new ChatBots($this->pdo);
        $this->blocking = new ChatBlocking($this->pdo);
    }

    public function testABlockedSendersMessageIsDroppedAndPastMessagesStay(): void
    {
        $first = $this->write('visitor:ahmad', 'Hello');
        self::assertNotNull($first);
        $conversation = $this->idOf($first);

        $block = $this->blocking->blockConversation(self::A, $conversation, true, self::AMRO);
        self::assertTrue($block['reported_spam']);
        self::assertSame('Amro Saleh', $block['blocked_by']);
        self::assertSame($block['id'], $this->blocking->forConversation(self::A, $conversation)['id'] ?? null);

        self::assertNull($this->write('VISITOR:Ahmad', 'Buy cheap watches'), 'the address is compared without case');
        self::assertSame(['Hello'], $this->bodies($conversation));

        self::assertTrue($this->blocking->unblock(self::A, $block['id']));
        self::assertNotNull($this->write('visitor:ahmad', 'Sorry, wrong number'));
    }

    public function testTheWelcomeBotAnswersAFirstMessageOnly(): void
    {
        $this->bots->save(self::A, 'welcome', true, ['text' => 'Hello from Example Bakery!'], self::AMRO);
        $first = $this->write('visitor:sara', 'Hi');
        $conversation = $this->idOf($first);
        $this->write('visitor:sara', 'Are you open?', $conversation);

        self::assertSame(['Hi', 'Hello from Example Bakery!', 'Are you open?'], $this->bodies($conversation));
        self::assertSame(['customer', 'bot.welcome', 'customer'], $this->sources($conversation));
    }

    public function testTheAwayBotAnswersOutsideHoursOnceADay(): void
    {
        // Closed every day: no opening days at all.
        $this->bots->save(self::A, 'away', true, ['text' => 'We are closed now.', 'days' => [], 'timezone' => 'Asia/Amman'], self::AMRO);
        $first = $this->write('visitor:lina', 'Hello?');
        $conversation = $this->idOf($first);
        $this->write('visitor:lina', 'Anyone?', $conversation);

        self::assertSame(['Hello?', 'We are closed now.', 'Anyone?'], $this->bodies($conversation));

        $open = ['days' => [1, 2, 3, 4, 5, 6, 7], 'from' => '00:00', 'to' => '23:59', 'timezone' => 'UTC'];
        self::assertTrue(ChatBots::isOpen($open, strtotime('2026-11-16 10:00:00 UTC')));
        self::assertFalse(ChatBots::isOpen(['days' => [1], 'from' => '09:00', 'to' => '18:00', 'timezone' => 'UTC'], strtotime('2026-11-16 18:30:00 UTC')));
    }

    public function testHandOverGivesNewConversationsToPeopleInTurnAndOnlyStaff(): void
    {
        $saved = $this->bots->save(self::A, 'handover', true, ['people' => [self::AMRO, self::CUSTOMER, self::AWSS]], self::AMRO);
        self::assertSame([self::AMRO, self::AWSS], $saved['settings']['people'], 'a customer is never handed conversations');

        $a = $this->conversation('visitor:one', 'One');
        $b = $this->conversation('visitor:two', 'Two');
        $c = $this->conversation('visitor:three', 'Three');

        self::assertSame([self::AMRO, self::AWSS, self::AMRO], [$this->assignee($a), $this->assignee($b), $this->assignee($c)]);
    }

    public function testAnUnknownBotIsRefused(): void
    {
        $this->expectException(ChatException::class);
        $this->bots->save(self::A, 'stock', true, [], self::AMRO);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** @return array<string, mixed>|null */
    private function write(string $from, string $body, ?int $conversationId = null): ?array
    {
        return $this->messaging->ingest(self::A, WebChatChannel::KEY, new InboundMessage(from: $from, fromName: null, body: $body), $conversationId);
    }

    /** @return list<string> */
    private function bodies(int $conversationId): array
    {
        $stmt = $this->pdo->prepare('SELECT body_text FROM messages WHERE conversation_id = :c ORDER BY id');
        $stmt->execute([':c' => $conversationId]);

        return array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** @return list<string> */
    private function sources(int $conversationId): array
    {
        $stmt = $this->pdo->prepare('SELECT source FROM messages WHERE conversation_id = :c ORDER BY id');
        $stmt->execute([':c' => $conversationId]);

        return array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    private function conversation(string $from, string $body): int
    {
        return $this->idOf($this->write($from, $body));
    }

    /** @param array<string, mixed>|null $message */
    private function idOf(?array $message): int
    {
        self::assertNotNull($message, 'the message was filed');

        return (int) $message['conversation_id'];
    }

    private function assignee(int $conversationId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT assigned_profile_id FROM conversations WHERE id = :c');
        $stmt->execute([':c' => $conversationId]);
        $v = $stmt->fetchColumn();

        return $v === null || $v === false ? null : (int) $v;
    }

    private function seed(): void
    {
        $pdo = $this->pdo;
        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name, slug) VALUES (0, 'system', 'system')");
        $pdo->exec('INSERT INTO tenants (id, name, slug) VALUES (' . self::A . ", 'Example Bakery', 'bakery')");
        $stmt = $pdo->prepare("SELECT id FROM roles WHERE name = 'user' AND tenant_id IS NULL");
        $stmt->execute();
        $role = (int) $stmt->fetchColumn();
        foreach ([self::AMRO => ['Amro Saleh', 'member'], self::AWSS => ['Awss Haddad', 'member'], self::CUSTOMER => ['Ahmad Khalil', 'customer']] as $id => [$name, $kind]) {
            $pdo->exec("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                        VALUES ({$id}, '{$name}', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
            $pdo->exec("INSERT INTO memberships (profile_id, tenant_id, role_id, status, kind, created_at) VALUES ({$id}, " . self::A . ", {$role}, 'active', '{$kind}', CURRENT_TIMESTAMP)");
        }
        // Web chat is on, so the bots' replies can go out on it.
        $pdo->exec("INSERT INTO tenant_settings (tenant_id, setting_key, value, updated_at) VALUES (" . self::A . ", 'messaging.webchat_enabled', 'true', CURRENT_TIMESTAMP)");
    }
}
