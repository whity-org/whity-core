<?php

declare(strict_types=1);

namespace Whity\Sdk\Jobs;

/**
 * Where one of a plugin's jobs has got to (WHIT-1031). `status` is one of
 * `pending`, `reserved` (running), `completed` or `dead` (out of attempts). Finished jobs
 * are kept for a day.
 */
final class JobStatus
{
    /**
     * @param string                    $job    The bare name the plugin declared.
     * @param array<string, mixed>|null $result What the handler returned, once it has finished.
     * @param string|null               $error  The last failure, if any.
     */
    public function __construct(
        public readonly int $id,
        public readonly string $job,
        public readonly string $status,
        public readonly int $progress,
        public readonly int $attempts,
        public readonly int $maxAttempts,
        public readonly ?array $result,
        public readonly ?string $error,
        public readonly ?string $createdAt,
        public readonly ?string $completedAt,
    ) {
    }
}
