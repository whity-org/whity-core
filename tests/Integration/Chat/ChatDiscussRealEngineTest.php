<?php

declare(strict_types=1);

namespace Tests\Integration\Chat;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Chat\ChatException;
use Whity\Core\Chat\ChatRepository;
use Whity\Core\Chat\ChatService;
use Whity\Core\Chat\ChatViewer;

/**
 * "Discuss in chat" from a board, a meeting or a record (merge map C2,
 * WHIT-1135): one group chat per linked thing, started the first time and
 * named after it, joined by whoever opens it next, and never shared across
 * workspaces.
 *
 * Example Bakery (1): Amro (10) and Awss (11). Example Florist (2): Emad (12).
 */
final class ChatDiscussRealEngineTest extends TestCase
{
    private const A = 1;
    private const B = 2;
    private const AMRO = 10;
    private const AWSS = 11;
    private const EMAD = 12;

    private PDO $pdo;
    private ChatService $chat;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->seed();
        $this->chat = new ChatService(new ChatRepository($this->pdo));
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
        $people = array_column(array_filter(
            $this->chat->show(self::A, ChatViewer::person(self::AWSS), $first['id'])['members'],
            static fn (array $m): bool => $m['type'] === 'person',
        ), 'profile_id');
        sort($people);
        self::assertSame([self::AMRO, self::AWSS], $people, 'each person once');
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

    private function seed(): void
    {
        $pdo = $this->pdo;
        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name, slug) VALUES (0, 'system', 'system')");
        $pdo->exec('INSERT INTO tenants (id, name, slug) VALUES (' . self::A . ", 'Example Bakery', 'bakery'), (" . self::B . ", 'Example Florist', 'florist')");
        $stmt = $pdo->prepare("SELECT id FROM roles WHERE name = 'user' AND tenant_id IS NULL");
        $stmt->execute();
        $role = (int) $stmt->fetchColumn();
        foreach ([self::AMRO => ['Amro Saleh', self::A], self::AWSS => ['Awss Haddad', self::A], self::EMAD => ['Emad Nasser', self::B]] as $id => [$name, $tenant]) {
            $pdo->exec("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                        VALUES ({$id}, '{$name}', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
            $pdo->exec("INSERT INTO memberships (profile_id, tenant_id, role_id, status, kind, created_at) VALUES ({$id}, {$tenant}, {$role}, 'active', 'member', CURRENT_TIMESTAMP)");
        }
    }
}
