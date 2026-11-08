<?php

declare(strict_types=1);

namespace Whity\Core\Queue;

use Whity\Sdk\Jobs\JobQueue;
use Whity\Sdk\Jobs\JobStatus;
use Whity\Sdk\PluginNamespace;

/**
 * One plugin's jobs (WHIT-1031). A job is this plugin's because its stored name
 * begins with the plugin's slug, which the plugin cannot write: a bare name with
 * a colon is not in its declared set.
 *
 * Every job is kept after it finishes (`retain_result`), so {@see status()} can
 * answer; the scheduler prunes finished jobs after a day.
 */
final class HostJobQueue implements JobQueue
{
    private const MAX_RECENT = 200;
    private const MAX_DELAY_SECONDS = 2_592_000;

    /**
     * @param array<string, true> $declared bare job names
     * @param \Closure(): ?int    $tenant
     */
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly string $slug,
        private readonly array $declared,
        private readonly \Closure $tenant,
    ) {
    }

    public function enqueue(string $job, array $payload = [], int $delaySeconds = 0, ?string $idempotencyKey = null): ?int
    {
        $tenantId = $this->workspace();
        if (!isset($this->declared[$job])) {
            throw new \InvalidArgumentException("This plugin has not declared the job {$job}.");
        }

        return $this->jobs->enqueue($tenantId, $this->prefix() . $job, $payload, [
            'delay' => min(max(0, $delaySeconds), self::MAX_DELAY_SECONDS),
            // Under the plugin's name, so one plugin's key cannot suppress another's job.
            'idempotency_key' => $idempotencyKey !== null && $idempotencyKey !== '' ? $this->prefix() . $idempotencyKey : null,
            'retain_result' => true,
        ]);
    }

    public function status(int $id): ?JobStatus
    {
        $row = $this->jobs->find($this->workspace(), $id);
        if ($row === null || !str_starts_with((string) $row['name'], $this->prefix())) {
            return null;
        }

        return $this->statusOf($row);
    }

    public function recent(int $limit = 50): array
    {
        $rows = $this->jobs->listByNamePrefix($this->workspace(), $this->prefix(), min(max(1, $limit), self::MAX_RECENT));

        return array_map(fn (array $row): JobStatus => $this->statusOf($row), $rows);
    }

    /** @param array<string, mixed> $row */
    private function statusOf(array $row): JobStatus
    {
        /** @var array<string, mixed>|null $result */
        $result = $row['result'];

        return new JobStatus(
            (int) $row['id'],
            substr((string) $row['name'], strlen($this->prefix())),
            (string) $row['status'],
            (int) $row['progress'],
            (int) $row['attempts'],
            (int) $row['max_attempts'],
            $result,
            $row['last_error'] === null ? null : (string) $row['last_error'],
            $row['created_at'] === null ? null : (string) $row['created_at'],
            $row['completed_at'] === null ? null : (string) $row['completed_at'],
        );
    }

    private function prefix(): string
    {
        return $this->slug . PluginNamespace::SEPARATOR;
    }

    private function workspace(): int
    {
        $tenantId = ($this->tenant)();
        if ($tenantId === null || $tenantId === 0) {
            throw new \RuntimeException('Plugin jobs need a workspace, and none is in scope.');
        }

        return $tenantId;
    }
}
