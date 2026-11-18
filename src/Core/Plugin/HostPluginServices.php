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

    /**
     * @param (\Closure(): list<array{plugin: string, theme: array<string, mixed>}>)|null $pluginThemes
     *        The themes plugins ship (PluginLoader::getPluginThemes), where the
     *        entry point has a loader; without it plugins read Whity's own and
     *        imported themes only.
     */
    public static function register(PDO $pdo, AuditLoggerInterface $audit, ?LoggerInterface $logger = null, ?\Closure $pluginThemes = null): void
    {
        // SDK 1.125 (WHIT-1225): the workspace's theme, read-only, for plugin
        // code everywhere it runs. The workspace comes from the context (D2).
        // A later call without the plugin themes (an entry point that boots
        // the web wiring first, then registers again) keeps the binding that
        // has them, so jobs and commands see the same themes as the web.
        if ($pluginThemes !== null || !isset($GLOBALS['whity_services'][\Whity\Sdk\Theme\WorkspaceTheme::class])) {
            $settings = new \Whity\Core\Settings\SettingsService(
                new \Whity\Core\Settings\GlobalSettingsRepository($pdo),
                new \Whity\Core\Settings\TenantSettingsRepository($pdo),
            );
            $themes = new \Whity\Core\Theme\ThemeRepository($pdo);
            \Whity\register_service(\Whity\Sdk\Theme\WorkspaceTheme::class, new \Whity\Core\Theme\HostWorkspaceTheme(
                new \Whity\Core\Branding\BrandingService($settings, themes: $themes, pluginThemes: $pluginThemes),
                $settings,
                new \Whity\Core\Theme\ThemeLibrary($themes, $pluginThemes),
            ));
        }
        // SDK 1.124 (D3): the one entry point; it reaches the factories below
        // and the ones each entry point binds itself, at call time.
        \Whity\register_service(\Whity\Sdk\PluginContext::class, new HostPluginContext());
        // SDK 1.84: plans and prices need only the database, so every entry
        // point has them (a billing plugin's renewal job runs in the worker).
        \Whity\register_service(\Whity\Sdk\Billing\PlanCatalogue::class, new \Whity\Core\Plan\HostPlanCatalogue($pdo));
        \Whity\register_service(\Whity\Sdk\Audit\PluginAudit::class, new \Whity\Core\Audit\HostPluginAudit($pdo, $audit));
        \Whity\register_service(\Whity\Sdk\Jobs\PluginJobQueue::class, new \Whity\Core\Queue\HostPluginJobQueue(new \Whity\Core\Queue\JobRepository($pdo)));
        $activation = new \Whity\Core\Plugin\Workspace\CodePluginActivation($pdo);
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
            null,
            // WHIT-1123: read fresh on every send, so a worker that outlives
            // the switch never sends for a plugin a workspace turned off.
            static function (string $plugin, int $tenantId) use ($activation): bool {
                $activation->resetRequest();

                return !$activation->isOn($plugin, $tenantId);
            },
        ));
    }
}
