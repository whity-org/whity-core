<?php

declare(strict_types=1);

namespace Whity\Core\Scheduler;

use Psr\Log\LoggerInterface;
use Whity\Sdk\Jobs\PluginScheduledJobsInterface;
use Whity\Sdk\PluginJobsInterface;
use Whity\Sdk\PluginNamespace;

/**
 * Turns plugins' declared schedules into `scheduled_jobs` rows, one per workspace,
 * so {@see \Whity\Cli\Commands\ScheduleRunCommand} has one scheduler for all of
 * them (WHIT-1031).
 *
 * A row is a plugin's when its name carries the namespace colon, which core's
 * names never do. A row is left alone when it already matches, so a workspace's
 * pause and the next run time survive. A plugin that is gone loses its rows, so
 * its schedule can't keep enqueueing jobs no handler will take. A plugin whose
 * declaration couldn't be read keeps them: a bad deploy shouldn't erase a
 * workspace's pause.
 */
final class PluginSchedules
{
    public function __construct(
        private readonly ScheduledJobRepository $schedules,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Canonical job name => cron expression.
     *
     * @return array<string, string>
     * @throws \InvalidArgumentException on a malformed declaration, refused whole
     */
    public static function of(PluginScheduledJobsInterface $plugin, string $slug): array
    {
        $jobs = $plugin instanceof PluginJobsInterface ? $plugin->getJobs() : [];

        $schedules = [];
        foreach ($plugin->getScheduledJobs() as $job => $cron) {
            $job = (string) $job;
            if (!isset($jobs[$job])) {
                throw new \InvalidArgumentException("Schedule names a job the plugin does not ship: {$job}");
            }
            new CronExpression((string) $cron); // throws on invalid
            $schedules[$slug . PluginNamespace::SEPARATOR . $job] = (string) $cron;
        }

        return $schedules;
    }

    /**
     * @param array{schedules: array<string, string>, unreadable: list<string>} $declared
     */
    public function sync(array $declared): void
    {
        $existing = [];
        foreach ($this->schedules->listPluginSchedules() as $row) {
            $existing[(int) $row['tenant_id']][(string) $row['name']] = $row;
        }

        foreach ($this->schedules->workspaceIds() as $tenantId) {
            $rows = $existing[$tenantId] ?? [];
            unset($existing[$tenantId]);
            foreach ($declared['schedules'] as $name => $cron) {
                $row = $rows[$name] ?? null;
                if ($row === null || (string) $row['cron_expression'] !== $cron) {
                    $this->schedules->register($tenantId, $name, $cron, [], ['enabled' => $row === null || $row['enabled'] === true]);
                }
                unset($rows[$name]);
            }
            $this->removeWithdrawn($tenantId, $rows, $declared);
        }

        foreach ($existing as $tenantId => $rows) {
            $this->removeWithdrawn($tenantId, $rows, $declared);
        }
    }

    /**
     * @param array<string, array<string, mixed>> $rows rows no declaration claimed
     * @param array{schedules: array<string, string>, unreadable: list<string>} $declared
     */
    private function removeWithdrawn(int $tenantId, array $rows, array $declared): void
    {
        foreach ($rows as $name => $row) {
            $slug = strstr($name, PluginNamespace::SEPARATOR, true);
            if ($slug === false || in_array($slug, $declared['unreadable'], true)) {
                continue;
            }
            $this->schedules->delete($tenantId, (int) $row['id']);
            $this->logger?->info('[schedule:run] removed a withdrawn plugin schedule', ['name' => $name, 'tenant_id' => $tenantId]);
        }
    }
}
