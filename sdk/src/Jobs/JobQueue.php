<?php

declare(strict_types=1);

namespace Whity\Sdk\Jobs;

/**
 * One plugin's jobs in the current workspace (WHIT-1031). Take it from
 * {@see PluginJobQueue}.
 *
 * The workspace is the one the request or job is running in, and the job runs
 * under it. Without one (a command with no workspace) every method throws
 * {@see \RuntimeException}.
 *
 * Only this plugin's jobs can be enqueued or seen: `$job` is a BARE name from its
 * {@see \Whity\Sdk\PluginJobsInterface::getJobs()}. Anything else throws
 * {@see \InvalidArgumentException}, and {@see status()} answers null for a job
 * that belongs to another plugin or workspace.
 */
interface JobQueue
{
    /**
     * @param array<string, mixed> $payload        Handed verbatim to the handler; keep it to ids and small values.
     * @param int                  $delaySeconds   Run no sooner than this.
     * @param string|null          $idempotencyKey A live job of this plugin with the same key is not enqueued twice.
     * @return int|null The job id, or null when the key matched a live job.
     */
    public function enqueue(string $job, array $payload = [], int $delaySeconds = 0, ?string $idempotencyKey = null): ?int;

    public function status(int $id): ?JobStatus;

    /**
     * The newest jobs first.
     *
     * @param int $limit At most 200.
     * @return list<JobStatus>
     */
    public function recent(int $limit = 50): array;
}
