<?php

declare(strict_types=1);

namespace Tests\Integration\Chat;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Chat\HeldLinesInboxSource;

/**
 * An agent's chat lines held for a person wait in that person's Waiting for me
 * (merge map C3, WHIT-1122): only theirs, only while held, in their own
 * workspace; with done items included, the ones they decided too.
 *
 * Example Bakery (1): Amro (10) and Awss (11) each have an agent posting in a
 * team chat.
 */
final class HeldLinesInboxSourceRealEngineTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'bakery'), (2, 'Example Florist', 'florist')");
        foreach ([10 => 'Amro Saleh', 11 => 'Awss Haddad'] as $id => $name) {
            $this->pdo->exec("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                              VALUES ({$id}, '{$name}', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        }
        foreach ([[101, 1], [201, 2]] as [$id, $tenant]) {
            $this->pdo->exec("INSERT INTO conversations (id, tenant_id, kind, channel, subject, status, contact_address, last_message_at, created_at, updated_at)
                              VALUES ({$id}, {$tenant}, 'team', 'team', 'Ramadan boxes', 'open', '', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        }
        $this->line(1, 101, 10, '2026-11-16 08:00:00', 'Stock is low on dates', ['held' => ['by' => ['id' => 10, 'name' => 'Amro Saleh']]]);
        $this->line(2, 101, 11, '2026-11-16 08:05:00', 'For Awss', ['held' => ['by' => ['id' => 11, 'name' => 'Awss Haddad']]]);
        $this->line(3, 101, 10, '2026-11-16 07:00:00', 'Approved earlier', ['decided' => ['by' => ['id' => 10, 'name' => 'Amro Saleh'], 'approved' => true]]);
        $this->line(4, 101, 10, '2026-11-16 06:00:00', 'Plain line', []);
        $this->line(5, 201, 10, '2026-11-16 08:10:00', 'Another workspace', ['held' => ['by' => ['id' => 10, 'name' => 'Amro Saleh']]]);
    }

    public function testOnlyTheLinesHeldForThePersonWait(): void
    {
        $source = new HeldLinesInboxSource($this->pdo);

        $items = $source->list(1, 10, true, 10, 0);
        self::assertSame(['1'], array_map(static fn ($i): string => $i->id, $items));
        self::assertSame('Stock assistant', $items[0]->title);
        self::assertSame('chat', $items[0]->resourceType);
        self::assertSame('101', $items[0]->resourceId, 'opens its chat');
        self::assertSame('waiting', $items[0]->status);
        self::assertSame(1, $source->count(1, 10, true));
    }

    public function testIncludingDoneAddsTheLinesTheyDecided(): void
    {
        $items = (new HeldLinesInboxSource($this->pdo))->list(1, 10, false, 10, 0);

        self::assertSame([['1', 'waiting'], ['3', 'approved']], array_map(static fn ($i): array => [$i->id, $i->status], $items));
    }

    public function testAnotherWorkspacesLinesNeverShow(): void
    {
        self::assertSame(['5'], array_map(static fn ($i): string => $i->id, (new HeldLinesInboxSource($this->pdo))->list(2, 10, true, 10, 0)));
    }

    /** @param array<string, mixed> $meta */
    private function line(int $id, int $chat, int $profile, string $at, string $body, array $meta): void
    {
        $meta = $meta + ['agent' => ['id' => 7, 'name' => 'Stock assistant']];
        $stmt = $this->pdo->prepare(
            "INSERT INTO messages (id, tenant_id, conversation_id, kind, author_profile_id, by_agent, source, body_text, status, chat_meta, created_at)
             VALUES (:id, (SELECT tenant_id FROM conversations WHERE id = :chat), :chat2, 'note', :profile, :agent, 'chat.agent', :body, 'noted', :meta, :at)"
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':chat', $chat, PDO::PARAM_INT);
        $stmt->bindValue(':chat2', $chat, PDO::PARAM_INT);
        $stmt->bindValue(':profile', $profile, PDO::PARAM_INT);
        $stmt->bindValue(':agent', true, PDO::PARAM_BOOL);
        $stmt->bindValue(':body', $body);
        $stmt->bindValue(':meta', (string) json_encode($meta));
        $stmt->bindValue(':at', $at);
        $stmt->execute();
    }
}
