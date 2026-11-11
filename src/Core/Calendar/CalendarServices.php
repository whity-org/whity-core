<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use PDO;
use Psr\Log\LoggerInterface;
use Whity\Core\Branding\TenantHostRepository;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Http\FrontendUrls;
use Whity\Core\Mail\EmailLayout;
use Whity\Core\Mail\Mailer;
use Whity\Core\Queue\QueueService;
use Whity\Core\Settings\SettingsService;

/**
 * Builds the booking service the same way for a web request and for the
 * queue worker, so a reminder sent by the worker reads and writes exactly
 * as a booking made on the page does.
 */
final class CalendarServices
{
    public static function bookingService(
        PDO $pdo,
        SettingsService $settings,
        ?HookManager $hooks,
        Mailer $mailer,
        FrontendUrls $urls,
        ?QueueService $queue,
        ?LoggerInterface $logger = null,
    ): BookingService {
        $service = new BookingService(
            $pdo,
            new CalendarRepository($pdo),
            new ServiceRepository($pdo),
            new BookingRepository($pdo),
            $settings,
            $hooks,
        );
        $service->setLinks(new ManageLinks($pdo));
        $service->setMessenger(new EmailBookingMessenger(
            $mailer,
            new EmailLayout(),
            $settings,
            new TenantHostRepository($pdo),
            $urls->linkOrPath(FrontendUrls::BOOKING_MANAGE),
            $logger,
        ));
        if ($queue !== null) {
            $service->setQueue($queue);
        }

        return $service;
    }
}
