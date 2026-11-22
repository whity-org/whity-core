<?php

declare(strict_types=1);

namespace Whity\Core\Audit;

use Whity\Sdk\JobInterface;

/**
 * Seals settled audit rows into the hash chain (WHIT-1339). Queued every five
 * minutes by `schedule:run`; each run seals at most a few batches, so a large
 * backlog (the first run on an existing install) is worked down over several
 * runs without holding the chain head for long.
 *
 * The head it reaches is written to the log on every run that sealed
 * something. Kept off the host, those lines are what show a rewrite of the
 * chain by someone who could also rewrite the database.
 */
final class AuditSealJob implements JobInterface
{
    public const NAME = 'core.audit.seal';

    private const BATCH = 5000;
    private const BATCHES_PER_RUN = 10;

    public function __construct(private readonly AuditSeals $seals)
    {
    }

    public function handle(array $payload): array
    {
        $result = $this->seals->seal(self::BATCH);
        $sealed = $result['sealed'];
        for ($i = 1; $i < self::BATCHES_PER_RUN && $result['sealed'] === self::BATCH; $i++) {
            $result = $this->seals->seal(self::BATCH);
            $sealed += $result['sealed'];
        }

        if ($sealed > 0) {
            error_log(sprintf(
                '[audit] chain head audit_id=%d seals=%d hash=%s',
                $result['last_audit_id'],
                $result['sealed_count'],
                $result['head_hash']
            ));
        }

        return [
            'sealed' => $sealed,
            'last_audit_id' => $result['last_audit_id'],
            'head_hash' => $result['head_hash'],
        ];
    }
}
