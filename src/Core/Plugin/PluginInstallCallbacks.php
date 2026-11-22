<?php

declare(strict_types=1);

namespace Whity\Core\Plugin;

use PDO;
use Psr\Log\LoggerInterface;
use Whity\Sdk\PluginInstallLifecycleInterface;
use Whity\Sdk\PluginInterface;

/**
 * Calls a plugin's installed and upgraded callbacks (SDK 1.152, #1686) once per
 * version per deployment, after its migrations ran.
 *
 * The last version the host saw for each plugin is kept in
 * `plugin_versions_seen`. No row: `onInstalled()`. A different version:
 * `onUpgraded(from, to)`. The same: nothing. The row is written only after the
 * callback returns, so a callback that throws is called again on the next
 * `migrate` or enable. Every plugin's version is recorded, callbacks or not, so
 * a plugin that adds the interface in a later version is told it was upgraded,
 * not installed.
 *
 * Callbacks run with no workspace and nothing that could enable a plugin.
 */
final class PluginInstallCallbacks
{
    public const INSTALLED = 'installed';
    public const UPGRADED = 'upgraded';
    public const UNCHANGED = 'unchanged';
    public const FAILED = 'failed';

    public function __construct(
        private readonly PDO $pdo,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /** @return self::INSTALLED|self::UPGRADED|self::UNCHANGED|self::FAILED */
    public function run(PluginInterface $plugin): string
    {
        $name = $plugin->getName();
        $version = $plugin->getVersion();

        $statement = $this->pdo->prepare('SELECT version FROM plugin_versions_seen WHERE plugin_name = ?');
        $statement->execute([$name]);
        $seen = $statement->fetchColumn();
        $seen = is_string($seen) ? $seen : null;

        if ($seen === $version) {
            return self::UNCHANGED;
        }

        $outcome = $seen === null ? self::INSTALLED : self::UPGRADED;
        if ($plugin instanceof PluginInstallLifecycleInterface) {
            try {
                if ($seen === null) {
                    $plugin->onInstalled($version);
                } else {
                    $plugin->onUpgraded($seen, $version);
                }
            } catch (\Throwable $e) {
                $this->logger?->warning("Plugin '{$name}' {$outcome} callback failed; it will run again next time.", [
                    'event' => 'plugin.install_callback.failed',
                    'plugin' => $name,
                    'from' => $seen,
                    'to' => $version,
                    'exception' => $e::class,
                ]);

                return self::FAILED;
            }
        }

        $this->record($name, $version, $seen === null);

        return $outcome;
    }

    private function record(string $name, string $version, bool $first): void
    {
        if ($first) {
            $this->pdo->prepare(
                'INSERT INTO plugin_versions_seen (plugin_name, version, seen_at) VALUES (?, ?, CURRENT_TIMESTAMP)
                 ON CONFLICT (plugin_name) DO UPDATE SET version = EXCLUDED.version, seen_at = EXCLUDED.seen_at'
            )->execute([$name, $version]);

            return;
        }
        $this->pdo->prepare('UPDATE plugin_versions_seen SET version = ?, seen_at = CURRENT_TIMESTAMP WHERE plugin_name = ?')
            ->execute([$version, $name]);
    }
}
