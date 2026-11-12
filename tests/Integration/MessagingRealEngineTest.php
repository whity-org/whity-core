<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\InboundMessagesApiHandler;
use Whity\Api\MeMessagesApiHandler;
use Whity\Api\MessagesApiHandler;
use Whity\Api\PublicChatApiHandler;
use Whity\Core\Branding\HostResolver;
use Whity\Core\Branding\TenantHostRepository;
use Whity\Core\Http\HttpFetcher;
use Whity\Core\Mail\OutgoingEmail;
use Whity\Core\Mail\ThreadedMailer;
use Whity\Core\Messaging\CoreMessaging;
use Whity\Core\Messaging\HostCustomerMessages;
use Whity\Core\Messaging\Email\EmailChannel;
use Whity\Core\Messaging\MessageChannelRegistry;
use Whity\Core\Messaging\MessagesInboxSource;
use Whity\Core\Messaging\MessagingException;
use Whity\Core\Messaging\MessagingService;
use Whity\Core\Messaging\MessagingSettings;
use Whity\Core\Messaging\Recipient;
use Whity\Core\Messaging\SendOptions;
use Whity\Core\Plugin\PublicForm\BotCheck;
use Whity\Core\RateLimit\ClientIp;
use Whity\Core\Request;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Store\DatabaseSharedStore;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Http\Response;
use Whity\Sdk\Messaging\ChannelCapabilities;
use Whity\Sdk\Messaging\MessageChannel;
use Whity\Sdk\Messaging\OutboundMessage;
use Whity\Sdk\Messaging\SendOutcome;
use Whity\Sdk\PluginInterface;

/**
 * Messages end to end on the real schema (ADR 0016, WHIT-938): the send API,
 * delivery, email threading in and out, the inbound webhook, web chat for
 * visitors and customers, agent holds, and that nothing crosses workspaces.
 */
final class MessagingRealEngineTest extends TestCase
{
    private const A = 1;
    private const B = 2;
    private const STAFF_A = 10;
    private const STAFF_B = 11;
    private const CUSTOMER_A = 12;

    private PDO $pdo;
    private MessagingService $messaging;
    private MessagingSettings $settings;

    /** @var list<OutgoingEmail> */
    private array $mail = [];

    /** @var list<array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}> */
    private array $jobs = [];

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->pdo = SchemaFromMigrations::make(true);
        $this->seed();

        $secrets = new EncryptedSecretStore(['v1' => str_repeat('k', 32)], 'v1');
        $this->settings = new MessagingSettings($this->pdo, $secrets);
        $mailer = new class (function (OutgoingEmail $e): void {
            $this->mail[] = $e;
        }) implements ThreadedMailer {
            public function __construct(private readonly \Closure $sink)
            {
            }

            public function sendThreaded(OutgoingEmail $email): void
            {
                ($this->sink)($email);
            }
        };

        $this->messaging = CoreMessaging::build(
            $this->pdo,
            new MessageChannelRegistry(),
            $mailer,
            $secrets,
            function (string $name, array $payload, array $opts): int {
                $this->jobs[] = [$name, $payload, $opts];

                return count($this->jobs);
            },
            null,
            'https://app.whity.test',
        );
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testSendQueuesThenDeliversWithThreadingHeaders(): void
    {
        $this->settings->save(self::A, false, null, 'hello@bakery.example');

        $first = $this->messaging->send(self::A, Recipient::person(self::CUSTOMER_A), 'Your cake is ready.', new SendOptions(subject: 'Your order', source: 'calendar.reminder'));
        self::assertSame('queued', $first['status']);
        self::assertSame('calendar.reminder', $first['source']);
        self::assertSame(MessagingService::DELIVER_JOB, $this->jobs[0][0]);
        self::assertSame(0, $this->jobs[0][2]['delay']);

        self::assertSame('sent', $this->messaging->deliver(self::A, (int) $first['id']));
        self::assertSame('customer-a@example.test', $this->mail[0]->to);
        self::assertSame('Example Bakery', $this->mail[0]->fromName);
        self::assertSame('hello@bakery.example', $this->mail[0]->replyTo);
        self::assertMatchesRegularExpression('/^<m\.\d+\.[0-9a-f]+@app\.whity\.test>$/', (string) $this->mail[0]->messageId);
        self::assertSame('skipped', $this->messaging->deliver(self::A, (int) $first['id']), 'delivered once');

        $conversation = $this->messaging->conversations()->find(self::A, (int) $first['conversation_id']);
        self::assertSame(self::CUSTOMER_A, (int) ($conversation['customer_profile_id'] ?? 0), 'linked to the customer');

        $second = $this->messaging->reply(self::A, (int) $first['conversation_id'], self::STAFF_A, 'Pick-up is at 5.');
        $this->messaging->deliver(self::A, (int) $second['id']);
        self::assertSame($this->mail[0]->messageId, $this->mail[1]->inReplyTo);
        self::assertSame('Re: Your order', $this->mail[1]->subject);
    }

    public function testInboundEmailThreadsIntoTheConversationOnlyWhenSigned(): void
    {
        $this->settings->save(self::A, false, null, 'hello@bakery.example');
        $secret = $this->settings->rotateInboundSecret(self::A);
        $sent = $this->messaging->send(self::A, Recipient::address('walkin@example.test', 'Walk In'), 'Hello', new SendOptions(subject: 'Quote'));
        $this->messaging->deliver(self::A, (int) $sent['id']);
        $ourId = (string) $this->mail[0]->messageId;

        $raw = "From: Walk In <walkin@example.test>\nSubject: Re: Quote\nMessage-ID: <r1@example.test>\nIn-Reply-To: {$ourId}\n\nThanks!";
        $handler = new InboundMessagesApiHandler($this->messaging, new TenantHostRepository($this->pdo), new DatabaseSharedStore($this->pdo));
        $post = fn (string $sig, string $slug = 'bakery'): Response => $handler->receive(
            new Request('POST', '/api/public/messages/inbound/email/' . $slug, ['X-Whity-Signature' => $sig], $raw),
            ['channel' => 'email', 'workspace' => $slug]
        );

        self::assertSame(202, $post('sha256=nope')->getStatusCode());
        self::assertSame(1, $this->rows('messages'), 'an unsigned mail files nothing');

        self::assertSame(202, $post(EmailChannel::sign($raw, $secret))->getStatusCode());
        self::assertSame(202, $post(EmailChannel::sign($raw, $secret))->getStatusCode(), 'a retried delivery');
        self::assertSame(202, $post(EmailChannel::sign($raw, $secret), 'florist')->getStatusCode(), 'another workspace has no secret');

        $thread = $this->messaging->messages()->forConversation(self::A, (int) $sent['conversation_id'], null, 50);
        self::assertCount(2, $thread, 'filed once, in the same conversation');
        self::assertSame('inbound', $thread[1]['kind']);
        self::assertSame('Thanks!', $thread[1]['body_text']);
        self::assertSame(0, $this->rows('messages', self::B));
    }

    public function testAnAgentsReplyIsHeldAndCanBeCancelled(): void
    {
        $c = $this->messaging->send(self::A, Recipient::address('x@example.test'), 'Hi', new SendOptions());
        $held = $this->messaging->reply(self::A, (int) $c['conversation_id'], self::STAFF_A, 'Drafted by the agent', true);

        self::assertTrue($held['by_agent']);
        self::assertSame('agent.reply', $held['source']);
        self::assertNotNull($held['send_after']);
        self::assertSame(MessagingService::AGENT_HOLD_SECONDS, $this->jobs[count($this->jobs) - 1][2]['delay']);
        self::assertSame('skipped', $this->messaging->deliver(self::A, (int) $held['id']), 'not before the hold ends');
        self::assertTrue($this->messaging->cancel(self::A, (int) $held['id']));
        self::assertFalse($this->messaging->cancel(self::A, (int) $held['id']));
        self::assertFalse($this->messaging->cancel(self::B, (int) $c['id']), 'another workspace cannot cancel it');
    }

    public function testStaffApiIsBoundToTheCallersWorkspace(): void
    {
        $c = $this->messaging->send(self::A, Recipient::address('x@example.test'), 'Hi', new SendOptions());
        $handler = new MessagesApiHandler($this->messaging, $this->settings);

        TenantContext::reset();
        TenantContext::setTenantId(self::B);
        $show = $handler->show($this->staffRequest(self::STAFF_B, 'GET', '/api/messages/conversations/1'), ['id' => (string) $c['conversation_id']]);
        self::assertSame(404, $show->getStatusCode());
        $list = json_decode($handler->list($this->staffRequest(self::STAFF_B, 'GET', '/api/messages/conversations'))->getBody(), true);
        self::assertSame([], $list['data']);

        TenantContext::reset();
        TenantContext::setTenantId(self::A);
        $assign = $handler->update(
            $this->staffRequest(self::STAFF_A, 'PATCH', '/x', ['assigned_profile_id' => self::STAFF_B]),
            ['id' => (string) $c['conversation_id']]
        );
        self::assertSame(422, $assign->getStatusCode(), 'only staff of this workspace');
        $assign = $handler->update(
            $this->staffRequest(self::STAFF_A, 'PATCH', '/x', ['assigned_profile_id' => self::STAFF_A, 'status' => 'pending']),
            ['id' => (string) $c['conversation_id']]
        );
        $data = json_decode($assign->getBody(), true)['data'];
        self::assertSame(['id' => self::STAFF_A, 'name' => 'Staff A'], $data['assignee']);
        self::assertSame('pending', $data['status']);

        $search = json_decode($handler->list($this->staffRequest(self::STAFF_A, 'GET', '/api/messages/conversations?q=X%40EXAMPLE'))->getBody(), true);
        self::assertCount(1, $search['data']);

        $note = $handler->note($this->staffRequest(self::STAFF_A, 'POST', '/x', ['body' => 'Called them']), ['id' => (string) $c['conversation_id']]);
        self::assertSame(201, $note->getStatusCode());
        $empty = $handler->reply($this->staffRequest(self::STAFF_A, 'POST', '/x', ['body' => '  ']), ['id' => (string) $c['conversation_id']]);
        self::assertSame(422, $empty->getStatusCode());

        $source = new MessagesInboxSource($this->messaging->conversations());
        self::assertSame(0, $source->count(self::A, self::STAFF_A, true), 'pending is not open');
        $this->messaging->conversations()->setStatus(self::A, (int) $c['conversation_id'], 'open');
        self::assertSame((string) $c['conversation_id'], $source->listAfter(self::A, self::STAFF_A, true, null, 5)[0]->id);
        self::assertSame(0, $source->count(self::B, self::STAFF_A, true));
    }

    public function testWebChatForVisitorsHidesNotesAndChecksTheToken(): void
    {
        $chat = new PublicChatApiHandler(
            $this->pdo,
            $this->messaging,
            $this->settings,
            new TenantHostRepository($this->pdo),
            new HostResolver(new TenantHostRepository($this->pdo), 'whity.test'),
            new BotCheck(new GlobalSettingsRepository($this->pdo), new EncryptedSecretStore(['v1' => str_repeat('k', 32)], 'v1'), new HttpFetcher()),
            new DatabaseSharedStore($this->pdo),
            'pepper',
        );
        $req = fn (string $method, array $body = [], ?string $token = null, string $ip = '203.0.113.9'): Request => new Request(
            $method,
            '/api/public/chat/bakery/messages',
            array_filter(['Host' => 'app.whity.test', ClientIp::HEADER => $ip, 'Authorization' => $token !== null ? 'Bearer ' . $token : null]),
            json_encode($body) ?: ''
        );
        $params = ['workspace' => 'bakery'];

        self::assertSame(404, $chat->info($req('GET'), $params)->getStatusCode(), 'off by default');
        $this->settings->save(self::A, true, 'Hello! How can we help?', null);
        self::assertSame('Hello! How can we help?', json_decode($chat->info($req('GET'), $params)->getBody(), true)['data']['greeting']);

        $bot = $chat->start($req('POST', ['message' => 'buy now', 'website' => 'spam.example']), $params);
        self::assertSame(201, $bot->getStatusCode());
        self::assertSame(0, $this->rows('messages'), 'the honeypot writes nothing');

        $started = $chat->start($req('POST', ['message' => 'Do you deliver?', 'name' => 'Visitor']), $params);
        self::assertSame(201, $started->getStatusCode());
        $token = json_decode($started->getBody(), true)['data']['token'];
        $conversationId = $this->scalar('SELECT conversation_id FROM webchat_visitors');

        $this->messaging->note(self::A, $conversationId, self::STAFF_A, 'Internal: check the van');
        $this->messaging->reply(self::A, $conversationId, self::STAFF_A, 'Yes, across Amman.');
        $this->messaging->reply(self::A, $conversationId, self::STAFF_A, 'Agent draft', true);

        $poll = json_decode($chat->poll($req('GET', [], $token), $params)->getBody(), true)['data'];
        self::assertSame(['Do you deliver?', 'Yes, across Amman.'], array_column($poll, 'body'), 'no note, nothing held');
        self::assertSame(['you', 'workspace'], array_column($poll, 'from'));

        self::assertSame(401, $chat->poll($req('GET', [], 'not-a-token'), $params)->getStatusCode());
        self::assertSame(201, $chat->send($req('POST', ['message' => 'Great'], $token), $params)->getStatusCode());
        self::assertSame(404, $chat->poll($req('GET', [], $token), ['workspace' => 'florist'])->getStatusCode(), 'chat is off there');

        for ($i = 0; $i < 4; $i++) {
            $chat->start($req('POST', ['message' => 'again ' . $i], null, '198.51.100.1'), $params);
        }
        self::assertSame(429, $chat->start($req('POST', ['message' => 'one more'], null, '198.51.100.1'), $params)->getStatusCode());
    }

    public function testACustomerSeesOnlyTheirOwnConversations(): void
    {
        $this->settings->save(self::A, true, null, null);
        $me = new MeMessagesApiHandler($this->messaging);
        TenantContext::reset();
        TenantContext::setTenantId(self::A);

        $started = $me->start($this->staffRequest(self::CUSTOMER_A, 'POST', '/api/me/messages', ['message' => 'Is my order ready?']), []);
        self::assertSame(201, $started->getStatusCode());
        $cid = json_decode($started->getBody(), true)['data']['conversation_id'];
        $conversation = $this->messaging->conversations()->find(self::A, $cid);
        self::assertSame(self::CUSTOMER_A, (int) ($conversation['customer_profile_id'] ?? 0));

        self::assertCount(1, json_decode($me->list($this->staffRequest(self::CUSTOMER_A, 'GET', '/api/me/messages'), [])->getBody(), true)['data']);
        self::assertSame(404, $me->show($this->staffRequest(self::STAFF_A, 'GET', '/x'), ['id' => (string) $cid])->getStatusCode(), 'not theirs');
    }

    public function testDeliveryNewsOnlyMovesForwardAndChannelsAreChecked(): void
    {
        $m = $this->messaging->send(self::A, Recipient::address('x@example.test'), 'Hi', new SendOptions());
        $this->messaging->deliver(self::A, (int) $m['id']);
        $external = (string) $this->mail[0]->messageId;

        $repo = $this->messaging->messages();
        self::assertSame(1, $repo->applyStatus(self::A, $external, 'read', null));
        self::assertSame(0, $repo->applyStatus(self::A, $external, 'delivered', null), 'read is not undone by a late delivered');
        self::assertSame(0, $repo->applyStatus(self::B, $external, 'failed', 'x'), 'another workspace');

        $this->expectException(MessagingException::class);
        $this->messaging->send(self::A, Recipient::address('x@example.test'), 'Hi', new SendOptions(channel: 'acme:whatsapp'));
    }

    /** @param array<string, mixed> $body */
    private function staffRequest(int $profileId, string $method, string $path, array $body = []): Request
    {
        $request = new Request($method, $path, ['Content-Type' => 'application/json'], $body === [] ? '' : (string) json_encode($body));
        $request->user = (object) ['profile_id' => $profileId, 'display_name' => 'Caller'];

        return $request;
    }

    private function scalar(string $sql): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    private function rows(string $table, int $tenantId = self::A): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE tenant_id = ?");
        $stmt->execute([$tenantId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * WHIT-967: a plugin's customer message never goes to a channel that
     * cannot take it now. A WhatsApp-style channel needs a conversation the
     * customer started (their address) and, outside its window, a template;
     * otherwise email takes the message, so a refused send never drops it.
     */
    public function testCustomerMessagesUseWhatsAppOnlyWhenItCanReachThemAndFallBackToEmail(): void
    {
        $this->messaging->channels()->register('Whity WhatsApp', [new class implements MessageChannel {
            public function key(): string
            {
                return 'whatsapp';
            }

            public function label(): array
            {
                return ['en' => 'WhatsApp', 'ar' => 'واتساب'];
            }

            public function isEnabled(int $tenantId): bool
            {
                return true;
            }

            public function capabilities(): ChannelCapabilities
            {
                return new ChannelCapabilities(requiresTemplate: true, freeformWindowSeconds: 86400);
            }

            public function send(OutboundMessage $message): SendOutcome
            {
                return SendOutcome::sent('wamid');
            }

            public function receive(int $tenantId, string $rawBody, array $headers): array
            {
                return [];
            }

            public function templates(int $tenantId): array
            {
                return [];
            }
        }]);
        $key = (string) array_values(array_filter($this->messaging->channels()->keys(), static fn (string $k): bool => str_ends_with($k, ':whatsapp')))[0];
        $host = new HostCustomerMessages(fn (): MessagingService => $this->messaging);
        $plugin = $this->createStub(PluginInterface::class);
        $plugin->method('getName')->willReturn('WhityPay');

        // No WhatsApp conversation: no address we know reaches them there.
        $first = $host->send($plugin, self::A, self::CUSTOMER_A, 'Your payment did not go through.', ['whatsapp', 'email'], 'Payment failed');
        self::assertSame('email', $first);

        // They wrote on WhatsApp two days ago: outside the window only a template may go.
        $conversation = $this->messaging->conversations()->create(self::A, $key, '+962790000000', 'Customer A', null, self::CUSTOMER_A);
        $this->pdo->exec("UPDATE conversations SET last_inbound_at = '" . gmdate('Y-m-d H:i:s', time() - 2 * 86400) . "' WHERE id = {$conversation}");
        self::assertSame('email', $host->send($plugin, self::A, self::CUSTOMER_A, 'Your payment did not go through.', ['whatsapp', 'email'], 'Payment failed'));

        // They wrote an hour ago: free text is allowed, in their conversation, marked as the plugin's.
        $this->pdo->exec("UPDATE conversations SET last_inbound_at = '" . gmdate('Y-m-d H:i:s', time() - 3600) . "' WHERE id = {$conversation}");
        self::assertSame($key, $host->send($plugin, self::A, self::CUSTOMER_A, 'Your payment did not go through.', ['whatsapp', 'email'], 'Payment failed'));
        $statement = $this->pdo->prepare('SELECT source FROM messages WHERE conversation_id = :c ORDER BY id DESC LIMIT 1');
        $statement->execute([':c' => $conversation]);
        $source = $statement->fetchColumn();
        self::assertSame('plugin.whitypay', $source);

        // Another workspace's person is never reached.
        self::assertNull($host->send($plugin, self::B, self::CUSTOMER_A, 'x', ['whatsapp', 'email']));
    }

    private function seed(): void
    {
        $pdo = $this->pdo;
        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name, slug) VALUES (0, 'system', 'system')");
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (" . self::A . ", 'Example Bakery', 'bakery'), (" . self::B . ", 'Example Florist', 'florist')");
        $role = $this->scalar("SELECT id FROM roles WHERE name = 'user' AND tenant_id IS NULL");
        self::assertGreaterThan(0, $role);

        $people = [self::STAFF_A => 'Staff A', self::STAFF_B => 'Staff B', self::CUSTOMER_A => 'Customer A'];
        foreach ($people as $id => $name) {
            $pdo->exec("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                        VALUES ({$id}, '{$name}', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
            $email = strtolower(str_replace(' ', '-', $name)) . '@example.test';
            $pdo->exec("INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at) VALUES ({$id}, '{$email}', true, true, CURRENT_TIMESTAMP)");
        }
        $pdo->exec("INSERT INTO memberships (profile_id, tenant_id, role_id, status, kind, created_at) VALUES
            (" . self::STAFF_A . ", " . self::A . ", {$role}, 'active', 'member', CURRENT_TIMESTAMP),
            (" . self::STAFF_B . ", " . self::B . ", {$role}, 'active', 'member', CURRENT_TIMESTAMP),
            (" . self::CUSTOMER_A . ", " . self::A . ", {$role}, 'active', 'customer', CURRENT_TIMESTAMP)");
        SchemaFromMigrations::syncSequences($pdo);
    }
}
