<?php

declare(strict_types=1);

namespace Whity\Core\Billing\Cash;

use Whity\Core\Mail\EmailContent;
use Whity\Sdk\JobInterface;

/**
 * Reminds a workspace's payers before its cash cover for a plugin ends
 * (WHIT-1153): pay cash again, or switch to paying online. Once per payment,
 * 7 days before its end, and only for the last payment for that plugin.
 * Queued once an hour by `schedule:run`.
 */
final class CashRemindersJob implements JobInterface
{
    public const NAME = 'core.billing.cash_reminders';

    public const DAYS_BEFORE = 7;

    /**
     * @param \Closure(int, string, EmailContent): int $send       (workspace, subject, content) => how many were sent
     * @param \Closure(int): string                    $language   the workspace's language
     * @param \Closure(string): string                 $planName   a plan key's name, e.g. "Whity for Salons"
     */
    public function __construct(
        private readonly CashPayments $payments,
        private readonly \Closure $send,
        private readonly \Closure $language,
        private readonly \Closure $planName,
        private readonly string $billingUrl,
    ) {
    }

    public function handle(array $payload): array
    {
        $sent = 0;
        foreach ($this->payments->dueReminders(self::DAYS_BEFORE) as $payment) {
            $tenantId = (int) $payment['tenant_id'];
            $ar = ($this->language)($tenantId) === 'ar';
            $name = ($this->planName)((string) $payment['plan_key']);
            $date = substr((string) $payment['period_end'], 0, 10);

            $content = $ar
                ? new EmailContent(
                    heading: "اشتراكك في {$name} ينتهي في {$date}",
                    paragraphs: [
                        "دفعتَ نقداً مقابل {$name} (إيصال {$payment['receipt_number']}). ينتهي ما يغطيه في {$date}.",
                        'لتستمر دون انقطاع، ادفع نقداً مرة أخرى، أو انتقل إلى الدفع الإلكتروني من صفحة الفوترة.',
                        'إن لم تجدّد، تبقى الإضافة تعمل 7 أيام ثم تصبح للقراءة فقط. لا يُحذف شيء.',
                    ],
                    ctaLabel: 'فتح الفوترة',
                    ctaUrl: $this->billingUrl,
                    language: 'ar',
                )
                : new EmailContent(
                    heading: "Your {$name} ends on {$date}",
                    paragraphs: [
                        "You paid for {$name} in cash (receipt {$payment['receipt_number']}). What it covers ends on {$date}.",
                        'To carry on without a break, pay cash again, or switch to paying online from the Billing page.',
                        'If you don\'t renew, the plugin keeps working for 7 days, then becomes read-only. Nothing is deleted.',
                    ],
                    ctaLabel: 'Open Billing',
                    ctaUrl: $this->billingUrl,
                    language: 'en',
                );

            $sent += ($this->send)($tenantId, $content->heading, $content);
            $this->payments->markReminded($tenantId, (int) $payment['id']);
        }

        return ['reminded' => $sent];
    }
}
