<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Whity\Core\Branding\TenantHostRepository;
use Whity\Core\Mail\EmailBranding;
use Whity\Core\Mail\EmailContent;
use Whity\Core\Mail\EmailLayout;
use Whity\Core\Mail\Mailer;
use Whity\Core\Money\Currency;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;

/**
 * Booking emails to the customer (ADR 0017, section 5): confirmation,
 * move, cancellation and the reminder, in the workspace's language (English
 * or Arabic), with times in the workspace's zone named in the text.
 *
 * Until the core messages API carries attachments (ADR 0016), this sends
 * through the Mailer; the confirmation's `.ics` is offered as a link to the
 * manage page, which serves it, rather than attached.
 *
 * A booking with no email address gets nothing. Delivery failures are
 * logged and never thrown.
 */
final class EmailBookingMessenger implements BookingMessenger
{
    private const AR_MONTHS = ['كانون الثاني', 'شباط', 'آذار', 'نيسان', 'أيار', 'حزيران', 'تموز', 'آب', 'أيلول', 'تشرين الأول', 'تشرين الثاني', 'كانون الأول'];
    private const AR_DAYS = ['الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت', 'الأحد'];

    public function __construct(
        private readonly Mailer $mailer,
        private readonly EmailLayout $layout,
        private readonly SettingsService $settings,
        private readonly TenantHostRepository $tenants,
        private readonly string $manageUrlBase,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function confirmed(int $tenantId, array $booking): void
    {
        $this->send($tenantId, $booking, 'confirmed');
    }

    public function rescheduled(int $tenantId, array $booking): void
    {
        $this->send($tenantId, $booking, 'rescheduled');
    }

    public function cancelled(int $tenantId, array $booking): void
    {
        $this->send($tenantId, $booking, 'cancelled');
    }

    public function remind(int $tenantId, array $booking): void
    {
        $this->send($tenantId, $booking, 'reminder');
    }

    /**
     * The email for one booking, without sending it. Public for tests.
     *
     * @param array<string, mixed> $booking
     * @return array{subject: string, text: string, html: string}|null
     */
    public function render(int $tenantId, array $booking, string $kind): ?array
    {
        $customer = is_array($booking['customer'] ?? null) ? $booking['customer'] : [];
        $email = is_string($customer['email'] ?? null) ? $customer['email'] : '';
        if ($email === '') {
            return null;
        }
        $effective = $this->settings->effective($tenantId);
        $settings = CalendarSettings::fromSettings($effective);
        $arabic = str_starts_with((string) ($effective[SettingsRegistry::LOCALE] ?? 'en'), 'ar');
        $workspace = $this->tenants->findNameById($tenantId) ?? '';
        $service = is_array($booking['service'] ?? null) ? $booking['service'] : [];
        $serviceName = self::label(is_array($service['name'] ?? null) ? $service['name'] : [], $arabic);
        $staff = is_string($booking['staff_name'] ?? null) ? $booking['staff_name'] : '';
        $start = Interval::fromApi($booking['starts_at'] ?? null) ?? new DateTimeImmutable();
        $when = self::when($start->setTimezone($settings->zone), $arabic) . ' (' . $settings->zone->getName() . ')';
        $price = is_int($booking['price_minor'] ?? null) && is_string($booking['currency'] ?? null)
            ? self::money($booking['price_minor'], $booking['currency'])
            : null;
        $token = is_string($booking['manage_token'] ?? null) ? $booking['manage_token'] : null;
        $link = $token !== null && $kind !== 'cancelled' ? rtrim($this->manageUrlBase, '/') . '/' . $token : null;

        $t = $arabic ? self::AR : self::EN;
        $heading = sprintf($t[$kind . '.heading'], $serviceName);
        $lines = [
            sprintf($t['line.when'], $when),
            $staff !== '' ? sprintf($t['line.with'], $staff) : null,
            $price !== null ? sprintf($t['line.price'], $price) : null,
            $workspace !== '' ? sprintf($t['line.where'], $workspace) : null,
        ];
        $paragraphs = [$t[$kind . '.lead']];
        foreach ($lines as $line) {
            if ($line !== null) {
                $paragraphs[] = $line;
            }
        }
        $rendered = $this->layout->render(
            new EmailContent(
                heading: $heading,
                paragraphs: $paragraphs,
                ctaLabel: $link !== null ? $t['cta'] : null,
                ctaUrl: $link,
                footnote: $link !== null ? $t['footnote'] : null,
            ),
            EmailBranding::fromSettings($this->settings),
        );

        return ['subject' => $heading . ($workspace !== '' ? ' · ' . $workspace : ''), 'text' => $rendered->text, 'html' => $rendered->html];
    }

    /** @param array<string, mixed> $booking */
    private function send(int $tenantId, array $booking, string $kind): void
    {
        try {
            $message = $this->render($tenantId, $booking, $kind);
            if ($message === null) {
                return;
            }
            $customer = is_array($booking['customer'] ?? null) ? $booking['customer'] : [];
            $this->mailer->send((string) ($customer['email'] ?? ''), $message['subject'], $message['text'], $message['html']);
        } catch (\Throwable $e) {
            $this->logger?->warning('[calendar] could not send a booking email', ['kind' => $kind, 'booking' => $booking['id'] ?? null, 'error' => $e->getMessage()]);
        }
    }

    /** @param array<string, mixed> $label */
    private static function label(array $label, bool $arabic): string
    {
        $first = $arabic ? 'ar' : 'en';
        $second = $arabic ? 'en' : 'ar';
        foreach ([$first, $second] as $language) {
            if (is_string($label[$language] ?? null) && $label[$language] !== '') {
                return $label[$language];
            }
        }

        return '';
    }

    /** "Monday 16 November 2026, 10:00", or the Arabic with Levantine months and Western digits. */
    public static function when(DateTimeImmutable $local, bool $arabic): string
    {
        if (!$arabic) {
            return $local->format('l j F Y, H:i');
        }
        $day = self::AR_DAYS[(int) $local->format('N') - 1];
        $month = self::AR_MONTHS[(int) $local->format('n') - 1];

        return $day . ' ' . $local->format('j') . ' ' . $month . ' ' . $local->format('Y') . '، ' . $local->format('H:i');
    }

    /** "JOD 12.500": normal units with the currency's decimals. */
    public static function money(int $minor, string $currency): string
    {
        $exponent = Currency::exponentFor($currency);
        $negative = $minor < 0;
        $digits = str_pad((string) abs($minor), $exponent + 1, '0', STR_PAD_LEFT);
        $amount = $exponent > 0 ? substr($digits, 0, -$exponent) . '.' . substr($digits, -$exponent) : $digits;

        return strtoupper($currency) . ' ' . ($negative ? '-' : '') . $amount;
    }

    private const EN = [
        'confirmed.heading' => 'Booked: %s',
        'confirmed.lead' => 'Your booking is confirmed.',
        'rescheduled.heading' => 'Moved: %s',
        'rescheduled.lead' => 'Your booking has a new time.',
        'cancelled.heading' => 'Cancelled: %s',
        'cancelled.lead' => 'Your booking has been cancelled.',
        'reminder.heading' => 'Reminder: %s',
        'reminder.lead' => 'This is a reminder of your booking.',
        'line.when' => 'When: %s',
        'line.with' => 'With: %s',
        'line.price' => 'Price: %s',
        'line.where' => 'At: %s',
        'cta' => 'Move or cancel',
        'footnote' => 'The link works until the booking starts. It also lets you add the booking to your calendar.',
    ];

    private const AR = [
        'confirmed.heading' => 'تم الحجز: %s',
        'confirmed.lead' => 'تم تأكيد حجزك.',
        'rescheduled.heading' => 'تم تغيير الموعد: %s',
        'rescheduled.lead' => 'لحجزك موعد جديد.',
        'cancelled.heading' => 'تم الإلغاء: %s',
        'cancelled.lead' => 'تم إلغاء حجزك.',
        'reminder.heading' => 'تذكير: %s',
        'reminder.lead' => 'هذا تذكير بحجزك.',
        'line.when' => 'الموعد: %s',
        'line.with' => 'مع: %s',
        'line.price' => 'السعر: %s',
        'line.where' => 'لدى: %s',
        'cta' => 'تغيير الموعد أو الإلغاء',
        'footnote' => 'يعمل الرابط حتى بدء الموعد، ويمكنك منه إضافة الحجز إلى تقويمك.',
    ];
}
