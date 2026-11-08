<?php

declare(strict_types=1);

namespace Whity\Core\Billing\External;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Container\HostWiredService;
use Whity\Core\Store\SharedStoreInterface;
use Whity\Sdk\Billing\AccessRefresh;

/**
 * {@see AccessRefresh}: re-read one workspace's access through the active
 * billing portal and record it (SDK 1.86, WHIT-1013).
 *
 * The same path as a verified provider notification:
 * {@see BillingPortal::accessFor()} and then {@see AccessRecorder::record()}.
 * The caller supplies only the subject. Any plugin may call this, so it can
 * make the host ask; it can never tell the host the answer.
 *
 * THROTTLED per subject: one re-read per subject per window. A repeated call
 * inside the window is a no-op, so a loop in a plugin cannot hammer the
 * provider through this. Audited as `billing.access_refreshed`, with what the
 * portal said, so an operator can see who was re-read and with what result.
 */
final class HostAccessRefresh implements AccessRefresh, HostWiredService
{
    public const WINDOW_SECONDS = 30;
    private const PREFIX = 'billing:refresh:';

    public function __construct(
        private readonly BillingPortal $portal,
        private readonly AccessRecorder $recorder,
        private readonly SharedStoreInterface $store,
        private readonly ?AuditLogger $audit = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly int $windowSeconds = self::WINDOW_SECONDS,
    ) {
    }

    public function refresh(string $subjectRef): bool
    {
        $tenantId = BillingSubject::tenantIdFrom($subjectRef);
        if ($tenantId === null || !$this->portal->isConfigured()) {
            return false;
        }

        if ($this->store->increment(self::PREFIX . $subjectRef, $this->windowSeconds) > 1) {
            return false;
        }

        try {
            $snapshot = $this->portal->accessFor($subjectRef);
        } catch (BillingPortalException $e) {
            $this->logger->warning('A requested billing access refresh could not be read', [
                'tenant_id' => $tenantId,
                'reason' => $e->reason,
            ]);
            // Let a later call try again rather than waiting out the window.
            $this->store->delete(self::PREFIX . $subjectRef);

            return false;
        }

        $this->recorder->record($tenantId, $snapshot);

        $this->audit?->record('billing.access_refreshed', [
            'tenant_id' => $tenantId,
            'target_type' => 'tenant',
            'target_id' => $tenantId,
            'metadata' => [
                'has_access' => $snapshot->hasAccess,
                'status' => $snapshot->status,
                'plan' => $snapshot->planCode,
            ],
        ]);

        return true;
    }
}
