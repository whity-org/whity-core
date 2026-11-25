<?php

declare(strict_types=1);

namespace Whity\Core\Sales;

use Whity\Core\Mail\BrandedEmailSender;
use Whity\Core\Mail\EmailContent;
use Whity\Core\Mail\EmailOptions;
use Whity\Core\Mail\MailAttachment;
use Whity\Core\Money\Currency;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;

/**
 * The emails a workspace sends its customers about money (WHIT-1323, design
 * section 34 §5, register TR.2-TR.4, EM.1's "Money" group): a quote, an
 * invoice, a receipt and a payment reminder, worded as `handoff/emails/` has
 * them, in English and Arabic, drawn in the workspace's brand by
 * {@see BrandedEmailSender}.
 *
 * One service owns the words, so the quote screen and, later, accounting's
 * invoices (A2) send the same emails without drawing any HTML.
 *
 * - One main button at most (EM.1): Review and accept, Pay now (a payment
 *   link, when Whity Pay gives one), or View.
 * - Amounts in normal units with the currency (CU.1-CU.4), left to right in
 *   both languages.
 * - Replies go to the workspace.
 * - Each email is on by default; the workspace turns each off in its sales
 *   settings (`sales.email_quote`, `sales.email_invoice`,
 *   `sales.email_receipt`, `sales.email_reminder`), and its email channel
 *   switch stops them all.
 * - Best effort: answers false when nothing went out, never throws.
 */
final class TradingEmails
{
    public const QUOTE = 'quote';
    public const INVOICE = 'invoice';
    public const RECEIPT = 'receipt';
    public const REMINDER = 'reminder';

    /** Email => the workspace's switch for it. */
    public const SWITCHES = [
        self::QUOTE => SettingsRegistry::SALES_EMAIL_QUOTE,
        self::INVOICE => SettingsRegistry::SALES_EMAIL_INVOICE,
        self::RECEIPT => SettingsRegistry::SALES_EMAIL_RECEIPT,
        self::REMINDER => SettingsRegistry::SALES_EMAIL_REMINDER,
    ];

    /** WHIT-1428: "See it in your account" for customers who have one. */
    private ?\Whity\Core\CustomerArea\CustomerAreaLinks $accountLinks = null;

    public function __construct(
        private readonly BrandedEmailSender $sender,
        private readonly SettingsService $settings,
    ) {
    }

    public function setAccountLinks(\Whity\Core\CustomerArea\CustomerAreaLinks $links): void
    {
        $this->accountLinks = $links;
    }

    /** Whether the workspace sends this email. */
    public function enabled(int $tenantId, string $email): bool
    {
        $key = self::SWITCHES[$email] ?? null;
        if ($key === null) {
            return false;
        }
        try {
            $value = $this->settings->effective($tenantId)[$key] ?? SettingsRegistry::defaults()[$key] ?? 'true';
        } catch (\Throwable) {
            $value = 'true';
        }

        return $value === 'true' && $this->sender->gate()->workspace($tenantId);
    }

    /**
     * A quote to its customer.
     *
     * @param array{number: string, customer: string, what: string, total_minor: int, currency: string, valid_until: string} $quote
     */
    public function quote(int $tenantId, string $to, array $quote, string $link, ?MailAttachment $pdf = null, ?int $quoteId = null): bool
    {
        if (!$this->enabled($tenantId, self::QUOTE)) {
            return false;
        }
        $ar = $this->arabic($tenantId);
        $workspace = $this->workspace($tenantId);
        $total = self::money($quote['total_minor'], $quote['currency'], $ar);
        $valid = self::date($quote['valid_until'], $ar);
        $what = trim($quote['what']);

        return $this->deliver($tenantId, $to, $ar, $ar
            ? 'عرض السعر ' . $quote['number'] . ' من ' . $workspace
            : 'Quote ' . $quote['number'] . ' from ' . $workspace, new EmailContent(
                heading: $ar ? 'عرض السعر الخاص بك، ' . $quote['customer'] : 'Your quote, ' . $quote['customer'],
                paragraphs: [$ar
                    ? 'كما وعدنا، هذا عرض السعر' . ($what !== '' ? ' لـ ' . $what : '') . '.'
                    : 'As promised, here is our quote' . ($what !== '' ? ' for ' . $what : '') . '.'],
                ctaLabel: $ar ? 'راجع واقبل' : 'Review and accept',
                ctaUrl: $link,
                footnote: $ar ? 'أسئلة أو تعديلات؟ فقط ردّ على هذه الرسالة.' : 'Questions or changes? Just reply to this email.',
                preheader: $ar ? 'صالح حتى ' . $valid . '. اقبله عبر الإنترنت بخطوة واحدة.' : 'Valid until ' . $valid . '. Accept it online in one step.',
                rows: [[$ar ? 'المجموع' : 'Total', $total], [$ar ? 'صالح حتى' : 'Valid until', $valid]],
                notice: $this->notice($tenantId, $ar),
                language: $ar ? 'ar' : 'en',
                rowStyles: [0 => 'strong'],
            )->withAccountLink($quoteId !== null ? $this->accountLinks?->forEmail($tenantId, $to, 'bills/quotes/' . $quoteId, $ar) : null), $pdf);
    }

    /**
     * An issued invoice to its customer (accounting A2 calls this).
     *
     * @param array{number: string, customer: string, total_minor: int, currency: string, due_date: string} $invoice
     */
    public function invoice(int $tenantId, string $to, array $invoice, string $viewLink, ?string $payLink = null, ?MailAttachment $pdf = null): bool
    {
        if (!$this->enabled($tenantId, self::INVOICE)) {
            return false;
        }
        $ar = $this->arabic($tenantId);
        $workspace = $this->workspace($tenantId);
        $total = self::money($invoice['total_minor'], $invoice['currency'], $ar);
        $due = self::date($invoice['due_date'], $ar);

        return $this->deliver($tenantId, $to, $ar, $ar
            ? 'فاتورة ' . $invoice['number'] . ' من ' . $workspace . ': ' . $total
            : 'Invoice ' . $invoice['number'] . ' from ' . $workspace . ': ' . $total, new EmailContent(
                heading: $ar ? 'الفاتورة ' . $invoice['number'] : 'Invoice ' . $invoice['number'],
                paragraphs: [$ar ? 'مرحباً ' . $invoice['customer'] . '، هذه فاتورتك. شكراً لطلبك.' : 'Hello ' . $invoice['customer'] . ', here is your invoice. Thank you for your order.'],
                ctaLabel: $payLink !== null ? ($ar ? 'ادفع الآن' : 'Pay now') : ($ar ? 'اعرض الفاتورة' : 'View the invoice'),
                ctaUrl: $payLink ?? $viewLink,
                footnote: $pdf !== null
                    ? ($ar ? 'الفاتورة مرفقة كملف PDF مع رمز QR للتحقق.' : 'The invoice is attached as a PDF, with its QR code for checking.')
                    : null,
                preheader: $ar ? 'تستحق في ' . $due . '. ادفع عبر الإنترنت أو اعرض الفاتورة.' : 'Due ' . $due . '. Pay online or view the invoice.',
                rows: [[$ar ? 'المبلغ' : 'Amount', $total], [$ar ? 'تاريخ الاستحقاق' : 'Due', $due], [$ar ? 'الفاتورة' : 'Invoice', $invoice['number']]],
                notice: $this->notice($tenantId, $ar),
                language: $ar ? 'ar' : 'en',
                rowStyles: [0 => 'strong'],
            ), $pdf);
    }

    /**
     * Thanks for a payment, with its receipt (accounting A2 calls this).
     *
     * @param array{amount_minor: int, currency: string, for: string, paid_on: string, method: string} $payment
     */
    public function receipt(int $tenantId, string $to, array $payment, ?string $viewLink = null, ?MailAttachment $pdf = null): bool
    {
        if (!$this->enabled($tenantId, self::RECEIPT)) {
            return false;
        }
        $ar = $this->arabic($tenantId);
        $amount = self::money($payment['amount_minor'], $payment['currency'], $ar);
        $rows = [[$ar ? 'المدفوع' : 'Paid', $amount]];
        if ($payment['for'] !== '') {
            $rows[] = [$ar ? 'عن' : 'For', $payment['for']];
        }
        $rows[] = [$ar ? 'بتاريخ' : 'On', self::date($payment['paid_on'], $ar)];
        if ($payment['method'] !== '') {
            $rows[] = [$ar ? 'بواسطة' : 'By', self::method($payment['method'], $ar)];
        }

        return $this->deliver($tenantId, $to, $ar, $ar ? 'شكراً: استلمنا ' . $amount : 'Thank you: we received ' . $amount, new EmailContent(
            heading: $ar ? 'شكراً لك. تم استلام الدفعة.' : 'Thank you. Payment received.',
            paragraphs: [],
            ctaLabel: $viewLink !== null ? ($ar ? 'اعرض الإيصال' : 'View your receipt') : null,
            ctaUrl: $viewLink,
            footnote: $pdf !== null ? ($ar ? 'إيصالك مرفق كملف PDF.' : 'Your receipt is attached as a PDF.') : null,
            preheader: $payment['for'] !== '' ? ($ar ? 'عن ' . $payment['for'] . '.' : 'For ' . $payment['for'] . '.') : null,
            rows: $rows,
            notice: $this->notice($tenantId, $ar),
            language: $ar ? 'ar' : 'en',
            rowStyles: [0 => 'strong'],
        ), $pdf);
    }

    /**
     * A kind reminder about an open invoice. It assumes the customer may have
     * paid (EM.1). Sent by a person, or a reminder an assistant proposed and a
     * person accepted (AC.11).
     *
     * @param array{number: string, customer: string, total_minor: int, currency: string, due_date: string} $invoice
     */
    public function reminder(int $tenantId, string $to, array $invoice, string $viewLink, ?string $payLink = null): bool
    {
        if (!$this->enabled($tenantId, self::REMINDER)) {
            return false;
        }
        $ar = $this->arabic($tenantId);
        $total = self::money($invoice['total_minor'], $invoice['currency'], $ar);
        $due = self::date($invoice['due_date'], $ar);

        return $this->deliver($tenantId, $to, $ar, $ar ? 'تذكير بالفاتورة ' . $invoice['number'] : 'A reminder about invoice ' . $invoice['number'], new EmailContent(
            heading: $ar ? 'تذكير لطيف' : 'A friendly reminder',
            paragraphs: [$ar
                ? 'مرحباً ' . $invoice['customer'] . '، تُظهر سجلاتنا أن الفاتورة ' . $invoice['number'] . ' ما زالت مفتوحة. إن كنت قد دفعت، فشكراً لك، وتجاهل هذه الرسالة.'
                : 'Hello ' . $invoice['customer'] . ', our records show invoice ' . $invoice['number'] . " is still open. If you've already paid, thank you, and please ignore this."],
            ctaLabel: $payLink !== null ? ($ar ? 'ادفع الآن' : 'Pay now') : ($ar ? 'اعرض الفاتورة' : 'View the invoice'),
            ctaUrl: $payLink ?? $viewLink,
            footnote: $ar ? 'تحتاج وقتاً إضافياً؟ ردّ وأخبرنا؛ يمكننا الاتفاق على موعد.' : 'Need more time? Reply and tell us; we can agree a date.',
            preheader: $ar ? 'كان مبلغ ' . $total . ' مستحقاً في ' . $due . '.' : $total . ' was due on ' . $due . '.',
            rows: [[$ar ? 'المبلغ' : 'Amount', $total], [$ar ? 'كان مستحقاً في' : 'Was due', $due], [$ar ? 'الفاتورة' : 'Invoice', $invoice['number']]],
            notice: $this->notice($tenantId, $ar),
            language: $ar ? 'ar' : 'en',
            rowStyles: [0 => 'strong'],
        ));
    }

    private function deliver(int $tenantId, string $to, bool $ar, string $subject, EmailContent $content, ?MailAttachment $pdf = null): bool
    {
        $to = trim($to);
        if ($to === '' || !\Whity\Http\Validation\Field::isEmail($to)) {
            return false;
        }
        try {
            ['replyTo' => $replyTo] = $this->sender->workspaceContact($tenantId, $ar ? 'ar' : 'en');

            return $this->sender->send($tenantId, $to, $subject, $content, new EmailOptions(
                attachments: $pdf === null ? [] : [$pdf],
                replyTo: $replyTo,
            ));
        } catch (\Throwable $e) {
            error_log('[TradingEmails] could not send: ' . $e->getMessage());

            return false;
        }
    }

    private function arabic(int $tenantId): bool
    {
        return $this->sender->language($tenantId) === 'ar';
    }

    private function workspace(int $tenantId): string
    {
        return $this->sender->branding($tenantId)->siteName;
    }

    private function notice(int $tenantId, bool $ar): string
    {
        ['address' => $address, 'replyTo' => $replyTo] = $this->sender->workspaceContact($tenantId, $ar ? 'ar' : 'en');

        return implode(' · ', array_filter([
            $this->workspace($tenantId),
            $address,
            $replyTo !== null ? ($ar ? 'ردّ على هذه الرسالة للتواصل معهم.' : 'Reply to this email to reach them.') : null,
        ]));
    }

    /** "$312.00" or "JOD 312.000", Western digits; in Arabic isolated so it stays left to right. */
    private static function money(int $minor, string $currency, bool $ar): string
    {
        $amount = Currency::display($minor, $currency);

        return $ar ? "\u{2066}" . $amount . "\u{2069}" : $amount;
    }

    private static function date(string $ymd, bool $ar): string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($ymd, 0, 10));
        if ($d === false) {
            return $ymd;
        }
        if (!$ar) {
            return $d->format('j F Y');
        }
        $months = ['كانون الثاني', 'شباط', 'آذار', 'نيسان', 'أيار', 'حزيران', 'تموز', 'آب', 'أيلول', 'تشرين الأول', 'تشرين الثاني', 'كانون الأول'];

        return $d->format('j') . ' ' . $months[(int) $d->format('n') - 1] . ' ' . $d->format('Y');
    }

    private static function method(string $method, bool $ar): string
    {
        $words = [
            'cash' => ['Cash', 'نقداً'], 'card' => ['Card', 'بطاقة'], 'transfer' => ['Bank transfer', 'تحويل بنكي'],
            'cliq' => ['CliQ', 'CliQ'], 'cheque' => ['Cheque', 'شيك'], 'whity_pay' => ['Online', 'عبر الإنترنت'],
        ];

        return isset($words[$method]) ? $words[$method][$ar ? 1 : 0] : $method;
    }
}
