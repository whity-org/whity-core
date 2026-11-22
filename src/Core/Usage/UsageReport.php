<?php

declare(strict_types=1);

namespace Whity\Core\Usage;

use DateTimeImmutable;
use Whity\Core\Entitlement\EntitlementRegistry;
use Whity\Core\Entitlement\EntitlementService;

/**
 * Every meter for one workspace and one month: what was used, the base, and
 * whether it is near or past it (section 33 §5, SC.14). What the usage screen,
 * `GET /api/usage` and the SDK's Usage service show.
 *
 * The current month takes fresh snapshots first, so the numbers are live;
 * an earlier month is read from the ledger as it was kept.
 *
 * Nothing here refuses anything. `over` is a warning (BM.4): the workspace
 * carries on, and an owner may buy a pack once paid plans sell them. A base of
 * null means no limit, which is what a self-hosted install has (BM.3 Q14).
 */
final class UsageReport
{
    /** SC.14: near is 80%, said quietly on Billing only. */
    public const NEAR_RATIO = 0.8;

    public const STATE_UNLIMITED = 'unlimited';
    public const STATE_OK = 'ok';
    public const STATE_NEAR = 'near';
    public const STATE_OVER = 'over';

    /** Meters whose total is held, not used up in a month. */
    private const STANDING = [UsageMeters::SEATS, UsageMeters::ITEMS, UsageMeters::STORAGE];

    public function __construct(
        private readonly UsageLedger $ledger,
        private readonly UsageSnapshots $snapshots,
        private readonly EntitlementService $entitlements,
    ) {
    }

    /**
     * @param string|null $period `YYYY-MM`; null for the current month.
     * @return array{period: string, current: bool, resets_at: string|null, meters: list<array<string, mixed>>, periods: list<string>}
     */
    public function forTenant(int $tenantId, ?string $period = null, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $currentPeriod = $this->ledger->period($tenantId, $now);
        $period ??= $currentPeriod;
        $current = $period === $currentPeriod;

        if ($current) {
            $this->snapshots->take($tenantId, $now);
        }
        $rows = $this->ledger->month($tenantId, $period);

        $meters = [];
        foreach (UsageMeters::all() as $meter) {
            $meters[] = $this->row($tenantId, $meter, $rows[$meter] ?? [], $current, $current ? $this->ledger->resetsAt($tenantId, $now) : null);
        }

        $periods = $this->ledger->periods($tenantId);
        if (!in_array($currentPeriod, $periods, true)) {
            array_unshift($periods, $currentPeriod);
        }

        return [
            'period' => $period,
            'current' => $current,
            'resets_at' => $current ? $this->ledger->resetsAt($tenantId, $now) : null,
            'meters' => $meters,
            'periods' => $periods,
        ];
    }

    /**
     * @param array<string, array{kind: string, quantity: int, peak: int, updated_at: string}> $dimensions
     * @return array<string, mixed>
     */
    private function row(int $tenantId, string $meter, array $dimensions, bool $current, ?string $monthEnds): array
    {
        $key = UsageMeters::entitlementFor($meter);
        $kind = UsageMeters::kindOf($meter);
        $baseDimension = UsageMeters::baseDimension($meter);

        $used = 0;
        $peak = 0;
        $split = [];
        foreach ($dimensions as $dimension => $row) {
            if ($dimension !== '') {
                $split[$dimension] = $row['quantity'];
            }
            if ($baseDimension === '' || $dimension === $baseDimension) {
                $used += $row['quantity'];
                $peak += $row['peak'];
            }
        }

        // A past month shows the plan as it is now: the ledger keeps what was
        // used, not what the plan allowed then.
        $limit = $this->entitlements->limit($tenantId, $key);
        $base = $limit === EntitlementRegistry::UNLIMITED ? null : $limit;
        $measured = $kind === UsageMeters::KIND_SNAPSHOT && !$current ? $peak : $used;

        return [
            'meter' => $meter,
            'key' => $key,
            'kind' => $kind,
            'unit' => $meter === UsageMeters::STORAGE ? 'bytes' : 'count',
            'used' => $used,
            'peak' => $kind === UsageMeters::KIND_SNAPSHOT ? max($peak, $used) : $used,
            'base' => $base,
            'state' => self::stateOf($measured, $base),
            'dimensions' => $split,
            'resets_at' => in_array($meter, self::STANDING, true) ? null : $monthEnds,
        ];
    }

    public static function stateOf(int $used, ?int $base): string
    {
        if ($base === null) {
            return self::STATE_UNLIMITED;
        }
        if ($used > $base) {
            return self::STATE_OVER;
        }

        return $base > 0 && $used >= (int) ceil($base * self::NEAR_RATIO) ? self::STATE_NEAR : self::STATE_OK;
    }
}
