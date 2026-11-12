<?php

declare(strict_types=1);

namespace Whity\Core\Mail;

use Whity\Sdk\Mail\WorkspaceEmail;
use Whity\Sdk\Mail\WorkspaceEmails;

/**
 * The host side of {@see WorkspaceEmails} (SDK 1.99, WHIT-978): a plugin's
 * email, drawn by {@see BrandedEmailSender} like every email core sends.
 *
 * It maps the SDK's small vocabulary onto {@see EmailContent} and nothing
 * more: no code block, checklist or proposal box (those are core's own
 * kinds of email), and the button always in the brand tone.
 */
final class SdkWorkspaceEmails implements WorkspaceEmails
{
    public function __construct(private readonly BrandedEmailSender $sender)
    {
    }

    public function send(int $tenantId, string $to, string $subject, WorkspaceEmail $email): bool
    {
        if ($tenantId <= 0 || !filter_var(trim($to), FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $subject = trim(preg_replace('/[\r\n]+/', ' ', $subject) ?? '');
        if ($subject === '') {
            return false;
        }

        return $this->sender->send($tenantId, trim($to), mb_substr($subject, 0, 200), $this->content($tenantId, $email));
    }

    public function language(int $tenantId): string
    {
        return $this->sender->language($tenantId);
    }

    public function content(int $tenantId, WorkspaceEmail $email): EmailContent
    {
        return new EmailContent(
            heading: $email->heading,
            paragraphs: $email->paragraphs,
            ctaLabel: $email->buttonLabel,
            ctaUrl: $email->buttonUrl,
            callout: $email->note,
            preheader: $email->preheader,
            rows: $email->rows,
            notice: $email->notice,
            language: $email->language ?? $this->sender->language($tenantId),
        );
    }
}
