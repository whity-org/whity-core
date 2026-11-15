<?php

declare(strict_types=1);

namespace Tests\Core\Health;

use PDO;
use PHPUnit\Framework\TestCase;
use Whity\Core\Health\HealthProbe;
use Whity\Core\Health\HealthSampleRepository;
use Whity\Core\Scheduler\SchedulerHeartbeat;
use Whity\Core\Settings\GlobalSettingsRepository;

/**
 * WHIT-1013: the scheduler is judged by its own tick heartbeat, not by
 * `scheduled_jobs.last_run_at`. An install with no schedules used to report
 * "unknown" forever while `schedule:run` ticked every minute, and the public
 * status page read "degraded" permanently.
 */
final class SchedulerHeartbeatHealthTest extends TestCase
{
    public function testARecentTickIsOperationalEvenWithNoScheduledJobs(): void
    {
        $pdo = $this->pdo();
        $this->beat($pdo, time() - 30);
        // scheduled_jobs exists and is empty: nothing is due, ever.
        self::assertSame('operational', $this->schedulerStatus($pdo));
    }

    public function testAStaleTickIsDown(): void
    {
        $pdo = $this->pdo();
        $this->beat($pdo, time() - 3600);

        self::assertSame('down', $this->schedulerStatus($pdo));
    }

    public function testASchedulerThatHasNeverTickedIsUnknown(): void
    {
        self::assertSame('unknown', $this->schedulerStatus($this->pdo()));
    }

    public function testTheHeartbeatRoundTripsThroughAppSettings(): void
    {
        $heartbeat = new SchedulerHeartbeat(new GlobalSettingsRepository($this->pdo()));
        self::assertNull($heartbeat->lastTick());

        $heartbeat->beat(1_700_000_000);
        $heartbeat->beat(1_700_000_060);

        self::assertSame(1_700_000_060, $heartbeat->lastTick());
    }

    private function beat(PDO $pdo, int $at): void
    {
        (new SchedulerHeartbeat(new GlobalSettingsRepository($pdo)))->beat($at);
    }

    private function schedulerStatus(PDO $pdo): string
    {
        $repo = new HealthSampleRepository($pdo);
        (new HealthProbe($pdo, $repo, null))->runAll();

        $latest = $repo->latestPerComponent();
        self::assertArrayHasKey('scheduler', $latest);

        return $latest['scheduler']['status'];
    }

    private function pdo(): PDO
    {
        $pdo = PDO::connect('sqlite::memory:');
        self::assertInstanceOf(\Pdo\Sqlite::class, $pdo);
        // GlobalSettingsRepository stamps updated_at with Postgres's NOW().
        $pdo->createFunction('NOW', static fn (): string => gmdate('Y-m-d H:i:s'), 0);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            "CREATE TABLE health_samples (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                component   TEXT NOT NULL,
                status      TEXT NOT NULL,
                source      TEXT NOT NULL DEFAULT 'internal',
                latency_ms  INTEGER,
                detail      TEXT,
                observed_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S', 'now'))
            )"
        );
        $pdo->exec('CREATE TABLE jobs (id INTEGER PRIMARY KEY, status TEXT, available_at TEXT)');
        $pdo->exec('CREATE TABLE scheduled_jobs (id INTEGER PRIMARY KEY, enabled INTEGER, last_run_at TEXT)');
        $pdo->exec('CREATE TABLE app_settings (setting_key TEXT PRIMARY KEY, value TEXT NOT NULL, updated_at TEXT)');

        return $pdo;
    }
}
