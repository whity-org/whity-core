<?php

declare(strict_types=1);

namespace Tests\Integration\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Calendar\BookingRepository;
use Whity\Core\Calendar\BookingService;
use Whity\Core\Calendar\CalendarRejectedException;
use Whity\Core\Calendar\CalendarRepository;
use Whity\Core\Calendar\Commission;
use Whity\Core\Calendar\CommissionCloses;
use Whity\Core\Calendar\ServiceRepository;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Sdk\Calendar\CommissionClose;
use Whity\Sdk\Contribute\WorkspaceCommissionExpenses;

/**
 * Closing a commission period by hand (WHIT-1334, design section 35 §4.3,
 * SL.6): only an ended period, never twice, frozen against later rate
 * changes, and handed to the books when something books commission.
 *
 * Example Bakery (tenant 1): bakers 11 and 12. Now is 18 November 2026.
 */
final class CommissionClosesRealEngineTest extends TestCase
{
    private const NOW = '2026-11-18 12:00:00';

    private PDO $pdo;
    private Commission $commission;
    private CalendarRepository $calendar;
    private int $tasting;
    /** @var list<CommissionClose> */
    private array $booked = [];
    private bool $booksRefuse = false;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Other Bakery', 'other-bakery')");
        $this->pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES (901, 'staff', '', NULL, CURRENT_TIMESTAMP)");
        foreach ([11, 12] as $profile) {
            $this->pdo->exec(
                "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                 VALUES ({$profile}, 'Baker {$profile}', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
            );
            $this->pdo->exec("INSERT INTO memberships (profile_id, tenant_id, role_id, status, kind, created_at) VALUES ({$profile}, 1, 901, 'active', 'member', CURRENT_TIMESTAMP)");
        }
        $settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        $settings->setTenant(1, 'timezone', 'Asia/Amman');
        $this->calendar = new CalendarRepository($this->pdo);
        $services = new ServiceRepository($this->pdo);
        $clock = static fn (): DateTimeImmutable => new DateTimeImmutable(self::NOW, new DateTimeZone('UTC'));
        $booking = new BookingService($this->pdo, $this->calendar, $services, new BookingRepository($this->pdo), $settings, null, clock: $clock);
        $this->commission = new Commission($this->pdo, $booking, $this->calendar, $services, $clock);
        $this->tasting = $services->insert(1, [
            'name' => ['en' => 'Cake tasting'], 'description' => [],
            'duration_minutes' => 30, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
            'price_minor' => 10000, 'currency' => 'JOD', 'bookable_online' => true, 'active' => true, 'sort_order' => 0,
        ]);
        foreach ([11, 12] as $baker) {
            $this->calendar->ensureStaff(1, $baker);
        }
        $this->commission->setRate(1, ['profile_id' => 11, 'percent' => '30'], 11);
        $this->booking(11, '2026-10-05 08:00:00');
        $this->booking(11, '2026-10-06 08:00:00');
        $this->booking(12, '2026-10-07 08:00:00');
    }

    public function testClosingLastMonthFreezesItAndOnlyOnce(): void
    {
        $closes = $this->closes(null);
        self::assertSame(['last_month'], array_column($closes->closable(1), 'id'), 'only an ended period');

        $close = $closes->close(1, 'last_month', 11);
        self::assertSame('2026-10-01', $close['from']);
        self::assertSame('2026-10-31', $close['to']);
        self::assertNull($close['expense_ref']);
        self::assertSame('Ready for the books', $close['books']);
        self::assertCount(1, $close['lines'], 'only people with a rate');
        self::assertSame(6000, $close['lines'][0]['commission_minor']);
        self::assertSame('JOD 6.000', $close['total']);

        // A later rate change does not move it.
        $this->commission->setRate(1, ['profile_id' => 11, 'percent' => '50'], 11);
        self::assertSame(6000, $closes->list(1)[0]['lines'][0]['commission_minor']);

        self::assertSame([], $closes->closable(1));
        try {
            $closes->close(1, 'last_month', 11);
            self::fail('Closed twice');
        } catch (CalendarRejectedException $e) {
            self::assertSame('already_closed', $e->extra['reason']);
        }
        // Another workspace sees none.
        self::assertSame([], $closes->list(2));
    }

    public function testAPeriodStillRunningCannotBeClosed(): void
    {
        $this->expectException(CalendarRejectedException::class);
        $this->closes(null)->close(1, 'this_month', 11);
    }

    public function testTheBooksGetTheCloseAndItsReference(): void
    {
        $close = $this->closes($this->books())->close(1, 'last_month', 11);

        self::assertSame('JE-0412', $close['expense_ref']);
        self::assertCount(1, $this->booked);
        self::assertSame(1, $this->booked[0]->workspaceId);
        self::assertSame('2026-10-01', $this->booked[0]->from);
        self::assertSame(11, $this->booked[0]->lines[0]->profileId);
        self::assertSame(20000, $this->booked[0]->lines[0]->salesMinor);

        $this->expectException(CalendarRejectedException::class);
        $this->closes($this->books())->book(1, (int) $close['id']);
    }

    public function testRefusedByTheBooksTheCloseStandsAndCanBeSentAgain(): void
    {
        $this->booksRefuse = true;
        $closes = $this->closes($this->books());
        $close = $closes->close(1, 'last_month', 11);
        self::assertNull($close['expense_ref']);

        $this->booksRefuse = false;
        self::assertSame('JE-0412', $closes->book(1, (int) $close['id'])['expense_ref']);
    }

    private function closes(?WorkspaceCommissionExpenses $books): CommissionCloses
    {
        return new CommissionCloses($this->pdo, $this->commission, $this->calendar, null, static fn (): ?WorkspaceCommissionExpenses => $books);
    }

    private function books(): WorkspaceCommissionExpenses
    {
        $test = $this;

        return new class ($test) implements WorkspaceCommissionExpenses {
            public function __construct(private readonly CommissionClosesRealEngineTest $test)
            {
            }

            public function record(CommissionClose $close): string
            {
                return $this->test->recordForBooks($close);
            }
        };
    }

    /** @internal the fake books' record(), here so it can see the test's state */
    public function recordForBooks(CommissionClose $close): string
    {
        if ($this->booksRefuse) {
            throw new \RuntimeException('The period is locked');
        }
        $this->booked[] = $close;

        return 'JE-0412';
    }

    private function booking(int $profile, string $starts): void
    {
        $ends = (new DateTimeImmutable($starts))->modify('+30 minutes')->format('Y-m-d H:i:s');
        $this->pdo->prepare(
            "INSERT INTO calendar_bookings (tenant_id, service_id, profile_id, starts_at, ends_at, blocks_from, blocks_until, status,
                    customer_name, source, price_minor, currency, created_at, updated_at)
             VALUES (1, :s, :p, :a, :b, :a, :b, 'confirmed', 'Lina', 'staff', 10000, 'JOD', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        )->execute([':s' => $this->tasting, ':p' => $profile, ':a' => $starts, ':b' => $ends]);
    }
}
