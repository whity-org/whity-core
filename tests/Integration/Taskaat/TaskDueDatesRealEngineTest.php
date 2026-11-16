<?php

declare(strict_types=1);

namespace Tests\Integration\Taskaat;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Taskaat\TaskaatClient;
use Whity\Core\Taskaat\TaskaatConnection;
use Whity\Core\Taskaat\TaskDueDates;

/**
 * Tasks due on the calendar (ADR 0018, WHIT-1114): read from Taskaat, shown as
 * all-day items on their date, never busy, never stored; Taskaat being away
 * leaves the calendar whole.
 */
final class TaskDueDatesRealEngineTest extends TestCase
{
    private const KEY = 'unit-test-encryption-key-please-ignore-0123456789';
    private const BAKERY = 4;
    private const AMRO = 41;

    private PDO $pdo;
    private TaskaatClient $client;
    private bool $down = false;

    /** @var list<array<string, mixed>> */
    private array $calls = [];

    /** @var list<array<string, mixed>> */
    private array $tasks = [];

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (4, 'Example Bakery', 'example-bakery'), (5, 'Example Cafe', 'example-cafe')");
        $now = 2_000_000_000;
        $tokenReplies = [['access_token' => 'at_1', 'refresh_token' => 'rt_1', 'expires_in' => 30 * 86400]];
        $connection = new TaskaatConnection(
            new TenantSettingsRepository($this->pdo),
            new EncryptedSecretStore(['v1' => self::KEY], 'v1'),
            'https://taskaat.test',
            'https://bakery.example.test/tasks/connect',
            'Whity',
            static fn (string $url, array $headers = []): array => [
                'authorization_endpoint' => 'https://taskaat.test/oauth/authorize',
                'token_endpoint' => 'https://taskaat.test/api/oauth/token',
                'registration_endpoint' => 'https://taskaat.test/api/oauth/register',
            ],
            static function (string $url, array $params) use (&$tokenReplies): ?array {
                return array_shift($tokenReplies);
            },
            static fn (string $url, array $payload): array => ['client_id' => 'c1'],
            static fn (): int => $now,
        );
        parse_str((string) parse_url($connection->start(self::BAKERY, self::AMRO), PHP_URL_QUERY), $query);
        $state = $query['state'] ?? '';
        self::assertIsString($state);
        $connection->complete(self::BAKERY, 'code', $state, self::AMRO);

        $this->client = new TaskaatClient($connection, function (string $url, array $payload, array $headers): ?array {
            $this->calls[] = (array) $payload['params']['arguments'];
            if ($this->down) {
                return null;
            }
            $structured = ['tasks' => $this->tasks];

            return ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['content' => [['type' => 'text', 'text' => json_encode($structured)]], 'structuredContent' => $structured]];
        });
    }

    public function testTasksDueInTheRangeAreAllDayItemsOnTheirDate(): void
    {
        $this->tasks = [
            ['id' => 'a1', 'short_id' => 'WHIT-1', 'title' => 'Order flour', 'status' => 'pending', 'due_date' => '2026-11-17', 'assignee' => ['kind' => 'person', 'ref' => 'whity:4:person:41', 'name' => 'Amro Saleh']],
            ['id' => 'a2', 'short_id' => 'WHIT-2', 'title' => 'Next month', 'status' => 'pending', 'due_date' => '2026-12-20'],
            ['id' => 'a3', 'short_id' => 'WHIT-3', 'title' => 'No date', 'status' => 'pending'],
        ];

        $items = (new TaskDueDates($this->client))->between(self::BAKERY, $this->at('2026-11-16 00:00', 'Asia/Amman'), $this->at('2026-11-23 00:00', 'Asia/Amman'), new DateTimeZone('Asia/Amman'), [41]);

        self::assertNotNull($items);
        self::assertSame(['task_due:a1'], array_column($items, 'id'));
        self::assertSame('Order flour', $items[0]['title']);
        self::assertTrue($items[0]['all_day']);
        self::assertFalse($items[0]['busy'], 'a task due never blocks time');
        self::assertSame('2026-11-16T21:00:00Z', $items[0]['starts_at'], 'midnight in Amman (UTC+3)');
        self::assertSame(41, $items[0]['organiser_id']);
        self::assertSame(['open' => true, 'move' => false, 'resize' => false, 'cancel' => false], $items[0]['can']);
        self::assertSame('whity:4:person:41', $this->calls[0]['assignee_ref'] ?? null, 'only that person\'s tasks');
        self::assertSame('open', $this->calls[0]['status'] ?? null);
    }

    public function testTaskaatAwayLeavesTheRestOfTheCalendarAlone(): void
    {
        $this->down = true;

        self::assertNull((new TaskDueDates($this->client))->between(self::BAKERY, $this->at('2026-11-16 00:00'), $this->at('2026-11-23 00:00'), new DateTimeZone('UTC')));
    }

    public function testAWorkspaceWithoutTaskaatHasNoTasksAndAsksNothing(): void
    {
        self::assertSame([], (new TaskDueDates($this->client))->between(5, $this->at('2026-11-16 00:00'), $this->at('2026-11-23 00:00'), new DateTimeZone('UTC')));
        self::assertSame([], $this->calls);
    }

    private function at(string $local, string $zone = 'UTC'): DateTimeImmutable
    {
        return (new DateTimeImmutable($local, new DateTimeZone($zone)))->setTimezone(new DateTimeZone('UTC'));
    }
}
