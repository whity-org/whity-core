<?php

declare(strict_types=1);

namespace Whity\Core\Plugin;

use PDO;
use Psr\Log\LoggerInterface;
use Whity\Core\Audit\AuditLoggerInterface;

/**
 * The per-plugin host services that need nothing but the database, registered
 * once here and called from each entry point (web, CLI, queue worker), so a
 * plugin's job reaches the same service as its routes.
 */
final class HostPluginServices
{
    private function __construct()
    {
    }

    public static function register(PDO $pdo, AuditLoggerInterface $audit, ?LoggerInterface $logger = null): void
    {
        \Whity\register_service(\Whity\Sdk\Jobs\PluginJobQueue::class, new \Whity\Core\Queue\HostPluginJobQueue(new \Whity\Core\Queue\JobRepository($pdo)));
    }
}
