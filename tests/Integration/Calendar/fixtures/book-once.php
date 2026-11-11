<?php

declare(strict_types=1);

/**
 * One of several processes racing for the same time (BookingRealEngineTest).
 * Prints `booked` or `taken`, or the error.
 *
 * argv: dsn user password schema serviceId now
 */

require __DIR__ . '/../../../../vendor/autoload.php';

use Whity\Core\Calendar\BookingRepository;
use Whity\Core\Calendar\BookingService;
use Whity\Core\Calendar\CalendarRejectedException;
use Whity\Core\Calendar\CalendarRepository;
use Whity\Core\Calendar\Interval;
use Whity\Core\Calendar\ServiceRepository;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;

[, $dsn, $user, $password, $schema, $serviceId, $now] = $argv;
$pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('SET search_path TO ' . $schema . ', public');

$service = new BookingService(
    $pdo,
    new CalendarRepository($pdo),
    new ServiceRepository($pdo),
    new BookingRepository($pdo),
    new SettingsService(new GlobalSettingsRepository($pdo), new TenantSettingsRepository($pdo)),
    null,
    clock: static fn (): DateTimeImmutable => new DateTimeImmutable($now, new DateTimeZone('UTC')),
);

try {
    $service->book(1, [
        'service_id' => (int) $serviceId,
        'profile_id' => 11,
        'starts_at' => Interval::fromApi('2026-11-16T10:00:00+03:00') ?? throw new LogicException('bad instant'),
        'customer_profile_id' => null,
        'customer_name' => 'Racer',
        'customer_email' => 'racer@example.com',
        'customer_phone' => '',
        'customer_note' => '',
        'source' => 'public',
        'created_by_profile_id' => null,
        'enforce_hours' => true,
        'public' => true,
    ]);
    echo 'booked';
} catch (CalendarRejectedException $e) {
    echo $e->status === 409 ? 'taken' : 'rejected ' . $e->getMessage();
} catch (Throwable $e) {
    echo 'error ' . $e->getMessage();
}
