<?php

declare(strict_types=1);

namespace Tests\Integration\Queue;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\RoleChecker;
use Whity\Core\Queue\JobRegistry;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Database\Database;
use Whity\Core\Queue\JobRepository;
use Whity\Core\Queue\JobRunner;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\JobInterface;

/**
 * Real-engine tests for {@see JobRunner} (WC-queue): reserve→run→complete, the
 * per-job tenant restore (+ reset afterwards), retry then dead-letter on
 * repeated failure, and dead-lettering a job whose handler is not registered.
 */
final class JobRunnerRealEngineTest extends TestCase
{
    private const TENANT = 1;

    private PDO $pdo;
    private JobRepository $repo;
    private JobRegistry $registry;
    private JobRunner $runner;

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a', 'a'), (2, 'b', 'b')");
        $this->repo = new JobRepository($this->pdo);
        $this->registry = new JobRegistry();
        $this->runner = new JobRunner($this->repo, $this->registry);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testProcessNextRunsTheHandlerThenCompletesTheJob(): void
    {
        $ran = [];
        $this->registry->register('demo', $this->handler(static function (array $payload) use (&$ran): void {
            $ran[] = $payload;
        }));
        $this->repo->enqueue(self::TENANT, 'demo', ['n' => 1]);

        self::assertTrue($this->runner->processNext());
        self::assertSame([['n' => 1]], $ran);
        self::assertSame(0, $this->countJobs(), 'a completed job is removed');
        self::assertFalse($this->runner->processNext(), 'queue is now empty');
    }

    public function testHandlerRunsUnderTheJobsTenantAndContextResetsAfter(): void
    {
        $seenTenant = null;
        $this->registry->register('scoped', $this->handler(static function () use (&$seenTenant): void {
            $seenTenant = TenantContext::getTenantId();
        }));
        $this->repo->enqueue(2, 'scoped', []); // tenant 2

        $this->runner->processNext();

        self::assertSame(2, $seenTenant, 'the handler saw its job\'s origin tenant');
        self::assertNull(TenantContext::getTenantId(), 'tenant context is reset after the job');
    }

    /**
     * #2205: a permission revoked between two jobs must be gone in the second.
     * RoleChecker's caches are process-wide, so without a reset per job a
     * long-running worker kept serving the old answer until it recycled.
     */
    public function testAPermissionRevokedBetweenJobsIsGoneInTheNextJob(): void
    {
        $pdo = $this->pdo;
        $this->pdo->exec("INSERT INTO roles (name, description, tenant_id, created_at) VALUES ('clerk', '', 1, NOW())");
        $roleId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT OR IGNORE INTO permissions (name, created_at) VALUES ('users:read', NOW())");
        $this->pdo->exec("INSERT INTO role_permissions (role_id, permission_id, created_at)
            SELECT {$roleId}, id, NOW() FROM permissions WHERE name = 'users:read'");

        $checker = new RoleChecker(Database::withFactory(static fn (): PDO => $pdo), new PermissionRegistry());
        $seen = [];
        $this->registry->register('check', $this->handler(static function () use ($checker, $roleId, &$seen): void {
            $seen[] = $checker->getEffectivePermissionsForRole($roleId);
        }));
        $resets = 0;
        $this->runner->setAfterJob(static function () use (&$resets): void {
            $resets++;
        });

        $this->repo->enqueue(self::TENANT, 'check', []);
        $this->runner->processNext();
        $this->pdo->exec("DELETE FROM role_permissions WHERE role_id = {$roleId}");
        $this->repo->enqueue(self::TENANT, 'check', []);
        $this->runner->processNext();

        self::assertSame([['users:read'], []], $seen, 'the second job must not see the revoked permission');
        self::assertSame(2, $resets, 'the kernel\'s per-request reads are reset after every job');
        RoleChecker::clearCache();
    }

    public function testFailingHandlerIsRetriedThenDeadLettered(): void
    {
        $this->registry->register('boom', $this->handler(static function (): void {
            throw new \RuntimeException('nope');
        }));
        $id = (int) $this->repo->enqueue(self::TENANT, 'boom', [], ['max_attempts' => 2]);

        // Attempt 1: fails, attempts(1) < max(2) → rescheduled as pending.
        $this->runner->processNext();
        self::assertSame('pending', $this->row($id)['status']);

        // Attempt 2 (made due): fails, attempts(2) >= max(2) → dead-lettered.
        $this->makeDue($id);
        $this->runner->processNext();
        $row = $this->row($id);
        self::assertSame('dead', $row['status']);
        self::assertSame('nope', $row['last_error']);
    }

    public function testUnknownHandlerIsDeadLettered(): void
    {
        $id = (int) $this->repo->enqueue(self::TENANT, 'no.handler.for.this', []);
        $this->runner->processNext();
        self::assertSame('dead', $this->row($id)['status']);
    }

    public function testRetainedJobIsKeptAsCompletedWithTheHandlerResult(): void
    {
        // A job enqueued with retain_result keeps its row as 'completed' + result,
        // so the status API can return it (a fire-and-forget job is deleted).
        $this->registry->register('produce', $this->handler(
            static fn (array $payload): array => ['doubled' => (int) ($payload['n'] ?? 0) * 2]
        ));
        $id = (int) $this->repo->enqueue(self::TENANT, 'produce', ['n' => 21], ['retain_result' => true]);

        self::assertTrue($this->runner->processNext());

        $row = $this->row($id);
        self::assertSame('completed', $row['status'], 'a retained job is kept, not deleted');
        self::assertSame(100, (int) $row['progress']);
        self::assertSame(['doubled' => 42], json_decode((string) $row['result'], true), 'the handler result is persisted');
        self::assertSame(1, $this->countJobs(), 'the retained completed job remains in the table');
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function handler(callable $fn): JobInterface
    {
        return new class ($fn) implements JobInterface {
            /** @var callable */
            private $fn;

            public function __construct(callable $fn)
            {
                $this->fn = $fn;
            }

            public function handle(array $payload): array
            {
                $result = ($this->fn)($payload);

                return is_array($result) ? $result : [];
            }
        };
    }

    private function makeDue(int $id): void
    {
        $this->pdo->prepare('UPDATE jobs SET available_at = :past WHERE id = :id')
            ->execute([':past' => date('Y-m-d H:i:s', time() - 60), ':id' => $id]);
    }

    private function countJobs(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM jobs');

        return $stmt === false ? 0 : (int) $stmt->fetchColumn();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM jobs WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? [] : $row;
    }
}
