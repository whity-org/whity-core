<?php

declare(strict_types=1);

namespace Whity\Core\Accounting;

use Whity\Core\Document\Routing\RouteSubjectApprovedInterface;
use Whity\Core\Document\Routing\RouteSubjectProviderInterface;
use Whity\Core\Document\Routing\RouteSubjectRejectedInterface;
use Whity\Core\Document\Routing\RoutingRejectedException;

/**
 * An invoice travelling its route (WHIT-1318 A2a, design section 32 §9.1):
 * subject type `accounting.invoice`. Approved, it is issued as the person who
 * decided; turned down or sent back, it is a draft again.
 */
final class InvoiceRouteSubject implements RouteSubjectProviderInterface, RouteSubjectApprovedInterface, RouteSubjectRejectedInterface
{
    public const TYPE = 'accounting.invoice';

    public function __construct(
        private readonly AccountingDb $db,
        private readonly Invoices $invoices,
    ) {
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function title(int $tenantId, int $subjectId): ?string
    {
        $row = $this->invoices->row($tenantId, $subjectId);

        return $row === null ? null : 'Invoice' . (is_string($row['number']) ? ' ' . $row['number'] : '');
    }

    public function documentId(int $tenantId, int $subjectId): ?int
    {
        return null;
    }

    public function author(int $tenantId, int $subjectId): ?int
    {
        return AccountingDb::int($this->invoices->row($tenantId, $subjectId)['created_by_profile_id'] ?? null);
    }

    public function snapshot(int $tenantId, int $subjectId): ?array
    {
        if ($this->invoices->row($tenantId, $subjectId) === null) {
            return null;
        }
        $invoice = $this->invoices->get($tenantId, $subjectId);

        return [
            'customer' => $invoice['contact_name'],
            'issue_date' => $invoice['issue_date'],
            'due_date' => $invoice['due_date'],
            'currency' => $invoice['currency'],
            'subtotal_minor' => $invoice['subtotal_minor'],
            'tax_minor' => $invoice['tax_minor'],
            'total_minor' => $invoice['total_minor'],
            'lines' => array_map(static fn (array $l): array => array_intersect_key($l, array_flip([
                'variant_id', 'description', 'quantity', 'quantity_decimals', 'unit_price_minor', 'discount_bp', 'tax_code', 'total_minor',
            ])), $invoice['lines']),
        ];
    }

    public function editableWhenSentBack(): bool
    {
        return false;
    }

    public function isWithdrawn(int $tenantId, int $subjectId): bool
    {
        $row = $this->invoices->row($tenantId, $subjectId);

        return $row === null || $row['status'] === 'cancelled';
    }

    public function approved(int $tenantId, int $subjectId, int $routeId): void
    {
        try {
            $this->invoices->approve($tenantId, $subjectId, $this->decidedBy($tenantId, $routeId));
        } catch (AccountingRejectedException $e) {
            throw RoutingRejectedException::because($e->clientMessage);
        }
    }

    public function rejected(int $tenantId, int $subjectId, int $routeId): void
    {
        $this->invoices->rejected($tenantId, $subjectId);
    }

    private function decidedBy(int $tenantId, int $routeId): ?int
    {
        return AccountingDb::int($this->db->one(
            'SELECT actor_profile_id FROM document_route_events
              WHERE tenant_id = :tenant_id AND route_id = :route_id AND verdict IS NOT NULL
              ORDER BY id DESC LIMIT 1',
            [':tenant_id' => $tenantId, ':route_id' => $routeId]
        )['actor_profile_id'] ?? null);
    }
}
