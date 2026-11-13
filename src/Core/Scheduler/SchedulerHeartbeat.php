<?php

declare(strict_types=1);

namespace Whity\Core\Scheduler;

use Whity\Core\Settings\GlobalSettingsRepository;

/**
 * The scheduler's own proof of life (WHIT-1013).
 *
 * `schedule:run` records the time of every tick it completes, whether or not
 * any schedule was due, and {@see \Whity\Core\Health\HealthProbe} reads it back.
 * Judging the scheduler by `scheduled_jobs.last_run_at` instead left an install
 * with no schedules permanently "unknown" while the tick ran every minute.
 *
 * Stored in `app_settings` (a sanctioned global table) under an internal key
 * that is not in the settings registry, so it never surfaces through the
 * settings API. The value is a Unix timestamp, so no time zone is involved.
 */
final class SchedulerHeartbeat
{
    public const KEY = 'scheduler.last_tick_at';

    public function __construct(private readonly GlobalSettingsRepository $globals)
    {
    }

    public function beat(int $now): void
    {
        $this->globals->set(self::KEY, (string) $now);
    }

    /**
     * When the scheduler last ticked, or null when it never has.
     *
     * @phpstan-impure It reads the database, so each call can answer differently.
     */
    public function lastTick(): ?int
    {
        $value = $this->globals->get(self::KEY);
        if ($value === null || !ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }
}
