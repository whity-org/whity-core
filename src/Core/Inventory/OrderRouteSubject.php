<?php

declare(strict_types=1);

namespace Whity\Core\Inventory;

use Whity\Core\Document\Routing\RouteSubjectApprovedInterface;
use Whity\Core\Document\Routing\RouteSubjectProviderInterface;
use Whity\Core\Document\Routing\RouteSubjectRejectedInterface;
use Whity\Core\Document\Routing\RoutingRejectedException;

/**
 * A purchase order travelling its route (WHIT-1234, design 31 §4.2, register
 * IV.12, IV.19): subject type `inventory.purchase_order`. Approved, the order
 * can be received; turned down or sent back, it returns to draft.
 */
final class OrderRouteSubject implements RouteSubjectProviderInterface, RouteSubjectApprovedInterface, RouteSubjectRejectedInterface
{
    public const PURCHASE_ORDER = 'inventory.purchase_order';

    public function __construct(
        private readonly InventoryDb $db,
        private readonly PurchaseOrders $orders,
    ) {
    }

    public function type(): string
    {
        return self::PURCHASE_ORDER;
    }

    public function title(int $tenantId, int $subjectId): ?string
    {
        $row = $this->orders->row($tenantId, $subjectId);

        return $row === null ? null : 'Purchase order ' . (string) $row['number'];
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
        if ($this->orders->row($tenantId, $subjectId) === null) {
            return null;
        }
        $order = $this->orders->get($tenantId, $subjectId);

        return [
            'number' => $order['number'],
            'supplier' => $order['contact_name'],
            'location_id' => $order['location_id'],
            'expected_on' => $order['expected_on'],
            'currency' => $order['currency'],
            'total_minor' => $order['total_minor'],
            'lines' => array_map(static fn (array $l): array => array_intersect_key($l, array_flip([
                'variant_id', 'item_name', 'sku', 'unit', 'quantity', 'price_minor', 'line_total_minor',
            ])), $order['lines']),
        ];
    }

    public function editableWhenSentBack(): bool
    {
        return false;
    }

    public function isWithdrawn(int $tenantId, int $subjectId): bool
    {
        $row = $this->orders->row($tenantId, $subjectId);

        return $row === null || $row['status'] === 'cancelled';
    }

    public function approved(int $tenantId, int $subjectId, int $routeId): void
    {
        try {
            $this->orders->approve($tenantId, $subjectId, $this->decidedBy($tenantId, $routeId));
        } catch (InventoryRejectedException $e) {
            throw RoutingRejectedException::because($e->clientMessage);
        }
    }

    public function rejected(int $tenantId, int $subjectId, int $routeId): void
    {
        $this->orders->rejected($tenantId, $subjectId, $this->decidedBy($tenantId, $routeId));
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
