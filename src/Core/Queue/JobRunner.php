<?php

declare(strict_types=1);

namespace Whity\Core\Queue;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Whity\Auth\RoleChecker;
use Whity\Core\Audit\AuditContext;
use Whity\Core\Tenant\TenantContext;

/**
 * The consumer-side of the durable queue: reserve the next runnable job,
 * restore its origin tenant, run its handler, and record the outcome
 * (complete / retry-with-backoff / dead-letter).
 *
 * {@see self::processNext()} does ONE reserve+run; a long-running `queue:work`
 * worker process (a separate task) simply loops it. It is written so the loop
 * body is fully testable without a real worker daemon.
 *
 * Per-job isolation mirrors the FrankenPHP request loop: TenantContext is set
 * to the job's tenant before the handler and RESET afterwards (along with
 * AuditContext, the RoleChecker permission caches and the per-request reads
 * the web kernel resets, #2205), so no per-job state leaks into the next job in
 * a persistent worker. Without the cache reset a permission revoked on the web
 * stayed live in `queue:work` until it recycled, up to an hour.
 */
final class JobRunner
{
    private JobRepository $repo;
    private JobRegistry $registry;
    private LoggerInterface $logger;

    public function __construct(
        JobRepository $repo,
        JobRegistry $registry,
        ?LoggerInterface $logger = null
    ) {
        $this->repo = $repo;
        $this->registry = $registry;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Plugins switched off for a workspace (WHIT-1123). A job a plugin queued
     * for a workspace where it is now off is completed without running.
     * Null: every job runs, as before.
     */
    private ?\Whity\Core\Plugin\Workspace\CodePluginActivation $activation = null;

    public function setWorkspaceActivation(\Whity\Core\Plugin\Workspace\CodePluginActivation $activation): void
    {
        $this->activation = $activation;
    }

    /** What else to reset after every job: the web kernel's per-request reads (#2205). */
    private ?\Closure $afterJob = null;

    public function setAfterJob(\Closure $afterJob): void
    {
        $this->afterJob = $afterJob;
    }

    /** The per-job reset, after every job however it ended. */
    private function resetAfterJob(): void
    {
        TenantContext::reset();
        AuditContext::reset();
        RoleChecker::clearCache();
        if ($this->afterJob !== null) {
            ($this->afterJob)();
        }
    }

    /**
     * Reserve and process the next runnable job on a queue.
     *
     * @return bool true if a job was processed, false if the queue was empty.
     */
    public function processNext(string $queue = 'default'): bool
    {
        // Start each cycle from a clean tenant context (the reserve reads across
        // all tenants; the handler runs under the job's own tenant).
        TenantContext::reset();
        $job = $this->repo->reserve($queue);
        if ($job === null) {
            return false;
        }

        $this->runReserved($job);

        return true;
    }

    /**
     * @param array<string, mixed> $job A normalized reserved-job row.
     */
    private function runReserved(array $job): void
    {
        $id = (int) $job['id'];
        $name = (string) $job['name'];
        $handler = $this->registry->get($name);

        if ($handler === null) {
            $this->logger->error('No handler registered for job', ['job' => $name, 'id' => $id]);
            $this->repo->deadLetter($id, 'No handler registered for job: ' . $name);

            return;
        }

        /** @var array<string, mixed> $payload */
        $payload = is_array($job['payload']) ? $job['payload'] : [];

        // Restore the job's origin tenant so the handler's queries are scoped —
        // which is also what puts the ENQUEUEING tenant on any audit row the
        // handler causes, rather than the system tenant (#935).
        TenantContext::setTenantId((int) $job['tenant_id']);
        // Read fresh for every job: a long-running worker must see a workspace
        // turn a plugin off without restarting.
        $this->activation?->resetRequest();
        $source = $this->registry->sourceOf($name);
        if ($source !== null && $this->activation !== null && !$this->activation->isOn($source, (int) $job['tenant_id'])) {
            $this->logger->info('Job skipped: its plugin is off for this workspace', ['job' => $name, 'id' => $id, 'tenant_id' => (int) $job['tenant_id']]);
            $this->repo->markCompleted($id, ['skipped' => 'plugin_off']);
            $this->resetAfterJob();

            return;
        }
        // Name the unit of work for the audit trail. The NAME only, never the
        // payload: payloads carry caller-supplied data and the trail is readable
        // by any tenant admin with `audit:read` — the same rule #931 adopted for
        // command arguments.
        AuditContext::setJob($name);
        try {
            $result = $handler->handle($payload);
            $this->repo->markCompleted($id, $result);
        } catch (\Throwable $e) {
            $this->logger->error('Job failed', ['job' => $name, 'id' => $id, 'error' => $e->getMessage()]);
            if ((int) $job['attempts'] >= (int) $job['max_attempts']) {
                $this->repo->deadLetter($id, $e->getMessage());
            } else {
                $this->repo->retry($id, self::backoffSeconds((int) $job['attempts']), $e->getMessage());
            }
        } finally {
            // Never leak per-job IN-MEMORY state into the next job
            // (persistent-worker rule). DB connection hygiene (dangling-
            // transaction rollback / DISCARD ALL) is the worker loop's concern,
            // not the per-job runner's — and rolling back here would undo the
            // outcome write on a connection the caller manages transactionally.
            $this->resetAfterJob();
        }
    }

    /**
     * Exponential backoff, capped at 1 hour: attempt 1 → 2s, 2 → 4s, 3 → 8s, …
     */
    private static function backoffSeconds(int $attempts): int
    {
        $exponent = min(max($attempts, 1), 12);

        return min(3600, (int) (2 ** $exponent));
    }
}
