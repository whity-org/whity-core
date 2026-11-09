<?php

declare(strict_types=1);

namespace Whity\Core\Document\Issuing;

use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\JobInterface;

/**
 * Runs a bulk issue (SDK 1.83, WHIT-997): a chunk of the batch's pending items
 * per run, then queues the next run until none are left, so one batch never
 * holds a worker for long and a crash loses at most the item in hand (which
 * its origin key replays). The next run waits as {@see BulkIssuing::run()}
 * says, to stay under the render tier's limit. Internal: only
 * {@see BulkIssuing} queues it.
 */
final class IssueBatchJob implements JobInterface
{
    public const NAME = 'core.documents.issue_batch';

    public function __construct(private readonly BulkIssuing $bulk)
    {
    }

    /**
     * @param array<string, mixed> $payload `batch_id`
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        $tenantId = TenantContext::getTenantId();
        $batchId = (int) ($payload['batch_id'] ?? 0);
        if ($tenantId === null || $batchId <= 0) {
            return ['done' => true, 'skipped' => 'no batch'];
        }

        $run = $this->bulk->run($tenantId, $batchId);
        if (!$run['done']) {
            // Paced under the render tier's limit, and backed off when it refused.
            $this->bulk->enqueue($tenantId, $batchId, $run['delay']);
        }

        return $run;
    }
}
