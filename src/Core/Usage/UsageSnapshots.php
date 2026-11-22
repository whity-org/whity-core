<?php

declare(strict_types=1);

namespace Whity\Core\Usage;

use DateTimeImmutable;
use PDO;
use Whity\Core\Entitlement\EntitlementService;
use Whity\Core\Entitlement\TenantEntitlementRepository;
use Whity\Core\Seat\SeatService;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;

/**
 * The live totals of the snapshot meters, read now and written to the ledger
 * (section 33 §5): staff seats, customers this month, inventory items,
 * invoices this month and stored bytes.
 *
 * Taken once a day for every workspace by {@see UsageSnapshotJob}, and again
 * whenever an owner opens the usage screen, so the month's `peak` is the
 * highest any of those readings saw.
 *
 * Each total comes from the place that already counts it: seats from
 * {@see SeatService} (members, not customers or agents), customers from
 * {@see CustomerUsage}, items from {@see PlanLimitsReport::liveItems()} (IV.24),
 * invoices from accounting's {@see InvoiceUsageCounter} (none until accounting
 * binds one) and bytes from {@see StorageUsage}.
 */
final class UsageSnapshots
{
    public function __construct(
        private readonly PDO $db,
        private readonly UsageLedger $ledger,
        private readonly SeatService $seats,
        private readonly CustomerUsage $customers,
    ) {
    }

    /** For the places that hold only a PDO: the queue worker, the CLI. */
    public static function forDatabase(PDO $db, ?UsageLedger $ledger = null): self
    {
        $settings = new SettingsService(new GlobalSettingsRepository($db), new TenantSettingsRepository($db));
        $entitlements = new EntitlementService(new TenantEntitlementRepository($db));

        return new self(
            $db,
            $ledger ?? new UsageLedger($db, $settings),
            new SeatService($db, $entitlements, $settings),
            new CustomerUsage($db, $entitlements, $settings),
        );
    }

    /**
     * Read every snapshot meter for one workspace and write it.
     *
     * @return array<string, int> meter => value read
     */
    public function take(int $tenantId, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $values = $this->read($tenantId, $now);
        foreach ($values as $meter => $value) {
            $this->ledger->snapshot($tenantId, $meter, $value, '', $now);
        }

        return $values;
    }

    /**
     * The live totals, without writing them.
     *
     * @return array<string, int> meter => value
     */
    public function read(int $tenantId, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $out = [];
        foreach ([
            UsageMeters::SEATS => fn (): int => $this->seats->used($tenantId),
            UsageMeters::CUSTOMERS => fn (): int => $this->customers->count($tenantId, $now),
            UsageMeters::ITEMS => fn (): int => PlanLimitsReport::liveItems($this->db, $tenantId),
            UsageMeters::INVOICES => fn (): int => $this->invoicesThisMonth($tenantId, $now),
            UsageMeters::STORAGE => fn (): int => StorageUsage::bytes($this->db, $tenantId),
        ] as $meter => $read) {
            // One meter that cannot be read never hides the others.
            try {
                $out[$meter] = max(0, $read());
            } catch (\Throwable $e) {
                error_log(sprintf('[usage] could not read %s for workspace %d: %s', $meter, $tenantId, $e->getMessage()));
            }
        }

        return $out;
    }

    /**
     * Accounting's counter when it has bound one, otherwise none (A2 is not
     * built yet). Read each time, so a counter bound after this was built is used.
     */
    public static function invoiceCounter(): InvoiceUsageCounter
    {
        $bound = $GLOBALS['whity_services'][InvoiceUsageCounter::class] ?? null;

        return $bound instanceof InvoiceUsageCounter ? $bound : new NoInvoicesYet();
    }

    private function invoicesThisMonth(int $tenantId, DateTimeImmutable $now): int
    {
        $from = $this->ledger->monthStart($tenantId, $now);

        return self::invoiceCounter()->issuedBetween($tenantId, $from, $from->modify('first day of next month midnight'));
    }
}
