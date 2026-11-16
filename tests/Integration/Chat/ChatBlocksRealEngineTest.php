<?php

declare(strict_types=1);

namespace Tests\Integration\Chat;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Chat\ChatBlocks;
use Whity\Core\Chat\ChatException;
use Whity\Core\Chat\ChatRepository;
use Whity\Core\Chat\ChatService;
use Whity\Core\Chat\ChatViewer;

/**
 * Blocks inside chat messages on the real schema (design 3.5, UC.5,
 * WHIT-1096; migration 280): a poll's counts are live and a vote can change,
 * a closed poll takes no votes, and a Whity record shows its current status
 * and only from this workspace.
 */
final class ChatBlocksRealEngineTest extends TestCase
{
    private const A = 1;
    private const B = 2;
    private const AMRO = 10;
    private const AWSS = 11;
    private const EMAD = 12;

    private PDO $pdo;
    private ChatService $chat;
    private int $group = 0;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->seed();
        $this->chat = new ChatService(new ChatRepository($this->pdo), null, null, null, new ChatBlocks($this->pdo));
        $this->group = (int) $this->chat->createGroup(self::A, ChatViewer::person(self::AMRO), 'Abdali team', [self::AWSS], null);
    }

    public function testAPollCountsLiveAndAVoteCanChange(): void
    {
        $poll = $this->chat->post(self::A, ChatViewer::person(self::AWSS), $this->group, '', [], 'message', null, null, false, [],
            ['type' => 'poll', 'question' => 'Saturday tasting slot?', 'options' => ['10:00', '12:00', '14:00', '12:00', '']]);
        self::assertSame('', $poll['body']);
        self::assertSame(['10:00', '12:00', '14:00'], array_column($poll['block']['options'], 'text'), 'blank and repeated answers are dropped');

        $this->chat->vote(self::A, ChatViewer::person(self::AMRO), $poll['id'], [0]);
        $after = $this->chat->vote(self::A, ChatViewer::person(self::AWSS), $poll['id'], [0]);
        self::assertSame([2, 0, 0], array_column($after['block']['options'], 'votes'));
        self::assertSame(['Amro Saleh', 'Awss Haddad'], $after['block']['options'][0]['people']);
        self::assertTrue($after['block']['options'][0]['mine']);

        $changed = $this->chat->vote(self::A, ChatViewer::person(self::AMRO), $poll['id'], [2]);
        self::assertSame([1, 0, 1], array_column($changed['block']['options'], 'votes'));
        self::assertSame(2, $changed['block']['voters']);

        $this->refused(fn () => $this->chat->vote(self::A, ChatViewer::person(self::AMRO), $poll['id'], [0, 1]), 'one answer');
        $this->refused(fn () => $this->chat->vote(self::A, ChatViewer::person(self::EMAD), $poll['id'], [0]), null, 404);
    }

    public function testAClosedPollTakesNoVotesAndAPollNeedsTwoAnswers(): void
    {
        $poll = $this->chat->post(self::A, ChatViewer::person(self::AMRO), $this->group, '', [], 'message', null, null, false, [],
            ['type' => 'poll', 'question' => 'Late one?', 'options' => ['Yes', 'No'], 'closes_at' => gmdate('c', time() - 60)]);
        self::assertTrue($poll['block']['closed']);
        $this->refused(fn () => $this->chat->vote(self::A, ChatViewer::person(self::AWSS), $poll['id'], [0]), 'closed');
        $this->refused(fn () => $this->chat->post(self::A, ChatViewer::person(self::AMRO), $this->group, '', [], 'message', null, null, false, [],
            ['type' => 'poll', 'question' => 'Only one?', 'options' => ['Yes']]), 'between 2');
    }

    public function testARecordShowsItsCurrentStatusAndOnlyFromThisWorkspace(): void
    {
        $this->pdo->exec('INSERT INTO calendar_services (id, tenant_id, name, duration_minutes, created_at, updated_at) VALUES (1, ' . self::A . ", 'Cake tasting', 75, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $this->pdo->exec('INSERT INTO calendar_bookings (id, tenant_id, service_id, profile_id, customer_name, starts_at, ends_at, blocks_from, blocks_until, status, source, created_at, updated_at)
            VALUES (7, ' . self::A . ", 1, " . self::AWSS . ", 'Ahmad Khalil', '2026-11-17 10:00:00', '2026-11-17 11:15:00', '2026-11-17 10:00:00', '2026-11-17 11:15:00', 'confirmed', 'staff', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");

        $shared = $this->chat->post(self::A, ChatViewer::person(self::AMRO), $this->group, 'Tasting tomorrow', [], 'message', null, null, false, [],
            ['type' => 'record', 'record' => 'booking', 'id' => 7]);
        self::assertSame('Cake tasting · Ahmad Khalil', $shared['block']['title']);
        self::assertSame('confirmed', $shared['block']['status']);
        self::assertSame('2026-11-17T10:00:00Z', $shared['block']['starts_at']);
        self::assertSame('Awss Haddad', $shared['block']['with']);

        $this->pdo->exec("UPDATE calendar_bookings SET status = 'cancelled' WHERE id = 7");
        $read = $this->chat->show(self::A, ChatViewer::person(self::AWSS), $this->group);
        $last = end($read['messages']);
        self::assertSame('cancelled', $last['block']['status'], 'the block says what the booking is now');

        self::assertSame([7], array_column($this->chat->findRecords(self::A, ChatViewer::person(self::AMRO), 'booking', 'ahmad'), 'id'));
        self::assertSame([], $this->chat->findRecords(self::B, ChatViewer::person(self::AMRO), 'booking', ''));
        $this->refused(fn () => $this->chat->post(self::B, ChatViewer::person(self::AMRO), $this->group, '', [], 'message', null, null, false, [],
            ['type' => 'record', 'record' => 'booking', 'id' => 7]), null, 404);
    }

    public function testAnAgentAddsNoBlocks(): void
    {
        $this->refused(fn () => $this->chat->post(self::A, ChatViewer::agent(1, self::AMRO, 'Mira'), $this->group, '', [], 'message', null, null, false, [],
            ['type' => 'poll', 'question' => 'x', 'options' => ['a', 'b']]), null, 404);
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
        $pdo->exec('INSERT INTO tenants (id, name, slug) VALUES (' . self::A . ", 'Example Bakery', 'bakery'), (" . self::B . ", 'Example Florist', 'florist')");
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
