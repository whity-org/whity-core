<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Whity\Core\Calendar\CalendarPlugins;
use Whity\Core\Hooks\HookManager;
use Whity\Core\PluginLoader;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Router;

/**
 * SDK 1.98: the loader hands a plugin's booking payment step and busy times
 * to the calendar. One payment step per deployment; a second is refused and
 * the plugin keeps loading. A getter that throws does not stop the plugin.
 */
final class PluginLoaderCalendarTest extends TestCase
{
    private static string $dir;

    /** Unique per run, so the plugin classes never collide with an earlier load in the same process. */
    private static string $tag;

    /** name => [has payment step, getter throws] */
    private const PLUGINS = [
        'AaPay' => [true, false],
        'BbPay' => [true, false],
        'CcSync' => [false, false],
        'DdBroken' => [true, true],
    ];

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/whity_calendar_' . uniqid();
        self::$tag = 'R' . bin2hex(random_bytes(4));
        foreach (self::PLUGINS as $base => [$pays, $throws]) {
            $name = $base . self::$tag;
            mkdir(self::$dir . '/' . $name, 0755, true);
            $interfaces = $pays ? 'PluginInterface, PluginBookingPaymentInterface, PluginCalendarBusyInterface' : 'PluginInterface, PluginCalendarBusyInterface';
            $getter = $throws ? "throw new \\RuntimeException('boom');" : 'return new Step();';
            $paymentMethod = $pays ? "public function getBookingPaymentStep(): BookingPaymentStep { {$getter} }" : '';
            file_put_contents(self::$dir . "/{$name}/Plugin.php", <<<PHP
<?php
declare(strict_types=1);
namespace {$name};
use DateTimeImmutable;
use Whity\\Sdk\\Calendar\\BookingPaymentStep;
use Whity\\Sdk\\Calendar\\BookingQuote;
use Whity\\Sdk\\Calendar\\PaymentHandoff;
use Whity\\Sdk\\Calendar\\PaymentRequirement;
use Whity\\Sdk\\Calendar\\PendingBooking;
use Whity\\Sdk\\PluginBookingPaymentInterface;
use Whity\\Sdk\\PluginCalendarBusyInterface;
use Whity\\Sdk\\PluginInterface;
final class Step implements BookingPaymentStep
{
    public function requirement(BookingQuote \$q): ?PaymentRequirement { return null; }
    public function start(PendingBooking \$b, string \$r): PaymentHandoff { return new PaymentHandoff('https://pay.example.com/x'); }
}
final class Plugin implements {$interfaces}
{
    public function getName(): string    { return '{$name}'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array      { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array       { return []; }
    public function getMigrations(): array  { return []; }
    public function busyTimes(int \$t, int \$p, DateTimeImmutable \$f, DateTimeImmutable \$to): array { return []; }
    {$paymentMethod}
}
PHP);
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach (array_keys(self::PLUGINS) as $base) {
            $name = $base . self::$tag;
            @unlink(self::$dir . "/{$name}/Plugin.php");
            @rmdir(self::$dir . "/{$name}");
        }
        @rmdir(self::$dir);
    }

    public function testOnePaymentStepIsTakenAndEveryBusySourceIsAdded(): void
    {
        $permissions = new PermissionRegistry();
        $permissions->registerCorePermissions();
        $loader = new PluginLoader(self::$dir, new Router(''), $permissions, new HookManager());
        $calendar = new CalendarPlugins();
        $loader->setCalendarPlugins($calendar);
        $loader->load();

        $names = array_map(static fn ($plugin): string => $plugin->getName(), $loader->getPlugins());
        self::assertContains('BbPay' . self::$tag, $names, 'a refused second payment step does not stop the plugin; loaded: ' . implode(', ', $names));
        self::assertNotNull($calendar->paymentStep());
        self::assertSame('AaPay' . self::$tag . '\Step', $calendar->paymentStep()::class, 'the first payment step is kept');
        self::assertGreaterThanOrEqual(3, count($calendar->busySources()));
    }
}
