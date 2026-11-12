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
 * Best-effort: {@see send()} reports false and logs instead of throwing, because
 * an email is a side channel and must never break the request that caused it.
 */
final class BrandedEmailSender
{
    private LoggerInterface $logger;
    private ?BrandingService $branding = null;

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

    public function branding(int $tenantId): EmailBranding
    {
        return $this->branding !== null
            ? EmailBranding::forTenant($this->settings, $this->branding, $tenantId)
            : EmailBranding::fromSettings($this->settings);
    }

    /**
     * Render and send. True when the transport accepted it.
     */
    public function send(int $tenantId, string $to, string $subject, EmailContent $content): bool
    {
        $to = trim($to);
        if ($to === '') {
            return false;
        }

        try {
            $rendered = $this->layout->render($content, $this->branding($tenantId));
            $this->mailer->send($to, $subject, $rendered->text, $rendered->html);

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('[mail] could not send "' . $subject . '": ' . $e->getMessage());

            return false;
        }
    }
}
