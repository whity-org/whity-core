<?php

declare(strict_types=1);

namespace Whity\Core\Queue\Jobs;

use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Plugin\PluginRecords;
use Whity\Core\PluginLoader;
use Whity\Core\PluginMigrationRollback;
use Whity\Core\PluginMigrationRunner;
use Whity\Sdk\JobInterface;

/**
 * Ends the 30-day keep on uninstalled plugins (S.7, WHIT-870).
 *
 * For each removal whose time is up: run the plugin's migrations' down(), then
 * remove its files and forget it. A plugin whose down() fails is left as it is,
 * with the reason on its row, and is tried again on the next run: half-deleted
 * data with no code left to finish the job is the worst place to stop.
 */
final class PurgeRemovedPluginsJob implements JobInterface
{
    public const NAME = 'core.plugins.purge_removed';

    /**
     * @param \Closure(): ?PluginLoader $loader The worker's loader, once it has one.
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly \Closure $loader,
        private readonly ?AuditLogger $auditLogger = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        $loader = ($this->loader)();
        if ($loader === null) {
            throw new \RuntimeException('The plugin loader is not available in this worker.');
        }

        return $this->purge($loader);
    }

    /** @return array{purged: list<string>, failed: list<string>} */
    public function purge(PluginLoader $loader, ?string $now = null): array
    {
        $records = new PluginRecords($this->pdo);
        $purged = [];
        $failed = [];

        foreach ($records->dueRemovals($now) as $removal) {
            $name = (string) $removal['plugin_name'];
            $key = (string) $removal['plugin_key'];

            try {
                $plugin = $loader->getPluginInstance($key);
                if ($plugin !== null) {
                    (new PluginMigrationRunner($this->pdo))->rollBackForPlugin($plugin);
                }
                (new PluginMigrationRollback($this->pdo))->rollback($name);
                $result = $loader->uninstallPlugin($key, $this->pdo);
                if ($result['errors'] !== []) {
                    throw new \RuntimeException(implode('; ', $result['errors']));
                }
            } catch (\Throwable $e) {
                $this->logger->error('[plugins] purge failed', ['plugin' => $name, 'error' => $e->getMessage()]);
                $records->notePurgeError($name, 'The plugin could not remove its data.');
                $failed[] = $name;
                continue;
            }

            $records->forgetRemoval($name);
            $records->forgetInstall($name);
            $this->auditLogger?->record('plugin.purged', [
                'tenant_id' => 0,
                'target_type' => 'plugin',
                'metadata' => ['plugin' => $name, 'version' => $removal['version'] ?? null],
            ]);
            $purged[] = $name;
        }

        return ['purged' => $purged, 'failed' => $failed];
    }
}
