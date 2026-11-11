<?php

declare(strict_types=1);

namespace Whity\Core\Messaging\Jobs;

use Closure;
use Whity\Core\Messaging\MessagingService;
use Whity\Sdk\JobInterface;

/**
 * Hands one queued message to its channel (ADR 0016 §2). Internal: only
 * {@see MessagingService} enqueues it. The service is resolved when the job
 * runs, so the worker's channels, plugin ones included, are the ones used.
 */
final class DeliverMessageJob implements JobInterface
{
    public const NAME = MessagingService::DELIVER_JOB;

    /** @param Closure(): MessagingService $service */
    public function __construct(private readonly Closure $service)
    {
    }

    public function handle(array $payload): array
    {
        $tenantId = (int) ($payload['tenant_id'] ?? 0);
        $messageId = (int) ($payload['message_id'] ?? 0);
        $attempt = max(1, (int) ($payload['attempt'] ?? 1));
        if ($tenantId <= 0 || $messageId <= 0) {
            return ['status' => 'skipped', 'reason' => 'bad_payload'];
        }

        $outcome = ($this->service)()->deliver($tenantId, $messageId, $attempt);

        return ['status' => $outcome, 'message_id' => $messageId];
    }
}
