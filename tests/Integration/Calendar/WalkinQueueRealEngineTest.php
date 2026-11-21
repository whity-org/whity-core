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
use Whity\Core\Calendar\ServiceRepository;
use Whity\Core\Calendar\WalkinQueue;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;

/**
 * The walk-in queue against the real schema (WHIT-1334, design section 35 §3):
 * adding, the order, handing over (a checked-in booking now, the contact found
 * by mobile), leaving, and workspace isolation.
 *
 * Example Bakery (tenant 1) has two bakers, 11 and 12. Tenant 2 has 21.
 */
final class WalkinQueueRealEngineTest extends TestCase
{
    /** Sunday 15 November 2026, 12:00 in Amman. */
    private const NOW = '2026-11-15 09:00:00';

    private PDO $pdo;
    private WalkinQueue $queue;
    private CalendarRepository $calendar;
    private ServiceRepository $services;
    private BookingRepository $bookings;
    private BookingService $booking;
    private int $tasting;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Other Bakery', 'other-bakery')");
        $this->pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES (901, 'staff', '', NULL, CURRENT_TIMESTAMP)");
        foreach ([11 => 1, 12 => 1, 21 => 2] as $profile => $tenant) {
            $this->pdo->exec(
                "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                 VALUES ({$profile}, 'Baker {$profile}', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
            );
            $this->pdo->exec(
                "INSERT INTO memberships (profile_id, tenant_id, role_id, status, kind, created_at)
                 VALUES ({$profile}, {$tenant}, 901, 'active', 'member', CURRENT_TIMESTAMP)"
            );
        }
        $settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        foreach ([1, 2] as $tenant) {
            $settings->setTenant($tenant, 'timezone', 'Asia/Amman');
            $settings->setTenant($tenant, 'calendar.walkins_enabled', 'true');
        }

        $this->calendar = new CalendarRepository($this->pdo);
        $this->services = new ServiceRepository($this->pdo);
        $this->bookings = new BookingRepository($this->pdo);
        $clock = static fn (): DateTimeImmutable => new DateTimeImmutable(self::NOW, new DateTimeZone('UTC'));
        $this->booking = new BookingService($this->pdo, $this->calendar, $this->services, $this->bookings, $settings, null, clock: $clock);
        $this->queue = new WalkinQueue($this->pdo, $this->booking, $this->calendar, $this->services, $this->bookings, $clock);

        $this->tasting = $this->services->insert(1, [
            'name' => ['en' => 'Cake tasting', 'ar' => 'تذوق الكعك'], 'description' => [],
            'duration_minutes' => 30, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
            'price_minor' => 12500, 'currency' => 'JOD', 'bookable_online' => true, 'active' => true, 'sort_order' => 0,
        ]);
        $this->services->replaceStaff(1, $this->tasting, [11, 12]);
        foreach ([[1, 11], [1, 12], [2, 21]] as [$tenant, $baker]) {
            $this->calendar->ensureStaff($tenant, $baker);
        }
    }

    public function testFirstComeFirstServedWithWhatTheyWant(): void
    {
        $this->queue->add(1, ['name' => 'Lina', 'phone' => '+962 79 000 0001', 'service_id' => $this->tasting], 11);
        $this->queue->add(1, ['name' => 'Omar', 'service_id' => $this->tasting, 'profile_id' => 12, 'note' => 'Prefers chocolate'], 11);

        $waiting = $this->queue->waiting(1, 'ar');
        self::assertSame(['Lina', 'Omar'], array_column($waiting, 'name'));
        self::assertSame([1, 2], array_column($waiting, 'position'));
        self::assertSame('تذوق الكعك', $waiting[0]['service']);
        self::assertSame('Baker 12', $waiting[1]['preferred']);
        self::assertSame(0, $waiting[0]['waited_minutes']);

        // Another workspace sees nobody.
        self::assertSame([], $this->queue->waiting(2));
    }

    public function testAddRefusesWhatItCannotServe(): void
    {
        foreach ([
            ['service_id' => $this->tasting],
            ['name' => 'Lina'],
            ['name' => 'Lina', 'service_id' => $this->tasting, 'phone' => 'call me'],
            ['name' => 'Lina', 'service_id' => $this->tasting, 'profile_id' => 21],
        ] as $input) {
            try {
                $this->queue->add(1, $input, 11);
                self::fail('Accepted ' . json_encode($input));
            } catch (CalendarRejectedException $e) {
                self::assertSame(422, $e->status);
            }
        }
        // Another workspace's service is not one to choose.
        $this->expectException(CalendarRejectedException::class);
        $this->queue->add(2, ['name' => 'Lina', 'service_id' => $this->tasting], 21);
    }

    public function testHandingOverBooksNowCheckedInAndFindsTheContact(): void
    {
        $this->pdo->exec("INSERT INTO contacts (tenant_id, name, mobile, is_customer, created_at, updated_at) VALUES (1, 'Lina Haddad', '+962790000001', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $found = $this->pdo->query("SELECT id FROM contacts WHERE name = 'Lina Haddad'");
        self::assertNotFalse($found);
        $contactId = (int) $found->fetchColumn();
        $walkin = $this->queue->add(1, ['name' => 'Lina', 'phone' => '+962 79 000 0001', 'service_id' => $this->tasting, 'profile_id' => 12], 11);

        $served = $this->queue->assign(1, (int) $walkin['id'], null, 11);

        self::assertSame(WalkinQueue::SERVED, $served['status']);
        self::assertSame(12, $served['assigned_profile_id'], 'the one they asked for, who is free');
        self::assertSame($contactId, $served['contact_id']);
        $booking = $this->booking->bookingOrFail(1, (int) $served['booking_id']);
        self::assertSame(12, $booking['profile_id']);
        self::assertSame('2026-11-15T09:00:00Z', $booking['starts_at']);
        self::assertNotNull($booking['checked_in_at'] ?? null);
        self::assertSame(BookingRepository::SOURCE_WALKIN, $booking['source']);
        self::assertSame([], $this->queue->waiting(1));

        // 12 is busy now, so the next one goes to 11.
        $staff = $this->queue->staff(1);
        self::assertSame([11, 12], array_column($staff, 'profile_id'));
        self::assertFalse($staff[1]['free']);
        self::assertStringContainsString('busy until 12:30', $staff[1]['label'], 'the end, in Amman time');
        $next = $this->queue->add(1, ['name' => 'Omar', 'service_id' => $this->tasting, 'profile_id' => 12], 11);
        self::assertSame(11, $this->queue->assign(1, (int) $next['id'], null, 11)['assigned_profile_id']);
    }

    public function testNobodyFreeKeepsThemWaitingUnlessSomeoneIsChosen(): void
    {
        foreach (['A', 'B', 'C'] as $name) {
            $this->queue->add(1, ['name' => $name, 'service_id' => $this->tasting], 11);
        }
        $waiting = $this->queue->waiting(1);
        $this->queue->assign(1, $waiting[0]['id'], null, 11);
        $this->queue->assign(1, $waiting[1]['id'], null, 11);

        try {
            $this->queue->assign(1, $waiting[2]['id'], null, 11);
            self::fail('Served with nobody free');
        } catch (CalendarRejectedException $e) {
            self::assertSame(409, $e->status);
            self::assertSame('nobody_free', $e->extra['reason']);
        }
        self::assertSame(WalkinQueue::WAITING, $this->queue->row(1, $waiting[2]['id'])['status'] ?? null);

        // The desk may choose someone busy.
        self::assertSame(12, $this->queue->assign(1, $waiting[2]['id'], 12, 11)['assigned_profile_id']);
    }

    public function testServedOrLeftCannotBeServedAgain(): void
    {
        $walkin = $this->queue->add(1, ['name' => 'Lina', 'service_id' => $this->tasting], 11);
        $left = $this->queue->left(1, (int) $walkin['id']);
        self::assertSame(WalkinQueue::LEFT, $left['status']);
        self::assertNotNull($left['left_at']);

        try {
            $this->queue->assign(1, (int) $walkin['id'], 11, 11);
            self::fail('Served someone who left');
        } catch (CalendarRejectedException $e) {
            self::assertSame(409, $e->status);
        }
        $this->expectException(CalendarRejectedException::class);
        $this->queue->left(1, (int) $walkin['id']);
    }

    public function testAnotherWorkspacesWalkinIsNotFound(): void
    {
        $walkin = $this->queue->add(1, ['name' => 'Lina', 'service_id' => $this->tasting], 11);
        try {
            $this->queue->assign(2, (int) $walkin['id'], 21, 21);
            self::fail('Served another workspace\'s walk-in');
        } catch (CalendarRejectedException $e) {
            self::assertSame(404, $e->status);
        }
        self::assertNull($this->queue->row(2, (int) $walkin['id']));
    }
}
