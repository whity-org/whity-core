<?php

declare(strict_types=1);

namespace Whity\Core\Identity;

use Whity\Core\Mail\BrandedEmailSender;
use Whity\Core\Mail\EmailContent;

/**
 * Concrete {@see EmailVerificationProvider} (WC-235): issues a token via
 * {@see EmailVerificationService} and delivers the verification link through
 * {@see BrandedEmailSender} in the instance's brand (EM.1 `verify-email`).
 * Bound in the composition root in place of {@see NullEmailVerificationProvider}
 * so that, when EMAIL_VERIFICATION_ENFORCED is on, registration (and the resend
 * endpoint) actually send a link.
 *
 * Resolution is by email (globally unique in profile_emails). Already-verified
 * or unknown addresses are a silent no-op — there is nothing to verify, and the
 * public resend path must not reveal which addresses exist.
 */
final class TokenEmailVerificationProvider implements EmailVerificationProvider
{
    public function __construct(
        private readonly EmailVerificationService $service,
        private readonly ProfileEmailRepository $emails,
        private readonly BrandedEmailSender $sender,
        private readonly string $verifyUrlBase,
    ) {}

    public function sendVerification(int $profileId, string $email): void
    {
        $row = $this->emails->findByEmail($email);

        // Nothing to do: unknown address, belongs to another profile, or already
        // verified. Silent (no enumeration, no redundant email).
        if ($row === null
            || (int) $row['profile_id'] !== $profileId
            || $row['verified'] === true
        ) {
            return;
        }

        $token = $this->service->issue((int) $row['id']);
        $link  = $this->buildLink($token);

        $this->sender->deliver(
            0,
            $email,
            'Verify your email address',
            new EmailContent(
                heading: 'Confirm your email',
                paragraphs: [
                    'Confirm this address to finish signing up and keep your account yours.',
                ],
                ctaLabel: 'Confirm my email',
                ctaUrl: $link,
                footnote: 'Never share this link: we will never ask you for it.',
                preheader: 'One step to finish signing up.',
                notice: "Sent to {$email} because someone signed up with it. If it wasn't you, ignore this email.",
            ),
        );
    }
    /**
     * Compose the verification link. The base may already carry a query string;
     * append the token with the correct separator.
     */
    private function buildLink(string $token): string
    {
        $base = rtrim($this->verifyUrlBase, '?&');
        $separator = str_contains($base, '?') ? '&' : '?';

        return $base . $separator . 'token=' . urlencode($token);
    }
}
