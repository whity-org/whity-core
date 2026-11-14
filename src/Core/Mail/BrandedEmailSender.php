<?php

declare(strict_types=1);

namespace Whity\Core\Mail;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Whity\Core\Branding\BrandingService;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;

/**
 * Sends one email as a workspace (EM.1): in its brand, in its language, through
 * the {@see EmailLayout}. The one place that knows how a workspace's email looks,
 * so every core sender and every plugin that sends through core (Front Office's
 * invoices, Whity Pay's receipts) gets the same card, the same white-label rule
 * and the same Arabic layout without drawing HTML of its own.
 *
 * The language is the workspace's `locale` setting: Arabic when it starts with
 * `ar`, English otherwise. Whity keeps no per-person language on the server yet.
 *
 * Every email core sends goes through here (E2), including the instance's own
 * account emails (tenant 0, the instance's brand) and those that need a brand,
 * a file or a thread of their own ({@see EmailOptions}).
 *
 * {@see send()} is best-effort: it reports false and logs instead of throwing,
 * because an email is a side channel and must never break the request that
 * caused it. {@see deliver()} lets the failure through for the few callers that
 * have to report it.
 */
final class BrandedEmailSender
{
    private LoggerInterface $logger;
    private ?BrandingService $branding = null;
    private ?EmailGate $gate = null;

    public function __construct(
        private readonly Mailer $mailer,
        private readonly EmailLayout $layout,
        private readonly SettingsService $settings,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * The workspace branding source. Set after boot builds it (it needs the
     * entitlement service); until then emails use the instance's own brand.
     */
    public function useBranding(BrandingService $branding): void
    {
        $this->branding = $branding;
    }

    /**
     * The one gate every email asks before it goes ({@see EmailGate}). Set
     * after boot with the database; until then only the operator's
     * `mail.events.*` switches apply.
     */
    public function useGate(EmailGate $gate): void
    {
        $this->gate = $gate;
    }

    public function gate(): EmailGate
    {
        return $this->gate ??= new EmailGate($this->settings);
    }

    /** 'ar' or 'en', from the workspace's locale. */
    public function language(int $tenantId): string
    {
        try {
            $locale = $tenantId > 0
                ? (string) ($this->settings->effective($tenantId)[SettingsRegistry::LOCALE] ?? 'en')
                : (string) ($this->settings->getGlobal()[SettingsRegistry::LOCALE] ?? 'en');
        } catch (\Throwable) {
            return 'en';
        }

        return str_starts_with(strtolower(trim($locale)), 'ar') ? 'ar' : 'en';
    }

    /**
     * The workspace's own contact details for its emails' footers (3.4:
     * "{{tenant_name}} · {{tenant_address}} · Reply to this email to reach
     * them"): its address in the email's language (Settings › Company), and
     * its email as the reply-to when it has a valid one. Nulls when unset.
     *
     * @return array{address: ?string, replyTo: ?string}
     */
    public function workspaceContact(int $tenantId, string $language): array
    {
        try {
            $effective = $tenantId > 0 ? $this->settings->effective($tenantId) : $this->settings->getGlobal();
        } catch (\Throwable) {
            return ['address' => null, 'replyTo' => null];
        }
        $text = static function (mixed $value): ?string {
            return is_string($value) && trim($value) !== '' ? trim(str_replace(["\r", "\n"], ' ', $value)) : null;
        };
        $address = ($language === 'ar' ? $text($effective[SettingsRegistry::COMPANY_ADDRESS_AR] ?? null) : null)
            ?? $text($effective[SettingsRegistry::COMPANY_ADDRESS] ?? null);
        $email = $text($effective[SettingsRegistry::COMPANY_EMAIL] ?? null);

        return [
            'address' => $address,
            'replyTo' => $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null,
        ];
    }

    public function branding(int $tenantId): EmailBranding
    {
        return $this->branding !== null
            ? EmailBranding::forTenant($this->settings, $this->branding, $tenantId)
            : EmailBranding::fromSettings($this->settings);
    }

    /**
     * The email as it would be sent, without sending it.
     */
    public function render(int $tenantId, EmailContent $content, ?EmailOptions $options = null): RenderedEmail
    {
        return $this->layout->render($content, $options->branding ?? $this->branding($tenantId));
    }

    /**
     * Render and send. True when the transport accepted it; a failure is
     * logged and reported as false.
     */
    public function send(int $tenantId, string $to, string $subject, EmailContent $content, ?EmailOptions $options = null): bool
    {
        if (trim($to) === '') {
            return false;
        }

        try {
            $this->deliver($tenantId, $to, $subject, $content, $options);

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('[mail] could not send "' . $subject . '": ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Render and send, letting a failure through for the caller that has to
     * tell someone the email didn't go (an administrator sending a reset link).
     *
     * A workspace's email (tenant above 0) comes from "the workspace, via
     * Whity"; tenant 0 is the instance's own email and keeps the transport's
     * from name. Attachments, Reply-To and the thread need a {@see ThreadedMailer};
     * a mailer that can't send them sends the same email without them.
     *
     * @throws \Throwable when rendering or the transport fails
     */
    public function deliver(int $tenantId, string $to, string $subject, EmailContent $content, ?EmailOptions $options = null): void
    {
        $to = trim($to);
        if ($to === '') {
            return;
        }
        $branding = $options->branding ?? $this->branding($tenantId);
        $rendered = $this->layout->render($content, $branding);
        // EM.1: from "the workspace, via Whity"; a white-label plan drops "via Whity".
        $fromName = $tenantId > 0 ? self::fromName($branding, $content->language) : null;

        if ($this->mailer instanceof ThreadedMailer && ($fromName !== null || ($options?->needsHeaders() ?? false))) {
            $this->mailer->sendThreaded(new OutgoingEmail(
                to: $to,
                subject: $subject,
                textBody: $rendered->text,
                htmlBody: $rendered->html,
                fromName: $fromName,
                messageId: $options?->messageId,
                inReplyTo: $options?->inReplyTo,
                references: $options->references ?? [],
                replyTo: $options?->replyTo,
                attachments: $options->attachments ?? [],
            ));

            return;
        }
        $this->mailer->send($to, $subject, $rendered->text, $rendered->html);
    }

    /**
     * "Example Bakery via Whity" ("Example Bakery عبر Whity" in Arabic), or the
     * workspace's name alone on a white-label plan.
     */
    public static function fromName(EmailBranding $branding, string $language = 'en'): string
    {
        $name = trim(str_replace(["\r", "\n"], ' ', $branding->siteName));
        if ($branding->whiteLabel || $name === 'Whity') {
            return $name;
        }

        return $name . ($language === 'ar' ? ' عبر Whity' : ' via Whity');
    }
}
