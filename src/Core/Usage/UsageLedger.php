<?php

declare(strict_types=1);

namespace Whity\Core\Usage;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;

/**
 * The durable usage history a bill is made from (section 33 §5, BM.17).
 *
 * One row per workspace, meter, month and dimension in `usage_ledger`
 * (migration 332). Counted meters are added to as things happen
 * ({@see add()}); snapshot meters are set from a live total
 * ({@see snapshot()}), keeping the month's highest beside the latest.
 *
 * ── Never in the way ───────────────────────────────────────────────────────
 *
 * Counting never stops what it counts: every write here swallows its own
 * failure and logs it. A message is sent, a call answered and a file kept
 * whether or not the ledger could be written. Nothing here refuses anything:
 * every limit only warns (BM.4, BM.17).
 *
 * ── Whose month ────────────────────────────────────────────────────────────
 *
 * The workspace's, in its own time zone, as {@see CustomerUsage} and
 * {@see \Whity\Core\Entitlement\MeterService} read it, so the ledger's month is
 * the month the plan's allowance resets on.
 */
final class UsageLedger
{
    /**
     * Each workspace's zone, remembered for a few minutes in this worker so a
     * counted API call does not read settings every time. A changed time zone
     * reaches the ledger within ZONE_TTL_SECONDS.
     *
     * @var array<int, array{0: DateTimeZone, 1: int}>
     */
    private array $zones = [];

    private const ZONE_CACHE_MAX = 256;

    private const ZONE_TTL_SECONDS = 300;

    public function __construct(
        private readonly PDO $db,
        private readonly SettingsService $settings,
    ) {
    }

    /** For the places that hold only a PDO: the CLI, the queue worker. */
    public static function forDatabase(PDO $db): self
    {
        return new self($db, new SettingsService(new GlobalSettingsRepository($db), new TenantSettingsRepository($db)));
    }

    /** Add to a counted meter. Never throws. */
    public function add(int $tenantId, string $meter, int $units = 1, string $dimension = '', ?DateTimeImmutable $now = null): void
    {
        if ($tenantId <= 0 || $units <= 0) {
            return;
        }
        try {
            $this->addOrFail($tenantId, $meter, $units, $dimension, $now ?? new DateTimeImmutable());
        } catch (\Throwable $e) {
            error_log(sprintf('[usage] could not count %s for workspace %d: %s', $meter, $tenantId, $e->getMessage()));
        }
    }

    /** Set a snapshot meter from a live total. Never throws. */
    public function snapshot(int $tenantId, string $meter, int $value, string $dimension = '', ?DateTimeImmutable $now = null): void
    {
        if ($tenantId <= 0) {
            return;
        }
        try {
            $this->snapshotOrFail($tenantId, $meter, max(0, $value), $dimension, $now ?? new DateTimeImmutable());
        } catch (\Throwable $e) {
            error_log(sprintf('[usage] could not snapshot %s for workspace %d: %s', $meter, $tenantId, $e->getMessage()));
        }
    }

    /**
     * A workspace's rows for one month, meter => dimension => row.
     *
     * @return array<string, array<string, array{kind: string, quantity: int, peak: int, updated_at: string}>>
     */
    public function month(int $tenantId, string $period): array
    {
        $stmt = $this->db->prepare(
            'SELECT meter, dimension, kind, quantity, peak, updated_at
               FROM usage_ledger
              WHERE tenant_id = :tenant_id AND period = :period
              ORDER BY meter, dimension'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':period' => $period]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string) $row['meter']][(string) $row['dimension']] = [
                'kind' => (string) $row['kind'],
                'quantity' => (int) $row['quantity'],
                'peak' => (int) $row['peak'],
                'updated_at' => (string) $row['updated_at'],
            ];
        }

        return $out;
    }

    /**
     * The months a workspace has rows for, newest first.
     *
     * @return list<string>
     */
    public function periods(int $tenantId, int $limit = 12): array
    {
        $stmt = $this->db->prepare(
            'SELECT DISTINCT period FROM usage_ledger WHERE tenant_id = :tenant_id ORDER BY period DESC LIMIT ' . max(1, min(120, $limit))
        );
        $stmt->execute([':tenant_id' => $tenantId]);

        return array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** The workspace's month for this moment, `YYYY-MM`. */
    public function period(int $tenantId, ?DateTimeImmutable $now = null): string
    {
        return ($now ?? new DateTimeImmutable())->setTimezone($this->zoneFor($tenantId))->format('Y-m');
    }

    /** When the workspace's month began, in its own zone. */
    public function monthStart(int $tenantId, ?DateTimeImmutable $now = null): DateTimeImmutable
    {
        return ($now ?? new DateTimeImmutable())
            ->setTimezone($this->zoneFor($tenantId))
            ->modify('first day of this month midnight');
    }

    /** When the workspace's month ends, as an ISO-8601 time in its own zone. */
    public function resetsAt(int $tenantId, ?DateTimeImmutable $now = null): string
    {
        return ($now ?? new DateTimeImmutable())
            ->setTimezone($this->zoneFor($tenantId))
            ->modify('first day of next month midnight')
            ->format(DATE_ATOM);
    }

    private function addOrFail(int $tenantId, string $meter, int $units, string $dimension, DateTimeImmutable $now): void
    {
        if (UsageMeters::kindOf($meter) !== UsageMeters::KIND_COUNTED) {
            throw new \InvalidArgumentException("{$meter} is a snapshot meter; it is set, not added to.");
        }

        $stmt = $this->db->prepare(
            "INSERT INTO usage_ledger (tenant_id, meter, period, dimension, kind, quantity, peak, first_at, updated_at)
             VALUES (:tenant_id, :meter, :period, :dimension, 'counted', :units, :units2, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
             ON CONFLICT (tenant_id, meter, period, dimension) DO UPDATE
                SET quantity = usage_ledger.quantity + EXCLUDED.quantity,
                    peak = usage_ledger.quantity + EXCLUDED.quantity,
                    updated_at = EXCLUDED.updated_at"
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':meter' => $meter,
            ':period' => $this->period($tenantId, $now),
            ':dimension' => UsageMeters::cleanDimension($dimension),
            ':units' => $units,
            ':units2' => $units,
        ]);
    }

    private function snapshotOrFail(int $tenantId, string $meter, int $value, string $dimension, DateTimeImmutable $now): void
    {
        if (UsageMeters::kindOf($meter) !== UsageMeters::KIND_SNAPSHOT) {
            throw new \InvalidArgumentException("{$meter} is a counted meter; it is added to, not set.");
        }

        $stmt = $this->db->prepare(
            "INSERT INTO usage_ledger (tenant_id, meter, period, dimension, kind, quantity, peak, first_at, updated_at)
             VALUES (:tenant_id, :meter, :period, :dimension, 'snapshot', :value, :value2, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
             ON CONFLICT (tenant_id, meter, period, dimension) DO UPDATE
                SET quantity = EXCLUDED.quantity,
                    peak = CASE WHEN EXCLUDED.quantity > usage_ledger.peak THEN EXCLUDED.quantity ELSE usage_ledger.peak END,
                    updated_at = EXCLUDED.updated_at"
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':meter' => $meter,
            ':period' => $this->period($tenantId, $now),
            ':dimension' => UsageMeters::cleanDimension($dimension),
            ':value' => $value,
            ':value2' => $value,
        ]);
    }

    private function zoneFor(int $tenantId): DateTimeZone
    {
        $now = time();
        if (isset($this->zones[$tenantId]) && $this->zones[$tenantId][1] > $now) {
            return $this->zones[$tenantId][0];
        }

        $value = $this->settings->effective($tenantId)[SettingsRegistry::TIMEZONE] ?? null;
        try {
            $zone = new DateTimeZone(is_string($value) && $value !== '' ? $value : 'UTC');
        } catch (\Exception) {
            $zone = new DateTimeZone('UTC');
        }

        if (count($this->zones) >= self::ZONE_CACHE_MAX) {
            $this->zones = [];
        }

        $this->zones[$tenantId] = [$zone, $now + self::ZONE_TTL_SECONDS];

        return $zone;
    }
}
