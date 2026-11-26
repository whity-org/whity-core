<?php

declare(strict_types=1);

/**
 * One of several people taking the same freed time at once, each through
 * their own waitlist offer (CalendarWaitlistRealEngineTest, WHIT-1442).
 * Prints `booked` or `taken_first`, or the error.
 *
 * argv: dsn user password schema token now
 */

require __DIR__ . '/../../../../vendor/autoload.php';

use Whity\Core\Calendar\BookingRepository;
use Whity\Core\Calendar\BookingService;
use Whity\Core\Calendar\CalendarRepository;
use Whity\Core\Calendar\ServiceRepository;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;

[, $dsn, $user, $password, $schema, $token, $now] = $argv;
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
    echo $service->waitlist()->accept($token)['outcome'];
} catch (Throwable $e) {
    echo 'error ' . $e->getMessage();
}
