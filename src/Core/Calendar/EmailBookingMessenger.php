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
use Whity\Core\Money\Currency;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;

/**
 * Booking emails to the customer (ADR 0017 section 5; design 3.4 `EmailBookings`,
 * `handoff/emails/booking-*`): confirmed, moved, cancelled and the reminder,
 * plus the held, requested and suggested notes that have no card of their own.
 * In the workspace's brand and language, from "the workspace, via Whity", with
 * times in the workspace's zone.
 *
 * **Calendar file (E2).** Confirmed and moved carry `booking.ics` as
 * METHOD:REQUEST; cancelled carries METHOD:CANCEL. All three use one UID for
 * the booking's life (`booking-{id}-{tenant}@whity`, the manage page's too) and
 * the booking's `ics_sequence`, which every move and the cancel raise, so a
 * calendar updates or removes the entry it already has instead of adding one.
 *
 * Replies go to the workspace's own address (Settings › Company) when it has
 * one; the footer says "Reply to this email to reach them" only then.
 *
 * A booking with no email address gets nothing. Delivery failures are logged
 * and never thrown.
 */
final class EmailBookingMessenger implements BookingMessenger
{
    private const AR_MONTHS = ['كانون الثاني', 'شباط', 'آذار', 'نيسان', 'أيار', 'حزيران', 'تموز', 'آب', 'أيلول', 'تشرين الأول', 'تشرين الثاني', 'كانون الأول'];
    private const AR_DAYS = ['الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت', 'الأحد'];

    /** @var (\Closure(): DateTimeImmutable)|null */
    private ?\Closure $clock = null;

    public function __construct(
        private readonly BrandedEmailSender $sender,
        private readonly SettingsService $settings,
        private readonly TenantHostRepository $tenants,
        private readonly string $manageUrlBase,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /** @param \Closure(): DateTimeImmutable $clock For tests: what "now" is when a reminder says "tomorrow". */
    public function setClock(\Closure $clock): void
    {
        $this->clock = $clock;
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
     * The email for one booking, without sending it. Public for tests and the
     * email preview.
     *
     * @param array<string, mixed> $booking
     * @return array{subject: string, text: string, html: string, ics: ?string, method: ?string}|null
     */
    public function render(int $tenantId, array $booking, string $kind): ?array
    {
        $message = $this->message($tenantId, $booking, $kind);
        if ($message === null) {
            return null;
        }
        $rendered = $this->sender->render($tenantId, $message['content']);

        return [
            'subject' => $message['subject'],
            'text' => $rendered->text,
            'html' => $rendered->html,
            'ics' => $message['ics'],
            'method' => $message['method'],
        ];
    }

    /** @param array<string, mixed> $booking */
    private function send(int $tenantId, array $booking, string $kind): void
    {
        try {
            $message = $this->message($tenantId, $booking, $kind);
            if ($message === null) {
                return;
            }
            $attachments = $message['ics'] !== null && $message['method'] !== null
                ? [new MailAttachment('booking.ics', 'text/calendar; charset=utf-8; method=' . $message['method'], $message['ics'])]
                : [];
            $this->sender->deliver(
                $tenantId,
                $message['to'],
                $message['subject'],
                $message['content'],
                new EmailOptions(attachments: $attachments, replyTo: $message['replyTo']),
            );
        } catch (\Throwable $e) {
            $this->logger?->warning('[calendar] could not send a booking email', ['kind' => $kind, 'booking' => $booking['id'] ?? null, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string, mixed> $booking
     * @return array{to: string, subject: string, content: EmailContent, ics: ?string, method: ?string, replyTo: ?string}|null
     */
    private function message(int $tenantId, array $booking, string $kind): ?array
    {
        $customer = is_array($booking['customer'] ?? null) ? $booking['customer'] : [];
        $email = is_string($customer['email'] ?? null) ? trim($customer['email']) : '';
        if ($email === '') {
            return null;
        }
        $effective = $this->settings->effective($tenantId);
        $calendar = CalendarSettings::fromSettings($effective);
        $arabic = str_starts_with((string) ($effective[SettingsRegistry::LOCALE] ?? 'en'), 'ar');
        $t = $arabic ? self::AR : self::EN;
        $workspace = $this->sender->branding($tenantId)->siteName;
        ['address' => $address, 'replyTo' => $replyTo] = $this->sender->workspaceContact($tenantId, $arabic ? 'ar' : 'en');

        $service = is_array($booking['service'] ?? null) ? $booking['service'] : [];
        $serviceName = self::label(is_array($service['name'] ?? null) ? $service['name'] : [], $arabic);
        $staff = is_string($booking['staff_name'] ?? null) ? trim($booking['staff_name']) : '';
        $room = self::label(is_array($booking['room_name'] ?? null) ? $booking['room_name'] : [], $arabic);
        $place = $room !== '' ? $room : $workspace;
        $name = is_string($customer['name'] ?? null) ? trim($customer['name']) : '';
        $start = Interval::fromApi($booking['starts_at'] ?? null) ?? new DateTimeImmutable();
        $when = self::when($start->setTimezone($calendar->zone), $arabic);
        $previous = Interval::fromApi($booking['previous_starts_at'] ?? null);
        $was = $previous !== null ? self::when($previous->setTimezone($calendar->zone), $arabic) : null;
        $price = is_int($booking['price_minor'] ?? null) && is_string($booking['currency'] ?? null)
            ? self::money($booking['price_minor'], $booking['currency'])
            : null;

        $token = is_string($booking['manage_token'] ?? null) ? $booking['manage_token'] : null;
        $manage = $token !== null && $kind !== 'cancelled' ? rtrim($this->manageUrlBase, '/') . '/' . $token : null;
        $origin = self::origin($this->manageUrlBase);
        $icsUrl = $token !== null && $origin !== null ? $origin . '/api/v1/public/bookings/' . $token . '/ics' : null;
        $slug = $this->tenants->findSlugById($tenantId);
        $bookAgain = $calendar->bookingPageEnabled && $slug !== null && $origin !== null ? $origin . '/book/' . rawurlencode($slug) : null;

        $rows = [[$t['row.what'], $serviceName], [$t['row.when'], $when], [$t['row.where'], $place]];
        if ($staff !== '') {
            $rows[] = [$t['row.with'], $staff];
        }
        $footer = implode(' · ', array_filter([$workspace, $address, $replyTo !== null ? $t['foot.reply'] : null], static fn (?string $v): bool => $v !== null && $v !== ''));
        $attached = $t['note.attached'];
        $method = null;
        $content = null;

        switch ($kind) {
            case 'confirmed':
                $subject = sprintf($t['confirmed.subject'], $serviceName, $when);
                $paragraphs = [$name !== '' ? sprintf($t['confirmed.hello'], $name) : $t['confirmed.soon']];
                if ($manage === null && ($booking['payment_ref'] ?? null) !== null) {
                    $paragraphs[] = $t['confirmed.paid'];
                }
                $content = new EmailContent(
                    heading: $t['confirmed.heading'],
                    paragraphs: $paragraphs,
                    ctaLabel: $icsUrl !== null ? $t['confirmed.cta'] : null,
                    ctaUrl: $icsUrl,
                    preheader: trim(sprintf($t['confirmed.preheader'], $place) . ($staff !== '' ? sprintf($t['pre.with'], $staff) : '') . $t['pre.end'] . ($manage !== null ? ' ' . $t['pre.change'] : '')),
                    rows: $rows,
                    notice: $footer,
                    language: $arabic ? 'ar' : 'en',
                    rowStyles: [0 => 'strong'],
                    linkLine: $manage !== null ? ['before' => $t['confirmed.line.before'], 'label' => $t['line.move'], 'url' => $manage, 'after' => $t['confirmed.line.after']] : null,
                    smallNote: $attached,
                );
                $method = 'REQUEST';
                break;

            case 'rescheduled':
                $subject = sprintf($t['moved.subject'], $serviceName, $when);
                $movedRows = $was !== null ? [[$t['row.was'], $was], [$t['row.now'], $when]] : [[$t['row.when'], $when]];
                $movedRows[] = [$t['row.where'], $place];
                if ($staff !== '') {
                    $movedRows[] = [$t['row.with'], $staff];
                }
                $content = new EmailContent(
                    heading: $t['moved.heading'],
                    ctaLabel: $icsUrl !== null ? $t['moved.cta'] : null,
                    ctaUrl: $icsUrl,
                    preheader: $was !== null ? sprintf($t['moved.preheader'], $was) : null,
                    rows: $movedRows,
                    notice: $footer,
                    rowStyles: $was !== null ? [0 => 'struck', 1 => 'strong'] : [],
                    language: $arabic ? 'ar' : 'en',
                    linkLine: $manage !== null ? ['before' => $t['moved.line.before'], 'label' => $t['moved.line.label'], 'url' => $manage, 'after' => $t['moved.line.after']] : null,
                    smallNote: $attached,
                );
                $method = 'REQUEST';
                break;

            case 'cancelled':
                $subject = sprintf($t['cancelled.subject'], $serviceName, $when);
                $content = new EmailContent(
                    heading: $t['cancelled.heading'],
                    ctaLabel: $bookAgain !== null ? $t['cancelled.cta'] : null,
                    ctaUrl: $bookAgain,
                    preheader: sprintf($t['cancelled.preheader'], $workspace),
                    rows: $rows,
                    notice: $footer,
                    language: $arabic ? 'ar' : 'en',
                    rowStyles: [0 => 'strong'],
                    smallNote: $t['note.removed'],
                );
                $method = 'CANCEL';
                break;

            case 'reminder':
                $day = $this->day($start, $calendar);
                $subject = sprintf($t['reminder.subject.' . $day], $serviceName, $place);
                $directions = $address !== null ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($address) : null;
                $content = new EmailContent(
                    heading: $t['reminder.heading.' . $day],
                    ctaLabel: $directions !== null ? $t['reminder.cta'] : ($manage !== null ? $t['line.move'] : null),
                    ctaUrl: $directions ?? $manage,
                    preheader: $when . ($staff !== '' ? sprintf($t['pre.with'], $staff) : '') . $t['pre.end'],
                    rows: $rows,
                    notice: $footer,
                    language: $arabic ? 'ar' : 'en',
                    rowStyles: [0 => 'strong'],
                    linkLine: $directions !== null && $manage !== null ? ['before' => $t['reminder.line.before'], 'label' => $t['line.move'], 'url' => $manage, 'after' => $t['reminder.line.after']] : null,
                );
                break;

            default: // held, requested, suggested: no card of their own, the same layout.
                [$heading, $lead] = match ($kind) {
                    'held' => [$t['held.heading'], $t['held.lead']],
                    'requested' => [$t['requested.heading'], $t['requested.lead']],
                    default => [$t['suggested.heading'], $t['suggested.lead']],
                };
                $subject = sprintf($heading, $serviceName);
                $paragraphs = [$lead];
                if ($kind === 'held' && is_string($booking['hold_expires_at'] ?? null)) {
                    $until = Interval::fromApi($booking['hold_expires_at']);
                    if ($until !== null) {
                        $paragraphs[] = sprintf($t['held.until'], $until->setTimezone($calendar->zone)->format('H:i'));
                    }
                }
                $content = new EmailContent(
                    heading: $subject,
                    paragraphs: $paragraphs,
                    ctaLabel: $manage !== null ? $t['line.move'] : null,
                    ctaUrl: $manage,
                    footnote: $manage !== null ? $t['footnote'] : null,
                    // Not yet booked or paid: the price helps decide. The cards' emails leave it out.
                    rows: $price !== null ? [...$rows, [$t['row.price'], $price]] : $rows,
                    notice: $footer,
                    language: $arabic ? 'ar' : 'en',
                    rowStyles: [0 => 'strong'],
                );
        }

        return [
            'to' => $email,
            'subject' => $subject,
            'content' => $content,
            'ics' => $method !== null ? $this->icsFor($tenantId, $booking, $method, $email, $name) : null,
            'method' => $method,
            'replyTo' => $replyTo,
        ];
    }

    /**
     * The booking as iCalendar for an email: one UID for the booking's life
     * (the manage page's file uses it too) and the booking's revision, so a
     * REQUEST updates the entry a calendar already has and a CANCEL removes it.
     *
     * @param array<string, mixed> $booking
     */
    public function icsFor(int $tenantId, array $booking, string $method = 'REQUEST', string $attendee = '', string $attendeeName = ''): ?string
    {
        $start = Interval::fromApi($booking['starts_at'] ?? null);
        $end = Interval::fromApi($booking['ends_at'] ?? null);
        if ($start === null || $end === null || !is_int($booking['id'] ?? null)) {
            return null;
        }
        $service = is_array($booking['service'] ?? null) ? $booking['service'] : [];
        $name = self::label(is_array($service['name'] ?? null) ? $service['name'] : [], false);
        $workspace = $this->sender->branding($tenantId)->siteName;
        $event = [
            'uid' => self::uid($tenantId, (int) $booking['id']),
            'start' => $start,
            'end' => $end,
            'summary' => trim($name . ($workspace !== '' ? ' · ' . $workspace : '')),
            'description' => is_string($booking['staff_name'] ?? null) ? $booking['staff_name'] : '',
            'status' => $method === 'CANCEL' ? 'CANCELLED' : 'CONFIRMED',
            'sequence' => is_int($booking['ics_sequence'] ?? null) ? $booking['ics_sequence'] : 0,
        ];
        $organizer = $this->organizerEmail($tenantId);
        if ($organizer !== null) {
            $event['organizer'] = ['name' => $workspace, 'email' => $organizer];
        }
        if ($attendee !== '') {
            $event['attendee'] = ['name' => $attendeeName, 'email' => $attendee];
        }

        return Ics::calendar([$event], '', $method);
    }

    /** The UID a booking keeps for its whole life, in every file about it. */
    public static function uid(int $tenantId, int $bookingId): string
    {
        return 'booking-' . $bookingId . '-' . $tenantId . '@whity';
    }

    /**
     * RFC 5546 needs an organizer on a REQUEST: the workspace's own address,
     * else the address mail is sent from.
     */
    private function organizerEmail(int $tenantId): ?string
    {
        try {
            $effective = $this->settings->effective($tenantId);
            $global = $this->settings->getGlobal();
        } catch (\Throwable) {
            return null;
        }
        foreach ([$effective[SettingsRegistry::COMPANY_EMAIL] ?? null, $global[SettingsRegistry::MAIL_FROM_ADDRESS] ?? null] as $candidate) {
            if (is_string($candidate) && filter_var(trim($candidate), FILTER_VALIDATE_EMAIL) !== false) {
                return trim($candidate);
            }
        }

        return null;
    }

    /** 'today', 'tomorrow' or 'soon', in the workspace's zone. */
    private function day(DateTimeImmutable $start, CalendarSettings $calendar): string
    {
        $now = ($this->clock !== null ? ($this->clock)() : new DateTimeImmutable())->setTimezone($calendar->zone);
        $date = $start->setTimezone($calendar->zone)->format('Y-m-d');

        return match ($date) {
            $now->format('Y-m-d') => 'today',
            $now->modify('+1 day')->format('Y-m-d') => 'tomorrow',
            default => 'soon',
        };
    }

    /** "https://app.example.com" from "https://app.example.com/booking", or null for a bare path. */
    private static function origin(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
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

    /** "Mon 16 Nov, 10:00", or the Arabic with Levantine months and Western digits. */
    public static function when(DateTimeImmutable $local, bool $arabic): string
    {
        // 3.4 `booking-*`: the short form, with the year only when it isn't this one.
        $year = $local->format('Y') !== (new DateTimeImmutable('now', $local->getTimezone()))->format('Y');
        if (!$arabic) {
            return $local->format($year ? 'D j M Y, H:i' : 'D j M, H:i');
        }
        $day = self::AR_DAYS[(int) $local->format('N') - 1];
        $month = self::AR_MONTHS[(int) $local->format('n') - 1];

        return $day . ' ' . $local->format('j') . ' ' . $month . ($year ? ' ' . $local->format('Y') : '') . '، ' . $local->format('H:i');
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
        'row.what' => 'What',
        'row.when' => 'When',
        'row.where' => 'Where',
        'row.with' => 'With',
        'row.price' => 'Price',
        'row.was' => 'Was',
        'row.now' => 'Now',
        'pre.with' => ' with %s',
        'pre.end' => '.',
        'pre.change' => 'Move or cancel before it starts.',
        'line.move' => 'Move or cancel',
        'foot.reply' => 'Reply to this email to reach them.',
        'note.attached' => 'A calendar file is attached: open it to add the booking to your calendar.',
        'note.removed' => 'A calendar file is attached: open it to take the booking out of your calendar.',
        'confirmed.subject' => 'Booked: %s on %s',
        'confirmed.preheader' => 'At %s',
        'confirmed.heading' => "You're booked",
        'confirmed.hello' => 'Hello %s, see you soon.',
        'confirmed.soon' => 'See you soon.',
        'confirmed.cta' => 'Add to my calendar',
        'confirmed.line.before' => 'Need to change it? ',
        'confirmed.line.after' => ' before it starts.',
        'confirmed.paid' => 'Your payment went through. To move or cancel, use the link in the email we sent when you booked.',
        'moved.subject' => 'Moved: %s is now %s',
        'moved.preheader' => 'It was %s.',
        'moved.heading' => 'Your booking moved',
        'moved.cta' => 'Add the new time to my calendar',
        'moved.line.before' => "If the new time doesn't suit you, ",
        'moved.line.label' => 'choose another',
        'moved.line.after' => '.',
        'cancelled.subject' => 'Cancelled: %s on %s',
        'cancelled.preheader' => 'Your booking at %s is cancelled.',
        'cancelled.heading' => 'Your booking is cancelled',
        'cancelled.cta' => 'Book another time',
        'reminder.subject.today' => 'Today: %s at %s',
        'reminder.subject.tomorrow' => 'Tomorrow: %s at %s',
        'reminder.subject.soon' => 'Reminder: %s at %s',
        'reminder.heading.today' => 'See you today',
        'reminder.heading.tomorrow' => 'See you tomorrow',
        'reminder.heading.soon' => 'See you soon',
        'reminder.cta' => 'Get directions',
        'reminder.line.before' => "Can't make it? ",
        'reminder.line.after' => ' so someone else can have the time.',
        'held.heading' => 'Held for you: %s',
        'held.lead' => 'Your time is held while you pay. It is confirmed once the payment goes through.',
        'held.until' => 'If the payment is not finished by %s, the time is let go.',
        'requested.heading' => 'Request sent: %s',
        'requested.lead' => 'We have your request. The time is not booked yet: you will get an email when it is accepted, or if another time is suggested.',
        'suggested.heading' => 'Another time for: %s',
        'suggested.lead' => 'The time you asked for is not possible. Here is another one. Open the link to take it or to pick a different time.',
        'footnote' => 'The link works until the booking starts.',
    ];

    private const AR = [
        'row.what' => 'ماذا',
        'row.when' => 'متى',
        'row.where' => 'أين',
        'row.with' => 'مع',
        'row.price' => 'السعر',
        'row.was' => 'كان',
        'row.now' => 'أصبح',
        'pre.with' => ' مع %s',
        'pre.end' => '.',
        'pre.change' => 'يمكنك التغيير أو الإلغاء قبل بدء الموعد.',
        'line.move' => 'غيّره أو ألغِه',
        'foot.reply' => 'ردّ على هذه الرسالة للتواصل معهم.',
        'note.attached' => 'مرفق ملف تقويم: افتحه لإضافة الحجز إلى تقويمك.',
        'note.removed' => 'مرفق ملف تقويم: افتحه لإزالة الحجز من تقويمك.',
        'confirmed.subject' => 'تم الحجز: %s في %s',
        'confirmed.preheader' => 'في %s',
        'confirmed.heading' => 'تم حجزك',
        'confirmed.hello' => 'مرحباً %s، نراك قريباً.',
        'confirmed.soon' => 'نراك قريباً.',
        'confirmed.cta' => 'أضِف إلى تقويمي',
        'confirmed.line.before' => 'تحتاج إلى تغييره؟ ',
        'confirmed.line.after' => ' قبل بدء الموعد.',
        'confirmed.paid' => 'تم الدفع. لتغيير الموعد أو إلغائه استخدم الرابط في رسالة الحجز السابقة.',
        'moved.subject' => 'تم التغيير: %s أصبح %s',
        'moved.preheader' => 'كان %s.',
        'moved.heading' => 'تغيّر موعد حجزك',
        'moved.cta' => 'أضِف الموعد الجديد إلى تقويمي',
        'moved.line.before' => 'إن لم يناسبك الموعد الجديد، ',
        'moved.line.label' => 'اختر غيره',
        'moved.line.after' => '.',
        'cancelled.subject' => 'أُلغي: %s في %s',
        'cancelled.preheader' => 'أُلغي حجزك لدى %s.',
        'cancelled.heading' => 'أُلغي حجزك',
        'cancelled.cta' => 'احجز موعداً آخر',
        'reminder.subject.today' => 'اليوم: %s في %s',
        'reminder.subject.tomorrow' => 'غداً: %s في %s',
        'reminder.subject.soon' => 'تذكير: %s في %s',
        'reminder.heading.today' => 'نراك اليوم',
        'reminder.heading.tomorrow' => 'نراك غداً',
        'reminder.heading.soon' => 'نراك قريباً',
        'reminder.cta' => 'الاتجاهات',
        'reminder.line.before' => 'لن تستطيع الحضور؟ ',
        'reminder.line.after' => ' ليستفيد غيرك من الوقت.',
        'held.heading' => 'محجوز لك: %s',
        'held.lead' => 'موعدك محجوز ريثما تدفع، ويتأكد عند إتمام الدفع.',
        'held.until' => 'إذا لم يكتمل الدفع حتى الساعة %s يُلغى الحجز.',
        'requested.heading' => 'تم إرسال الطلب: %s',
        'requested.lead' => 'وصلنا طلبك. لم يُحجز الموعد بعد: ستصلك رسالة عند قبوله أو عند اقتراح موعد آخر.',
        'suggested.heading' => 'موعد آخر لـ: %s',
        'suggested.lead' => 'الموعد الذي طلبته غير متاح، وهذا موعد آخر. افتح الرابط لقبوله أو لاختيار موعد مختلف.',
        'footnote' => 'يعمل الرابط حتى بدء الموعد.',
    ];
}
