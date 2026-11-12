<?php

declare(strict_types=1);

namespace Whity\Core\Mail;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Whity\Core\Plugin\PluginIdentity;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Store\SharedStoreInterface;
use Whity\Sdk\Mail\BillingEmails;
use Whity\Sdk\Mail\WorkspaceEmail;
use Whity\Sdk\PluginBillingEmailsInterface;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginNamespace;

/**
 * The host side of {@see BillingEmails} (SDK 1.102, WHIT-985): Whity's own
 * email about a workspace's plan, drawn like {@see PlanLimitMailer}'s (the
 * instance's brand, the workspace's language) and sent to the workspace's
 * members who hold `billing:pay`.
 *
 * Three gates before anything is sent, each refusing with 0 and a log line:
 *   1. the plugin declares {@see PluginBillingEmailsInterface};
 *   2. the operator lists it in `plugins.billing_email_senders` (global-only,
 *      empty by default), matched on the slug the LOADER bound to this object
 *      ({@see PluginIdentity}), never on what `getName()` says now;
 *   3. its button, if any, opens this app (a Whity-branded email must not
 *      send a payer anywhere else);
 *   4. the workspace is under its daily cap, counted in the shared store so
 *      every worker shares one count.
 * The grant is read on every call, so removing a plugin from the setting
 * applies at once.
 */
final class SdkBillingEmails implements BillingEmails
{
    /** At most this many billing emails per workspace in a day, from every plugin together. */
    public const DAILY_CAP = 5;

    private const WINDOW_SECONDS = 86400;

    private LoggerInterface $logger;

    public function __construct(
        private readonly BrandedEmailSender $sender,
        private readonly WorkspaceRecipients $recipients,
        private readonly SettingsService $settings,
        private readonly SharedStoreInterface $counter,
        /** The app's base URL; the Billing page is under it. */
        private readonly string $appUrl,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function send(PluginInterface $from, int $tenantId, string $subject, WorkspaceEmail $email): int
    {
        $slug = $this->grantedSlug($from);
        if ($slug === null) {
            $this->logger->warning('[mail] billing email refused: the plugin is not granted plugins.billing_email_senders');

            return 0;
        }
        $subject = trim(preg_replace('/[\r\n]+/', ' ', $subject) ?? '');
        if ($tenantId <= 0 || $subject === '') {
            return 0;
        }
        // In the instance's brand, the one button may only open this app.
        if ($email->buttonUrl !== null && !str_starts_with($email->buttonUrl, rtrim($this->appUrl, '/') . '/')) {
            $this->logger->warning('[mail] billing email from ' . $slug . ' refused: its button leaves the app');

            return 0;
        }
        $to = $this->recipients->holding($tenantId, CorePermissions::BILLING_PAY);
        if ($to === []) {
            return 0;
        }
        if ($this->counter->increment('billing_emails:tenant:' . $tenantId, self::WINDOW_SECONDS) > self::DAILY_CAP) {
            $this->logger->warning('[mail] billing email from ' . $slug . ' refused: workspace ' . $tenantId . ' reached its daily cap');

            return 0;
        }

        $content = $this->content($tenantId, $email);
        $subject = mb_substr($subject, 0, 200);
        $sent = 0;
        foreach ($to as $address) {
            // Whity's own email: the instance's brand (tenant 0), the workspace's language.
            if ($this->sender->send(0, $address, $subject, $content)) {
                $sent++;
            }
        }

        return $sent;
    }

    public function isAllowed(PluginInterface $from): bool
    {
        return $this->grantedSlug($from) !== null;
    }

    public function language(int $tenantId): string
    {
        return $this->sender->language($tenantId);
    }

    public function billingUrl(int $tenantId): string
    {
        return rtrim($this->appUrl, '/') . '/billing';
    }

    private function grantedSlug(PluginInterface $from): ?string
    {
        if (!$from instanceof PluginBillingEmailsInterface) {
            return null;
        }
        try {
            $slug = PluginIdentity::slugOf($from);
        } catch (\InvalidArgumentException) {
            return null;
        }
        try {
            $granted = (string) ($this->settings->getGlobal()[SettingsRegistry::PLUGINS_BILLING_EMAIL_SENDERS] ?? '');
        } catch (\Throwable) {
            return null;
        }
        foreach (explode(',', $granted) as $name) {
            if (trim($name) !== '' && PluginNamespace::slug(trim($name)) === $slug) {
                return $slug;
            }
        }

        return null;
    }

    private function content(int $tenantId, WorkspaceEmail $email): EmailContent
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
