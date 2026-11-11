<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Whity\Sdk\Calendar\BusyInterval;
use Whity\Sdk\PluginCalendarBusyInterface;

/**
 * One plugin's busy times as core reads them (ADR 0017 section 4): anything
 * that is not a {@see BusyInterval} is dropped, a throw is logged and read as
 * "not busy" (fail open), and an answer slower than the 1.5-second budget is
 * logged so the slow plugin can be found.
 */
final class PluginBusySource implements ExternalBusySource
{
    private const BUDGET_SECONDS = 1.5;

    public function __construct(
        private readonly string $plugin,
        private readonly PluginCalendarBusyInterface $source,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function busyTimes(int $tenantId, int $profileId, DateTimeImmutable $fromUtc, DateTimeImmutable $toUtc): array
    {
        $started = microtime(true);
        try {
            // Wider than the contract on purpose: a plugin may return anything.
            /** @var array<mixed> $answer */
            $answer = $this->source->busyTimes($tenantId, $profileId, $fromUtc, $toUtc);
        } catch (\Throwable $e) {
            $this->logger?->warning('[calendar] a plugin could not say when someone is busy', ['plugin' => $this->plugin, 'error' => $e->getMessage()]);

            return [];
        } finally {
            $took = microtime(true) - $started;
            if ($took > self::BUDGET_SECONDS) {
                $this->logger?->warning('[calendar] a plugin was slow to say when someone is busy', ['plugin' => $this->plugin, 'seconds' => round($took, 2)]);
            }
        }
        $busy = [];
        foreach ($answer as $interval) {
            if ($interval instanceof BusyInterval) {
                $busy[] = new Interval($interval->start, $interval->end);
            }
        }

        return $busy;
    }
}
