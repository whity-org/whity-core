<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use Closure;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Throwable;
use Whity\Core\Messaging\MessagingService;
use Whity\Core\Messaging\Recipient;
use Whity\Core\Messaging\SendOptions;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;

/**
 * Booking reminders by text (WhatsApp, SMS), through the one way Whity
 * messages customers (merge map C4, WHIT-1123).
 *
 * Core's email reminder keeps its own template (EmailBookings). Beside it, each
 * text channel a plugin installs ({@see \Whity\Sdk\Messaging\MessageChannel})
 * that is on for the workspace gets the reminder at the customer's phone
 * number. It is sent through {@see MessagingService::send()}, so it lands in
 * the customer's one conversation (C5), is counted like any message, and the
 * channel decides about templates and consent. This replaces the calendar's
 * own `ReminderChannel` hook, which nothing used.
 *
 * Never stops the email reminder: a channel that refuses is logged and skipped.
 */
final class BookingTextReminders
{
    /** Core's channels that are not text messages to a phone. */
    private const NOT_TEXT = ['email', 'webchat'];

    /** @param Closure(): ?MessagingService $messaging resolved when a reminder goes, so the queue worker finds the one the app wired */
    public function __construct(
        private readonly Closure $messaging,
        private readonly SettingsService $settings,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /** The app's messaging, when it is wired (web and worker); otherwise none. */
    public static function fromApp(SettingsService $settings, ?LoggerInterface $logger = null): self
    {
        return new self(static function (): ?MessagingService {
            // What `register_service()` wired (public/index.php, which the worker boots too).
            $service = $GLOBALS['whity_services'][MessagingService::class] ?? null;

            return $service instanceof MessagingService ? $service : null;
        }, $settings, $logger);
    }

    /**
     * Remind the customer on every text channel that is on. Returns what
     * happened on each channel (WHIT-1417): a {@see BookingRepository}
     * REMINDER_* outcome, `no_address` when the booking has no phone.
     *
     * @param array<string, mixed> $booking as {@see BookingService} reads it, with `service` and `staff_name`
     * @return array<string, string>
     */
    public function remind(int $tenantId, array $booking): array
    {
        $phone = trim((string) ($booking['customer']['phone'] ?? $booking['customer_phone'] ?? ''));
        $messaging = ($this->messaging)();
        if ($messaging === null) {
            return [];
        }
        $text = $this->text($tenantId, $booking);
        $name = trim((string) ($booking['customer']['name'] ?? $booking['customer_name'] ?? ''));
        $sent = [];
        foreach ($messaging->channels()->keys() as $key) {
            if (in_array($key, self::NOT_TEXT, true) || !$this->isOn($messaging, $tenantId, $key)) {
                continue;
            }
            if ($phone === '') {
                $sent[$key] = BookingRepository::REMINDER_NO_ADDRESS;
                continue;
            }
            try {
                $messaging->send(
                    $tenantId,
                    Recipient::address($phone, $name !== '' ? $name : null),
                    $text,
                    new SendOptions(channel: $key, source: 'calendar.reminder'),
                );
                $sent[$key] = BookingRepository::REMINDER_SENT;
            } catch (Throwable $e) {
                // Off for the workspace, no consent, a bad number: the email still went.
                $this->logger?->info('[calendar] no text reminder on ' . $key . ': ' . $e->getMessage());
                $sent[$key] = BookingRepository::REMINDER_FAILED;
            }
        }

        return $sent;
    }

    /**
     * Send a text on every text channel that is on (WHIT-1442: a waitlist
     * offer). Returns each channel's outcome, as {@see self::remind()} does.
     *
     * @return array<string, string>
     */
    public function sendText(int $tenantId, string $phone, string $name, string $text, string $source): array
    {
        $messaging = ($this->messaging)();
        if ($messaging === null) {
            return [];
        }
        $sent = [];
        foreach ($messaging->channels()->keys() as $key) {
            if (in_array($key, self::NOT_TEXT, true) || !$this->isOn($messaging, $tenantId, $key)) {
                continue;
            }
            if (trim($phone) === '') {
                $sent[$key] = BookingRepository::REMINDER_NO_ADDRESS;
                continue;
            }
            try {
                $messaging->send($tenantId, Recipient::address(trim($phone), $name !== '' ? $name : null), $text, new SendOptions(channel: $key, source: $source));
                $sent[$key] = BookingRepository::REMINDER_SENT;
            } catch (Throwable $e) {
                $this->logger?->info('[calendar] no text on ' . $key . ': ' . $e->getMessage());
                $sent[$key] = BookingRepository::REMINDER_FAILED;
            }
        }

        return $sent;
    }

    /** Whether a channel is on for the workspace; one that is off is not a failed reminder. */
    private function isOn(MessagingService $messaging, int $tenantId, string $key): bool
    {
        try {
            return $messaging->channels()->get($key)?->isEnabled($tenantId) === true;
        } catch (Throwable $e) {
            $this->logger?->info('[calendar] could not tell whether ' . $key . ' is on: ' . $e->getMessage());

            return false;
        }
    }

    /** @param array<string, mixed> $booking */
    private function text(int $tenantId, array $booking): string
    {
        $effective = $this->settings->effective($tenantId);
        $arabic = str_starts_with((string) ($effective[SettingsRegistry::LOCALE] ?? 'en'), 'ar');
        // WHIT-1440 (AP.16): in the zone of the person's branch.
        $zoneName = BranchZones::valid($booking['time_zone'] ?? null);
        $zone = $zoneName !== null ? new \DateTimeZone($zoneName) : CalendarSettings::fromSettings($effective)->zone;
        $start = Interval::fromApi(is_string($booking['starts_at'] ?? null) ? $booking['starts_at'] : null) ?? new DateTimeImmutable();
        $when = EmailBookingMessenger::when($start->setTimezone($zone), $arabic);
        $service = is_array($booking['service']['name'] ?? null) ? $booking['service']['name'] : [];
        $serviceName = (string) ($service[$arabic ? 'ar' : 'en'] ?? $service['en'] ?? reset($service) ?: '');
        $with = trim((string) ($booking['staff_name'] ?? ''));
        // WHIT-1417 (AP.15): the customer's page, where I'm coming and I can't come are.
        $link = is_string($booking['manage_url'] ?? null) && $booking['manage_url'] !== '' ? $booking['manage_url'] : null;

        if ($arabic) {
            return trim('تذكير: ' . $serviceName . ' ' . $when . ($with !== '' ? ' مع ' . $with : '') . '.'
                . ($link !== null ? ' هل ستحضر؟ أخبرنا هنا: ' . $link : ''));
        }

        return trim('Reminder: ' . $serviceName . ' on ' . $when . ($with !== '' ? ' with ' . $with : '') . '.'
            . ($link !== null ? ' Are you coming? Let us know: ' . $link : ''));
    }
}
