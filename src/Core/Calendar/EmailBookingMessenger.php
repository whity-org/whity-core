<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Whity\Core\Branding\TenantHostRepository;
use Whity\Core\Mail\BrandedEmailSender;
use Whity\Core\Mail\EmailContent;
use Whity\Core\Mail\EmailOptions;
use Whity\Core\Mail\MailAttachment;
use Whity\Core\Mail\ThreadedMailer;
use Whity\Core\Money\Currency;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;

/**
 * Booking emails to the customer (ADR 0017, section 5): confirmation,
 * move, cancellation and the reminder, in the workspace's language (English
 * or Arabic), with times in the workspace's zone named in the text.
 *
 * Sends through {@see BrandedEmailSender}. The confirmation and the move carry
 * the booking as an `.ics` attachment when the mailer can send one ({@see ThreadedMailer},
 * WHIT-947); a mailer that cannot sends the same email without it, and the
 * manage page still serves the file.
 *
 * A booking with no email address gets nothing. Delivery failures are
 * logged and never thrown.
 */
final class EmailBookingMessenger implements BookingMessenger
{
    private const AR_MONTHS = ['كانون الثاني', 'شباط', 'آذار', 'نيسان', 'أيار', 'حزيران', 'تموز', 'آب', 'أيلول', 'تشرين الأول', 'تشرين الثاني', 'كانون الأول'];
    private const AR_DAYS = ['الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت', 'الأحد'];

    public function __construct(
        private readonly BrandedEmailSender $sender,
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

    public function awaitingPayment(int $tenantId, array $booking): void
    {
        $this->send($tenantId, $booking, 'held');
    }

    public function requested(int $tenantId, array $booking): void
    {
        $this->send($tenantId, $booking, 'requested');
    }

    public function suggested(int $tenantId, array $booking): void
    {
        $this->send($tenantId, $booking, 'suggested');
    }

    /**
     * The email for one booking, without sending it. Public for tests.
     *
     * @param array<string, mixed> $booking
     * @return array{subject: string, text: string, html: string}|null
     */
    public function render(int $tenantId, array $booking, string $kind): ?array
    {
        $message = $this->content($tenantId, $booking, $kind);
        if ($message === null) {
            return null;
        }
        // Tenant 0: the instance's brand and from name, as booking emails have always had.
        $rendered = $this->sender->render(0, $message['content']);

        return ['subject' => $message['subject'], 'text' => $rendered->text, 'html' => $rendered->html];
    }

    /**
     * @param array<string, mixed> $booking
     * @return array{subject: string, content: EmailContent}|null
     */
    private function content(int $tenantId, array $booking, string $kind): ?array
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
        if ($kind === 'held' && is_string($booking['hold_expires_at'] ?? null)) {
            $until = Interval::fromApi($booking['hold_expires_at']);
            if ($until !== null) {
                $paragraphs[] = sprintf($t['held.until'], $until->setTimezone($settings->zone)->format('H:i'));
            }
        }
        if ($kind === 'confirmed' && $link === null && ($booking['payment_ref'] ?? null) !== null) {
            $paragraphs[] = $t['confirmed.paid'];
        }
        foreach ($lines as $line) {
            if ($line !== null) {
                $paragraphs[] = $line;
            }
        }
        return [
            'subject' => $heading . ($workspace !== '' ? ' · ' . $workspace : ''),
            'content' => new EmailContent(
                heading: $heading,
                paragraphs: $paragraphs,
                ctaLabel: $link !== null ? $t['cta'] : null,
                ctaUrl: $link,
                footnote: $link !== null ? $t['footnote'] : null,
                language: $arabic ? 'ar' : 'en',
            ),
        ];
    }

    /** @param array<string, mixed> $booking */
    private function send(int $tenantId, array $booking, string $kind): void
    {
        try {
            $message = $this->content($tenantId, $booking, $kind);
            if ($message === null) {
                return;
            }
            $customer = is_array($booking['customer'] ?? null) ? $booking['customer'] : [];
            $to = (string) ($customer['email'] ?? '');
            $ics = in_array($kind, ['confirmed', 'rescheduled'], true) ? $this->icsFor($tenantId, $booking) : null;
            // Tenant 0: the instance's brand and from name, as booking emails have always had.
            $this->sender->deliver(0, $to, $message['subject'], $message['content'], $ics !== null
                ? new EmailOptions(attachments: [new MailAttachment('booking.ics', 'text/calendar; charset=utf-8; method=PUBLISH', $ics)])
                : null);
        } catch (\Throwable $e) {
            $this->logger?->warning('[calendar] could not send a booking email', ['kind' => $kind, 'booking' => $booking['id'] ?? null, 'error' => $e->getMessage()]);
        }
    }

    /**
     * The booking as iCalendar. The UID is the same one the manage page's
     * .ics uses, so a moved booking replaces the earlier one in the customer's
     * calendar instead of adding a second.
     *
     * @param array<string, mixed> $booking
     */
    public function icsFor(int $tenantId, array $booking): ?string
    {
        $start = Interval::fromApi($booking['starts_at'] ?? null);
        $end = Interval::fromApi($booking['ends_at'] ?? null);
        if ($start === null || $end === null || !is_int($booking['id'] ?? null)) {
            return null;
        }
        $service = is_array($booking['service'] ?? null) ? $booking['service'] : [];
        $name = self::label(is_array($service['name'] ?? null) ? $service['name'] : [], false);
        $workspace = $this->tenants->findNameById($tenantId) ?? '';

        return Ics::calendar([[
            'uid' => 'booking-' . $booking['id'] . '-' . $tenantId . '@whity',
            'start' => $start,
            'end' => $end,
            'summary' => trim($name . ($workspace !== '' ? ' · ' . $workspace : '')),
            'description' => is_string($booking['staff_name'] ?? null) ? $booking['staff_name'] : '',
            'sequence' => time(),
        ]]);
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
        'held.heading' => 'Held for you: %s',
        'held.lead' => 'Your time is held while you pay. It is confirmed once the payment goes through.',
        'held.until' => 'If the payment is not finished by %s, the time is let go.',
        'confirmed.paid' => 'Your payment went through. To move or cancel, use the link in the email we sent when you booked.',
        'reminder.lead' => 'This is a reminder of your booking.',
        'requested.heading' => 'Request sent: %s',
        'requested.lead' => 'We have your request. The time is not booked yet: you will get an email when it is accepted, or if another time is suggested.',
        'suggested.heading' => 'Another time for: %s',
        'suggested.lead' => 'The time you asked for is not possible. Here is another one. Open the link to take it or to pick a different time.',
        'line.when' => 'When: %s',
        'line.with' => 'With: %s',
        'line.price' => 'Price: %s',
        'line.where' => 'At: %s',
        'cta' => 'Move or cancel',
        'footnote' => 'The link works until the booking starts. The booking is attached as a calendar file, and the link offers it too.',
    ];

    private const AR = [
        'confirmed.heading' => 'تم الحجز: %s',
        'confirmed.lead' => 'تم تأكيد حجزك.',
        'rescheduled.heading' => 'تم تغيير الموعد: %s',
        'rescheduled.lead' => 'لحجزك موعد جديد.',
        'cancelled.heading' => 'تم الإلغاء: %s',
        'cancelled.lead' => 'تم إلغاء حجزك.',
        'reminder.heading' => 'تذكير: %s',
        'held.heading' => 'محجوز لك: %s',
        'held.lead' => 'موعدك محجوز ريثما تدفع، ويتأكد عند إتمام الدفع.',
        'held.until' => 'إذا لم يكتمل الدفع حتى الساعة %s يُلغى الحجز.',
        'confirmed.paid' => 'تم الدفع. لتغيير الموعد أو إلغائه استخدم الرابط في رسالة الحجز السابقة.',
        'reminder.lead' => 'هذا تذكير بحجزك.',
        'requested.heading' => 'تم إرسال الطلب: %s',
        'requested.lead' => 'وصلنا طلبك. لم يُحجز الموعد بعد: ستصلك رسالة عند قبوله أو عند اقتراح موعد آخر.',
        'suggested.heading' => 'موعد آخر لـ: %s',
        'suggested.lead' => 'الموعد الذي طلبته غير متاح، وهذا موعد آخر. افتح الرابط لقبوله أو لاختيار موعد مختلف.',
        'line.when' => 'الموعد: %s',
        'line.with' => 'مع: %s',
        'line.price' => 'السعر: %s',
        'line.where' => 'لدى: %s',
        'cta' => 'تغيير الموعد أو الإلغاء',
        'footnote' => 'يعمل الرابط حتى بدء الموعد. الحجز مرفق بملف تقويم، ويمكنك تنزيله من الرابط أيضاً.',
    ];
}
