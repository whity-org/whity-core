<?php

declare(strict_types=1);

namespace Whity\Core\Mail;

use PDO;
use Whity\Core\Identity\ProfileEmailRepository;

/**
 * The two security emails EM.1 added (`new-sign-in`, `two-step-off`).
 *
 * Security emails can't be turned off: no `mail.events.*` toggle and no personal
 * preference gates them. The only way they don't go is an instance with no mail
 * transport (the mailer is a NullMailer). Neither ever carries a password or a
 * code, and the button only opens the app.
 *
 * A sign-in is "new" when its browser has not signed in to this account before
 * (no earlier session with the same User-Agent) and the account has signed in
 * at least once before, so the first sign-in after joining sends nothing.
 */
final class AccountSecurityMailer
{
    private const WORDS = [
        'en' => [
            'signin.subject' => 'New sign-in to %s',
            'signin.preheader' => 'From %s. Was it you?',
            'signin.heading' => 'A new sign-in to your account',
            'when' => 'When',
            'device' => 'Device',
            'network' => 'Network address',
            'signin.you' => 'If it was you, there is nothing to do.',
            'signin.not_you' => "If it wasn't, secure your account now: sign out everywhere and choose a new password.",
            'signin.cta' => "It wasn't me",
            'off.subject' => 'Two-step sign-in was turned off',
            'off.preheader' => 'Your account is now protected by your password only.',
            'off.heading' => 'Two-step sign-in was turned off',
            'off.body' => 'On %s, two-step sign-in was turned off for %s. Your account is now protected by your password only.',
            'off.lead' => 'We recommend turning it back on.',
            'off.note' => 'It takes a minute with your phone.',
            'off.cta' => 'Turn it back on',
            'off.after' => "If you didn't do this, sign out everywhere in Settings › Security and change your password straight away.",
            'notice' => "Security emails can't be turned off. Sent to %s.",
        ],
        'ar' => [
            'signin.subject' => 'دخول جديد إلى %s',
            'signin.preheader' => 'من %s. هل كنت أنت؟',
            'signin.heading' => 'دخول جديد إلى حسابك',
            'when' => 'متى',
            'device' => 'الجهاز',
            'network' => 'عنوان الشبكة',
            'signin.you' => 'إن كنت أنت، فلا شيء عليك فعله.',
            'signin.not_you' => 'إن لم تكن أنت، أمّن حسابك الآن: سجّل الخروج من كل الأجهزة واختر كلمة مرور جديدة.',
            'signin.cta' => 'لم أكن أنا',
            'off.subject' => 'أُوقف الدخول بخطوتين',
            'off.preheader' => 'حسابك الآن محمي بكلمة المرور فقط.',
            'off.heading' => 'أُوقف الدخول بخطوتين',
            'off.body' => 'في %s أُوقف الدخول بخطوتين لـ %s. حسابك الآن محمي بكلمة المرور فقط.',
            'off.lead' => 'ننصح بإعادة تشغيله.',
            'off.note' => 'يستغرق دقيقة باستخدام هاتفك.',
            'off.cta' => 'أعد تشغيله',
            'off.after' => 'إن لم تفعل ذلك أنت، سجّل الخروج من كل الأجهزة من الإعدادات › الأمان وغيّر كلمة المرور فوراً.',
            'notice' => 'لا يمكن إيقاف رسائل الأمان. أُرسلت إلى %s.',
        ],
    ];

    public function __construct(
        private readonly BrandedEmailSender $sender,
        private readonly ProfileEmailRepository $emails,
        private readonly PDO $db,
        /** Where the buttons go: the account's security settings in the app. */
        private readonly string $securityUrl,
    ) {
    }

    /**
     * Called right after a sign-in recorded its session. Sends only for a
     * browser this account hasn't signed in with before.
     */
    public function signedIn(int $profileId, int $tenantId, ?string $userAgent, ?string $ip, ?\DateTimeImmutable $at = null): bool
    {
        try {
            if (!$this->isNewDevice($profileId, $userAgent)) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }
        $email = $this->primaryEmail($profileId);
        if ($email === null) {
            return false;
        }

        $lang = $this->sender->language($tenantId);
        $w = self::WORDS[$lang];
        $device = DeviceLabel::from($userAgent, $lang);
        $name = $this->sender->branding($tenantId)->siteName;
        $rows = [[$w['when'], self::when($at)], [$w['device'], $device]];
        if ($ip !== null && $ip !== '') {
            $rows[] = [$w['network'], $ip];
        }

        return $this->sender->send($tenantId, $email, sprintf($w['signin.subject'], $name), new EmailContent(
            heading: $w['signin.heading'],
            paragraphs: [$w['signin.you'], $w['signin.not_you']],
            ctaLabel: $w['signin.cta'],
            ctaUrl: $this->securityUrl,
            preheader: sprintf($w['signin.preheader'], $device),
            rows: $rows,
            ctaTone: 'danger',
            notice: sprintf($w['notice'], $email),
            language: $lang,
        ));
    }

    /** Two-step sign-in was turned off for this account. */
    public function twoStepTurnedOff(int $profileId, int $tenantId, ?\DateTimeImmutable $at = null): bool
    {
        $email = $this->primaryEmail($profileId);
        if ($email === null) {
            return false;
        }

        $lang = $this->sender->language($tenantId);
        $w = self::WORDS[$lang];

        return $this->sender->send($tenantId, $email, $w['off.subject'], new EmailContent(
            heading: $w['off.heading'],
            paragraphs: [sprintf($w['off.body'], self::when($at), $email)],
            ctaLabel: $w['off.cta'],
            ctaUrl: $this->securityUrl,
            callout: $w['off.note'],
            footnote: $w['off.after'],
            preheader: $w['off.preheader'],
            calloutLead: $w['off.lead'],
            notice: sprintf($w['notice'], $email),
            language: $lang,
        ));
    }

    private function isNewDevice(int $profileId, ?string $userAgent): bool
    {
        $ua = $userAgent !== null ? substr($userAgent, 0, 512) : null;
        $total = $this->db->prepare('SELECT COUNT(*) FROM sessions WHERE profile_id = :p');
        $total->execute([':p' => $profileId]);
        if ((int) $total->fetchColumn() <= 1) {
            return false; // The first sign-in ever: nothing to compare with.
        }

        if ($ua === null) {
            $same = $this->db->prepare('SELECT COUNT(*) FROM sessions WHERE profile_id = :p AND user_agent IS NULL');
            $same->execute([':p' => $profileId]);
        } else {
            $same = $this->db->prepare('SELECT COUNT(*) FROM sessions WHERE profile_id = :p AND user_agent = :ua');
            $same->execute([':p' => $profileId, ':ua' => $ua]);
        }

        // The session just recorded is the only one from this browser.
        return (int) $same->fetchColumn() <= 1;
    }

    private function primaryEmail(int $profileId): ?string
    {
        try {
            $row = $this->emails->findPrimaryForProfile($profileId);
        } catch (\Throwable) {
            return null;
        }
        $email = trim((string) ($row['email'] ?? ''));

        return $email !== '' ? $email : null;
    }

    /** Western digits, UTC, plain: "12 Nov 2026, 18:02 UTC". */
    private static function when(?\DateTimeImmutable $at): string
    {
        return ($at ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('j M Y, H:i') . ' UTC';
    }
}
