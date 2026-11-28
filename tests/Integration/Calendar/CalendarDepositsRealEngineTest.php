<?php

declare(strict_types=1);

namespace Tests\Integration\Calendar;

use Database\Migrations\AddCalendarDeposits;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Audit\AuditLoggerInterface;
use Whity\Core\Calendar\BookingRepository;
use Whity\Core\Calendar\BookingService;
use Whity\Core\Calendar\CalendarPlugins;
use Whity\Core\Calendar\CalendarRejectedException;
use Whity\Core\Calendar\CalendarRepository;
use Whity\Core\Calendar\Deposits;
use Whity\Core\Calendar\EmbedOrigins;
use Whity\Core\Calendar\HostBookings;
use Whity\Core\Calendar\Interval;
use Whity\Core\Calendar\ServiceRepository;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Database\Database;
use Whity\Sdk\Calendar\BookingDepositRefunds;
use Whity\Sdk\Calendar\BookingPaymentStep;
use Whity\Sdk\Calendar\BookingQuote;
use Whity\Sdk\Calendar\DepositRefund;
use Whity\Sdk\Calendar\PaymentHandoff;
use Whity\Sdk\Calendar\PaymentRequirement;
use Whity\Sdk\Calendar\PendingBooking;

/**
 * Online booking (WHIT-1445, design section 39 §9, AP.24–AP.26): a service's
 * deposit is asked of online bookings through the payment step, in the
 * booking's currency; without a provider the booking is a request. What
 * happens to the deposit is read from the booking: kept on a late cancel or a
 * no-show, to refund on an early cancel or "we cancelled", applied when they
 * came. A refund happens only when a person presses Refund, once, and is
 * audited. The policy asks a deposit of a customer who missed often. A
 * customer moves once online inside the window. Your website takes https
 * origins only. Real engine.
 */
final class CalendarDepositsRealEngineTest extends TestCase
{
    private PDO $pdo;
    private BookingService $service;
    private SettingsService $settings;
    private CalendarPlugins $plugins;
    private ServiceRepository $services;
    private DateTimeImmutable $now;
    private int $cake;
    private int $tart;
    /** @var list<BookingQuote> */
    private array $quotes = [];
    /** @var list<DepositRefund> */
    private array $refunds = [];
    /** @var list<array{action: string, options: array<string, mixed>}> */
    private array $audited = [];

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery')");
        $this->pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES (901, 'staff', '', NULL, CURRENT_TIMESTAMP)");
        foreach ([11 => 'Amro Saleh', 12 => 'Awss Haddad'] as $profile => $name) {
            $this->pdo->exec("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                VALUES ({$profile}, '{$name}', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
            $this->pdo->exec("INSERT INTO memberships (profile_id, tenant_id, role_id, status, kind, created_at) VALUES ({$profile}, 1, 901, 'active', 'member', CURRENT_TIMESTAMP)");
        }
        $this->settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        $this->settings->setTenant(1, 'timezone', 'Asia/Amman');
        $this->settings->setTenant(1, 'calendar.min_notice_minutes', '0');
        $this->settings->setTenant(1, 'calendar.late_cancel_hours', '24');

        // Sunday 15 November 2026, 07:00 in Amman.
        $this->now = new DateTimeImmutable('2026-11-15 04:00:00', new DateTimeZone('UTC'));
        $calendar = new CalendarRepository($this->pdo);
        $this->services = new ServiceRepository($this->pdo);
        $this->service = new BookingService($this->pdo, $calendar, $this->services, new BookingRepository($this->pdo), $this->settings, null, clock: fn (): DateTimeImmutable => $this->now);
        $this->plugins = new CalendarPlugins();
        $this->service->setPlugins($this->plugins);
        $this->service->setManageUrlBase('https://app.example.com/booking');
        $audited = &$this->audited;
        $this->service->setAudit(new class (static function (string $action, array $options) use (&$audited): void {
            $audited[] = ['action' => $action, 'options' => $options];
        }) implements AuditLoggerInterface {
            public function __construct(private readonly \Closure $record)
            {
            }

            public function record(string $action, array $options = []): void
            {
                ($this->record)($action, $options);
            }
        });

        // A 25% deposit, with Amro at JOD 12.500 and Awss at his own JOD 20.000.
        $this->cake = $this->services->insert(1, [
            'name' => ['en' => 'Cake tasting'], 'description' => [], 'duration_minutes' => 30, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
            'price_minor' => 12500, 'currency' => 'JOD', 'bookable_online' => true, 'active' => true, 'sort_order' => 0,
            'deposit' => ['kind' => Deposits::PERCENT, 'percent' => 25],
        ]);
        $this->services->replaceStaff(1, $this->cake, [11, 12], [12 => ['duration_minutes' => null, 'buffer_after_minutes' => null, 'price_minor' => 20000]]);
        // No deposit.
        $this->tart = $this->services->insert(1, [
            'name' => ['en' => 'Tart tasting'], 'description' => [], 'duration_minutes' => 30, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
            'price_minor' => 8000, 'currency' => 'JOD', 'bookable_online' => true, 'active' => true, 'sort_order' => 1,
        ]);
        $this->services->replaceStaff(1, $this->tart, [11, 12]);
        foreach ([11, 12] as $person) {
            $calendar->ensureStaff(1, $person);
            $calendar->replaceHours(1, $person, array_map(static fn (int $d): array => ['weekday' => $d, 'start_time' => '09:00', 'end_time' => '17:00'], [1, 2, 3, 4, 5, 6, 7]));
        }
    }

    public function testAServiceKeepsItsDepositAndItIsChecked(): void
    {
        self::assertSame(['kind' => 'percent', 'amount_minor' => null, 'percent' => 25], $this->deposit($this->cake));
        self::assertSame(['kind' => 'none', 'amount_minor' => null, 'percent' => null], $this->deposit($this->tart));

        self::assertSame(['kind' => 'fixed', 'amount_minor' => 5000, 'percent' => null], Deposits::validate(['kind' => 'fixed', 'amount_minor' => 5000], 12500));
        foreach ([
            [['kind' => 'fixed', 'amount_minor' => 0], 12500],
            [['kind' => 'fixed', 'amount_minor' => 13000], 12500],
            [['kind' => 'fixed', 'amount_minor' => 5.5], 12500],
            [['kind' => 'percent', 'percent' => 0], 12500],
            [['kind' => 'percent', 'percent' => 101], 12500],
            [['kind' => 'percent', 'percent' => 20], null],
            [['kind' => 'all'], 12500],
            ['fixed', 12500],
        ] as [$raw, $price]) {
            try {
                Deposits::validate($raw, $price);
                self::fail('Refused: ' . json_encode($raw));
            } catch (CalendarRejectedException $e) {
                self::assertSame(422, $e->status);
            }
        }
        // Minor units in the currency's own decimals: 25% of JOD 12.500 is JOD 3.125.
        self::assertSame(3125, Deposits::amountFor(['kind' => 'percent', 'amount_minor' => null, 'percent' => 25], 12500));
        self::assertSame(8000, Deposits::amountFor(['kind' => 'fixed', 'amount_minor' => 9000, 'percent' => null], 8000), 'never more than the price');
        self::assertNull(Deposits::amountFor(['kind' => 'percent', 'amount_minor' => null, 'percent' => 25], null));
    }

    public function testAnOnlineBookingPaysTheDepositThroughTheStepAndItIsPaid(): void
    {
        $this->provider(true);
        $held = $this->book($this->cake, 12);
        self::assertSame('pending_payment', $held['status']);
        // The quote carries the deposit from Awss's own price: 25% of JOD 20.000.
        self::assertSame(5000, $this->quotes[0]->depositMinor);
        self::assertSame(['amount_minor' => 5000, 'currency' => 'JOD', 'state' => 'due'], array_intersect_key($held['deposit'], array_flip(['amount_minor', 'currency', 'state'])));

        self::assertTrue((new HostBookings($this->service))->confirmPaid(1, (int) $held['id'], 'pay_1'));
        $paid = $this->service->bookingOrFail(1, (int) $held['id']);
        self::assertSame('confirmed', $paid['status']);
        self::assertSame('paid', $paid['deposit']['state']);
        self::assertNotNull($paid['deposit']['paid_at']);

        // A service without a deposit asks for none; staff bookings never do.
        $this->book($this->tart, 11, '2026-11-16T12:00:00+03:00');
        self::assertNull($this->quotes[count($this->quotes) - 1]->depositMinor);
        $staff = $this->book($this->cake, 11, '2026-11-16T13:00:00+03:00', false);
        self::assertSame('confirmed', $staff['status']);
        self::assertNull($staff['deposit']);
    }

    public function testWithoutAProviderADepositBookingIsARequest(): void
    {
        $request = $this->book($this->cake, 11);
        self::assertSame('requested', $request['status']);
        self::assertNull($request['deposit']);
        self::assertSame('confirmed', $this->book($this->tart, 11, '2026-11-16T12:00:00+03:00')['status']);

        // A step that takes no deposit never lets it through unpaid either.
        $this->plugins->offerPaymentStep('Other', new class implements BookingPaymentStep {
            public function requirement(BookingQuote $quote): ?PaymentRequirement
            {
                return null;
            }

            public function start(PendingBooking $booking, string $returnUrl): PaymentHandoff
            {
                return new PaymentHandoff('https://pay.example.com/x');
            }
        });
        self::assertSame('requested', $this->book($this->cake, 11, '2026-11-16T14:00:00+03:00')['status']);
    }

    public function testWhatHappensToTheDepositIsReadFromTheBooking(): void
    {
        $this->provider(true);
        $paid = fn (string $at): int => $this->paidBooking($at);

        $late = $paid('2026-11-15T16:00:00+03:00');
        $this->service->cancel(1, $late, 'customer', '');
        self::assertSame('kept', $this->state($late), 'a late cancel keeps it');

        $early = $paid('2026-11-20T10:00:00+03:00');
        $this->service->cancel(1, $early, 'customer', '');
        self::assertSame('to_refund', $this->state($early));

        $ours = $paid('2026-11-15T15:00:00+03:00');
        $this->service->cancel(1, $ours, 'staff', '', 'business');
        self::assertSame('to_refund', $this->state($ours), 'we cancelled');

        $came = $paid('2026-11-15T10:00:00+03:00');
        $missed = $paid('2026-11-15T11:00:00+03:00');
        $this->now = new DateTimeImmutable('2026-11-15 09:00:00', new DateTimeZone('UTC'));
        $this->service->markAttendance(1, $came, 'attended', 11);
        $this->service->markAttendance(1, $missed, 'no_show', 11);
        self::assertSame('applied', $this->state($came));
        self::assertSame('kept', $this->state($missed));
    }

    public function testARefundIsOnePersonsClickOnceAndAudited(): void
    {
        $this->provider(true);
        $early = $this->paidBooking('2026-11-20T10:00:00+03:00');
        $upcoming = $this->paidBooking('2026-11-21T10:00:00+03:00');
        self::assertSame([], $this->refunds(), 'nothing is refunded on its own');
        $this->service->cancel(1, $early, 'customer', '');
        self::assertSame([], $this->refunds(), 'not even on an early cancel');

        $refunded = $this->service->refundDeposit(1, $early, 11, false);
        self::assertSame('refunded', $refunded['deposit']['state']);
        self::assertSame(['profile_id' => 11, 'name' => 'Amro Saleh'], $refunded['deposit']['refunded_by']);
        self::assertSame('re_' . $early, $refunded['deposit']['refund_ref']);
        $refunds = $this->refunds();
        self::assertCount(1, $refunds);
        self::assertSame([3125, 'JOD', 'pay_' . $early, 11], [$refunds[0]->amountMinor, $refunds[0]->currency, $refunds[0]->paymentRef, $refunds[0]->actingProfileId]);
        $audit = array_values(array_filter($this->audited, static fn (array $a): bool => $a['action'] === 'calendar.deposit.refunded'));
        self::assertCount(1, $audit);
        self::assertSame(['tenant_id' => 1, 'actor_user_id' => 11, 'target_type' => 'calendar_booking', 'target_id' => $early], array_intersect_key($audit[0]['options'], array_flip(['tenant_id', 'actor_user_id', 'target_type', 'target_id'])));
        self::assertSame(['amount_minor' => 3125, 'currency' => 'JOD', 'was' => 'to_refund', 'outside' => false], $audit[0]['options']['metadata']);

        foreach ([[$early, 'already refunded'], [$upcoming, 'still to come']] as [$id, $why]) {
            try {
                $this->service->refundDeposit(1, $id, 11, false);
                self::fail('Refused: ' . $why);
            } catch (CalendarRejectedException $e) {
                self::assertSame(422, $e->status, $why);
            }
        }
        self::assertCount(1, $this->refunds);
    }

    public function testAProviderThatCannotRefundLeavesItToBeMarkedRefunded(): void
    {
        $this->provider(false);
        $early = $this->paidBooking('2026-11-20T10:00:00+03:00');
        $this->service->cancel(1, $early, 'customer', '');
        try {
            $this->service->refundDeposit(1, $early, 11, false);
            self::fail('No refund from here');
        } catch (CalendarRejectedException $e) {
            self::assertSame(409, $e->status);
            self::assertSame('refund_outside', $e->extra['code']);
        }
        self::assertSame('to_refund', $this->state($early));
        $marked = $this->service->refundDeposit(1, $early, 11, true);
        self::assertSame('refunded', $marked['deposit']['state']);
        self::assertSame('outside', $marked['deposit']['refund_ref']);
        self::assertTrue($this->audited[count($this->audited) - 1]['options']['metadata']['outside']);
    }

    public function testThePolicyAsksADepositOfACustomerWhoMissedOften(): void
    {
        $this->provider(true);
        // Lina didn't come twice this month.
        foreach (['2026-11-16T10:00:00+03:00', '2026-11-16T11:00:00+03:00'] as $at) {
            $id = (int) $this->book($this->tart, 11, $at, false, 'lina@example.com')['id'];
            $this->pdo->exec("UPDATE calendar_bookings SET starts_at = '2026-11-01 08:00:00', ends_at = '2026-11-01 08:30:00', attendance = 'no_show' WHERE id = {$id}");
        }
        $this->quotes = [];

        // Off by default: she books the tart as anyone does.
        self::assertSame('confirmed', $this->book($this->tart, 11, '2026-11-17T10:00:00+03:00', true, 'lina@example.com')['status']);

        $this->settings->setTenant(1, SettingsRegistry::CALENDAR_DEPOSIT_POLICY, 'true');
        // The tart has no deposit, so the business confirms her booking instead.
        self::assertSame('requested', $this->book($this->tart, 11, '2026-11-17T11:00:00+03:00', true, 'lina@example.com')['status']);
        // The cake has one: she pays it, as everyone does.
        self::assertSame('pending_payment', $this->book($this->cake, 11, '2026-11-17T12:00:00+03:00', true, 'lina@example.com')['status']);
        // Someone else is not asked.
        self::assertSame('confirmed', $this->book($this->tart, 11, '2026-11-17T13:00:00+03:00', true, 'rana@example.com')['status']);

        $this->settings->setTenant(1, SettingsRegistry::CALENDAR_DEPOSIT_AFTER_NO_SHOWS, '3');
        self::assertSame('confirmed', $this->book($this->tart, 11, '2026-11-17T14:00:00+03:00', true, 'lina@example.com')['status']);
    }

    public function testACustomerMovesOnceOnlineInsideTheWindow(): void
    {
        $early = (int) $this->book($this->tart, 11, '2026-11-20T10:00:00+03:00')['id'];
        $this->service->moveByCustomer(1, $early, self::at('2026-11-20T11:00:00+03:00'));
        $this->service->moveByCustomer(1, $early, self::at('2026-11-20T12:00:00+03:00'));
        self::assertNull($this->service->bookingOrFail(1, $early)['late_moved_at'], 'outside the window, as often as they like');

        $soon = (int) $this->book($this->tart, 11, '2026-11-15T16:00:00+03:00')['id'];
        self::assertEquals(self::at('2026-11-14T16:00:00+03:00'), $this->service->freeUntil(1, $this->service->bookingOrFail(1, $soon)));
        // Moved to later today: still inside the window, so once is all.
        $moved = $this->service->moveByCustomer(1, $soon, self::at('2026-11-15T16:30:00+03:00'));
        self::assertNotNull($moved['late_moved_at']);
        self::assertNull($moved['cancel_kind'], 'not a miss');
        try {
            $this->service->moveByCustomer(1, $soon, self::at('2026-11-16T10:00:00+03:00'));
            self::fail('A second move inside the window is for the desk');
        } catch (CalendarRejectedException $e) {
            self::assertSame('call_to_move', $e->extra['code']);
        }
    }

    public function testYourWebsiteTakesHttpsOriginsOnly(): void
    {
        self::assertNull(EmbedOrigins::validate(''));
        self::assertNull(EmbedOrigins::validate('https://example-bakery.com, https://shop.example-bakery.com:8443'));
        foreach ([
            'http://example-bakery.com',
            'https://*.example-bakery.com',
            '*',
            'https://example-bakery.com/book',
            'https://example-bakery.com?x=1',
            'https://user@example-bakery.com',
            'https://Example-Bakery.com',
            'https://localhost',
            "https://example-bakery.com 'unsafe-inline'",
            'https://example-bakery.com:99999',
            implode(',', array_map(static fn (int $i): string => "https://s{$i}.example.com", range(1, 11))),
        ] as $bad) {
            self::assertNotNull(EmbedOrigins::validate($bad), $bad);
            self::assertNotNull(SettingsRegistry::validate(SettingsRegistry::CALENDAR_EMBED_ORIGINS, $bad), $bad);
        }
        self::assertSame("'none'", EmbedOrigins::frameAncestors(''));
        self::assertSame("'none'", EmbedOrigins::frameAncestors('http://example-bakery.com'));
        self::assertSame('https://example-bakery.com https://shop.example-bakery.com:8443', EmbedOrigins::frameAncestors('https://example-bakery.com,https://shop.example-bakery.com:8443'));
    }

    public function testTheMigrationRunsTwiceAndRollsBack(): void
    {
        require_once dirname(__DIR__, 3) . '/database/migrations/373_add_calendar_deposits.php';
        $db = Database::withFactory(fn (): PDO => $this->pdo);
        AddCalendarDeposits::up($db);
        AddCalendarDeposits::up($db);
        self::assertSame('percent', $this->deposit($this->cake)['kind']);
        AddCalendarDeposits::down($db);
        AddCalendarDeposits::down($db);
        $columns = static function (PDO $pdo, string $table): array {
            $stmt = $pdo->query("SELECT * FROM {$table} LIMIT 1");
            self::assertNotFalse($stmt);
            $out = [];
            for ($i = 0; $i < $stmt->columnCount(); $i++) {
                $meta = $stmt->getColumnMeta($i);
                $out[] = is_array($meta) ? (string) $meta['name'] : '';
            }

            return $out;
        };
        self::assertNotContains('deposit_kind', $columns($this->pdo, 'calendar_services'));
        self::assertNotContains('deposit_minor', $columns($this->pdo, 'calendar_bookings'));
        AddCalendarDeposits::up($db);
        self::assertSame('none', $this->deposit($this->cake)['kind']);
    }

    // ---------------------------------------------------------------------

    private function provider(bool $refunds): void
    {
        $quotes = &$this->quotes;
        $refunded = &$this->refunds;
        $record = static function (BookingQuote $q) use (&$quotes): void {
            $quotes[] = $q;
        };
        $refund = static function (DepositRefund $r) use (&$refunded): string {
            $refunded[] = $r;

            return 're_' . $r->bookingId;
        };
        $step = $refunds
            ? new class ($record, $refund) implements BookingPaymentStep, BookingDepositRefunds {
                public function __construct(private readonly \Closure $record, private readonly \Closure $refund)
                {
                }

                public function requirement(BookingQuote $quote): ?PaymentRequirement
                {
                    ($this->record)($quote);

                    return $quote->depositMinor !== null ? new PaymentRequirement($quote->depositMinor, $quote->currency, 'Deposit') : null;
                }

                public function start(PendingBooking $booking, string $returnUrl): PaymentHandoff
                {
                    return new PaymentHandoff('https://pay.example.com/checkout/' . $booking->bookingId);
                }

                public function refund(DepositRefund $refund): string
                {
                    return ($this->refund)($refund);
                }
            }
            : new class ($record) implements BookingPaymentStep {
                public function __construct(private readonly \Closure $record)
                {
                }

                public function requirement(BookingQuote $quote): ?PaymentRequirement
                {
                    ($this->record)($quote);

                    return $quote->depositMinor !== null ? new PaymentRequirement($quote->depositMinor, $quote->currency, 'Deposit') : null;
                }

                public function start(PendingBooking $booking, string $returnUrl): PaymentHandoff
                {
                    return new PaymentHandoff('https://pay.example.com/checkout/' . $booking->bookingId);
                }
            };
        $this->plugins->offerPaymentStep('WhityPay', $step);
    }

    /** A cake tasting with Amro, booked online and its deposit paid. */
    private function paidBooking(string $at): int
    {
        $id = (int) $this->book($this->cake, 11, $at)['id'];
        self::assertTrue((new HostBookings($this->service))->confirmPaid(1, $id, 'pay_' . $id));

        return $id;
    }

    /** @return array<string, mixed> a service's deposit */
    private function deposit(int $serviceId): array
    {
        $service = $this->services->find(1, $serviceId);
        self::assertIsArray($service);
        self::assertIsArray($service['deposit']);

        return $service['deposit'];
    }

    /** @return list<DepositRefund> */
    private function refunds(): array
    {
        return $this->refunds;
    }

    private function state(int $id): string
    {
        $deposit = $this->service->bookingOrFail(1, $id)['deposit'];
        self::assertIsArray($deposit);

        return (string) $deposit['state'];
    }

    /** @return array<string, mixed> */
    private function book(int $service, int $profile, string $at = '2026-11-16T10:00:00+03:00', bool $public = true, string $email = ''): array
    {
        return $this->service->book(1, [
            'service_id' => $service, 'profile_id' => $profile, 'starts_at' => self::at($at),
            'customer_profile_id' => null, 'customer_name' => 'Lina Haddad', 'customer_email' => $email,
            'customer_phone' => '', 'customer_note' => '', 'source' => $public ? 'public' : 'staff',
            'created_by_profile_id' => $public ? null : 11, 'enforce_hours' => $public, 'public' => $public, 'notify' => false,
        ]);
    }

    private static function at(string $iso): DateTimeImmutable
    {
        return Interval::fromApi($iso) ?? throw new \LogicException('bad instant');
    }
}
