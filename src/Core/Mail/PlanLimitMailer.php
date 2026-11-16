<?php

declare(strict_types=1);

namespace Whity\Core\Mail;

use Whity\Core\Entitlement\EntitlementRegistry;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;

/**
 * EM.1 `plan-limit`: tells the people who can choose a plan that the workspace
 * is close to a metered limit, or has passed it on an instance that warns
 * (#1799). Listens on {@see \Whity\Core\Entitlement\MeterService::onThreshold()},
 * which calls once per window per stage, so this sends at most twice a month per
 * limit.
 *
 * From Whity itself (the instance's brand), not the workspace: it is about the
 * workspace's plan. Plain words, no blame: it says what happens next and when the
 * count starts again. Can't be turned off.
 */
final class PlanLimitMailer
{
    /** What each limit counts, in the words the email uses. */
    private const THINGS = [
        EntitlementRegistry::CUSTOMERS_PER_MONTH => ['customers', 'عميل'],
        EntitlementRegistry::DOCUMENTS_RENDER_PER_MONTH => ['documents made', 'مستند'],
        EntitlementRegistry::AGENTS_RUNS_PER_MONTH => ['agent runs', 'تشغيل للوكلاء'],
        EntitlementRegistry::MESSAGES_OUTBOUND_PER_MONTH => ['messages sent', 'رسالة'],
    ];

    public function __construct(
        private readonly BrandedEmailSender $sender,
        private readonly WorkspaceRecipients $recipients,
        private readonly SettingsService $settings,
        /** The workspace's Billing page in the app. */
        private readonly string $billingUrl,
        /** @var \Closure(int): string Resolves a workspace's name. */
        private readonly \Closure $workspaceName,
    ) {
    }

    public function crossed(int $tenantId, string $key, int $used, int $limit, string $stage, string $resetsAt): int
    {
        $lang = $this->sender->language($tenantId);
        $ar = $lang === 'ar';
        $thing = self::THINGS[$key][$ar ? 1 : 0] ?? ($ar ? 'وحدة' : 'units');
        $period = str_ends_with($key, '.per_day')
            ? ($ar ? 'اليوم' : 'today')
            : ($ar ? 'هذا الشهر' : 'this month');
        $workspace = ($this->workspaceName)($tenantId);
        $resets = self::date($resetsAt);
        $warns = $this->warns($tenantId, $key);

        $count = $used . ($ar ? ' من ' : ' of ') . $limit . ' ' . $thing . ' ' . $period;
        if ($stage === 'passed') {
            $heading = $ar ? 'تجاوزت حد باقتك' : 'You passed your plan’s limit';
            $body = $ar
                ? 'لم يتوقف أو يُقفل أي شيء. اختر باقة تناسب حجم عملك الآن.'
                : 'Nothing has stopped or been locked. Choose a plan that fits how much you use now.';
            $subject = $ar ? $workspace . ' تجاوزت حد باقتها' : $workspace . ' passed its plan’s limit';
        } else {
            $heading = $ar ? 'أنت قريب من حد باقتك' : 'You are close to your plan’s limit';
            $body = $warns
                ? ($ar
                    ? 'خبر جيد: العمل ينمو. لن يتوقف أو يُقفل أي شيء. إن تجاوزت ' . $limit . '، سنطلب منك اختيار باقة.'
                    : 'Good news: business is growing. Nothing will stop or be locked. If you pass ' . $limit . ', we’ll ask you to choose a plan.')
                : ($ar
                    ? 'خبر جيد: العمل ينمو. عند ' . $limit . ' ينتظر الجديد حتى يبدأ العدّ من جديد، أو حتى تختار باقة أكبر.'
                    : 'Good news: business is growing. At ' . $limit . ', new ones wait until the count starts again, or until you choose a bigger plan.');
            $subject = $ar ? $workspace . ' قريبة من حد باقتها' : $workspace . ' is close to its plan’s limit';
        }

        $content = new EmailContent(
            heading: $heading,
            paragraphs: [$count, $body],
            ctaLabel: $ar ? 'اطّلع على الباقات' : 'See the plans',
            ctaUrl: $this->billingUrl,
            footnote: $ar ? 'يبدأ العدّ من جديد في ' . $resets . '.' : 'The count starts again on ' . $resets . '.',
            preheader: $stage === 'passed' || $warns
                ? ($ar ? 'لن يتوقف شيء. وهذا ما سيحدث.' : 'Nothing stops. Here is what happens next.')
                : ($ar ? 'وهذا ما سيحدث عند الحد.' : 'Here is what happens at the limit.'),
            notice: $ar ? 'أُرسلت إلى مالكي ' . $workspace . '.' : 'Sent to the owners of ' . $workspace . '.',
            language: $lang,
        );

        $sent = 0;
        foreach ($this->recipients->holding($tenantId, CorePermissions::BILLING_PAY) as $email) {
            // Whity's own email: the instance's brand (tenant 0), the workspace's language.
            if ($this->sender->send(0, $email, $subject, $content)) {
                $sent++;
            }
        }

        return $sent;
    }

    private function warns(int $tenantId, string $key): bool
    {
        if (in_array($key, EntitlementRegistry::WARN_ONLY, true)) {
            return true;
        }
        try {
            return ($this->settings->effective($tenantId)[SettingsRegistry::METERS_ENFORCEMENT] ?? 'block') === 'warn';
        } catch (\Throwable) {
            return false;
        }
    }

    private static function date(string $iso): string
    {
        try {
            return (new \DateTimeImmutable($iso))->format('j M Y');
        } catch (\Throwable) {
            return $iso;
        }
    }
}
