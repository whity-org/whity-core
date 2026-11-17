<?php

declare(strict_types=1);

namespace Whity\Core\Tenant;

use Whity\Core\Identity\ProfileEmailRepository;
use Whity\Core\Mail\BrandedEmailSender;
use Whity\Core\Mail\EmailContent;
use Whity\Core\Notification\NotificationDispatcher;

/**
 * Tells both people about an ownership offer and what became of it
 * (WHIT-1170, WHIT-1171): an in-app notification and an email, in the
 * workspace's language (English or Arabic). Fail-soft: a notice that can't be
 * sent never undoes the change it describes.
 *
 * The email only opens the app (EM.1: no actions inside approval emails);
 * accepting always happens there, after "Confirm it's you".
 */
final class OwnershipNotifier
{
    public const TYPE_OFFERED = 'workspace.ownership.offered';
    public const TYPE_DECIDED = 'workspace.ownership.decided';

    private const WORDS = [
        'en' => [
            'offer.transfer.subject' => '%s wants to hand %s over to you',
            'offer.transfer.body' => '%s offered you ownership of %s. If you accept, you become its owner: you look after its plan and payments and decide who else owns it. %s stays on as an admin unless they chose to leave.',
            'offer.co_owner.subject' => '%s invites you to co-own %s',
            'offer.co_owner.body' => '%s offered to make you a co-owner of %s. Co-owners have the same say as every other owner: the plan, payments and who owns the workspace.',
            'offer.until' => 'The offer is open until %s. Open Whity to accept or decline; you confirm it is you first.',
            'offer.cta' => 'Open the offer',
            'accepted.to.subject' => 'You now own %s',
            'accepted.to.co.subject' => 'You are now a co-owner of %s',
            'accepted.to.body' => 'You accepted, and you are now an owner of %s.',
            'accepted.from.subject' => '%s accepted your offer for %s',
            'accepted.from.transfer' => '%s now owns %s. You stay on as an admin.',
            'accepted.from.left' => '%s now owns %s, and you have left it as you chose.',
            'accepted.from.co' => '%s is now a co-owner of %s.',
            'declined.subject' => '%s declined your offer for %s',
            'declined.body' => 'Nothing changed in %s. You can offer it to someone else from Settings › Owners.',
            'cancelled.subject' => 'The offer for %s was withdrawn',
            'cancelled.body' => '%s withdrew the ownership offer for %s. Nothing changed.',
            'removed.subject' => 'You are no longer an owner of %s',
            'removed.body' => '%s removed you as an owner of %s. You keep your other roles there.',
            'cta.app' => 'Open Whity',
        ],
        'ar' => [
            'offer.transfer.subject' => 'يريد %s أن يسلّمك ملكية %s',
            'offer.transfer.body' => 'عرض عليك %s ملكية %s. إن قبلت تصبح مالكها: تتولى خطتها ومدفوعاتها وتقرر من يشاركك ملكيتها. ويبقى %s مسؤولًا فيها ما لم يختر المغادرة.',
            'offer.co_owner.subject' => 'يدعوك %s لتشارك في ملكية %s',
            'offer.co_owner.body' => 'عرض عليك %s أن تصبح شريكًا في ملكية %s. للشركاء في الملكية الكلمة نفسها التي لكل مالك: الخطة والمدفوعات ومن يملك مساحة العمل.',
            'offer.until' => 'العرض متاح حتى %s. افتح Whity لتقبله أو ترفضه، وستؤكد هويتك أولًا.',
            'offer.cta' => 'افتح العرض',
            'accepted.to.subject' => 'أصبحت مالك %s',
            'accepted.to.co.subject' => 'أصبحت شريكًا في ملكية %s',
            'accepted.to.body' => 'قبلت العرض، وأصبحت الآن من مالكي %s.',
            'accepted.from.subject' => 'قبل %s عرضك بشأن %s',
            'accepted.from.transfer' => 'أصبح %s مالك %s. وتبقى أنت مسؤولًا فيها.',
            'accepted.from.left' => 'أصبح %s مالك %s، وغادرتها كما اخترت.',
            'accepted.from.co' => 'أصبح %s شريكًا في ملكية %s.',
            'declined.subject' => 'رفض %s عرضك بشأن %s',
            'declined.body' => 'لم يتغير شيء في %s. يمكنك عرضها على شخص آخر من الإعدادات › المالكون.',
            'cancelled.subject' => 'سُحب العرض بشأن %s',
            'cancelled.body' => 'سحب %s عرض الملكية بشأن %s. لم يتغير شيء.',
            'removed.subject' => 'لم تعد من مالكي %s',
            'removed.body' => 'أزالك %s من مالكي %s. وتبقى أدوارك الأخرى فيها كما هي.',
            'cta.app' => 'افتح Whity',
        ],
    ];

    public function __construct(
        private readonly ?NotificationDispatcher $dispatcher,
        private readonly ?BrandedEmailSender $mail,
        private readonly ?ProfileEmailRepository $emails,
        /** The web app's base URL, for the buttons. */
        private readonly string $appUrl,
    ) {
    }

    /** @param array<string, mixed> $offer */
    public function offered(array $offer): void
    {
        $tenantId = (int) $offer['tenant_id'];
        $w = $this->words($tenantId);
        $from = (string) $offer['from_name'];
        $ws = (string) $offer['tenant_name'];
        $co = $offer['kind'] === WorkspaceOwnership::KIND_CO_OWNER;
        $subject = sprintf($w[$co ? 'offer.co_owner.subject' : 'offer.transfer.subject'], $from, $ws);
        $body = $co
            ? sprintf($w['offer.co_owner.body'], $from, $ws)
            : sprintf($w['offer.transfer.body'], $from, $ws, $from);
        $until = sprintf($w['offer.until'], substr((string) $offer['expires_at'], 0, 10));
        $url = $this->url('/ownership-offers/' . (int) $offer['id']);

        $this->tell($tenantId, (int) $offer['to_profile_id'], self::TYPE_OFFERED, $subject, [$body, $until], $w['offer.cta'], $url, $offer);
    }

    /** @param array<string, mixed> $offer */
    public function accepted(array $offer, bool $fromLeft): void
    {
        $tenantId = (int) $offer['tenant_id'];
        $w = $this->words($tenantId);
        $ws = (string) $offer['tenant_name'];
        $to = (string) $offer['to_name'];
        $co = $offer['kind'] === WorkspaceOwnership::KIND_CO_OWNER;

        $this->tell(
            $tenantId,
            (int) $offer['to_profile_id'],
            self::TYPE_DECIDED,
            sprintf($w[$co ? 'accepted.to.co.subject' : 'accepted.to.subject'], $ws),
            [sprintf($w['accepted.to.body'], $ws)],
            $w['cta.app'],
            $this->url('/dashboard'),
            $offer,
        );

        $fromBody = $co ? 'accepted.from.co' : ($fromLeft ? 'accepted.from.left' : 'accepted.from.transfer');
        $this->tell(
            $tenantId,
            (int) $offer['from_profile_id'],
            self::TYPE_DECIDED,
            sprintf($w['accepted.from.subject'], $to, $ws),
            [sprintf($w[$fromBody], $to, $ws)],
            $w['cta.app'],
            $this->url('/dashboard'),
            $offer,
            // After leaving, there is no workspace left to hold an in-app card.
            inApp: !$fromLeft,
        );
    }

    /** @param array<string, mixed> $offer */
    public function declined(array $offer): void
    {
        $tenantId = (int) $offer['tenant_id'];
        $w = $this->words($tenantId);
        $ws = (string) $offer['tenant_name'];
        $this->tell(
            $tenantId,
            (int) $offer['from_profile_id'],
            self::TYPE_DECIDED,
            sprintf($w['declined.subject'], (string) $offer['to_name'], $ws),
            [sprintf($w['declined.body'], $ws)],
            $w['cta.app'],
            $this->url('/admin/settings/owners'),
            $offer,
        );
    }

    /** @param array<string, mixed> $offer */
    public function cancelled(array $offer, string $byName): void
    {
        $tenantId = (int) $offer['tenant_id'];
        $w = $this->words($tenantId);
        $ws = (string) $offer['tenant_name'];
        $by = $byName;
        $this->tell(
            $tenantId,
            (int) $offer['to_profile_id'],
            self::TYPE_DECIDED,
            sprintf($w['cancelled.subject'], $ws),
            [sprintf($w['cancelled.body'], $by, $ws)],
            $w['cta.app'],
            $this->url('/dashboard'),
            $offer,
        );
    }

    /** An owner removed another owner (WHIT-1171). */
    public function removed(int $tenantId, string $workspaceName, int $removedId, string $byName): void
    {
        $w = $this->words($tenantId);
        $this->tell(
            $tenantId,
            $removedId,
            self::TYPE_DECIDED,
            sprintf($w['removed.subject'], $workspaceName),
            [sprintf($w['removed.body'], $byName, $workspaceName)],
            $w['cta.app'],
            $this->url('/dashboard'),
            ['tenant_id' => $tenantId],
        );
    }

    /**
     * @param list<string>          $paragraphs
     * @param array<string, mixed>  $offer
     */
    private function tell(
        int $tenantId,
        int $profileId,
        string $type,
        string $subject,
        array $paragraphs,
        string $cta,
        string $url,
        array $offer,
        bool $inApp = true,
    ): void {
        if ($inApp && $this->dispatcher !== null) {
            try {
                $this->dispatcher->dispatch($tenantId, $profileId, $type, [
                    'channels' => ['in_app'],
                    'subject'  => $subject,
                    'body'     => implode("\n", $paragraphs),
                    'data'     => [
                        'offer_id' => isset($offer['id']) ? (int) $offer['id'] : null,
                        'kind'     => $offer['kind'] ?? null,
                        'status'   => $offer['status'] ?? null,
                        'url'      => $url,
                    ],
                ]);
            } catch (\Throwable $e) {
                error_log('[ownership-notifier] in-app ' . $type . ' to profile ' . $profileId . ' failed: ' . $e->getMessage());
            }
        }
        if ($this->mail === null || $this->emails === null) {
            return;
        }
        try {
            $row = $this->emails->findPrimaryForProfile($profileId);
            $email = trim((string) ($row['email'] ?? ''));
            if ($email === '') {
                return;
            }
            $language = $this->mail->language($tenantId);
            $this->mail->send($tenantId, $email, $subject, new EmailContent(
                heading: $subject,
                paragraphs: $paragraphs,
                ctaLabel: $cta,
                ctaUrl: $url,
                language: $language,
            ));
        } catch (\Throwable $e) {
            error_log('[ownership-notifier] email ' . $type . ' to profile ' . $profileId . ' failed: ' . $e->getMessage());
        }
    }

    /** @return array<string, string> */
    private function words(int $tenantId): array
    {
        $language = 'en';
        try {
            $language = $this->mail?->language($tenantId) ?? 'en';
        } catch (\Throwable) {
            // English when the workspace's language can't be read.
        }
        return self::WORDS[$language === 'ar' ? 'ar' : 'en'];
    }

    private function url(string $path): string
    {
        return rtrim($this->appUrl, '/') . $path;
    }
}
