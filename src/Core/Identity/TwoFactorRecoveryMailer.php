<?php

declare(strict_types=1);

namespace Whity\Core\Identity;

use Whity\Core\Mail\BrandedEmailSender;
use Whity\Core\Mail\EmailContent;
use Whity\Core\Settings\SettingsRegistry;

/**
 * Sends the "I lost my 2FA device" recovery-request transactional emails
 * (WC-password-reset-2fa-recovery): the confirmation link (proves mailbox
 * ownership before the request becomes admin-visible) and the "your request
 * was submitted" notice once it lands in the admin queue.
 *
 * Direct-call sender, same rationale as {@see PasswordResetMailer} (a public,
 * unauthenticated request, not an authenticated lifecycle hook). Reuses the
 * SAME `mail.events.password_reset_enabled` toggle as password-reset — both
 * are account-recovery emails from the same feature area, and this avoids
 * introducing a second, narrower settings key for what an operator would
 * think of as one "account recovery email" concern.
 */
final class TwoFactorRecoveryMailer
{
    public function __construct(
        private readonly BrandedEmailSender $sender,
        private readonly string $confirmUrlBase,
    ) {}

    /**
     * Send the confirmation link. Caller resolves the raw token from
     * {@see TwoFactorRecoveryService::issue()} and passes it here.
     */
    public function sendConfirmationLink(string $email, string $rawToken): void
    {
        if (!$this->eventEnabled()) {
            return;
        }

        $link = $this->buildLink($rawToken);

        $this->sender->deliver(
            0,
            $email,
            'Confirm your account-recovery request',
            new EmailContent(
                heading: 'Confirm your account-recovery request',
                paragraphs: [
                    'We received a request to recover your account because you may have lost access to your password and your two-factor device.',
                    'Confirm this request to submit it for administrator review. This link expires in 1 hour.',
                ],
                ctaLabel: 'Confirm the request',
                ctaUrl: $link,
                footnote: "If you didn't ask for this, ignore this email: nothing changes without this link.",
                preheader: 'Confirm it so an administrator can review it. The link works for 1 hour.',
                notice: 'Sent to ' . $email . '.',
            ),
        );
    }

    /**
     * Send the "your request was submitted" notice once the confirmed request
     * lands in the admin queue.
     */
    public function sendSubmittedNotice(string $email): void
    {
        if (!$this->eventEnabled()) {
            return;
        }

        $this->sender->deliver(
            0,
            $email,
            'Your account-recovery request was submitted',
            new EmailContent(
                heading: 'Your account-recovery request was submitted',
                paragraphs: [
                    'Your account-recovery request has been submitted for administrator review. You will receive another email once it has been reviewed.',
                    "If you didn't request this, contact an administrator.",
                ],
                notice: 'Sent to ' . $email . '.',
            ),
        );
    }

    private function eventEnabled(): bool
    {
        return $this->sender->gate()->instanceEvent(SettingsRegistry::MAIL_EVENT_PASSWORD_RESET);
    }

    private function buildLink(string $token): string
    {
        $base = rtrim($this->confirmUrlBase, '?&');
        $separator = str_contains($base, '?') ? '&' : '?';

        return $base . $separator . 'token=' . urlencode($token);
    }
}
