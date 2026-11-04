<?php

declare(strict_types=1);

namespace Whity\Core\Plugin;

use PDO;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Document\Qr\DocumentQrPolicy;
use Whity\Core\Document\Qr\DocumentQrScanRepository;
use Whity\Core\Document\Qr\DocumentQrService;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Documents\Documents;
use Whity\Sdk\Documents\PluginDocuments;
use Whity\Sdk\Documents\PluginDocumentStore;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginNamespace;

/**
 * The host's plugin document service (SDK 1.63, WHIT-886).
 *
 * It adds ownership to the documents service it wraps, and nothing else. Issuing
 * still goes through {@see Documents}, so the document is made by the same rules
 * as any other. QR codes still go through {@see DocumentQrService}, so a code is
 * minted, rotated and resolved in one place. What is new here is the record of
 * which plugin issued each document ({@see PluginDocumentLinks}), and the check
 * that every plugin-facing answer is limited to that record.
 *
 * The documents service is taken lazily, because it is registered after this
 * one in the boot order.
 */
final class HostPluginDocuments implements PluginDocuments
{
    /** @param \Closure(): Documents $documents */
    public function __construct(
        private readonly PDO $db,
        private readonly \Closure $documents,
        private readonly DocumentQrService $qr,
        private readonly DocumentQrScanRepository $scans,
        private readonly SettingsService $settings,
        private readonly ?\Closure $tenant = null,
    ) {
    }

    public function for(PluginInterface $plugin): PluginDocumentStore
    {
        $slug = PluginNamespace::slug($plugin->getName());
        if ($slug === null) {
            throw new \InvalidArgumentException('This plugin has no usable name, so it can issue no documents.');
        }

        return new HostPluginDocumentStore(
            new PluginDocumentLinks($this->db),
            $this->documents,
            $this->qr,
            $this->scans,
            $this->settings,
            $slug,
            $this->tenant ?? static fn (): ?int => TenantContext::getTenantId(),
        );
    }
}
