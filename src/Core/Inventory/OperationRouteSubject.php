<?php

declare(strict_types=1);

namespace Whity\Core\Inventory;

use Whity\Core\Document\Routing\RouteSubjectApprovedInterface;
use Whity\Core\Document\Routing\RouteSubjectProviderInterface;
use Whity\Core\Document\Routing\RouteSubjectRejectedInterface;
use Whity\Core\Document\Routing\RoutingRejectedException;

/**
 * An adjustment, or a count's differences, travelling its route (WHIT-1233,
 * design 31 §4.2, register IV.14, IV.15, IV.19). Two subject types, one per
 * kind: `inventory.adjustment` and `inventory.count`.
 *
 * Approved, the operation posts inside the route's own transaction, so a
 * refusal (a `stock.moving` veto) rolls the approval back and says why.
 * Turned down, it goes back to draft. Sent back, the same: the author fixes
 * it by cancelling and writing it again, as stock lines are not edited in
 * place.
 */
final class OperationRouteSubject implements RouteSubjectProviderInterface, RouteSubjectApprovedInterface, RouteSubjectRejectedInterface
{
    public const ADJUSTMENT = 'inventory.adjustment';
    public const COUNT = 'inventory.count';

    private const KIND = [self::ADJUSTMENT => 'adjustment', self::COUNT => 'count'];

    public function __construct(
        private readonly string $type,
        private readonly InventoryDb $db,
        private readonly StockOperations $operations,
    ) {
        if (!isset(self::KIND[$type])) {
            throw new \InvalidArgumentException('Unknown inventory route subject ' . $type);
        }
    }

    public static function typeFor(string $kind): string
    {
        return $kind === 'count' ? self::COUNT : self::ADJUSTMENT;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function title(int $tenantId, int $subjectId): ?string
    {
        $op = $this->row($tenantId, $subjectId);

        return $op === null ? null : ($op['kind'] === 'count' ? 'Stock count ' : 'Stock adjustment ') . (string) $op['number'];
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
        if ($this->row($tenantId, $subjectId) === null) {
            return null;
        }
        $op = $this->operations->get($tenantId, $subjectId);

        return [
            'number' => $op['number'],
            'location_id' => $op['location_id'],
            'reason_code' => $op['reason_code'],
            'value_minor' => $op['value_minor'],
            'currency' => $op['currency'],
            'note' => $op['note'],
            'lines' => array_map(static fn (array $l): array => array_intersect_key($l, array_flip([
                'variant_id', 'item_name', 'sku', 'unit', 'change', 'expected', 'counted', 'difference', 'reason_code', 'value_minor', 'currency',
            ])), $op['lines']),
        ];
    }

    public function editableWhenSentBack(): bool
    {
        return false;
    }

    public function isWithdrawn(int $tenantId, int $subjectId): bool
    {
        $op = $this->row($tenantId, $subjectId);

        return $op === null || $op['status'] === 'cancelled';
    }

    public function approved(int $tenantId, int $subjectId, int $routeId): void
    {
        try {
            $this->operations->approved($tenantId, $subjectId, $this->decidedBy($tenantId, $routeId));
        } catch (InventoryRejectedException $e) {
            throw RoutingRejectedException::because($e->clientMessage);
        }
    }

    public function rejected(int $tenantId, int $subjectId, int $routeId): void
    {
        $this->operations->rejected($tenantId, $subjectId);
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

    /** @return array<string, mixed>|null */
    private function row(int $tenantId, int $id): ?array
    {
        $row = $this->operations->row($tenantId, $id);

        return $row !== null && $row['kind'] === self::KIND[$this->type] ? $row : null;
    }
}
