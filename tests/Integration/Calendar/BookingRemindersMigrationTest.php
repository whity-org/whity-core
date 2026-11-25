<?php

declare(strict_types=1);

namespace Tests\Integration\Calendar;

use Database\Migrations\CreateCalendarBookingReminders;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Calendar\CalendarSettings;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Database\Database;

/**
 * Migration 361 and the reminders setting (WHIT-1417, design section 39
 * §5.2, AP.14–AP.15): a workspace that chose its own reminder hours keeps
 * them as its one reminder; everyone else gets 24 and 2 hours before.
 */
final class BookingRemindersMigrationTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Other Bakery', 'other-bakery'), (3, 'Third Bakery', 'third-bakery')");
        require_once dirname(__DIR__, 3) . '/database/migrations/361_create_calendar_booking_reminders.php';
    }

    public function testAWorkspacesOwnReminderHoursCarryOverAndTheRestGetTheDefault(): void
    {
        $db = $this->database();
        CreateCalendarBookingReminders::down($db);
        // Before 361: tenant 1 chose 48 hours, tenant 2 turned reminders off.
        $this->pdo->exec("INSERT INTO tenant_settings (tenant_id, setting_key, value) VALUES (1, 'calendar.reminder_hours_before', '48'), (2, 'calendar.reminder_hours_before', '0')");
        CreateCalendarBookingReminders::up($db);
        CreateCalendarBookingReminders::up($db); // twice is fine

        $settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        $hours = static fn (int $tenant): array => CalendarSettings::fromSettings($settings->effective($tenant))->reminderHours;
        self::assertSame([48], $hours(1));
        self::assertSame([], $hours(2), 'off stays off');
        self::assertSame([24, 2], $hours(3), 'the new default');
        self::assertSame(0, CalendarSettings::fromSettings($settings->effective(2))->reminderHoursBefore);
        self::assertSame(24, CalendarSettings::fromSettings($settings->effective(3))->reminderHoursBefore, 'older clients see the first reminder');
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM tenant_settings WHERE tenant_id = 1 AND setting_key = 'calendar.reminders'"), 'copied once');

        CreateCalendarBookingReminders::down($db);
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM tenant_settings WHERE setting_key = 'calendar.reminders'"));
        self::assertSame('48', $this->scalar("SELECT value FROM tenant_settings WHERE tenant_id = 1 AND setting_key = 'calendar.reminder_hours_before'"), 'the old choice is untouched');
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'calendar_booking_reminders'"), 'down() drops the table');
        CreateCalendarBookingReminders::up($db);
    }

    public function testTheRemindersSettingIsReadLargestFirstAndValidated(): void
    {
        self::assertSame([24, 2], CalendarSettings::reminderHours('2, 24'));
        self::assertSame([48, 24, 2], CalendarSettings::reminderHours('2,24,48,24'));
        self::assertSame([], CalendarSettings::reminderHours(''));
        self::assertSame([5], CalendarSettings::reminderHours('0, x, 5, 200'), 'unreadable parts are left out');

        self::assertNull(SettingsRegistry::validate(SettingsRegistry::CALENDAR_REMINDERS, '24,2'));
        self::assertNull(SettingsRegistry::validate(SettingsRegistry::CALENDAR_REMINDERS, ''));
        self::assertNotNull(SettingsRegistry::validate(SettingsRegistry::CALENDAR_REMINDERS, '48,24,2,1'));
        self::assertNotNull(SettingsRegistry::validate(SettingsRegistry::CALENDAR_REMINDERS, '2,2'));
        self::assertNotNull(SettingsRegistry::validate(SettingsRegistry::CALENDAR_REMINDERS, '0'));
    }

    private function scalar(string $sql): mixed
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt);

        return $stmt->fetchColumn();
    }

    private function database(): Database
    {
        $pdo = $this->pdo;

        return Database::withFactory(static fn (): PDO => $pdo);
    }
}
