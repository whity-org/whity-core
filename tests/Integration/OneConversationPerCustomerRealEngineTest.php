<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Mail\OutgoingEmail;
use Whity\Core\Mail\ThreadedMailer;
use Whity\Core\Messaging\CoreMessaging;
use Whity\Core\Messaging\MessageChannelRegistry;
use Whity\Core\Messaging\MessagingService;
use Whity\Core\Messaging\MessagingSettings;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Messaging\ChannelCapabilities;
use Whity\Sdk\Messaging\InboundMessage;
use Whity\Sdk\Messaging\MessageChannel;
use Whity\Sdk\Messaging\OutboundMessage;
use Whity\Sdk\Messaging\SendOutcome;

/**
 * One conversation per customer across channels (owner Q35.2, merge map C5,
 * WHIT-1121): who the customer is decides the conversation, the channel moves
 * to the message, replies go where the customer last wrote unless "Send by"
 * chose another, and a conversation closed within 30 days reopens.
 *
 * Example Bakery (1): Lina is a contact with an email address and a mobile
 * number. A fake WhatsApp channel comes from the "acme" plugin.
 */
final class OneConversationPerCustomerRealEngineTest extends TestCase
{
    private const A = 1;
    private const B = 2;
    private const WHATSAPP = 'acme:whatsapp';

    private PDO $pdo;
    private MessagingService $messaging;

    /** @var list<OutgoingEmail> */
    private array $mail = [];

    /** @var list<OutboundMessage> */
    private array $whatsapp = [];

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'bakery'), (2, 'Example Florist', 'florist')");
        $this->pdo->exec("INSERT INTO contacts (tenant_id, name, email, mobile) VALUES (1, 'Lina', 'lina@example.test', '+962 79 123 4567')");

        $secrets = new EncryptedSecretStore(['v1' => str_repeat('k', 32)], 'v1');
        (new MessagingSettings($this->pdo, $secrets))->save(self::A, false, null, 'hello@bakery.example');
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
        $registry = new MessageChannelRegistry();
        $registry->register('acme', [new class (function (OutboundMessage $m): void {
            $this->whatsapp[] = $m;
        }) implements MessageChannel {
            public function __construct(private readonly \Closure $sink)
            {
            }

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
                return new ChannelCapabilities();
            }

            public function send(OutboundMessage $message): SendOutcome
            {
                ($this->sink)($message);

                return SendOutcome::sent('wa-' . $message->messageId);
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
        $this->messaging = CoreMessaging::build($this->pdo, $registry, $mailer, $secrets, static fn (): int => 1, null, 'https://app.whity.test');
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testOneCustomerOnTwoChannelsIsOneConversation(): void
    {
        $email = $this->in('email', 'Lina@Example.test', 'Is the cake ready?', 'e1');
        $whatsapp = $this->in(self::WHATSAPP, '00962791234567', 'Also, can I pay on pick-up?', 'w1');

        self::assertSame($email['conversation_id'], $whatsapp['conversation_id'], 'the contact joins them');
        self::assertSame(['email', self::WHATSAPP], array_column($this->messagesOf((int) $email['conversation_id']), 'channel'));
        $conversation = $this->messaging->conversations()->find(self::A, (int) $email['conversation_id']);
        self::assertSame(self::WHATSAPP, $conversation['channel'] ?? null, 'the badge is the channel last used');
    }

    public function testAReplyGoesWhereTheCustomerLastWroteUnlessSendByChoosesAnother(): void
    {
        $this->in('email', 'lina@example.test', 'Is the cake ready?', 'e1');
        $last = $this->in(self::WHATSAPP, '+962791234567', 'Hello?', 'w1');
        $conversation = (int) $last['conversation_id'];

        $reply = $this->messaging->reply(self::A, $conversation, null, 'Yes, it is ready.');
        self::assertSame(self::WHATSAPP, $reply['channel']);
        self::assertSame('sent', $this->messaging->deliver(self::A, (int) $reply['id']));
        self::assertSame('+962791234567', $this->whatsapp[0]->to);

        $byEmail = $this->messaging->reply(self::A, $conversation, null, 'The receipt is attached.', sendBy: 'email');
        self::assertSame('sent', $this->messaging->deliver(self::A, (int) $byEmail['id']));
        self::assertSame('lina@example.test', $this->mail[0]->to);

        self::assertSame(
            [self::WHATSAPP => '+962791234567', 'email' => 'lina@example.test'],
            $this->messaging->contacts()->addresses(self::A, $conversation),
            'what Send by offers, the last used first',
        );
        $this->expectExceptionMessage('This customer has not written on that channel');
        $this->messaging->reply(self::A, $conversation, null, 'Hi', sendBy: 'webchat');
    }

    public function testAStrangerOnAnotherChannelIsAnotherConversation(): void
    {
        $email = $this->in('email', 'lina@example.test', 'Hi', 'e1');
        $other = $this->in(self::WHATSAPP, '+962770000000', 'Who is this?', 'w1');

        self::assertNotSame($email['conversation_id'], $other['conversation_id']);
    }

    public function testAConversationClosedWithinThirtyDaysReopensAndAnOlderOneDoesNot(): void
    {
        $first = $this->in('email', 'lina@example.test', 'Hi', 'e1');
        $id = (int) $first['conversation_id'];
        $this->messaging->conversations()->setStatus(self::A, $id, 'closed');
        $this->pdo->exec("UPDATE conversations SET closed_at = '" . gmdate('Y-m-d H:i:s', time() - 10 * 86400) . "' WHERE id = {$id}");

        $again = $this->in(self::WHATSAPP, '+962791234567', 'Me again', 'w1');
        self::assertSame($id, (int) $again['conversation_id']);
        self::assertSame('open', $this->messaging->conversations()->find(self::A, $id)['status'] ?? null);

        $this->messaging->conversations()->setStatus(self::A, $id, 'closed');
        $this->pdo->exec("UPDATE conversations SET closed_at = '" . gmdate('Y-m-d H:i:s', time() - 40 * 86400) . "' WHERE id = {$id}");
        $later = $this->in('email', 'lina@example.test', 'New order', 'e2');
        self::assertNotSame($id, (int) $later['conversation_id'], 'after 30 days, a new conversation');
    }

    public function testAnotherWorkspaceNeverJoinsTheConversation(): void
    {
        $here = $this->in('email', 'lina@example.test', 'Hi', 'e1');
        (new MessagingSettings($this->pdo, new EncryptedSecretStore(['v1' => str_repeat('k', 32)], 'v1')))->save(self::B, false, null, 'hello@florist.example');
        $there = $this->messaging->ingest(self::B, 'email', new InboundMessage('lina@example.test', 'Lina', 'Flowers?', 'f1'));

        self::assertNotNull($there);
        self::assertNotSame($here['conversation_id'], $there['conversation_id']);
    }

    public function testTheBackfillGivesOldMessagesTheirChannelAndRunsAgainSafely(): void
    {
        $old = $this->in('email', 'lina@example.test', 'Hi', 'e1');
        $this->pdo->exec('UPDATE messages SET channel = NULL, channel_address = NULL');
        $this->pdo->exec('DELETE FROM conversation_contacts');

        $db = \Whity\Database\Database::withFactory(fn (): PDO => $this->pdo, 86400, 86400);
        $db->forceConnect();
        require_once dirname(__DIR__, 2) . '/database/migrations/289_one_conversation_per_customer.php';
        \Database\Migrations\OneConversationPerCustomer::up($db);
        \Database\Migrations\OneConversationPerCustomer::up($db);

        self::assertSame(['email'], array_column($this->messagesOf((int) $old['conversation_id']), 'channel'));
        $mapped = $this->pdo->query('SELECT COUNT(*) FROM conversation_contacts');
        self::assertNotFalse($mapped);
        self::assertSame(1, (int) $mapped->fetchColumn());
        self::assertSame($old['conversation_id'], $this->in(self::WHATSAPP, '+962791234567', 'Hello', 'w1')['conversation_id']);
    }

    /** @return array<string, mixed> */
    private function in(string $channel, string $from, string $body, string $externalId): array
    {
        $filed = $this->messaging->ingest(self::A, $channel, new InboundMessage($from, 'Lina', $body, $externalId));
        self::assertNotNull($filed);

        return $filed;
    }

    /** @return list<array<string, mixed>> */
    private function messagesOf(int $conversationId): array
    {
        return $this->messaging->messages()->forConversation(self::A, $conversationId, null, 50);
    }
}
