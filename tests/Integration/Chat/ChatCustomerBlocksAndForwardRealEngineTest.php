<?php

declare(strict_types=1);

namespace Tests\Integration\Chat;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Calendar\CalendarSettings;
use Whity\Core\Chat\ChatException;
use Whity\Core\Chat\ChatRepository;
use Whity\Core\Chat\ChatService;
use Whity\Core\Chat\ChatViewer;
use Whity\Core\Chat\CustomerBlocks;
use Whity\Core\Form\PublicFormLink;
use Whity\Core\Mail\OutgoingEmail;
use Whity\Core\Mail\ThreadedMailer;
use Whity\Core\Messaging\CoreMessaging;
use Whity\Core\Messaging\MessageChannelRegistry;
use Whity\Core\Messaging\MessagesPresenter;
use Whity\Core\Messaging\WebChat\WebChatChannel;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Sdk\Messaging\InboundMessage;

/**
 * Customer blocks and Forward on the real schema (design 3.5, UC.5, UC.9,
 * UC.10, WHIT-1106): booking times and forms carry links the workspace built,
 * the customer gets words and the team sees the block, a payment link must be
 * https, and Forward copies a message into another chat its sender is in.
 */
final class ChatCustomerBlocksAndForwardRealEngineTest extends TestCase
{
    private const A = 1;
    private const AMRO = 10;
    private const AWSS = 11;

    private PDO $pdo;
    private CustomerBlocks $blocks;
    private bool $bookingPage = true;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->seed();
        $bookingPage = &$this->bookingPage;
        $this->blocks = new CustomerBlocks(
            $this->pdo,
            new PublicFormLink('https://app.whity.test'),
            'https://app.whity.test',
            static function (int $tenantId) use (&$bookingPage): array {
                return [SettingsRegistry::CALENDAR_BOOKING_PAGE_ENABLED => $bookingPage ? 'true' : 'false', SettingsRegistry::TIMEZONE => 'Asia/Amman'];
            },
        );
    }

    public function testBookingTimesLinkToTheWorkspacesBookingPage(): void
    {
        $options = $this->blocks->options(self::A);
        self::assertTrue($options['booking_page']);
        self::assertSame('Asia/Amman', $options['time_zone']);
        self::assertSame([['id' => 1, 'name' => 'Cake tasting', 'minutes' => 75]], $options['services']);

        $built = $this->blocks->build(self::A, [
            'type' => 'booking_times',
            'service_id' => 1,
            'times' => ['2099-01-04T08:00:00Z', '2020-01-01T08:00:00Z', '2099-01-04T12:00:00Z'],
            'text' => 'Pick a time that suits you',
        ]);
        self::assertSame(['2099-01-04T08:00:00Z', '2099-01-04T12:00:00Z'], $built['block']['times'], 'past times are dropped');
        self::assertSame('https://app.whity.test/book/bakery', $built['block']['url']);
        self::assertSame('Asia/Amman', $built['block']['time_zone']);
        self::assertStringContainsString("Pick a time that suits you\n\nCake tasting: Sun 4 Jan, 11:00 · Sun 4 Jan, 15:00\nhttps://app.whity.test/book/bakery", $built['text']);

        $this->bookingPage = false;
        $this->refused(fn () => $this->blocks->build(self::A, ['type' => 'booking_times', 'service_id' => 1, 'times' => ['2099-01-04T08:00:00Z']]), 'booking page');
    }

    public function testAFormMustHaveAPublicLink(): void
    {
        self::assertSame([['id' => 1, 'title' => 'Event order']], $this->blocks->options(self::A)['forms']);
        $built = $this->blocks->build(self::A, ['type' => 'form', 'form_id' => 1]);
        self::assertSame('form', $built['block']['type']);
        self::assertStringStartsWith('https://app.whity.test/f/', $built['block']['url']);
        $this->refused(fn () => $this->blocks->build(self::A, ['type' => 'form', 'form_id' => 2]), 'public link');
    }

    public function testAPaymentLinkMustBeHttpsWithAnAmount(): void
    {
        $built = $this->blocks->build(self::A, ['type' => 'payment', 'url' => 'https://pay.example.test/c/1', 'amount' => '18.00', 'currency' => 'usd', 'description' => 'ORD-2291']);
        self::assertSame("ORD-2291: USD 18.00\nhttps://pay.example.test/c/1", $built['text']);
        $this->refused(fn () => $this->blocks->build(self::A, ['type' => 'payment', 'url' => 'javascript:alert(1)', 'amount' => '1', 'currency' => 'USD', 'description' => 'x']), 'https');
    }

    public function testTheCustomerGetsWordsAndTheTeamSeesTheBlock(): void
    {
        $messaging = CoreMessaging::build(
            $this->pdo,
            new MessageChannelRegistry(),
            new class () implements ThreadedMailer {
                public function sendThreaded(OutgoingEmail $email): void
                {
                }
            },
            new EncryptedSecretStore(['v1' => str_repeat('k', 32)], 'v1'),
            static fn (string $name, array $payload, array $opts): int => 1,
            null,
            'https://app.whity.test',
        );
        $in = $messaging->ingest(self::A, WebChatChannel::KEY, new InboundMessage(from: 'visitor:ahmad', fromName: 'Ahmad Khalil', body: 'Can I book a tasting?'));
        self::assertNotNull($in);
        $built = $this->blocks->build(self::A, ['type' => 'form', 'form_id' => 1, 'text' => 'Please fill this in']);
        $sent = $messaging->reply(self::A, (int) $in['conversation_id'], self::AMRO, $built['text'], false, 'staff.reply', ['block' => $built['block']]);

        $shown = MessagesPresenter::message($sent);
        self::assertSame('form', $shown['block']['type'] ?? null);
        self::assertStringContainsString('Please fill this in', $shown['body']);
        $customer = MessagesPresenter::customerMessage($sent);
        self::assertArrayNotHasKey('block', $customer, 'the customer sees words, not the team\'s block');
    }

    public function testForwardCopiesAMessageIntoAnotherChatItsSenderIsIn(): void
    {
        $chat = new ChatService(new ChatRepository($this->pdo));
        $amro = ChatViewer::person(self::AMRO);
        $from = $chat->createGroup(self::A, $amro, 'Abdali team', [self::AWSS], null);
        $to = $chat->createGroup(self::A, $amro, 'Sweifieh team', [], null);
        $elsewhere = $chat->createGroup(self::A, ChatViewer::person(self::AWSS), 'Payroll', [], null);
        $said = $chat->post(self::A, ChatViewer::person(self::AWSS), $from, 'Oven 2 is fixed');

        $copy = $chat->forward(self::A, $amro, $said['id'], $to);
        self::assertSame($to, $copy['chat_id']);
        self::assertSame('Oven 2 is fixed', $copy['body']);
        self::assertSame('Awss Haddad', $copy['forwarded_from']);
        self::assertSame(self::AMRO, $copy['author']['profile_id'] ?? null);

        // Not into a chat Amro isn't in.
        $this->refused(fn () => $chat->forward(self::A, $amro, $said['id'], $elsewhere), null, 404);
    }

    private function refused(callable $fn, ?string $words, int $status = 422): void
    {
        try {
            $fn();
            self::fail('expected a refusal');
        } catch (ChatException $e) {
            self::assertSame($status, $e->status(), $e->plain());
            if ($words !== null) {
                self::assertStringContainsString($words, $e->plain());
            }
        }
    }

    private function seed(): void
    {
        $pdo = $this->pdo;
        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name, slug) VALUES (0, 'system', 'system')");
        $pdo->exec('INSERT INTO tenants (id, name, slug) VALUES (' . self::A . ", 'Example Bakery', 'bakery')");
        $stmt = $pdo->prepare("SELECT id FROM roles WHERE name = 'user' AND tenant_id IS NULL");
        $stmt->execute();
        $role = (int) $stmt->fetchColumn();
        foreach ([self::AMRO => 'Amro Saleh', self::AWSS => 'Awss Haddad'] as $id => $name) {
            $pdo->exec("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                        VALUES ({$id}, '{$name}', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
            $pdo->exec("INSERT INTO memberships (profile_id, tenant_id, role_id, status, kind, created_at) VALUES ({$id}, " . self::A . ", {$role}, 'active', 'member', CURRENT_TIMESTAMP)");
        }
        $pdo->exec('INSERT INTO messaging_settings (tenant_id, webchat_enabled, updated_at) VALUES (' . self::A . ', TRUE, CURRENT_TIMESTAMP)');
        $pdo->exec('INSERT INTO calendar_services (id, tenant_id, name, duration_minutes, active, created_at, updated_at) VALUES (1, ' . self::A . ", 'Cake tasting', 75, TRUE, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $pdo->exec("INSERT INTO forms (id, tenant_id, form_key, name, title, status, public_enabled, public_slug, created_at, updated_at)
                    VALUES (1, " . self::A . ", 'event-order', 'Event order', NULL, 'published', TRUE, '" . str_repeat('a', 64) . "', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                           (2, " . self::A . ", 'internal', 'Internal', NULL, 'published', FALSE, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
    }
}
