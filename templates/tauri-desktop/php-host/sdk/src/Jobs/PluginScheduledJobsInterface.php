<?php

declare(strict_types=1);

namespace Whity\Sdk\Jobs;

/**
 * Optional capability: jobs that run on a schedule (WHIT-1031).
 *
 * Name a job this plugin ships ({@see \Whity\Sdk\PluginJobsInterface::getJobs()})
 * and a five-field cron expression in UTC. The host runs it once per workspace at
 * each occurrence, with an empty payload, under that workspace.
 *
 * A schedule is a declaration, so it follows the plugin: it appears within a few
 * minutes of the plugin loading and goes when the plugin does. A workspace admin
 * who pauses a schedule keeps it paused.
 *
 * A malformed declaration, or one naming a job the plugin doesn't ship, is
 * refused whole and logged.
 *
 *     public function getScheduledJobs(): array
 *     {
 *         return ['catalog.sync' => '0 * * * *'];
 *     }
 */
interface PluginScheduledJobsInterface
{
    /**
     * Bare job name => cron expression.
     *
     * @return array<string, string>
     */
    public function getScheduledJobs(): array;
}
