<?php

declare(strict_types=1);

namespace Whity\Core\Document\Issuing;

use PDO;
use Psr\Log\LoggerInterface;
use Whity\Auth\RoleChecker;
use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\DocumentSourceRepository;
use Whity\Core\Document\DocumentVisibilityPolicy;
use Whity\Core\Document\Embed\DocumentEmbedRegistry;
use Whity\Core\Document\Embed\IssuedDocumentRecordProvider;
use Whity\Core\Document\Embed\IssuedDocumentsByMonthChartSource;
use Whity\Core\Document\Fields\CompanyFieldProvider;
use Whity\Core\Document\Fields\DocumentFieldRegistry;
use Whity\Core\Document\Fields\IssuedDocumentFieldProvider;
use Whity\Core\Document\Fields\TenantRecordFieldProvider;
use Whity\Core\Document\Fields\TodayFieldProvider;
use Whity\Core\Document\Routing\DocumentRouteSubject;
use Whity\Core\Document\Routing\RouteEventRepository;
use Whity\Core\Document\Routing\RouteFinalisationRepository;
use Whity\Core\Document\Routing\RouteFinaliser;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\Document\Routing\RouteRepository;
use Whity\Core\Document\Routing\RouteSubjectRegistry;
use Whity\Core\Document\Routing\RouteSubjectVersionRepository;
use Whity\Core\Document\Slots\TemplateSlotRegistry;
use Whity\Core\Form\FormSubmissionRouteSubject;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Plugin\Tenant\TenantManifestHost;
use Whity\Core\Plugin\Tenant\TenantPluginRepository;
use Whity\Core\Plugin\Tenant\TenantRecordRepository;
use Whity\Core\Queue\JobRepository;
use Whity\Core\Queue\QueueService;
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
 * registry, the field registry and the SDK's DocumentRenderer (#1151), and returns the two registries for the
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
        $fields->add(new \Whity\Core\Document\Fields\IssuedDocumentNumberFieldProvider());
        // WHIT-954: contacts and their custom fields, as in public/index.php.
        $fields->add(new \Whity\Core\Contacts\ContactFieldProvider($pdo));
        $fields->addTenantSource(static fn (int $tenantId): array => TenantRecordFieldProvider::forManifests(
            $liveManifests($tenantId),
            $records,
            null,
            null,
            static fn (int $tenant, int $profileId): ?string => $records->memberName($tenant, $profileId),
        ));
        // WHIT-921: each form's submissions as a record type, as in public/index.php.
        $fields->addTenantSource(static fn (int $tenantId): array => \Whity\Core\Form\FormSubmissionFieldProvider::forTenant($pdo, $tenantId));
        $slots = new TemplateSlotRegistry($liveManifests);

        // One document stack for the worker (#1151): issuing and the SDK's
        // DocumentRenderer share its storage driver, issuer and renderer.
        $parts = new WorkerDocumentParts($pdo, $settings, $hooks, $fields, $embeds, $env, $storageRoot);
        $issuing = WorkerDocumentIssuing::build($pdo, $settings, $hooks, $roleChecker, $resourceTypes, $fields, $embeds, $slots, $env, $storageRoot, $parts);

        \Whity\register_service(DocumentFieldRegistry::class, $fields);
        \Whity\register_service(TemplateSlotRegistry::class, $slots);
        // WHIT-921: the worker retries a route's final document while the render
        // service was down. Its issuing stack is built on the first attempt.
        \Whity\register_service(RouteFinaliser::class, self::finaliser($pdo, $settings, $hooks, $fields, $parts));
        \Whity\register_service(TemplateIssuing::class, $issuing);
        // SDK 1.83 (WHIT-997): bulk issuing. A plugin's job may start a batch, and
        // the worker runs every batch (IssueBatchJob, registered by queue:work).
        $bulk = new BulkIssuing($pdo, $roleChecker, static fn (): TemplateIssuing => $issuing, new \Whity\Core\Queue\JobRepository($pdo), $logger, BulkIssuing::rendersPerMinuteFrom($env));
        \Whity\register_service(BulkIssuing::class, $bulk);
        \Whity\register_service(Documents::class, new SdkDocuments($issuing, $roleChecker, $jobPlugin, $slots, $bulk));
        // SDK 1.82 (WHIT-996): a job reads templates for the same caller it issues for.
        \Whity\register_service(\Whity\Sdk\Documents\Templates::class, SdkTemplates::build($pdo, $roleChecker, $resourceTypes, $settings, $slots, $jobPlugin));
        // #1151: a plugin's job renders and issues flowing documents too.
        \Whity\register_service(
            \Whity\Sdk\Render\DocumentRenderer::class,
            new \Whity\Core\Document\Render\DeferredSdkDocumentRenderer(
                static fn (): \Whity\Sdk\Render\DocumentRenderer => $parts->sdkRenderer()
            ),
        );

        // SDK 1.63 (WHIT-886): a plugin's job reaches the same plugin files and
        // plugin documents as its routes do. Both are built on first use, so a
        // worker that runs no plugin file job needs no storage key.
        \Whity\register_service(\Whity\Sdk\Storage\PluginFiles::class, new \Whity\Core\Plugin\HostPluginFiles(
            static fn (): \Whity\Storage\TenantStorageResolver => new \Whity\Storage\TenantStorageResolver(
                \Whity\Storage\StorageDriverFactory::fromSettings($settings, $env, $storageRoot),
                new \Whity\Storage\TenantStorageConfigRepository($pdo),
                new \Whity\Core\Entitlement\EntitlementService(new \Whity\Core\Entitlement\TenantEntitlementRepository($pdo)),
                \Whity\Core\Security\EncryptedSecretStore::fromEnv($env),
            ),
        ));
        $pluginDocumentUrls = \Whity\Core\Http\FrontendUrls::fromEnv();
        \Whity\register_service(\Whity\Sdk\Documents\PluginDocuments::class, new \Whity\Core\Plugin\HostPluginDocuments(
            $pdo,
            static fn (): Documents => \Whity\app(Documents::class),
            new \Whity\Core\Document\Qr\DocumentQrService(
                $pdo,
                new \Whity\Core\Document\Qr\DocumentQrTokenRepository($pdo),
                new \Whity\Core\Document\Qr\DocumentQrScanRepository($pdo),
                $pluginDocumentUrls->base(),
                $pluginDocumentUrls->link(\Whity\Core\Http\FrontendUrls::DOCUMENT_VERIFY)
            ),
            new \Whity\Core\Document\Qr\DocumentQrScanRepository($pdo),
            $settings,
        ));

        return ['slots' => $slots, 'fields' => $fields];
    }

    /** The route finaliser (WHIT-921) over the worker's document stack. */
    private static function finaliser(
        PDO $pdo,
        SettingsService $settings,
        HookManager $hooks,
        DocumentFieldRegistry $fields,
        WorkerDocumentParts $parts,
    ): RouteFinaliser {
        $subjects = new RouteSubjectRegistry();
        $subjects->register(new DocumentRouteSubject($pdo));
        $subjects->register(new FormSubmissionRouteSubject($pdo));

        return new RouteFinaliser(
            $pdo,
            new RouteRepository($pdo),
            new RouteRecipientRepository($pdo),
            new RouteEventRepository($pdo),
            new RouteFinalisationRepository($pdo),
            $subjects,
            new RouteSubjectVersionRepository($pdo),
            new DocumentSourceRepository($pdo),
            new DocumentRepository($pdo),
            static fn (): DocumentIssuing => $parts->issuing(),
            static fn (int $tenantId): bool => RouteFinaliser::pdfsCanBeMade($parts->flowRenderer(), $settings)($tenantId),
            $fields,
            new QueueService(new JobRepository($pdo)),
            $hooks,
        );
    }
}
