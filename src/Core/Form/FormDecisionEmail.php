<?php

declare(strict_types=1);

namespace Whity\Core\Form;

use PDO;
use Whity\Core\Calendar\EmailBookingMessenger;
use Whity\Core\Document\DocumentArtifactRepository;
use Whity\Core\Document\DocumentArtifactStore;
use Whity\Core\Document\Routing\RouteFinalisationRepository;
use Whity\Core\Document\Routing\RouteStepValueRepository;
use Whity\Core\Document\Routing\RouteVerdict;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Identity\ProfileEmailRepository;
use Whity\Core\Mail\BrandedEmailSender;
use Whity\Core\Mail\EmailContent;
use Whity\Core\Mail\EmailOptions;
use Whity\Core\Mail\MailAttachment;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Sdk\Hooks\Events;

/**
 * The decision on a form (design 3.4 `EmailForms`, `handoff/emails/form-decision`):
 * when a signed-in person's form submission finishes its route, the workspace
 * tells them what was decided, with the reference, any conditions, and, for an
 * approval with a final document, the printout PDF (P1) attached.
 *
 * Once per route:
 * - a route that makes a final document sends on `document.finalised`, with
 *   the PDF attached (when it fits in an email, 1 MiB; otherwise the button
 *   is how they get it);
 * - every other finished route (not approved, or approved with no final
 *   document) sends on `route.completed`, with no file.
 *
 * Public submissions have no submitter and get nothing (see
 * {@see FormReceivedEmail}). Like `form-received`, the person can turn it off;
 * the workspace's email switch applies. Best-effort: never fails the route.
 */
final class FormDecisionEmail
{
    public const TYPE = 'form.decision';
    private const MAX_ATTACHMENT = 1_048_576;

    public function __construct(
        private readonly PDO $db,
        private readonly ProfileEmailRepository $emails,
        private readonly BrandedEmailSender $sender,
        private readonly SettingsService $settings,
        /** The app's base URL ("https://app.example.com"). */
        private readonly string $appUrl,
        private readonly ?DocumentArtifactStore $artifacts = null,
    ) {
    }

    public function subscribe(HookManager $hooks): void
    {
        $hooks->listen(Events::ROUTE_COMPLETED, function (array $data, array $context = []): array {
            $this->guard(fn () => $this->onCompleted($data));

            return $data;
        });
        $hooks->listen(Events::DOCUMENT_FINALISED, function (array $data, array $context = []): array {
            $this->guard(fn () => $this->onFinalised($data));

            return $data;
        });
    }

    /** @param array<string, mixed> $payload `route.completed` */
    public function onCompleted(array $payload): bool
    {
        if (($payload['subject_type'] ?? null) !== FormSubmissionRouteSubject::TYPE) {
            return false;
        }
        $tenantId = (int) ($payload['tenant_id'] ?? 0);
        $routeId = (int) ($payload['route_id'] ?? 0);
        $approved = ($payload['outcome'] ?? null) === RouteVerdict::APPROVED;
        if ($approved && (new RouteFinalisationRepository($this->db))->findForRoute($tenantId, $routeId) !== null) {
            return false; // The final document's own event sends it, with the PDF.
        }

        return $this->send($tenantId, (int) ($payload['subject_id'] ?? 0), $routeId, $approved, null);
    }

    /** @param array<string, mixed> $payload `document.finalised` */
    public function onFinalised(array $payload): bool
    {
        if (($payload['subject_type'] ?? null) !== FormSubmissionRouteSubject::TYPE) {
            return false;
        }
        $tenantId = (int) ($payload['tenant_id'] ?? 0);
        $pdf = $this->pdf($tenantId, (int) ($payload['document_id'] ?? 0), (int) ($payload['artifact_id'] ?? 0));

        return $this->send($tenantId, (int) ($payload['subject_id'] ?? 0), (int) ($payload['route_id'] ?? 0), true, $pdf);
    }

    private function send(int $tenantId, int $submissionId, int $routeId, bool $approved, ?string $pdf): bool
    {
        if ($tenantId <= 0 || $submissionId <= 0) {
            return false;
        }
        $stmt = $this->db->prepare(
            'SELECT s.submitted_by_profile_id, s.reference, f.name AS form_name, p.display_name
               FROM form_submissions s
               JOIN forms f ON f.id = s.form_id AND f.tenant_id = s.tenant_id
               LEFT JOIN profiles p ON p.id = s.submitted_by_profile_id
              WHERE s.tenant_id = :tid AND s.id = :id'
        );
        $stmt->execute([':tid' => $tenantId, ':id' => $submissionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $profileId = is_array($row) && $row['submitted_by_profile_id'] !== null ? (int) $row['submitted_by_profile_id'] : null;
        if (!is_array($row) || $profileId === null || !$this->sender->gate()->allows($tenantId, $profileId, self::TYPE)) {
            return false;
        }
        $email = trim((string) ($this->emails->findPrimaryForProfile($profileId)['email'] ?? ''));
        if ($email === '') {
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
        $decision = $approved ? ($ar ? 'تمت الموافقة' : 'Approved') : ($ar ? 'لم تتم الموافقة' : 'Not approved');
        $conditions = $approved ? $this->conditions($tenantId, $routeId) : null;
        $when = $this->now($tenantId, $ar);
        ['address' => $address, 'replyTo' => $replyTo] = $this->sender->workspaceContact($tenantId, $lang);

        $rows = [[$ar ? 'القرار' : 'Decision', $decision]];
        if ($reference !== '') {
            $rows[] = [$ar ? 'المرجع' : 'Reference', $reference];
        }
        if ($approved) {
            $rows[] = [$ar ? 'الشروط' : 'Conditions', $conditions ?? ($ar ? 'لا يوجد' : 'None')];
        }
        $attachments = $pdf !== null
            ? [new MailAttachment('approval.pdf', 'application/pdf', $pdf)]
            : [];
        $mine = rtrim($this->appUrl, '/') . '/admin/x/my-form-submissions';

        return $this->sender->send(
            $tenantId,
            $email,
            trim($decision . ': ' . $form . ($reference !== '' ? ' ' . $reference : '')),
            new EmailContent(
                heading: $decision,
                paragraphs: [$ar
                    ? ($name !== '' ? 'مرحباً ' . $name . '، ' : '') . 'أصدرت ' . $workspace . ' قرارها بشأن ' . $form . ' في ' . $when . '.'
                    : ($name !== '' ? 'Hello ' . $name . ', ' : '') . $workspace . ' decided on your ' . $form . ' on ' . $when . '.'],
                ctaLabel: $approved && $pdf !== null ? ($ar ? 'نزّل الموافقة' : 'Download the approval') : ($ar ? 'اعرض ما أرسلته' : 'See what you sent'),
                ctaUrl: $mine,
                footnote: $attachments !== []
                    ? ($ar ? 'الملف مرفق أيضاً. يمكن لأي شخص التحقق من أصالته بمسح رمز QR.' : 'The PDF is attached too. Anyone can check it is genuine by scanning its QR code.')
                    : null,
                preheader: $attachments !== [] ? ($ar ? 'الموافقة مرفقة كملف PDF مع رمز QR.' : 'The approval is attached as a PDF with its QR code.') : null,
                rows: $rows,
                notice: implode(' · ', array_filter([$workspace, $address])),
                language: $lang,
                rowStyles: [0 => 'strong'],
            ),
            new EmailOptions(attachments: $attachments, replyTo: $replyTo),
        );
    }

    /** The final document's PDF when it fits in an email, else null. */
    private function pdf(int $tenantId, int $documentId, int $artifactId): ?string
    {
        if ($this->artifacts === null || $documentId <= 0 || $artifactId <= 0) {
            return null;
        }
        $artifact = (new DocumentArtifactRepository($this->db))->findById($artifactId, $documentId, $tenantId);
        if ($artifact === null || (int) ($artifact['byte_size'] ?? 0) > self::MAX_ATTACHMENT) {
            return null;
        }
        try {
            $bytes = $this->artifacts->get((string) $artifact['storage_key']);
        } catch (\Throwable) {
            return null;
        }

        return strlen($bytes) <= self::MAX_ATTACHMENT ? $bytes : null;
    }

    private function conditions(int $tenantId, int $routeId): ?string
    {
        if ($routeId <= 0) {
            return null;
        }
        $values = (new RouteStepValueRepository($this->db))->valuesForRoute($tenantId, $routeId);
        foreach (['conditions', 'approval_conditions'] as $key) {
            if (isset($values[$key]) && trim($values[$key]) !== '') {
                return trim($values[$key]);
            }
        }

        return null;
    }

    private function now(int $tenantId, bool $ar): string
    {
        try {
            $zone = new \DateTimeZone((string) ($this->settings->effective($tenantId)[SettingsRegistry::TIMEZONE] ?? 'UTC'));
        } catch (\Throwable) {
            $zone = new \DateTimeZone('UTC');
        }

        return EmailBookingMessenger::when(new \DateTimeImmutable('now', $zone), $ar);
    }

    /** @param \Closure(): mixed $work */
    private function guard(\Closure $work): void
    {
        try {
            $work();
        } catch (\Throwable $e) {
            error_log('[forms] form-decision email failed: ' . $e->getMessage());
        }
    }
}
