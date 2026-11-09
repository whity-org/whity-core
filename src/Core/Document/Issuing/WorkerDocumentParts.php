<?php

declare(strict_types=1);

namespace Whity\Core\Document\Issuing;

use PDO;
use Whity\Core\Document\DocumentArtifactRepository;
use Whity\Core\Document\DocumentArtifactStore;
use Whity\Core\Document\DocumentIssuer;
use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\DocumentSourceFileStore;
use Whity\Core\Document\DocumentSourceVersionRepository;
use Whity\Core\Document\Embed\DocumentEmbedRegistry;
use Whity\Core\Document\Fields\DocumentFieldRegistry;
use Whity\Core\Document\Print\LetterheadLogo;
use Whity\Core\Document\Print\PrintLabels;
use Whity\Core\Document\Print\SourceDocumentPrinter;
use Whity\Core\Document\Print\WorkspaceName;
use Whity\Core\Document\Qr\DocumentQrScanRepository;
use Whity\Core\Document\Qr\DocumentQrService;
use Whity\Core\Document\Qr\DocumentQrTokenRepository;
use Whity\Core\Document\Render\FlowDocumentRenderer;
use Whity\Core\Document\Render\RenderServiceClient;
use Whity\Core\Document\Render\SdkDocumentRenderer;
use Whity\Core\Document\Theme\DocumentThemeRepository;
use Whity\Core\Entitlement\EntitlementService;
use Whity\Core\Entitlement\MeterService;
use Whity\Core\Entitlement\TenantEntitlementRepository;
use Whity\Core\Http\FrontendUrls;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Store\DatabaseSharedStore;
use Whity\Storage\StorageDriverFactory;
use Whity\Storage\StorageDriverInterface;
use Whity\Storage\TenantRoutingStorageDriver;
use Whity\Storage\TenantStorageConfigRepository;
use Whity\Storage\TenantStorageResolver;

/**
 * The document stack of a process with no request (#1151): `queue:work` and
 * the CLI kernel. One per process, built on first use, so the issuing service
 * and the SDK's {@see \Whity\Sdk\Render\DocumentRenderer} share ONE storage
 * driver, one issuer, one flow renderer and one QR service, as they do in
 * `public/index.php`.
 *
 * One driver matters (#947 item 1): two drivers from one set of settings can
 * disagree about where a tenant's files live. Both entry points build theirs
 * from the same settings with {@see StorageDriverFactory::fromSettings()} and
 * route per tenant through {@see TenantRoutingStorageDriver}, so a document a
 * job issues lands where the same tenant's request would put it.
 *
 * Nothing is built until something issues or renders: a command that never
 * does (migrate, a backup) needs neither the render service nor storage nor
 * the key.
 */
final class WorkerDocumentParts
{
    private ?StorageDriverInterface $storage = null;
    private ?DocumentIssuer $issuer = null;
    private ?FlowDocumentRenderer $flow = null;
    private ?DocumentQrService $qr = null;
    private ?DocumentIssuing $issuing = null;
    private ?SdkDocumentRenderer $renderer = null;
    private ?DocumentArtifactRepository $artifacts = null;
    private ?DocumentRepository $documents = null;

    /** @param array<string, mixed> $env */
    public function __construct(
        private readonly PDO $pdo,
        private readonly SettingsService $settings,
        private readonly HookManager $hooks,
        private readonly DocumentFieldRegistry $fields,
        private readonly DocumentEmbedRegistry $embeds,
        private readonly array $env,
        private readonly string $storageRoot,
    ) {
    }

    /** The tenant-routing storage driver: the one this process writes documents through. */
    public function storage(): StorageDriverInterface
    {
        if ($this->storage === null) {
            $default = StorageDriverFactory::fromSettings($this->settings, $this->env, $this->storageRoot);
            $this->storage = new TenantRoutingStorageDriver($default, new TenantStorageResolver(
                $default,
                new TenantStorageConfigRepository($this->pdo),
                new EntitlementService(new TenantEntitlementRepository($this->pdo)),
                EncryptedSecretStore::fromEnv($this->env),
            ));
        }

        return $this->storage;
    }

    public function issuer(): DocumentIssuer
    {
        return $this->issuer ??= new DocumentIssuer(
            $this->pdo,
            $this->documents(),
            $this->artifacts(),
            new DocumentArtifactStore($this->storage()),
        );
    }

    public function flowRenderer(): FlowDocumentRenderer
    {
        return $this->flow ??= new FlowDocumentRenderer($this->settings, new RenderServiceClient(
            (string) ($this->env['RENDER_SERVICE_URL'] ?? 'http://render:8130'),
            (string) ($this->env['RENDER_SHARED_SECRET'] ?? ''),
            (int) ($this->env['RENDER_TIMEOUT_SECONDS'] ?? 30),
        ));
    }

    public function qr(): DocumentQrService
    {
        if ($this->qr === null) {
            $urls = FrontendUrls::fromEnv();
            $this->qr = new DocumentQrService(
                $this->pdo,
                new DocumentQrTokenRepository($this->pdo),
                new DocumentQrScanRepository($this->pdo),
                $urls->base(),
                $urls->link(FrontendUrls::DOCUMENT_VERIFY),
            );
        }

        return $this->qr;
    }

    /** The one issuing service, as `POST /api/documents` uses. */
    public function issuing(): DocumentIssuing
    {
        if ($this->issuing === null) {
            $storage = $this->storage();
            $this->issuing = new DocumentIssuing(
                new SourceDocumentPrinter(
                    $this->fields,
                    $this->embeds,
                    $this->settings,
                    new DocumentSourceFileStore($storage, $this->pdo),
                    new PrintLabels(),
                    new LetterheadLogo($this->settings, $storage),
                    new DocumentThemeRepository($this->pdo),
                    new WorkspaceName($this->pdo),
                ),
                new DocumentSourceVersionRepository($this->pdo),
                $this->issuer(),
                $this->settings,
                $this->flowRenderer(),
                $this->qr(),
                new MeterService(new DatabaseSharedStore($this->pdo), new EntitlementService(new TenantEntitlementRepository($this->pdo)), $this->settings),
                $this->hooks,
                $this->documents(),
                $this->artifacts(),
            );
        }

        return $this->issuing;
    }

    /**
     * The SDK rendering seam over the same renderer, issuer, QR service and
     * issuing service, so a job's document gets the same ceilings, storage
     * routing, veto and events as a request's.
     */
    public function sdkRenderer(): SdkDocumentRenderer
    {
        return $this->renderer ??= new SdkDocumentRenderer(
            $this->flowRenderer(),
            $this->issuer(),
            $this->settings,
            $this->qr(),
            $this->issuing(),
            $this->artifacts(),
        );
    }

    private function artifacts(): DocumentArtifactRepository
    {
        return $this->artifacts ??= new DocumentArtifactRepository($this->pdo);
    }

    private function documents(): DocumentRepository
    {
        return $this->documents ??= new DocumentRepository($this->pdo);
    }
}
