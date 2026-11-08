<?php

declare(strict_types=1);

namespace Tests\Integration\Scheduler;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Cli\Commands\ScheduleRunCommand;
use Whity\Core\Queue\JobRepository;
use Whity\Core\Queue\QueueService;
use Whity\Core\Scheduler\PluginSchedules;
use Whity\Core\Scheduler\ScheduledJobRepository;
use Whity\Core\Store\ArraySharedStore;
use Whity\Sdk\JobInterface;
use Whity\Sdk\Jobs\PluginScheduledJobsInterface;
use Whity\Sdk\PluginJobsInterface;

/**
 * Declared plugin schedules (WHIT-1031): one row per workspace, left alone when
 * unchanged, and gone when the plugin withdraws them.
 */
final class PluginSchedulesRealEngineTest extends TestCase
{
    private const JOB = 'acme:catalog.sync';
    private const NOT_READABLE = ['schedules' => [], 'unreadable' => ['acme']];

    private PDO $pdo;
    private ScheduledJobRepository $repo;
    private PluginSchedules $sync;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a', 'a'), (2, 'b', 'b') ON CONFLICT DO NOTHING");
        $this->repo = new ScheduledJobRepository($this->pdo);
        $this->sync = new PluginSchedules($this->repo);
    }

    public function testAScheduleGetsOneRowPerWorkspaceAndNoneForTheSystemTenant(): void
    {
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (0, 'system', 'system') ON CONFLICT DO NOTHING");

        $this->sync->sync(self::declared());

        self::assertSame([1, 2], $this->tenantsWithTheSchedule());
    }

    public function testACoreScheduleIsNeverTouched(): void
    {
        $id = $this->repo->register(1, 'core.something', '0 0 * * *');

        $this->sync->sync(['schedules' => [], 'unreadable' => []]);

        self::assertNotNull($this->repo->find(1, $id));
    }

    public function testAnUnchangedScheduleKeepsItsPauseAndNextRun(): void
    {
        $this->sync->sync(self::declared());
        $row = $this->row(1);
        $this->repo->setEnabled(1, (int) $row['id'], false);
        $this->pdo->exec("UPDATE scheduled_jobs SET next_run_at = '2030-01-01 00:00:00' WHERE id = " . (int) $row['id']);

        $this->sync->sync(self::declared());

        $after = $this->row(1);
        self::assertFalse($after['enabled']);
        self::assertSame('2030-01-01 00:00:00', $after['next_run_at']);
    }

    public function testAChangedCronIsAppliedAndAPauseSurvives(): void
    {
        $this->sync->sync(self::declared());
        $this->repo->setEnabled(1, (int) $this->row(1)['id'], false);

        $this->sync->sync(['schedules' => [self::JOB => '30 2 * * *'], 'unreadable' => []]);

        self::assertSame('30 2 * * *', $this->row(1)['cron_expression']);
        self::assertFalse($this->row(1)['enabled']);
    }

    public function testAWithdrawnScheduleIsRemovedFromEveryWorkspace(): void
    {
        $this->sync->sync(self::declared());

        $this->sync->sync(['schedules' => [], 'unreadable' => []]);

        self::assertSame([], $this->repo->listPluginSchedules());
    }

    public function testAnUnreadableDeclarationLeavesTheSchedulesAlone(): void
    {
        $this->sync->sync(self::declared());

        $this->sync->sync(self::NOT_READABLE);

        self::assertCount(2, $this->repo->listPluginSchedules());
    }

    public function testADeclarationIsNamespacedAndChecked(): void
    {
        self::assertSame([self::JOB => '0 * * * *'], PluginSchedules::of(self::plugin(['catalog.sync' => '0 * * * *']), 'acme'));
    }

    public function testANameThePluginDoesNotShipIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PluginSchedules::of(self::plugin(['other:job' => '0 * * * *']), 'acme');
    }

    public function testABadCronIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PluginSchedules::of(self::plugin(['catalog.sync' => 'hourly']), 'acme');
    }

    public function testTheTickSyncsAndRunsTheJobUnderEachWorkspaceAndKeepsItReadable(): void
    {
        $jobs = new JobRepository($this->pdo);
        $command = new ScheduleRunCommand(
            $this->repo,
            new QueueService($jobs),
            new ArraySharedStore(),
            $jobs,
            null,
            static function (int $seconds): void {
            },
            null,
            fn () => $this->sync->sync(self::declared()),
        );
        $this->sync->sync(self::declared());
        $this->pdo->exec("UPDATE scheduled_jobs SET next_run_at = '2020-01-01 00:00:00'");

        self::assertSame(2, $command->tick(300, 86400));

        $stmt = $this->pdo->query("SELECT tenant_id, retain_result FROM jobs WHERE name = '" . self::JOB . "' ORDER BY tenant_id");
        self::assertNotFalse($stmt);
        /** @var list<array{tenant_id: int|string, retain_result: mixed}> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame([1, 2], array_map(static fn (array $r): int => (int) $r['tenant_id'], $rows));
        self::assertTrue((bool) $rows[0]['retain_result']);
    }

    /** @return array{schedules: array<string, string>, unreadable: list<string>} */
    private static function declared(): array
    {
        return ['schedules' => [self::JOB => '0 * * * *'], 'unreadable' => []];
    }

    /** @return array<string, mixed> */
    private function row(int $tenantId): array
    {
        foreach ($this->repo->listForTenant($tenantId) as $row) {
            if ($row['name'] === self::JOB) {
                return $row;
            }
        }
        self::fail('No schedule row.');
    }

    /** @return list<int> */
    private function tenantsWithTheSchedule(): array
    {
        $ids = array_map(static fn (array $row): int => (int) $row['tenant_id'], $this->repo->listPluginSchedules());
        sort($ids);

        return $ids;
    }

    /** @param array<string, string> $schedules */
    private static function plugin(array $schedules): PluginScheduledJobsInterface&PluginJobsInterface
    {
        return new class ($schedules) implements PluginScheduledJobsInterface, PluginJobsInterface {
            /** @param array<string, string> $schedules */
            public function __construct(private array $schedules)
            {
            }

            public function getScheduledJobs(): array
            {
                return $this->schedules;
            }

            public function getJobs(): array
            {
                return ['catalog.sync' => new class implements JobInterface {
                    public function handle(array $payload): array
                    {
                        return [];
                    }
                }];
            }

            public function getSubmittableJobs(): array
            {
                return [];
            }
        };
    }
}
