<?php

declare(strict_types=1);

namespace Whity\Core\Plugin;

use PDO;
use Psr\Log\LoggerInterface;
use Whity\Core\Audit\AuditLoggerInterface;
use Whity\Core\Notification\CoreTransports;
use Whity\Core\Notification\DatabaseNotificationRenderer;
use Whity\Core\Notification\HostPluginNotifier;
use Whity\Core\Notification\NotificationDispatcher;
use Whity\Core\Notification\NotificationPreferenceRepository;
use Whity\Core\Notification\NotificationPreferenceResolver;
use Whity\Core\Notification\NotificationRepository;
use Whity\Core\Notification\NotificationTemplateRepository;
use Whity\Core\Queue\JobRepository;
use Whity\Core\Queue\QueueService;
use Whity\Sdk\Notification\PluginNotifier;

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
        \Whity\register_service(PluginNotifier::class, new HostPluginNotifier(
            $pdo,
            new NotificationDispatcher(
                new NotificationRepository($pdo),
                CoreTransports::make($logger),
                new QueueService(new JobRepository($pdo)),
                new DatabaseNotificationRenderer(new NotificationTemplateRepository($pdo)),
                $logger,
                new NotificationPreferenceResolver(new NotificationPreferenceRepository($pdo)),
                $audit,
            ),
            $logger,
        ));
    }
}
