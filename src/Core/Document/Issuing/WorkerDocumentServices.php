<?php

declare(strict_types=1);

namespace Whity\Core\Document\Issuing;

use PDO;
use Psr\Log\LoggerInterface;
use Whity\Auth\RoleChecker;
use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\DocumentVisibilityPolicy;
use Whity\Core\Document\Embed\DocumentEmbedRegistry;
use Whity\Core\Document\Embed\IssuedDocumentRecordProvider;
use Whity\Core\Document\Embed\IssuedDocumentsByMonthChartSource;
use Whity\Core\Document\Fields\CompanyFieldProvider;
use Whity\Core\Document\Fields\DocumentFieldRegistry;
use Whity\Core\Document\Fields\IssuedDocumentFieldProvider;
use Whity\Core\Document\Fields\TenantRecordFieldProvider;
use Whity\Core\Document\Fields\TodayFieldProvider;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\Document\Slots\TemplateSlotRegistry;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Plugin\Tenant\TenantManifestHost;
use Whity\Core\Plugin\Tenant\TenantPluginRepository;
use Whity\Core\Plugin\Tenant\TenantRecordRepository;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\RBAC\ResourceRoleAssignmentRepository;
use Whity\Core\RBAC\ResourceTypeRegistry;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Database\Database;
use Whity\Sdk\Documents\Documents;

/**
 * The document services for `queue:work` (WHIT-742, DOCUMENT-AUTOMATION.md
 * 3.5), which runs workspace automations and plugin jobs that issue documents.
 *
 * The worker isn't a {@see \Whity\Cli\Commands\BaseCommand}, so the kernel's
 * registrations never reach it: this builds and registers the same services
 * for it, {@see TemplateIssuing}, the SDK's {@see Documents}, the slot
 * registry and the field registry, and returns the two registries for the
 * worker's plugin loader to fill. The render, storage and QR parts are built
 * on the first issue.
 */
final class WorkerDocumentServices
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $env
     * @param (\Closure(string): (array{plugin: string, permissions: list<string>}|null))|null $jobPlugin
     *        the plugin a running job belongs to and the permissions it
     *        declares, so its jobs can issue in the background where allowed
     * @return array{slots: TemplateSlotRegistry, fields: DocumentFieldRegistry}
     */
    public static function register(PDO $pdo, HookManager $hooks, array $env, string $storageRoot, ?LoggerInterface $logger = null, ?\Closure $jobPlugin = null): array
    {
        $settings = new SettingsService(new GlobalSettingsRepository($pdo), new TenantSettingsRepository($pdo));
        $permissions = new PermissionRegistry();
        $permissions->registerCorePermissions();
        $roleChecker = new RoleChecker(Database::withFactory(static fn (): PDO => $pdo), $permissions, $logger);
        $resourceTypes = new ResourceTypeRegistry($hooks);

        $documents = new DocumentRepository($pdo);
        $visibility = new DocumentVisibilityPolicy(
            new RouteRecipientRepository($pdo),
            new ResourceRoleAssignmentRepository($pdo, $resourceTypes),
        );
        $embeds = new DocumentEmbedRegistry();
        $embeds->addRecordProvider(new IssuedDocumentRecordProvider($documents, $visibility));
        $embeds->addChartSource(new IssuedDocumentsByMonthChartSource($documents, $visibility));

        // A worker lives for many jobs, so each read of a workspace's plugins
        // starts fresh rather than from the last job's.
        $host = new TenantManifestHost(new TenantPluginRepository($pdo), $permissions, $logger);
        $liveManifests = static function (int $tenantId) use ($host): array {
            $host->resetRequest();

            return $host->manifestsFor($tenantId);
        };
        $records = new TenantRecordRepository($pdo);

        $fields = new DocumentFieldRegistry();
        $fields->add(new IssuedDocumentFieldProvider($documents, $visibility));
        $fields->add(new CompanyFieldProvider($settings));
        $fields->add(new TodayFieldProvider());
        $fields->addTenantSource(static fn (int $tenantId): array => TenantRecordFieldProvider::forManifests(
            $liveManifests($tenantId),
            $records,
            null,
            null,
            static fn (int $tenant, int $profileId): ?string => $records->memberName($tenant, $profileId),
        ));
        $slots = new TemplateSlotRegistry($liveManifests);

        $issuing = WorkerDocumentIssuing::build($pdo, $settings, $hooks, $roleChecker, $resourceTypes, $fields, $embeds, $slots, $env, $storageRoot);

        \Whity\register_service(DocumentFieldRegistry::class, $fields);
        \Whity\register_service(TemplateSlotRegistry::class, $slots);
        \Whity\register_service(TemplateIssuing::class, $issuing);
        \Whity\register_service(Documents::class, new SdkDocuments($issuing, $roleChecker, $jobPlugin, $slots));

        return ['slots' => $slots, 'fields' => $fields];
    }
}
