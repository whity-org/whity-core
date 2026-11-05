<?php

declare(strict_types=1);

namespace Whity\Core\Plugin;

use Whity\Core\Document\Qr\DocumentQrPolicy;
use Whity\Core\Document\Qr\DocumentQrScanRepository;
use Whity\Core\Document\Qr\DocumentQrService;
use Whity\Core\Settings\SettingsService;
use Whity\Sdk\Documents\DocumentTracking;
use Whity\Sdk\Documents\Documents;
use Whity\Sdk\Documents\IssueRequest;
use Whity\Sdk\Documents\IssueResult;
use Whity\Sdk\Documents\PluginDocumentStore;

/**
 * One plugin's documents in one workspace (SDK 1.63, WHIT-886). Built by
 * {@see HostPluginDocuments::for()}.
 *
 * ORIGIN KEYS ARE NAMESPACED. The plugin's `originKey` is stored as
 * `plugin:{slug}:{originKey}`, so an idempotent replay can only return a document
 * this plugin issued, and two plugins may use the same key text without touching
 * each other's documents.
 *
 * EVERY ANSWER IS LIMITED TO THE PLUGIN'S OWN DOCUMENTS. A document issued by a
 * person, or by another plugin, is answered exactly as one that does not exist:
 * null, with nothing that says it is there.
 */
final class HostPluginDocumentStore implements PluginDocumentStore
{
    private const TRACKING_SCAN_LIMIT = 50;

    /**
     * @param \Closure(): Documents $documents
     * @param \Closure(): ?int      $tenant
     */
    public function __construct(
        private readonly PluginDocumentLinks $links,
        private readonly \Closure $documents,
        private readonly DocumentQrService $qr,
        private readonly DocumentQrScanRepository $scans,
        private readonly SettingsService $settings,
        private readonly string $slug,
        private readonly \Closure $tenant,
    ) {
    }

    public function issue(IssueRequest $request): IssueResult
    {
        $tenantId = $this->tenantId();

        $scopedKey = $request->originKey === null ? null : 'plugin:' . $this->slug . ':' . $request->originKey;
        $result = ($this->documents)()->issue(new IssueRequest(
            $request->template,
            $request->record,
            $request->values,
            $request->title,
            $scopedKey,
            $request->render,
        ));

        // Recorded straight after issuing, in the same request. A document that
        // ends up linked to another plugin is refused rather than handed back.
        $owner = $this->links->claim($tenantId, $this->slug, $result->documentId);
        if ($owner !== $this->slug) {
            throw new \RuntimeException('The issued document is already linked to another plugin.');
        }

        return $result;
    }

    public function qrVerificationUrl(int $documentId): ?string
    {
        $tenantId = $this->tenantId();
        if (!$this->links->owns($tenantId, $this->slug, $documentId)) {
            return null;
        }
        if (!DocumentQrPolicy::enabledForTenant($this->settings->effective($tenantId))) {
            return null;
        }

        // Idempotent: the code already in force is returned, so a re-print does
        // not retire the paper already out there.
        $row = $this->qr->ensure($tenantId, $documentId, null);
        if ($row === null || !is_string($row['token'] ?? null)) {
            return null;
        }

        return $this->qr->verificationUrl($row['token']);
    }

    public function tracking(int $documentId): ?DocumentTracking
    {
        $tenantId = $this->tenantId();
        if (!$this->links->owns($tenantId, $this->slug, $documentId)) {
            return null;
        }

        $rows = $this->scans->listForDocument($tenantId, $documentId, self::TRACKING_SCAN_LIMIT, 0);

        return new DocumentTracking(
            $documentId,
            $this->qr->active($tenantId, $documentId) !== null,
            $this->scans->countForDocument($tenantId, $documentId),
            array_map(static fn (array $row): array => [
                'scanned_at' => (string) ($row['scanned_at'] ?? ''),
                'outcome' => (string) ($row['outcome'] ?? ''),
            ], $rows),
        );
    }

    /** @throws \LogicException when no workspace is in scope */
    private function tenantId(): int
    {
        $tenantId = ($this->tenant)();
        if ($tenantId === null || $tenantId <= 0) {
            throw new \LogicException('No workspace is in scope, so there are no plugin documents to reach.');
        }

        return $tenantId;
    }
}
