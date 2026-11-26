<?php

declare(strict_types=1);

namespace Whity\Cli\Commands;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Whity\Core\Queue\JobRepository;
use Whity\Core\Queue\QueueService;
use Whity\Core\Scheduler\CronExpression;
use Whity\Core\Scheduler\ScheduledJobRepository;
use Whity\Core\Scheduler\SchedulerHeartbeat;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Store\DatabaseSharedStore;
use Whity\Core\Store\SharedStoreInterface;
use Whity\Database\Database;

/**
 * `schedule:run` — the cron-tick scheduler (WC-scheduler / #a934420e).
 *
 * Each TICK:
 *  1. Acquire an EXACTLY-ONCE-PER-MINUTE lock across all workers via the shared
 *     store: `increment('scheduler:tick:<YYYYMMDDHHMM>', ttl)`. The FIRST worker
 *     to increment this minute's key gets counter == 1 and owns the tick; every
 *     other worker sees ≥ 2 and skips. This is what makes N schedulers safe.
 *  2. Claim every ENABLED schedule whose `next_run_at <= now` (across tenants),
 *     enqueue each onto the durable jobs queue under its ORIGIN tenant (with a
 *     per-(schedule, minute) idempotency key as belt-and-suspenders), and advance
 *     `next_run_at` to the cron's next occurrence — a missed minute catches up
 *     ONCE, never replays.
 *  3. Run built-in retention GC: prune completed jobs older than the retention
 *     window (wires JobRepository::pruneCompleted into the scheduler).
 *  4. Record the tick's heartbeat ({@see SchedulerHeartbeat}), whether or not
 *     anything was due, so the health probe sees the tick alive (WHIT-1013).
 *
 * `--once` runs a single tick and exits (cron-style / tests); otherwise it loops
 * (sleep between ticks), recycling on `--max-runtime` so a supervisor respawns a
 * fresh process (bounding memory, like queue:work). All times are UTC.
 *
 * No-arg constructable (Database::connect) so CliRunner + public/index.php can
 * dispatch it; deps are injectable for tests.
 */
final class ScheduleRunCommand implements CliCommand
{
    private ScheduledJobRepository $schedules;
    private QueueService $queue;
    private SharedStoreInterface $store;
    private JobRepository $jobs;
    private LoggerInterface $logger;
    /** @var \Closure(int): void */
    private \Closure $sleeper;
    /** @var (\Closure(): void)|null */
    private ?\Closure $syncPluginSchedules;
    private bool $pluginSchedulesSynced = false;
    private ?SchedulerHeartbeat $heartbeat;
    private ?GlobalSettingsRepository $globals;

    /**
     * #2205: the scheduler's repositories hold the raw PDO, so `Database`'s
     * own connection lifetime never rotates it; the scheduler recycles once it
     * has run that long. Null: never, as when a test injects its own deps.
     */
    private ?int $connectionMaxAge = null;

    public function recycleAfterConnectionAge(?int $seconds): void
    {
        $this->connectionMaxAge = $seconds;
    }

    /**
     * @param \Closure(int): void|null $sleeper Idle sleeper (seconds); default sleep(), a no-op in tests.
     * @param (\Closure(): void)|null $syncPluginSchedules Reconciles plugins' declared schedules (WHIT-1031).
     * @param SchedulerHeartbeat|null $heartbeat Where each completed tick is recorded (WHIT-1013).
     */
    public function __construct(
        ?ScheduledJobRepository $schedules = null,
        ?QueueService $queue = null,
        ?SharedStoreInterface $store = null,
        ?JobRepository $jobs = null,
        ?LoggerInterface $logger = null,
        ?\Closure $sleeper = null,
        private ?\Whity\Core\Plugin\PluginRecords $pluginRecords = null,
        ?\Closure $syncPluginSchedules = null,
        ?SchedulerHeartbeat $heartbeat = null,
        ?GlobalSettingsRepository $globals = null
    ) {
        $this->logger = $logger ?? new NullLogger();
        if ($schedules === null || $queue === null || $store === null || $jobs === null) {
            $database = Database::connect();
            $pdo = $database->getPdo();
            $this->connectionMaxAge = $database->getMaxLifetimeSeconds() > 0 ? $database->getMaxLifetimeSeconds() : null;
            $this->pluginRecords ??= new \Whity\Core\Plugin\PluginRecords($pdo);
            $schedules ??= new ScheduledJobRepository($pdo);
            $syncPluginSchedules ??= $this->pluginScheduleSync($schedules);
            $queue ??= new QueueService(new JobRepository($pdo));
            $store ??= new DatabaseSharedStore($pdo);
            $jobs ??= new JobRepository($pdo);
            $globals ??= new GlobalSettingsRepository($pdo);
            $heartbeat ??= new SchedulerHeartbeat($globals);
        }
        $this->heartbeat = $heartbeat;
        $this->globals = $globals;
        $this->syncPluginSchedules = $syncPluginSchedules;
        $this->schedules = $schedules;
        $this->queue = $queue;
        $this->store = $store;
        $this->jobs = $jobs;
        $this->sleeper = $sleeper ?? static function (int $seconds): void {
            if ($seconds > 0) {
                sleep($seconds);
            }
        };
    }

    /**
     * @param list<string> $argv
     */
    public function execute(array $argv): int
    {
        $opts = self::parseOptions($argv);
        $startedAt = time();

        $this->logger->info('[schedule:run] started', ['once' => $opts['once']]);

        while (true) {
            $this->tick($opts['lockTtl'], $opts['retention']);
            // #2205: nothing resolved during one tick is trusted in the next.
            \Whity\Auth\RoleChecker::clearCache();

            if ($opts['once']) {
                break;
            }
            ($this->sleeper)($opts['sleep']);

            if ($opts['maxRuntime'] > 0 && (time() - $startedAt) >= $opts['maxRuntime']) {
                $this->logger->info('[schedule:run] recycling (max-runtime reached)', ['seconds' => time() - $startedAt]);
                break;
            }
            if ($this->connectionMaxAge !== null && (time() - $startedAt) >= $this->connectionMaxAge) {
                $this->logger->info('[schedule:run] recycling (database connection lifetime reached)', ['seconds' => time() - $startedAt]);
                break;
            }
        }

        return 0;
    }

    /**
     * Run one tick. Returns the number of schedules dispatched (0 if this worker
     * did not win the minute's exactly-once lock).
     */
    public function tick(int $lockTtl, int $retentionSeconds): int
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $bucket = $now->format('YmdHi');

        // Exactly-once-per-minute across workers: only the first incrementer runs.
        if ($this->store->increment('scheduler:tick:' . $bucket, $lockTtl) !== 1) {
            $this->logger->debug('[schedule:run] tick skipped — another worker owns this minute', ['bucket' => $bucket]);

            return 0;
        }

        // WHIT-1031: declared plugin schedules, on start and every five minutes.
        if ($this->syncPluginSchedules !== null && (!$this->pluginSchedulesSynced || (int) $now->format('i') % 5 === 0)) {
            $this->pluginSchedulesSynced = true;
            try {
                ($this->syncPluginSchedules)();
            } catch (\Throwable $e) {
                $this->logger->error('[schedule:run] plugin schedule sync failed', ['error' => $e->getMessage()]);
            }
        }

        $nowStr = $now->format('Y-m-d H:i:s');
        $dispatched = 0;
        foreach ($this->schedules->claimDue($nowStr) as $schedule) {
            $id = (int) $schedule['id'];
            $tenantId = (int) $schedule['tenant_id'];
            $name = (string) $schedule['name'];

            try {
                $this->queue->dispatch($name, is_array($schedule['payload']) ? $schedule['payload'] : [], [
                    'tenant_id'       => $tenantId,
                    'queue'           => (string) $schedule['queue'],
                    'idempotency_key' => 'sched:' . $id . ':' . $bucket,
                    // A plugin can read its own scheduled runs back (WHIT-1031).
                    'retain_result'   => str_contains($name, ':'),
                ]);
                $next = (new CronExpression((string) $schedule['cron_expression']))->nextRunAfter($now)->format('Y-m-d H:i:s');
                $this->schedules->markRan($id, $nowStr, $next);
                $dispatched++;
            } catch (\Throwable $e) {
                // A bad cron / enqueue failure on one schedule must not abort the
                // whole tick. Log and continue; the row keeps its old next_run_at
                // and is retried next tick.
                $this->logger->error('[schedule:run] failed to dispatch schedule', [
                    'schedule_id' => $id,
                    'name'        => $name,
                    'tenant_id'   => $tenantId,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        // Built-in retention GC for the durable queue's retained completed jobs.
        $pruned = $this->jobs->pruneCompleted($retentionSeconds);

        // WHIT-1229 (WHIT-315): expired shared-store rows. prune() existed but
        // nothing called it, and several counters are keyed by the minute, hour
        // or day (push budgets, alert and summary e-mails), so every elapsed
        // window stayed in the table for good. One indexed range delete per
        // tick; a failure must not fail the tick.
        $storeExpired = 0;
        try {
            $storeExpired = $this->store->prune();
        } catch (\Throwable $e) {
            $this->logger->error('[schedule:run] shared store prune failed', ['error' => $e->getMessage()]);
        }

        // S.7: uninstalled plugins whose 30 days are over. Once an hour is plenty,
        // and the queue worker does it because that's where plugin code is loaded.
        if ($this->pluginRecords !== null && $this->pluginRecords->dueRemovals() !== []) {
            $this->queue->dispatch(\Whity\Core\Queue\Jobs\PurgeRemovedPluginsJob::NAME, [], [
                'tenant_id' => 0,
                'idempotency_key' => 'plugins-purge-' . gmdate('YmdH'),
            ]);
        }

        // WHIT-1014: the Whity store's Verified marks, every 15 minutes. The job fetches only
        // what is due, so a deployment with no store-installed plugin does nothing.
        if ((int) $now->format('i') % 15 === 0) {
            $this->queue->dispatch(\Whity\Core\Queue\Jobs\RefreshStoreMarksJob::NAME, [], [
                'tenant_id' => 0,
                'idempotency_key' => 'plugins-store-marks-' . $bucket,
            ]);
        }

        // #2206: core's own subscription billing (once an hour) and dunning
        // (once a day), only where the operator turned local billing on. Off by
        // default: workspace subscriptions belong to Whity Pay, and these runs
        // issue invoices and lock tenants. Both runs are safe to repeat; the
        // keys keep it to one job per hour and per day.
        if ($this->localBillingEnabled()) {
            $this->queue->dispatch(\Whity\Core\Billing\Jobs\RunSubscriptionBillingJob::NAME, [], [
                'tenant_id' => 0,
                'idempotency_key' => 'billing-run-' . $now->format('YmdH'),
            ]);
            $this->queue->dispatch(\Whity\Core\Billing\Jobs\RunDunningJob::NAME, [], [
                'tenant_id' => 0,
                'idempotency_key' => 'billing-dunning-' . $now->format('Ymd'),
            ]);
        }

        // WHIT-1153: cash payments about to end, once an hour; each payment's
        // reminder goes out once.
        if ((int) $now->format('i') === 0) {
            $this->queue->dispatch(\Whity\Core\Billing\Cash\CashRemindersJob::NAME, [], [
                'tenant_id' => 0,
                'idempotency_key' => 'billing-cash-reminders-' . $bucket,
            ]);
        }

        // WHIT-1235: batches expiring and expired, once a day at 05:00 UTC;
        // each batch is told once.
        if ($now->format('H:i') === '05:00') {
            $this->queue->dispatch(\Whity\Core\Inventory\LotExpiryJob::NAME, [], [
                'tenant_id' => 0,
                'idempotency_key' => 'inventory-lot-expiry-' . $now->format('Ymd'),
            ]);
        }

        // EM.1: the agents' daily summary, once an hour; the job sends only
        // where it is 18:00, and at most once a day per person.
        if ((int) $now->format('i') === 0) {
            $this->queue->dispatch(\Whity\Core\Notification\Jobs\AgentDailySummaryJob::NAME, [], [
                'tenant_id' => 0,
                'idempotency_key' => 'agents-daily-summary-' . $bucket,
            ]);
        }

        // WHIT-1427 (AP.4): end-of-day marking, once an hour; each workspace
        // that turned it on marks only its own ended days.
        if ((int) $now->format('i') === 0) {
            $this->queue->dispatch(\Whity\Core\Calendar\AttendanceAutoMarkJob::NAME, [], [
                'tenant_id' => 0,
                'idempotency_key' => 'calendar-attendance-auto-' . $bucket,
            ]);
        }

        // WHIT-1417 (AP.14): booking reminders the queue lost, every five
        // minutes; each reminder is claimed when sent, so it goes out once.
        if ((int) $now->format('i') % 5 === 0) {
            $this->queue->dispatch(\Whity\Core\Calendar\BookingReminderSweepJob::NAME, [], [
                'tenant_id' => 0,
                'idempotency_key' => 'calendar-reminder-sweep-' . $bucket,
            ]);
        }

        // WHIT-1339: settled audit rows into the hash chain, every five minutes.
        if ((int) $now->format('i') % 5 === 0) {
            $this->queue->dispatch(\Whity\Core\Audit\AuditSealJob::NAME, [], [
                'tenant_id' => 0,
                'idempotency_key' => 'audit-seal-' . $bucket,
            ]);
        }

        // WHIT-931: MCP idempotency keys older than a day, once an hour.
        if ((int) $now->format('i') === 0) {
            $this->queue->dispatch(\Whity\Mcp\Tools\McpCallKeysPruneJob::NAME, [], [
                'tenant_id' => 0,
                'idempotency_key' => 'mcp-call-keys-prune-' . $bucket,
            ]);
        }

        // Section 33 §5: the usage ledger's snapshot meters, once a day. Every
        // hour tries, and the day's key keeps it to one run.
        if ((int) $now->format('i') === 0) {
            $this->queue->dispatch(\Whity\Core\Usage\UsageSnapshotJob::NAME, [], [
                'tenant_id' => 0,
                'idempotency_key' => 'usage-snapshot-' . $now->format('Ymd'),
            ]);
        }

        // WHIT-1321: sent quotes past their valid-until date, once an hour.
        if ((int) $now->format('i') === 0) {
            $this->queue->dispatch(\Whity\Core\Sales\QuoteExpiryJob::NAME, [], [
                'tenant_id' => 0,
                'idempotency_key' => 'sales-quote-expiry-' . $bucket,
            ]);
        }

        // WHIT-1013: proof of life, due or not. A failed write must not fail the tick.
        try {
            $this->heartbeat?->beat($now->getTimestamp());
        } catch (\Throwable $e) {
            $this->logger->error('[schedule:run] heartbeat write failed', ['error' => $e->getMessage()]);
        }

        $this->logger->info('[schedule:run] tick complete', [
            'bucket'     => $bucket,
            'dispatched' => $dispatched,
            'pruned'     => $pruned,
            'store_expired' => $storeExpired,
        ]);

        return $dispatched;
    }

    /** #2206: a failed read counts as off, and must not fail the tick. */
    private function localBillingEnabled(): bool
    {
        if ($this->globals === null) {
            return false;
        }
        try {
            return $this->globals->get(\Whity\Core\Settings\SettingsRegistry::BILLING_LOCAL_RUNS_ENABLED) === 'true';
        } catch (\Throwable $e) {
            $this->logger->error('[schedule:run] could not read the local billing setting', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * @return \Closure(): void
     */
    private function pluginScheduleSync(ScheduledJobRepository $schedules): \Closure
    {
        $loader = null;

        return function () use (&$loader, $schedules): void {
            if ($loader === null) {
                $loader = \Whity\Core\Queue\PluginJobs::register(new \Whity\Core\Queue\JobRegistry(), null, $this->logger);
            } else {
                $loader->reload();
            }
            if ($loader !== null) {
                (new \Whity\Core\Scheduler\PluginSchedules($schedules, $this->logger))->sync($loader->collectSchedules());
            }
        };
    }

    /**
     * @param list<string> $argv
     * @return array{once: bool, sleep: int, maxRuntime: int, lockTtl: int, retention: int}
     */
    private static function parseOptions(array $argv): array
    {
        return [
            'once'       => in_array('--once', $argv, true),
            'sleep'      => self::intOpt($argv, 'sleep', 60),          // seconds between ticks
            'maxRuntime' => self::intOpt($argv, 'max-runtime', 0),     // seconds; 0 = unlimited
            'lockTtl'    => self::intOpt($argv, 'lock-ttl', 300),      // per-minute tick lock TTL (s)
            'retention'  => self::intOpt($argv, 'job-retention', 86400), // completed-job retention (s)
        ];
    }

    /**
     * @param list<string> $argv
     */
    private static function intOpt(array $argv, string $name, int $default): int
    {
        foreach ($argv as $arg) {
            if (str_starts_with($arg, "--{$name}=")) {
                return max(0, (int) substr($arg, strlen($name) + 3));
            }
        }

        return $default;
    }
}
