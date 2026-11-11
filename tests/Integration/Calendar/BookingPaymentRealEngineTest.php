<?php

declare(strict_types=1);

namespace Tests\Integration\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Calendar\BookingHoldExpiryJob;
use Whity\Core\Calendar\BookingMessenger;
use Whity\Core\Calendar\BookingRepository;
use Whity\Core\Calendar\BookingService;
use Whity\Core\Calendar\CalendarPlugins;
use Whity\Core\Calendar\CalendarRejectedException;
use Whity\Core\Calendar\CalendarRepository;
use Whity\Core\Calendar\HostBookings;
use Whity\Core\Calendar\HostCalendarEvents;
use Whity\Core\Calendar\Interval;
use Whity\Core\Calendar\ServiceRepository;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Sdk\Calendar\BookingPaymentStep;
use Whity\Sdk\Calendar\BookingQuote;
use Whity\Sdk\Calendar\BusyInterval;
use Whity\Sdk\Calendar\PaymentHandoff;
use Whity\Sdk\Calendar\PaymentRequirement;
use Whity\Sdk\Calendar\PendingBooking;
use Whity\Sdk\PluginCalendarBusyInterface;

/**
 * SDK 1.98 against the real schema (WHIT-943, ADR 0017 section 4): a payment
 * step holds the slot, confirms, releases or expires; plugin busy times block
 * and fail open; outside events are kept by reference.
 */
final class BookingPaymentRealEngineTest extends TestCase
{
    private PDO $pdo;
    private BookingService $service;
    private CalendarRepository $calendar;
    private CalendarPlugins $plugins;
    private DateTimeImmutable $now;
    /** @var list<string> */
    private array $told = [];
    private int $cake;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery')");
        $this->pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES (901, 'staff', '', NULL, CURRENT_TIMESTAMP)");
        $this->pdo->exec("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
            VALUES (11, 'Baker', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $this->pdo->exec("INSERT INTO memberships (profile_id, tenant_id, role_id, status, kind, created_at) VALUES (11, 1, 901, 'active', 'member', CURRENT_TIMESTAMP)");

        $settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        $settings->setTenant(1, 'timezone', 'Asia/Amman');
        $settings->setTenant(1, 'calendar.min_notice_minutes', '0');
        $settings->setTenant(1, 'calendar.payment_hold_minutes', '15');

        $this->now = new DateTimeImmutable('2026-11-15 04:00:00', new DateTimeZone('UTC'));
        $this->calendar = new CalendarRepository($this->pdo);
        $services = new ServiceRepository($this->pdo);
        $this->service = new BookingService($this->pdo, $this->calendar, $services, new BookingRepository($this->pdo), $settings, null, clock: fn (): DateTimeImmutable => $this->now);
        $this->plugins = new CalendarPlugins();
        $this->service->setPlugins($this->plugins);
        $this->service->setManageUrlBase('https://app.example.com/booking');
        $told = &$this->told;
        $this->service->setMessenger(new class (static function (string $what) use (&$told): void {
            $told[] = $what;
        }) implements BookingMessenger {
            public function __construct(private readonly \Closure $tell)
            {
            }

            public function confirmed(int $tenantId, array $booking): void
            {
                ($this->tell)('confirmed');
            }

            public function rescheduled(int $tenantId, array $booking): void
            {
                ($this->tell)('rescheduled');
            }

            public function cancelled(int $tenantId, array $booking): void
            {
                ($this->tell)('cancelled');
            }

            public function remind(int $tenantId, array $booking): void
            {
                ($this->tell)('remind');
            }

            public function awaitingPayment(int $tenantId, array $booking): void
            {
                ($this->tell)('awaitingPayment');
            }
        });

        $this->cake = $services->insert(1, [
            'name' => ['en' => 'Cake tasting'], 'description' => [],
            'duration_minutes' => 30, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
            'price_minor' => 12500, 'currency' => 'JOD', 'bookable_online' => true, 'active' => true, 'sort_order' => 0,
        ]);
        $services->replaceStaff(1, $this->cake, [11]);
        $this->calendar->ensureStaff(1, 11);
        $this->calendar->replaceHours(1, 11, [['weekday' => 1, 'start_time' => '09:00', 'end_time' => '17:00']]);
    }

    private function deposit(): void
    {
        $this->plugins->offerPaymentStep('WhityPay', new class implements BookingPaymentStep {
            public function requirement(BookingQuote $quote): ?PaymentRequirement
            {
                return $quote->priceMinor !== null ? new PaymentRequirement(intdiv($quote->priceMinor, 5), $quote->currency, 'Deposit') : null;
            }

            public function start(PendingBooking $booking, string $returnUrl): PaymentHandoff
            {
                return new PaymentHandoff('https://pay.example.com/checkout/' . $booking->bookingId . '?return=' . rawurlencode($returnUrl));
            }
        });
    }

    /** @return array<string, mixed> */
    private function book(string $at = '2026-11-16T10:00:00+03:00'): array
    {
        return $this->service->book(1, [
            'service_id' => $this->cake,
            'profile_id' => 11,
            'starts_at' => Interval::fromApi($at) ?? throw new \LogicException('bad instant'),
            'customer_profile_id' => null,
            'customer_name' => 'Lina',
            'customer_email' => '',
            'customer_phone' => '',
            'customer_note' => '',
            'source' => 'public',
            'created_by_profile_id' => null,
            'enforce_hours' => true,
            'public' => true,
        ]);
    }

    public function testWithoutAPaymentStepABookingIsConfirmedAtOnce(): void
    {
        self::assertSame('confirmed', $this->book()['status']);
        self::assertSame(['confirmed'], $this->told);
    }

    public function testADepositHoldsTheSlotUntilPaidThenConfirms(): void
    {
        $this->deposit();
        $held = $this->book();
        self::assertSame('pending_payment', $held['status']);
        self::assertSame(2500, $held['payment']['amount_minor']);
        self::assertStringStartsWith('https://pay.example.com/checkout/' . $held['id'], $held['payment']['url']);
        self::assertSame('2026-11-15T04:15:00Z', $held['payment']['hold_expires_at']);
        self::assertSame(['awaitingPayment'], $this->told);

        try {
            $this->book();
            self::fail('a held slot must not be booked again');
        } catch (CalendarRejectedException $e) {
            self::assertSame(409, $e->status);
        }

        self::assertTrue((new HostBookings($this->service))->confirmPaid(1, (int) $held['id'], 'pay_123'));
        self::assertSame('confirmed', $this->service->bookingOrFail(1, (int) $held['id'])['status']);
        self::assertSame(['awaitingPayment', 'confirmed'], $this->told);
        self::assertFalse((new HostBookings($this->service))->confirmPaid(1, (int) $held['id'], 'pay_123'), 'confirming twice does nothing');
    }

    public function testReleaseFreesTheSlot(): void
    {
        $this->deposit();
        $held = $this->book();
        self::assertTrue((new HostBookings($this->service))->release(1, (int) $held['id'], 'Card declined'));
        self::assertSame('cancelled', $this->service->bookingOrFail(1, (int) $held['id'])['status']);
        $this->plugins = new CalendarPlugins();
        $this->service->setPlugins($this->plugins);
        self::assertSame('confirmed', $this->book()['status']);
    }

    public function testAnUnsettledHoldExpiresAndLatePaymentWinsOnlyIfTheTimeIsStillFree(): void
    {
        $this->deposit();
        $first = $this->book();
        $job = new BookingHoldExpiryJob($this->service);
        self::assertSame(['expired' => false], $job->handle(['tenant_id' => 1, 'booking_id' => $first['id']]), 'not before the hold ends');

        $this->now = $this->now->modify('+16 minutes');
        self::assertSame(['expired' => true], $job->handle(['tenant_id' => 1, 'booking_id' => $first['id']]));
        // Paid late, but nobody took the time: it still counts.
        self::assertTrue($this->service->confirmPaid(1, (int) $first['id'], 'late'));

        $second = $this->book('2026-11-16T11:00:00+03:00');
        $this->now = $this->now->modify('+16 minutes');
        $job->handle(['tenant_id' => 1, 'booking_id' => $second['id']]);
        $this->plugins = new CalendarPlugins();
        $this->service->setPlugins($this->plugins);
        $this->book('2026-11-16T11:00:00+03:00');
        self::assertFalse($this->service->confirmPaid(1, (int) $second['id'], 'too late'), 'the time was taken after the hold lapsed');
    }

    public function testPluginBusyTimesBlockAndAFailingPluginFailsOpen(): void
    {
        $this->plugins->addBusyPlugin('Sync', new class implements PluginCalendarBusyInterface {
            public function busyTimes(int $tenantId, int $profileId, DateTimeImmutable $fromUtc, DateTimeImmutable $toUtc): array
            {
                // A misbehaving plugin: core must drop what is not an interval.
                return [new BusyInterval(new DateTimeImmutable('2026-11-16 06:00:00+00:00'), new DateTimeImmutable('2026-11-16 08:00:00+00:00')), 'not an interval']; // @phpstan-ignore return.type
            }
        });
        $this->plugins->addBusyPlugin('Broken', new class implements PluginCalendarBusyInterface {
            public function busyTimes(int $tenantId, int $profileId, DateTimeImmutable $fromUtc, DateTimeImmutable $toUtc): array
            {
                throw new \RuntimeException('provider down');
            }
        });
        $slots = $this->service->availability(1, $this->cake, 11, Interval::fromApi('2026-11-16T09:00:00+03:00') ?? $this->now, Interval::fromApi('2026-11-16T12:00:00+03:00') ?? $this->now);
        self::assertSame('2026-11-16T08:00:00Z', $slots[0]['starts_at']);
    }

    public function testOutsideEventsAreKeptByReferenceAndBlock(): void
    {
        $events = new HostCalendarEvents($this->service, $this->calendar);
        $id = $events->upsertExternal(1, 11, 'google:abc', 'Dentist', new DateTimeImmutable('2026-11-16 07:00:00+00:00'), new DateTimeImmutable('2026-11-16 08:00:00+00:00'));
        self::assertSame($id, $events->upsertExternal(1, 11, 'google:abc', 'Dentist (moved)', new DateTimeImmutable('2026-11-16 09:00:00+00:00'), new DateTimeImmutable('2026-11-16 10:00:00+00:00')));
        $event = $this->calendar->findEvent(1, $id);
        self::assertSame('Dentist (moved)', $event['title'] ?? null);
        self::assertSame('google:abc', $event['external_ref'] ?? null);

        try {
            $this->book('2026-11-16T12:00:00+03:00');
            self::fail('an outside busy event must block the time');
        } catch (CalendarRejectedException $e) {
            self::assertSame(409, $e->status);
        }
        self::assertTrue($events->deleteExternal(1, 11, 'google:abc'));
        self::assertFalse($events->deleteExternal(1, 11, 'google:abc'));
        self::assertSame('confirmed', $this->book('2026-11-16T12:00:00+03:00')['status']);

        $this->expectException(\InvalidArgumentException::class);
        $events->upsertExternal(1, 99, 'google:x', 'Not staff', new DateTimeImmutable('2026-11-16 07:00:00+00:00'), new DateTimeImmutable('2026-11-16 08:00:00+00:00'));
    }
}
