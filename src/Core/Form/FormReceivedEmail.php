<?php

declare(strict_types=1);

namespace Whity\Core\Form;

use PDO;
use Whity\Core\Calendar\EmailBookingMessenger;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Identity\ProfileEmailRepository;
use Whity\Core\Mail\BrandedEmailSender;
use Whity\Core\Mail\EmailContent;
use Whity\Core\Mail\EmailOptions;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Sdk\Hooks\Events;

/**
 * "We received it" (design 3.4 `EmailForms`, `handoff/emails/form-received`):
 * after a signed-in person sends a form, the workspace confirms it by email
 * with the reference, when it was sent, what happens next, and a button to
 * their submissions.
 *
 * Only to a signed-in submitter's own address. A public form's submission has
 * no submitter (the address in its answers is unverified), and emailing it
 * would let anyone make a workspace send mail to any address.
 *
 * The person can turn it off (Preferences › Notifications, `form.received` on
 * email, through {@see \Whity\Core\Mail\EmailGate}); the workspace's email
 * switch stops it too. Best-effort: never fails the submission.
 */
final class FormReceivedEmail
{
    public const TYPE = 'form.received';

    public function __construct(
        private readonly PDO $db,
        private readonly ProfileEmailRepository $emails,
        private readonly BrandedEmailSender $sender,
        private readonly SettingsService $settings,
        /** The app's base URL ("https://app.example.com"). */
        private readonly string $appUrl,
    ) {
    }

    public function subscribe(HookManager $hooks): void
    {
        $hooks->listen(Events::FORM_SUBMITTED, function (array $data, array $context = []): array {
            try {
                $this->send((int) ($data['tenant_id'] ?? 0), (int) ($data['id'] ?? 0), isset($data['actor_profile_id']) ? (int) $data['actor_profile_id'] : null, ($data['route_id'] ?? null) !== null);
            } catch (\Throwable $e) {
                error_log('[forms] form-received email failed: ' . $e->getMessage());
            }

            return $data;
        });
    }

    /** True when an email went out. */
    public function send(int $tenantId, int $submissionId, ?int $profileId, bool $routed): bool
    {
        if ($tenantId <= 0 || $submissionId <= 0 || $profileId === null || $profileId <= 0) {
            return false;
        }
        if (!$this->sender->gate()->allows($tenantId, $profileId, self::TYPE)) {
            return false;
        }
        $stmt = $this->db->prepare(
            'SELECT s.reference, s.submitted_at, f.name AS form_name, p.display_name
               FROM form_submissions s
               JOIN forms f ON f.id = s.form_id AND f.tenant_id = s.tenant_id
               LEFT JOIN profiles p ON p.id = s.submitted_by_profile_id
              WHERE s.tenant_id = :tid AND s.id = :id AND s.submitted_by_profile_id = :pid'
        );
        $stmt->execute([':tid' => $tenantId, ':id' => $submissionId, ':pid' => $profileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $email = trim((string) ($this->emails->findPrimaryForProfile($profileId)['email'] ?? ''));
        if (!is_array($row) || $email === '') {
            return false;
        }

        $lang = $this->sender->language($tenantId);
        $ar = $lang === 'ar';
        $workspace = $this->sender->branding($tenantId)->siteName;
        $label = LocalizedLabel::decode((string) $row['form_name']);
        $form = ($label[$lang] ?? '') !== '' ? $label[$lang] : LocalizedLabel::preferred($label);
        $form = $form !== '' ? $form : ($ar ? 'طلبك' : 'form');
        $name = trim((string) ($row['display_name'] ?? ''));
        $reference = trim((string) ($row['reference'] ?? ''));
        $when = $this->when((string) ($row['submitted_at'] ?? ''), $tenantId, $ar);
        ['address' => $address, 'replyTo' => $replyTo] = $this->sender->workspaceContact($tenantId, $lang);

        $rows = [];
        if ($reference !== '') {
            $rows[] = [$ar ? 'المرجع' : 'Reference', $reference];
        }
        if ($when !== null) {
            $rows[] = [$ar ? 'أُرسل' : 'Sent', $when];
        }
        if ($routed) {
            $rows[] = [$ar ? 'الخطوة التالية' : 'Next', $ar ? 'المراجعة' : 'It goes for review'];
        }
        $hello = $ar
            ? ($name !== '' ? 'مرحباً ' . $name . '، شكراً لك. ' : 'شكراً لك. ') . $form . ' لدى ' . $workspace . ' الآن.'
            : ($name !== '' ? 'Hello ' . $name . ', thank you. ' : 'Thank you. ') . 'Your ' . $form . ' is with ' . $workspace . '.';

        return $this->sender->send(
            $tenantId,
            $email,
            $ar ? 'وصلنا ' . $form : 'We received your ' . $form,
            new EmailContent(
                heading: $ar ? 'وصلنا طلبك' : 'We received it',
                paragraphs: [$hello],
                ctaLabel: $ar ? 'اعرض ما أرسلته' : 'See what you sent',
                ctaUrl: rtrim($this->appUrl, '/') . '/admin/x/my-form-submissions',
                footnote: $reference !== '' ? ($ar ? 'احتفظ بالمرجع: يمكنك ذكره في أي رسالة بهذا الشأن.' : 'Keep the reference: you can quote it in any message about this.') : null,
                preheader: $reference !== '' ? ($ar ? 'المرجع ' . $reference . '.' : 'Reference ' . $reference . '.') : null,
                rows: $rows,
                notice: implode(' · ', array_filter([$workspace, $address, $replyTo !== null ? ($ar ? 'ردّ على هذه الرسالة للتواصل معهم.' : 'Reply to this email to reach them.') : null])),
                language: $lang,
                rowStyles: $reference !== '' ? [0 => 'strong'] : [],
            ),
            new EmailOptions(replyTo: $replyTo),
        );
    }

    private function when(string $at, int $tenantId, bool $ar): ?string
    {
        if ($at === '') {
            return null;
        }
        try {
            $zone = new \DateTimeZone((string) ($this->settings->effective($tenantId)[SettingsRegistry::TIMEZONE] ?? 'UTC'));
            $local = (new \DateTimeImmutable(substr($at, 0, 19), new \DateTimeZone('UTC')))->setTimezone($zone);
        } catch (\Throwable) {
            return null;
        }

        return EmailBookingMessenger::when($local, $ar);
    }
}
