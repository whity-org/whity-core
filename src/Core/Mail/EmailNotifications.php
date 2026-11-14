<?php

declare(strict_types=1);

namespace Whity\Core\Mail;

use Whity\Core\Hooks\HookManager;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;

/**
 * Sends the platform's customer-facing lifecycle emails (WC-email) by listening
 * on {@see HookManager} events and sending them through {@see BrandedEmailSender}
 * in the instance's brand (or the new workspace's name, for `workspace-ready`).
 *
 * Decoupled by design: the flows that create/approve/invite accounts just
 * dispatch a hook; this subscriber (and any plugin) reacts. Every send is:
 *  - gated on the matching `mail.events.*` toggle (operator opt-out per event),
 *  - best-effort — a failure is logged and swallowed so it can never break the
 *    originating request (email is a side channel), and
 *  - a no-op when the transport is off (the injected mailer is a NullMailer).
 *
 * Registered on the hook manager once at boot via {@see subscribe()}.
 */
final class EmailNotifications
{
    public function __construct(
        private readonly BrandedEmailSender $sender,
        private readonly SettingsService $settings,
        private readonly string $appUrl = '',
    ) {
    }

    /**
     * Wire this subscriber's listeners onto the hook manager.
     */
    public function subscribe(HookManager $hooks): void
    {
        $hooks->listen('registration.completed', [$this, 'onRegistrationCompleted']);
        $hooks->listen('registration.approved', [$this, 'onRegistrationApproved']);
        $hooks->listen('registration.rejected', [$this, 'onRegistrationRejected']);
        $hooks->listen('user.created', [$this, 'onUserCreated']);
        $hooks->listen('user.deleted', [$this, 'onUserDeleted']);
    }

    /**
     * A user's access to a workspace was removed. Sends either a friendly
     * "sorry to see you go" farewell, or — when reason is 'terms_violation' — a
     * firm-but-fair termination notice with an appeal contact. Gated on
     * mail.events.deletion_enabled.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function onUserDeleted(array $data, array $context = []): array
    {
        if (!$this->eventEnabled(SettingsRegistry::MAIL_EVENT_DELETION)) {
            return $data;
        }
        $email = trim((string) ($data['email'] ?? ''));
        if ($email === '') {
            return $data;
        }
        $branding = $this->sender->branding(0);
        $workspace = trim((string) ($data['tenant_name'] ?? ''));
        $where = $workspace !== '' ? $workspace : 'your workspace';
        $isTermination = (string) ($data['reason'] ?? '') === 'terms_violation';

        if ($isTermination) {
            $paragraphs = [
                'Your access to ' . $where . ' on ' . $branding->siteName
                    . ' has been removed following a violation of our terms of service.',
            ];
            $appeal = $branding->supportEmail !== '' ? $branding->supportEmail : '';
            $paragraphs[] = $appeal !== ''
                ? 'If you believe this was a mistake, please contact ' . $appeal . '.'
                : 'If you believe this was a mistake, please reply to this message.';

            $this->trySend(
                $email,
                'Your ' . $branding->siteName . ' access has been removed',
                new EmailContent(
                    heading: 'Your access to ' . $where . ' was removed',
                    paragraphs: $paragraphs,
                ),
                $branding,
            );

            return $data;
        }

        // Friendly farewell.
        $this->trySend(
            $email,
            'You\'ve been removed from ' . $where,
            new EmailContent(
                heading: 'Sorry to see you go',
                paragraphs: [
                    'Your access to ' . $where . ' on ' . $branding->siteName . ' has been removed.',
                    'Thanks for being part of it — you\'re always welcome back.',
                ],
            ),
            $branding,
        );

        return $data;
    }

    /**
     * An admin added a user to a workspace → send the "you've been added"
     * invitation email. Gated on mail.events.invitation_enabled.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function onUserCreated(array $data, array $context = []): array
    {
        if (!$this->eventEnabled(SettingsRegistry::MAIL_EVENT_INVITATION)) {
            return $data;
        }
        $email = trim((string) ($data['email'] ?? ''));
        if ($email === '') {
            return $data;
        }
        $branding = $this->sender->branding(0);
        $workspace = trim((string) ($data['tenant_name'] ?? ''));
        $where = $workspace !== '' ? 'the ' . $workspace . ' workspace' : 'a workspace';

        $this->trySend(
            $email,
            'You\'ve been added to ' . ($workspace !== '' ? $workspace : $branding->siteName),
            new EmailContent(
                heading: $workspace !== '' ? 'You\'ve been added to ' . $workspace : 'You\'ve been added to a workspace',
                paragraphs: [
                    'An administrator added you to ' . $where . ' on ' . $branding->siteName . '.',
                    'Sign in to get started.',
                ],
                ctaLabel: $this->appUrl !== '' ? 'Sign in to ' . $branding->siteName : null,
                ctaUrl: $this->appUrl !== '' ? $this->appUrl : null,
                callout: $workspace !== '' ? 'Workspace: ' . $workspace : null,
            ),
            $branding,
        );

        return $data;
    }

    /**
     * Welcome a newly-registered owner — but only once their account is usable
     * (approval NOT pending). When approval is required the welcome is deferred to
     * the approval email, so a pending owner isn't told to "sign in" prematurely.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function onRegistrationCompleted(array $data, array $context = []): array
    {
        if (($data['approval_required'] ?? false) === true) {
            return $data;
        }
        if (!$this->eventEnabled(SettingsRegistry::MAIL_EVENT_WELCOME)) {
            return $data;
        }

        $email = trim((string) ($data['email'] ?? ''));
        if ($email === '') {
            return $data;
        }
        $name = trim((string) ($data['name'] ?? ''));
        $base = $this->sender->branding(0);
        $workspace = trim((string) ($data['tenant_name'] ?? '')) ?: $base->siteName;
        // EM.1 `workspace-ready`: from the new workspace, in its name.
        $branding = new EmailBranding($workspace, $base->brandColor, $base->supportEmail, $base->footerText, $base->whiteLabel);
        $ar = $this->instanceLanguage() === 'ar';

        $this->trySend(
            $email,
            $ar ? $workspace . ' جاهزة' : $workspace . ' is ready',
            new EmailContent(
                heading: $ar
                    ? ($name !== '' ? 'أهلاً ' . $name . '. ' : '') . $workspace . ' جاهزة.'
                    : 'Welcome' . ($name !== '' ? ', ' . $name : '') . '. ' . $workspace . ' is ready.',
                paragraphs: [$ar ? 'هذا ما تبدأ به معظم الفرق:' : 'Here is what most teams do first:'],
                ctaLabel: $this->appUrl !== '' ? ($ar ? 'افتح ' . $workspace : 'Open ' . $workspace) : null,
                ctaUrl: $this->appUrl !== '' ? $this->appUrl : null,
                preheader: $ar ? 'ثلاثة أشياء تبدأ بها، نحو عشر دقائق في المجموع.' : 'Three things to do first, about ten minutes in all.',
                checklist: $ar
                    ? ['ادعُ فريقك (دقيقتان)', 'أدخل عملاءك من جدول بيانات (5 دقائق)', 'أنشئ مستندك الأول (دقيقة واحدة)']
                    : ['Invite your team (2 minutes)', 'Bring in your customers from a spreadsheet (5 minutes)', 'Make your first document (1 minute)'],
                notice: $ar ? 'أُرسلت إلى ' . $email . '، مالك ' . $workspace . '.' : 'Sent to ' . $email . ', the owner of ' . $workspace . '.',
                language: $ar ? 'ar' : 'en',
            ),
            $branding,
        );

        return $data;
    }

    /**
     * A pending registration was approved → the account is now active. Sends the
     * "approved, sign in" email. Gated on mail.events.approval_enabled.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function onRegistrationApproved(array $data, array $context = []): array
    {
        if (!$this->eventEnabled(SettingsRegistry::MAIL_EVENT_APPROVAL)) {
            return $data;
        }
        $email = trim((string) ($data['email'] ?? ''));
        if ($email === '') {
            return $data;
        }
        $name = trim((string) ($data['name'] ?? ''));
        $branding = $this->sender->branding(0);

        $this->trySend(
            $email,
            'Your ' . $branding->siteName . ' account is approved',
            new EmailContent(
                heading: 'Your account is approved' . ($name !== '' ? ', ' . $name : ''),
                paragraphs: [
                    'An administrator has approved your registration. Your workspace on '
                        . $branding->siteName . ' is now active and ready to use.',
                    'Sign in to get started.',
                ],
                ctaLabel: $this->appUrl !== '' ? 'Sign in to ' . $branding->siteName : null,
                ctaUrl: $this->appUrl !== '' ? $this->appUrl : null,
            ),
            $branding,
        );

        return $data;
    }

    /**
     * A pending registration was rejected. Sends a brief, respectful decline.
     * Gated on mail.events.approval_enabled (same operator switch as approvals).
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function onRegistrationRejected(array $data, array $context = []): array
    {
        if (!$this->eventEnabled(SettingsRegistry::MAIL_EVENT_APPROVAL)) {
            return $data;
        }
        $email = trim((string) ($data['email'] ?? ''));
        if ($email === '') {
            return $data;
        }
        $name = trim((string) ($data['name'] ?? ''));
        $branding = $this->sender->branding(0);

        $paragraphs = [
            'Thank you for your interest in ' . $branding->siteName
                . '. After review, your registration was not approved at this time.',
        ];
        if ($branding->supportEmail !== '') {
            $paragraphs[] = 'If you think this was a mistake, reply to this message or contact '
                . $branding->supportEmail . '.';
        }

        $this->trySend(
            $email,
            'Update on your ' . $branding->siteName . ' registration',
            new EmailContent(
                heading: 'About your registration' . ($name !== '' ? ', ' . $name : ''),
                paragraphs: $paragraphs,
            ),
            $branding,
        );

        return $data;
    }

    /** 'ar' when the instance's default language is Arabic, else 'en'. */
    private function instanceLanguage(): string
    {
        try {
            $locale = (string) ($this->settings->getGlobal()[SettingsRegistry::LOCALE] ?? 'en');
        } catch (\Throwable) {
            return 'en';
        }

        return str_starts_with(strtolower($locale), 'ar') ? 'ar' : 'en';
    }

    /**
     * Whether an event's `mail.events.*` toggle is on. A settings-read failure
     * disables the send (fail-closed for a side channel).
     */
    private function eventEnabled(string $key): bool
    {
        try {
            $global = $this->settings->getGlobal();
        } catch (\Throwable) {
            return false;
        }

        return (string) ($global[$key] ?? 'false') === 'true';
    }

    /**
     * Send as the instance (tenant 0) in the given brand; a failure is logged
     * by the sender and swallowed (best-effort side channel).
     */
    private function trySend(string $toEmail, string $subject, EmailContent $content, EmailBranding $branding): void
    {
        $this->sender->send(0, $toEmail, $subject, $content, new EmailOptions(branding: $branding));
    }
}
