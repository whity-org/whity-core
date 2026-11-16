<?php

declare(strict_types=1);

namespace Whity\Core\Usage;

use PDO;
use Whity\Core\Entitlement\EntitlementRegistry;
use Whity\Core\Entitlement\EntitlementService;
use Whity\Core\Entitlement\MeterService;
use Whity\Core\Seat\SeatService;

/**
 * Where a workspace stands against each limit its plan sets (WHIT-932).
 *
 * The read side of "passing a limit warns first": the server already counts
 * and lets the work go ahead; this is what the workspace's admins are shown so
 * the warning reaches a person rather than only the server log.
 *
 * One row per limit that has a number or a feature in use:
 *
 *  - `kind` `count`: staff, customers this month, agent work, documents.
 *    `limit` null means unlimited; `over` is true once `used` passes it.
 *  - `kind` `feature`: a brand feature the workspace is using without its plan
 *    including it (today: its own web address). Shown only when that is so.
 *
 * Reads only; nothing here counts or spends anything.
 */
final class PlanLimitsReport
{
    /** Metered limits read through the meter, in the order they are shown. */
    private const METERED = [
        EntitlementRegistry::AGENTS_RUNS_PER_MONTH,
        EntitlementRegistry::MESSAGES_OUTBOUND_PER_MONTH,
        EntitlementRegistry::DOCUMENTS_RENDER_PER_DAY,
        EntitlementRegistry::DOCUMENTS_RENDER_PER_MONTH,
    ];

    public function __construct(
        private readonly PDO $db,
        private readonly EntitlementService $entitlements,
        private readonly SeatService $seats,
        private readonly CustomerUsage $customers,
        private readonly MeterService $meters,
    ) {
    }

    /**
     * @return list<array{key: string, kind: string, used: int|null, limit: int|null, over: bool, resets_at: string|null}>
     */
    public function forTenant(int $tenantId): array
    {
        $rows = [];

        $seatLimit = $this->seats->limit($tenantId);
        $rows[] = self::count(
            EntitlementRegistry::MEMBERS_MAX,
            $this->seats->used($tenantId),
            $seatLimit,
            null,
        );

        $rows[] = self::count(
            EntitlementRegistry::CUSTOMERS_PER_MONTH,
            $this->customers->count($tenantId),
            $this->entitlements->limit($tenantId, EntitlementRegistry::CUSTOMERS_PER_MONTH),
            $this->customers->resetsAt($tenantId),
        );

        foreach ($this->meters->peek($tenantId, self::METERED) as $key => $meter) {
            // An unlimited meter is not counted at all, so it has no number to
            // show; leaving it out beats showing "0 used" that is not true.
            if ($meter['limit'] === EntitlementRegistry::UNLIMITED) {
                continue;
            }
            $rows[] = self::count($key, $meter['used'], $meter['limit'], $meter['resets_at']);
        }

        if ($this->usesOwnWebAddress($tenantId)
            && !$this->entitlements->isGranted($tenantId, EntitlementRegistry::BRAND_CUSTOM_DOMAIN)) {
            $rows[] = [
                'key'       => EntitlementRegistry::BRAND_CUSTOM_DOMAIN,
                'kind'      => 'feature',
                'used'      => null,
                'limit'     => null,
                'over'      => true,
                'resets_at' => null,
            ];
        }

        return $rows;
    }

    /**
     * @return array{key: string, kind: string, used: int|null, limit: int|null, over: bool, resets_at: string|null}
     */
    private static function count(string $key, int $used, int $limit, ?string $resetsAt): array
    {
        $unlimited = $limit === EntitlementRegistry::UNLIMITED;

        return [
            'key'       => $key,
            'kind'      => 'count',
            'used'      => $used,
            'limit'     => $unlimited ? null : $limit,
            'over'      => !$unlimited && $used > $limit,
            'resets_at' => $resetsAt,
        ];
    }

    private function usesOwnWebAddress(int $tenantId): bool
    {
        // The tenants registry, read by id: this tenant's own row.
        $stmt = $this->db->prepare('SELECT branding_host FROM tenants WHERE id = :id');
        $stmt->execute([':id' => $tenantId]);
        $host = $stmt->fetchColumn();

        return is_string($host) && $host !== '';
    }
}
