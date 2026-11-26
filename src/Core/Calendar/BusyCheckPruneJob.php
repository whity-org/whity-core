<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use Whity\Sdk\JobInterface;

/**
 * Deletes external-calendar failures older than a week (WHIT-1440, AP.17),
 * so `calendar_busy_check_failures` stays short-lived. Queued once a day by
 * `schedule:run`; internal.
 */
final class BusyCheckPruneJob implements JobInterface
{
    public const NAME = 'core.calendar.busy_check_prune';

    public function __construct(private readonly BusyCheckFailures $failures)
    {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        return ['deleted' => $this->failures->prune(new DateTimeImmutable('now', new DateTimeZone('UTC')))];
    }
}
