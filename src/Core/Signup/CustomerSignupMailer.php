<?php

declare(strict_types=1);

namespace Whity\Core\Signup;

use Whity\Core\Mail\BrandedEmailSender;
use Whity\Core\Mail\EmailBranding;
use Whity\Core\Mail\EmailContent;
use Whity\Core\Mail\EmailOptions;

/**
 * Sends the "confirm your email to join" link for a customer sign-up (WHIT-915).
 *
 * Direct-call, like {@see \Whity\Core\Identity\InvitationMailer}: the raw token
 * exists only in this call and is never put on a hook payload.
 *
 * The copy is the same whether or not the address already has an account, and
 * whether or not it already belongs to the workspace. The page behind the link
 * says which case it is, to the person who proved they hold the mailbox.
 *
 * Not gated on a mail-event toggle: without this mail nobody can finish signing
 * up, and a workspace that wants no sign-ups turns sign-up off.
 */
final class CustomerSignupMailer
{
    public function __construct(
        private readonly BrandedEmailSender $sender,
        private readonly string $confirmUrlBase,
    ) {
    }

    public function send(string $email, string $workspaceName, string $rawToken): void
    {
        $branding = $this->sender->branding(0);
        $workspace = trim($workspaceName) !== '' ? trim($workspaceName) : $branding->siteName;

        $base = rtrim($this->confirmUrlBase, '?&');
        $link = $base . (str_contains($base, '?') ? '&' : '?') . 'token=' . urlencode($rawToken);

        $this->sender->deliver(
            0,
            $email,
            'Confirm your email to join ' . $workspace,
            new EmailContent(
                heading: 'Confirm your email to join ' . $workspace,
                paragraphs: [
                    'Someone asked to join ' . $workspace . ' on ' . $branding->siteName . ' with this email address.',
                    'Use the link below to confirm it is you. It expires in 24 hours and can only be used once.',
                ],
                ctaLabel: 'Confirm and join',
                ctaUrl: $link,
                footnote: 'If this wasn\'t you, ignore this message. Nothing happens until the link is used.',
                preheader: 'The link works for 24 hours.',
                notice: 'Sent because someone asked to join ' . $workspace . ' with this address.',
            ),
            new EmailOptions(branding: new EmailBranding($workspace, $branding->brandColor, $branding->supportEmail, $branding->footerText, $branding->whiteLabel)),
        );
    }
}
