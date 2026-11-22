<?php

declare(strict_types=1);

namespace Whity\Core\Sales;

use Whity\Core\Document\Routing\RouteSubjectApprovedInterface;
use Whity\Core\Document\Routing\RouteSubjectProviderInterface;
use Whity\Core\Document\Routing\RouteSubjectRejectedInterface;
use Whity\Core\Document\Routing\RoutingRejectedException;
use Whity\Core\Inventory\InventoryDb;

/**
 * A quote travelling its route (WHIT-1321, design section 34 §3.2, QT.4):
 * subject type `sales.quote`. Approved, it is ready to send; turned down or
 * sent back, it is a draft again.
 */
final class QuoteRouteSubject implements RouteSubjectProviderInterface, RouteSubjectApprovedInterface, RouteSubjectRejectedInterface
{
    public const TYPE = 'sales.quote';

    public function __construct(
        private readonly InventoryDb $db,
        private readonly Quotes $quotes,
    ) {
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function title(int $tenantId, int $subjectId): ?string
    {
        $row = $this->quotes->row($tenantId, $subjectId);

        return $row === null ? null : trim('Quote ' . (string) ($row['number'] ?? ''));
    }

    public function documentId(int $tenantId, int $subjectId): ?int
    {
        return null;
    }

    public function author(int $tenantId, int $subjectId): ?int
    {
        return null;
    }

    public function snapshot(int $tenantId, int $subjectId): ?array
    {
        if ($this->quotes->row($tenantId, $subjectId) === null) {
            return null;
        }
        $quote = $this->quotes->get($tenantId, $subjectId);

        return [
            'number' => $quote['number'],
            'customer' => $quote['contact_name'],
            'valid_until' => $quote['valid_until'],
            'currency' => $quote['currency'],
            'total_minor' => $quote['total_minor'],
            'lines' => array_map(static fn (array $l): array => array_intersect_key($l, array_flip([
                'variant_id', 'description', 'sku', 'unit', 'quantity', 'price_minor', 'discount_bp', 'line_total_minor',
            ])), $quote['lines']),
        ];
    }

    public function editableWhenSentBack(): bool
    {
        return false;
    }

    public function isWithdrawn(int $tenantId, int $subjectId): bool
    {
        $row = $this->quotes->row($tenantId, $subjectId);

        return $row === null || $row['status'] === 'cancelled';
    }

    public function approved(int $tenantId, int $subjectId, int $routeId): void
    {
        try {
            $this->quotes->approve($tenantId, $subjectId, $this->decidedBy($tenantId, $routeId));
        } catch (SalesRejectedException $e) {
            throw RoutingRejectedException::because($e->clientMessage);
        }
    }

    public function rejected(int $tenantId, int $subjectId, int $routeId): void
    {
        $this->quotes->rejected($tenantId, $subjectId);
    }

    private function decidedBy(int $tenantId, int $routeId): ?int
    {
        return InventoryDb::int($this->db->one(
            'SELECT actor_profile_id FROM document_route_events
              WHERE tenant_id = :tenant_id AND route_id = :route_id AND verdict IS NOT NULL
              ORDER BY id DESC LIMIT 1',
            [':tenant_id' => $tenantId, ':route_id' => $routeId]
        )['actor_profile_id'] ?? null);
    }
}
