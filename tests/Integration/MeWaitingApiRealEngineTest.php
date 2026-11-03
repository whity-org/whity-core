<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\MeWaitingApiHandler;
use Whity\Core\Agent\AgentProposalRepository;
use Whity\Core\Inbox\InboxItem;
use Whity\Core\Inbox\InboxSourceInterface;
use Whity\Core\Inbox\InboxSourceRegistry;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;

/**
 * The merged waiting list (WHIT-872, H.2): oldest first across sources, one failing
 * source doesn't blank it, the real totals, and only the caller's own rows in the
 * caller's own workspace.
 */
final class MeWaitingApiRealEngineTest extends TestCase
{
    private const RANA = 101;
    private const OMAR = 202;

    private PDO $pdo;
    private AgentProposalRepository $proposals;
    private InboxSourceRegistry $sources;

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'bakery'), (2, 'Example Pharmacy', 'pharmacy')");
        foreach ([self::RANA => 'Rana', self::OMAR => 'Omar'] as $id => $name) {
            $this->pdo->prepare("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                                 VALUES (?, ?, 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)")->execute([$id, $name]);
        }
        $this->proposals = new AgentProposalRepository($this->pdo);
        $this->sources = new InboxSourceRegistry();
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testInboxItemsAndProposalsMergeOldestFirst(): void
    {
        $this->sources->registerCoreSource('routing', self::source([
            [1, self::RANA, 'new document', '2026-11-03 09:00:00'],
            [1, self::RANA, 'old document', '2026-11-01 09:00:00'],
        ]));
        $this->propose(1, self::RANA, 'middle proposal', '2026-11-02 09:00:00');

        $body = $this->waiting(1, self::RANA);

        self::assertSame(
            ['old document', 'middle proposal', 'new document'],
            array_map(static fn (array $row): string => $row['item']['title'] ?? $row['proposal']['summary'], $body['data'])
        );
        self::assertSame(['inbox', 'proposal', 'inbox'], array_column($body['data'], 'kind'));
        self::assertSame(['inbox' => 2, 'proposals' => 1, 'total' => 3], $body['counts']);
        self::assertSame([], $body['unavailable']);
    }

    public function testTheLimitKeepsTheOldestAndTheTotalsStayReal(): void
    {
        // Eight rows, newest first as a source lists them: the oldest three must
        // come back, which is the source's LAST page and not its first.
        $rows = [];
        for ($day = 8; $day >= 1; $day--) {
            $rows[] = [1, self::RANA, "day {$day}", "2026-11-0{$day} 09:00:00"];
        }
        $this->sources->registerCoreSource('routing', self::source($rows));

        $body = $this->waiting(1, self::RANA, 3);

        self::assertSame(['day 1', 'day 2', 'day 3'], array_map(static fn (array $row): string => $row['item']['title'], $body['data']));
        self::assertSame(8, $body['counts']['total']);
    }

    public function testAFailingSourceIsNamedAndTheRestIsServed(): void
    {
        $this->sources->registerCoreSource('broken', new class () implements InboxSourceInterface {
            public function label(): string
            {
                return 'Broken';
            }

            public function list(int $tenantId, int $profileId, bool $openOnly, int $limit, int $offset): array
            {
                throw new RuntimeException('down');
            }

            public function count(int $tenantId, int $profileId, bool $openOnly): int
            {
                throw new RuntimeException('down');
            }
        });
        $this->sources->registerCoreSource('routing', self::source([[1, self::RANA, 'a document', '2026-11-01 09:00:00']]));

        $body = $this->waiting(1, self::RANA);

        self::assertSame(['broken'], $body['unavailable']);
        self::assertCount(1, $body['data']);
        self::assertSame(1, $body['counts']['total']);
    }

    public function testOnlyTheCallersOwnRowsInTheirOwnWorkspace(): void
    {
        $this->sources->registerCoreSource('routing', self::source([
            [1, self::RANA, 'mine', '2026-11-01 09:00:00'],
            [1, self::OMAR, 'a colleague\'s', '2026-11-01 09:00:00'],
            [2, self::RANA, 'my other workspace', '2026-11-01 09:00:00'],
        ]));
        $this->propose(1, self::RANA, 'my proposal', '2026-11-01 10:00:00');
        $this->propose(1, self::OMAR, 'a colleague\'s proposal', '2026-11-01 10:00:00');
        $this->propose(2, self::RANA, 'other workspace proposal', '2026-11-01 10:00:00');

        $body = $this->waiting(1, self::RANA);

        self::assertSame(
            ['mine', 'my proposal'],
            array_map(static fn (array $row): string => $row['item']['title'] ?? $row['proposal']['summary'], $body['data'])
        );
        self::assertSame(['inbox' => 1, 'proposals' => 1, 'total' => 2], $body['counts']);
    }

    public function testAProposalAlreadyDecidedNoLongerWaits(): void
    {
        $id = $this->propose(1, self::RANA, 'decided', '2026-11-01 10:00:00');
        $this->proposals->decide($id, 1, self::RANA, AgentProposalRepository::DECLINED);

        $body = $this->waiting(1, self::RANA);

        self::assertSame([], $body['data']);
        self::assertSame(0, $body['counts']['proposals']);
    }

    public function testAnUnauthenticatedCallerIsRefused(): void
    {
        TenantContext::setTenantId(1);
        $response = (new MeWaitingApiHandler($this->sources, $this->proposals))->list(new Request('GET', '/api/me/waiting'));

        self::assertSame(401, $response->getStatusCode());
    }

    /** @return array<string, mixed> */
    private function waiting(int $tenantId, int $profileId, ?int $limit = null): array
    {
        TenantContext::setTenantId($tenantId);
        $request = new Request('GET', '/api/me/waiting' . ($limit !== null ? "?limit={$limit}" : ''));
        $request->user = (object) ['profile_id' => $profileId];

        $response = (new MeWaitingApiHandler($this->sources, $this->proposals))->list($request);
        self::assertSame(200, $response->getStatusCode(), $response->getBody());

        return json_decode($response->getBody(), true);
    }

    private function propose(int $tenantId, int $profileId, string $summary, string $createdAt): int
    {
        $id = $this->proposals->create($tenantId, 'jti-1', $profileId, 'forms_create', [], [], ['forms:manage'], $summary);
        $this->pdo->prepare('UPDATE agent_proposals SET created_at = ? WHERE id = ?')->execute([$createdAt, $id]);

        return $id;
    }

    /**
     * A source that answers as a real one does: scoped to the tenant and person
     * asked about, newest first in the order given, honouring limit and offset.
     *
     * @param list<array{0: int, 1: int, 2: string, 3: string}> $rows tenant, profile, title, timestamp
     */
    private static function source(array $rows): InboxSourceInterface
    {
        return new class ($rows) implements InboxSourceInterface {
            /** @param list<array{0: int, 1: int, 2: string, 3: string}> $rows */
            public function __construct(private readonly array $rows)
            {
            }

            public function label(): string
            {
                return 'Routing';
            }

            public function list(int $tenantId, int $profileId, bool $openOnly, int $limit, int $offset): array
            {
                $items = [];
                foreach ($this->mine($tenantId, $profileId) as $i => $row) {
                    $items[] = new InboxItem((string) $i, $row[2], null, $row[3], null, null, null);
                }

                return array_slice($items, $offset, $limit);
            }

            public function count(int $tenantId, int $profileId, bool $openOnly): int
            {
                return count($this->mine($tenantId, $profileId));
            }

            /** @return list<array{0: int, 1: int, 2: string, 3: string}> */
            private function mine(int $tenantId, int $profileId): array
            {
                return array_values(array_filter(
                    $this->rows,
                    static fn (array $row): bool => $row[0] === $tenantId && $row[1] === $profileId
                ));
            }
        };
    }
}
