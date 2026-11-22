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
 *  - `kind` `count`: staff, customers this month, agent work, documents,
 *    items in inventory (each variant counted; only when the plan sets a
 *    number).
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
        // The usage ledger (section 33 §5). When given, messages and agent runs
        // are read from it rather than from the short-lived meter, and the
        // invoices, API calls and storage meters are shown too.
        private readonly ?UsageLedger $ledger = null,
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

        $itemLimit = $this->entitlements->limit($tenantId, EntitlementRegistry::INVENTORY_ITEMS_MAX);
        if ($itemLimit !== EntitlementRegistry::UNLIMITED) {
            $rows[] = self::count(EntitlementRegistry::INVENTORY_ITEMS_MAX, self::liveItems($this->db, $tenantId), $itemLimit, null);
        }

        $metered = self::METERED;
        if ($this->ledger !== null) {
            $metered = array_values(array_diff($metered, [
                EntitlementRegistry::AGENTS_RUNS_PER_MONTH,
                EntitlementRegistry::MESSAGES_OUTBOUND_PER_MONTH,
            ]));
            array_push($rows, ...$this->fromLedger($this->ledger, $tenantId));
        }

        foreach ($this->meters->peek($tenantId, $metered) as $key => $meter) {
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
     * The ledger's meters that have a limit, in the order they are shown.
     *
     * @return list<array{key: string, kind: string, used: int|null, limit: int|null, over: bool, resets_at: string|null}>
     */
    private function fromLedger(UsageLedger $ledger, int $tenantId): array
    {
        $month = $ledger->month($tenantId, $ledger->period($tenantId));
        $sum = static function (string $meter, string $only = '') use ($month): int {
            $total = 0;
            foreach ($month[$meter] ?? [] as $dimension => $row) {
                if ($only === '' || $dimension === $only) {
                    $total += $row['quantity'];
                }
            }

            return $total;
        };
        $resets = $ledger->resetsAt($tenantId);

        $readers = [
            EntitlementRegistry::AGENTS_RUNS_PER_MONTH => [static fn (): int => $sum(UsageMeters::AGENTS, UsageMeters::AGENT_RUNS), $resets],
            EntitlementRegistry::MESSAGES_OUTBOUND_PER_MONTH => [static fn (): int => $sum(UsageMeters::MESSAGES), $resets],
            EntitlementRegistry::API_CALLS_PER_MONTH => [static fn (): int => $sum(UsageMeters::API_CALLS), $resets],
            EntitlementRegistry::INVOICES_PER_MONTH => [function () use ($ledger, $tenantId): int {
                $from = $ledger->monthStart($tenantId);

                return UsageSnapshots::invoiceCounter()->issuedBetween($tenantId, $from, $from->modify('first day of next month midnight'));
            }, $resets],
            EntitlementRegistry::STORAGE_QUOTA_BYTES => [fn (): int => StorageUsage::bytes($this->db, $tenantId), null],
        ];

        $rows = [];
        foreach ($readers as $key => [$read, $resetsAt]) {
            $limit = $this->entitlements->limit($tenantId, $key);
            // Unlimited is left out, as the meters above are.
            if ($limit === EntitlementRegistry::UNLIMITED) {
                continue;
            }
            $rows[] = self::count($key, $read(), $limit, $resetsAt);
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

    /** Live variants of live items: what `inventory.items.max` counts (IV.24). */
    /** Items kept in inventory now, each variant counted, archived ones not (IV.24). */
    public static function liveItems(PDO $db, int $tenantId): int
    {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM inv_variants v
               JOIN inv_items i ON i.tenant_id = v.tenant_id AND i.id = v.item_id
              WHERE v.tenant_id = :tenant_id AND v.archived_at IS NULL AND i.archived_at IS NULL AND i.kind <> :service'
        );
        // Services are not stock: the owner kept them off the items meter
        // (WHIT-1334, SL.8, Q2), and every calendar service is one.
        $stmt->execute([':tenant_id' => $tenantId, ':service' => 'service']);

        return (int) $stmt->fetchColumn();
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
