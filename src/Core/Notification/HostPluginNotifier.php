<?php

declare(strict_types=1);

namespace Whity\Core\Notification;

use PDO;
use Psr\Log\LoggerInterface;
use Whity\Core\Plugin\PluginIdentity;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Notification\Notifier;
use Whity\Sdk\Notification\PluginNotificationsInterface;
use Whity\Sdk\Notification\PluginNotifier;
use Whity\Sdk\PluginInterface;

/**
 * The host's plugin notifier (WHIT-1030). The plugin comes from the loader's
 * identity and the workspace from {@see TenantContext}, so neither is something
 * a plugin can state.
 */
final class HostPluginNotifier implements PluginNotifier
{
    /** @var \Closure(): ?int */
    private readonly \Closure $tenant;

    /**
     * @param (\Closure(): ?int)|null $tenant the workspace in scope; the request's or job's by default
     */
    public function __construct(
        private readonly PDO $db,
        private readonly NotificationDispatcher $dispatcher,
        private readonly ?LoggerInterface $logger = null,
        ?\Closure $tenant = null,
    ) {
        $this->tenant = $tenant ?? static fn (): ?int => TenantContext::getTenantId();
    }

    public function for(PluginInterface $plugin): Notifier
    {
        $slug = PluginIdentity::slugOf($plugin);
        $types = $plugin instanceof PluginNotificationsInterface
            ? PluginNotificationTypes::of($plugin, $slug)
            : [];

        return new HostNotifier($this->db, $this->dispatcher, $slug, $types, $this->tenant, $this->logger);
    }
}
