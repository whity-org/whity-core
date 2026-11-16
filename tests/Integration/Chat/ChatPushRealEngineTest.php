<?php

declare(strict_types=1);

namespace Tests\Integration\Chat;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Integration\Notification\Push\FakeBrowser;
use Tests\Integration\Notification\Push\FakePushService;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\ChatApiHandler;
use Whity\Core\Chat\ChatPushNotifier;
use Whity\Core\Chat\ChatRepository;
use Whity\Core\Chat\ChatService;
use Whity\Core\Mail\EmailGate;
use Whity\Core\Notification\CoreTransports;
use Whity\Core\Notification\NotificationDispatcher;
use Whity\Core\Notification\NotificationPreferenceRepository;
use Whity\Core\Notification\NotificationRepository;
use Whity\Core\Notification\Push\Base64Url;
use Whity\Core\Notification\Push\PushBranding;
use Whity\Core\Notification\Push\PushDigestJob;
use Whity\Core\Notification\Push\PushSubscriptionRepository;
use Whity\Core\Notification\Push\PushTransport;
use Whity\Core\Notification\Push\VapidKeys;
use Whity\Core\Queue\CoreJobs;
use Whity\Core\Queue\JobRegistry;
use Whity\Core\Queue\JobRepository;
use Whity\Core\Queue\JobRunner;
use Whity\Core\Queue\QueueService;
use Whity\Core\Request;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Store\DatabaseSharedStore;
use Whity\Core\Tenant\TenantContext;

/**
 * Team chat lines as web push, end to end on a real database (WHIT-1077): a
 * post, the dispatcher's gate, the queue, the worker, encryption, and a push
 * service that decrypts like the browser. The author and people looking at
 * the chat get none; a chat's pushes share one tag and count what's unread;
 * mentions are their own kind; the rate limit holds a burst to a digest.
 */
final class ChatPushRealEngineTest extends TestCase
{
    private const KEY = 'push_test_key_0123456789abcdef0123456789';
    private const A = 1;
    private const AMRO = 10;
    private const AHMAD = 11;
    private const EMAD = 12;

    private PDO $pdo;
    private ChatApiHandler $handler;
    private FakePushService $service;
    private JobRunner $runner;
    private PushTransport $transport;
    private int $now = 1_800_000_000;

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->pdo = SchemaFromMigrations::make(true);
        $this->seed();

        $keys = new VapidKeys(new GlobalSettingsRepository($this->pdo), new EncryptedSecretStore(['v1' => self::KEY], 'v1'));
        $this->service = new FakePushService($keys->publicKey());
        $queue = new QueueService(new JobRepository($this->pdo));
        $store = new DatabaseSharedStore($this->pdo);
        $clock = fn (): int => $this->now;
        $this->transport = new PushTransport(
            $this->pdo,
            new PushSubscriptionRepository($this->pdo),
            static fn (): VapidKeys => $keys,
            $this->service,
            $store,
            $queue,
            'https://app.example.test',
            null,
            PushBranding::fromPdo($this->pdo),
            $clock,
        );
        $transports = CoreTransports::make();
        $transports->register($this->transport);
        $gate = new EmailGate(new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo)), $this->pdo);
        $dispatcher = new NotificationDispatcher(new NotificationRepository($this->pdo), $transports, $queue, null, null, null, null, $gate);

        $chats = new ChatRepository($this->pdo);
        $notifier = new ChatPushNotifier($dispatcher, $chats, new PushSubscriptionRepository($this->pdo), $store, null, $clock);
        $this->handler = new ChatApiHandler(new ChatService($chats, $notifier));

        $registry = new JobRegistry();
        CoreJobs::register($registry, $this->pdo, $transports);
        $this->runner = new JobRunner(new JobRepository($this->pdo), $registry);
        TenantContext::setTenantId(self::A);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    private function device(int $profileId): FakeBrowser
    {
        $browser = new FakeBrowser('https://fcm.googleapis.com/fcm/send/p' . $profileId);
        (new PushSubscriptionRepository($this->pdo))->upsert(self::A, $profileId, $browser->endpoint, $browser->p256dh(), Base64Url::encode($browser->auth), 'Chrome');
        $this->service->browsers[$browser->endpoint] = $browser;

        return $browser;
    }

    /** @param array<string, mixed> $body */
    private function as(int $profileId, array $body = []): Request
    {
        $request = new Request($body === [] ? 'GET' : 'POST', '/x', ['Content-Type' => 'application/json'], $body === [] ? '' : (string) json_encode($body));
        $request->user = (object) ['profile_id' => $profileId];

        return $request;
    }

    private function group(): int
    {
        $response = $this->handler->create($this->as(self::AMRO, ['type' => 'group', 'title' => 'Ramadan boxes', 'people' => [self::AHMAD, self::EMAD]]));
        self::assertSame(201, $response->getStatusCode(), $response->getBody());

        return (int) json_decode($response->getBody(), true)['data']['id'];
    }

    /** @param list<array{type: string, id: int}> $mentions */
    private function say(int $profileId, int $chatId, string $text, array $mentions = []): void
    {
        $response = $this->handler->post($this->as($profileId, ['body' => $text, 'mentions' => $mentions]), ['id' => (string) $chatId]);
        self::assertSame(201, $response->getStatusCode(), $response->getBody());
    }

    private function focus(int $profileId, int $chatId, bool $focused): void
    {
        $response = $this->handler->focus($this->as($profileId, ['focused' => $focused]), ['id' => (string) $chatId]);
        self::assertSame(200, $response->getStatusCode(), $response->getBody());
    }

    private function drain(): void
    {
        $ran = 0;
        while ($this->runner->processNext('default')) {
            if (++$ran > 50) {
                self::fail('queue did not drain');
            }
        }
        // The runner restores the origin workspace per job and clears it after.
        TenantContext::setTenantId(self::A);
    }

    public function testALineReachesTheOthersWithADeviceButNotItsAuthor(): void
    {
        $chat = $this->group();
        $amro = $this->device(self::AMRO);
        $ahmad = $this->device(self::AHMAD);
        $this->say(self::AMRO, $chat, "How many gift boxes\nfor the first week?");
        $this->drain();

        self::assertSame([], $amro->received, 'never to the author');
        self::assertCount(1, $ahmad->received);
        $push = $ahmad->received[0];
        self::assertSame('Example Bakery · New message in #Ramadan boxes', $push['title']);
        self::assertSame('Amro Saleh: How many gift boxes for the first week?', $push['body']);
        self::assertSame('/chat?c=' . $chat, $push['url']);
        self::assertSame('chat' . $chat, $push['tag']);
        self::assertSame('/push-icon/badge?l=E', $push['badge'], 'the workspace\'s badge');
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM notification_deliveries WHERE channel = 'in_app'"), 'push only');
    }

    public function testMoreLinesReplaceThePushAndCountTheUnread(): void
    {
        $chat = $this->group();
        $ahmad = $this->device(self::AHMAD);
        foreach (['One', 'Two', 'Three'] as $text) {
            $this->say(self::AMRO, $chat, $text);
        }
        $this->drain();

        $last = end($ahmad->received);
        self::assertIsArray($last);
        self::assertSame('Example Bakery · 3 new messages in #Ramadan boxes', $last['title']);
        self::assertSame('Amro Saleh: Three', $last['body']);
        self::assertSame(['chat' . $chat], array_values(array_unique(array_column($ahmad->received, 'tag'))), 'one tag: each replaces the last');
    }

    public function testNoPushWhileThePersonIsLookingAtTheChat(): void
    {
        $chat = $this->group();
        $ahmad = $this->device(self::AHMAD);
        $this->focus(self::AHMAD, $chat, true);
        $this->say(self::AMRO, $chat, 'Are you there?');
        $this->drain();
        self::assertSame([], $ahmad->received);

        // Window blurred: pushes start at once.
        $this->focus(self::AHMAD, $chat, false);
        $this->say(self::AMRO, $chat, 'Still there?');
        $this->drain();
        self::assertCount(1, $ahmad->received);
    }

    public function testLookingEndsWhenTheBeatsStop(): void
    {
        $chat = $this->group();
        $ahmad = $this->device(self::AHMAD);
        $this->focus(self::AHMAD, $chat, true);
        $this->now += ChatPushNotifier::HEARTBEAT * 2;
        $this->say(self::AMRO, $chat, 'Hello?');
        $this->drain();

        self::assertCount(1, $ahmad->received, 'two missed beats: no longer looking');
    }

    public function testLookingAtAnotherChatStillGetsThisOne(): void
    {
        $chat = $this->group();
        $other = (int) json_decode($this->handler->create($this->as(self::AHMAD, ['type' => 'group', 'title' => 'Stock', 'people' => [self::AMRO]]))->getBody(), true)['data']['id'];
        $ahmad = $this->device(self::AHMAD);
        $this->focus(self::AHMAD, $other, true);
        $this->say(self::AMRO, $chat, 'Over here');
        $this->drain();

        self::assertCount(1, $ahmad->received);
    }

    public function testAMentionIsItsOwnKindAndSaysSo(): void
    {
        $chat = $this->group();
        $ahmad = $this->device(self::AHMAD);
        (new NotificationPreferenceRepository($this->pdo))->set(self::A, self::AHMAD, ChatPushNotifier::TYPE_MESSAGE, 'push', false);

        $this->say(self::AMRO, $chat, 'Not for you');
        $this->say(self::AMRO, $chat, 'Ahmad, can you count them?', [['type' => 'person', 'id' => self::AHMAD]]);
        $this->drain();

        self::assertCount(1, $ahmad->received, 'chat messages off, mentions still on');
        self::assertSame('Example Bakery · Amro Saleh mentioned you in #Ramadan boxes', $ahmad->received[0]['title']);
    }

    public function testAMentionReachesTheInboxEvenWithoutADevice(): void
    {
        $chat = $this->group();
        // Emad has no device; Ahmad has one.
        $ahmad = $this->device(self::AHMAD);

        $this->say(self::AMRO, $chat, 'Please check', [['type' => 'person', 'id' => self::EMAD], ['type' => 'person', 'id' => self::AHMAD]]);
        $this->drain();

        // N4: both get a chat.mention notice for the Inbox's Mentions tab.
        $told = $this->pdo->query("SELECT recipient_profile_id FROM notifications WHERE type = 'chat.mention' ORDER BY recipient_profile_id");
        self::assertNotFalse($told);
        self::assertEqualsCanonicalizing([self::AHMAD, self::EMAD], array_map('intval', $told->fetchAll(\PDO::FETCH_COLUMN)));
        // Ahmad's device still gets the push.
        self::assertCount(1, $ahmad->received);
    }

    public function testAPlainLineMakesNoInboxNotice(): void
    {
        $chat = $this->group();
        $this->device(self::AHMAD);

        $this->say(self::AMRO, $chat, 'Morning');
        $this->drain();

        $rows = $this->pdo->query("SELECT COUNT(*) FROM notifications WHERE type = 'chat.message' AND id IN (SELECT notification_id FROM notification_deliveries WHERE channel = 'in_app')");
        self::assertNotFalse($rows);
        self::assertSame(0, (int) $rows->fetchColumn(), 'chat lines live in Chat, not the Inbox');
    }

    public function testADirectChatIsNamedForThePersonAndInTheirLanguage(): void
    {
        $this->pdo->exec("UPDATE profiles SET language_code = 'ar' WHERE id = " . self::AHMAD);
        $response = $this->handler->create($this->as(self::AMRO, ['type' => 'direct', 'profile_id' => self::AHMAD]));
        $chat = (int) json_decode($response->getBody(), true)['data']['id'];
        $ahmad = $this->device(self::AHMAD);
        $this->say(self::AMRO, $chat, 'السلام عليكم');
        $this->drain();

        $push = $ahmad->received[0];
        self::assertSame('Example Bakery · رسالة جديدة من Amro Saleh', $push['title']);
        self::assertSame('السلام عليكم', $push['body']);
        self::assertSame('rtl', $push['dir']);
    }

    public function testABurstIsHeldToTheDigest(): void
    {
        $chat = $this->group();
        $ahmad = $this->device(self::AHMAD);
        for ($i = 1; $i <= PushTransport::PER_MINUTE + 2; $i++) {
            $this->say(self::AMRO, $chat, 'Line ' . $i);
        }
        $this->drain();

        self::assertCount(PushTransport::PER_MINUTE, $ahmad->received);
        $payload = $this->scalar("SELECT payload FROM jobs WHERE name = '" . PushDigestJob::NAME . "'");
        self::assertIsString($payload);
        (new PushDigestJob(fn (): PushTransport => $this->transport))->handle(json_decode($payload, true));
        $last = end($ahmad->received);
        self::assertIsArray($last);
        self::assertSame('Example Bakery · 2 more things are waiting for you', $last['title']);
    }

    public function testFocusIsForMembersOnly(): void
    {
        $chat = $this->group();
        $response = $this->handler->focus($this->as(13, ['focused' => true]), ['id' => (string) $chat]);
        self::assertContains($response->getStatusCode(), [403, 404]);
    }

    private function scalar(string $sql): mixed
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt);

        return $stmt->fetchColumn();
    }

    private function seed(): void
    {
        $pdo = $this->pdo;
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (" . self::A . ", 'Example Bakery', 'bakery')");
        $role = (int) $this->scalar("SELECT id FROM roles WHERE name = 'user' AND tenant_id IS NULL");
        foreach ([self::AMRO => 'Amro Saleh', self::AHMAD => 'Ahmad Khalil', self::EMAD => 'Emad Nasser', 13 => 'Awss Haddad'] as $id => $name) {
            $pdo->exec("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                        VALUES ({$id}, '{$name}', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        }
        foreach ([self::AMRO, self::AHMAD, self::EMAD, 13] as $id) {
            $pdo->exec("INSERT INTO memberships (profile_id, tenant_id, role_id, status, kind, created_at) VALUES ({$id}, " . self::A . ", {$role}, 'active', 'member', CURRENT_TIMESTAMP)");
        }
    }
}
