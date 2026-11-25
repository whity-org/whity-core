<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use PDO;
use Psr\Log\LoggerInterface;
use Whity\Core\Branding\TenantHostRepository;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Http\FrontendUrls;
use Whity\Core\Mail\BrandedEmailSender;
use Whity\Core\Notification\NotificationDispatcher;
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
        BrandedEmailSender $sender,
        FrontendUrls $urls,
        ?QueueService $queue,
        ?LoggerInterface $logger = null,
        ?CalendarPlugins $plugins = null,
        ?CalendarInvites $invites = null,
    ): BookingService {
        $service = new BookingService(
            $pdo,
            new CalendarRepository($pdo),
            new ServiceRepository($pdo),
            new BookingRepository($pdo),
            $settings,
            $hooks,
        );
        // Meetings are calendar events (ADR 0018), so free/busy already counts them.
        $service->setLinks(new ManageLinks($pdo));
        $service->setManageUrlBase($urls->linkOrPath(FrontendUrls::BOOKING_MANAGE));
        if ($plugins !== null) {
            $service->setPlugins($plugins);
        }
        $service->setMessenger(new EmailBookingMessenger(
            $sender,
            $settings,
            new TenantHostRepository($pdo),
            $urls->linkOrPath(FrontendUrls::BOOKING_MANAGE),
            $logger,
            new \Whity\Core\CustomerArea\CustomerAreaLinks($pdo, $settings, new TenantHostRepository($pdo), $urls->base()),
        ));
        if ($queue !== null) {
            $service->setQueue($queue);
        }
        if ($invites !== null) {
            $service->setInvites($invites);
        }

        return $service;
    }

    /** The one invite path for events with people (ADR 0018). */
    public static function invites(
        PDO $pdo,
        NotificationDispatcher $notifications,
        BrandedEmailSender $sender,
        SettingsService $settings,
        FrontendUrls $urls,
        ?LoggerInterface $logger = null,
    ): CalendarInvites {
        $base = $urls->base();

        return new CalendarInvites(
            $pdo,
            new CalendarRepository($pdo),
            $notifications,
            $sender,
            $settings,
            ($base !== '' ? $base : '') . '/calendar',
            $logger,
        );
    }
}
