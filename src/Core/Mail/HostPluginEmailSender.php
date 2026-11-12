<?php

declare(strict_types=1);

namespace Whity\Core\Mail;

use Psr\Log\LoggerInterface;
use Whity\Core\Plugin\PluginIdentity;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Mail\EmailSender;
use Whity\Sdk\Mail\PluginEmailSender;
use Whity\Sdk\Mail\PluginEmailTemplatesInterface;
use Whity\Sdk\PluginInterface;

/**
 * The host's plugin email sender (WHIT-959): the extension point by which a
 * plugin sends its own emails (an invoice, a receipt, a payment that failed)
 * without patching core.
 *
 * The plugin comes from the loader's identity, the templates from the plugin's
 * own declaration ({@see PluginEmailTemplatesInterface}), and the brand from
 * the workspace in {@see TenantContext}, so none of them is something a plugin
 * can state. Every email goes through {@see EmailLayout}, the same shell as
 * core's.
 */
final class HostPluginEmailSender implements PluginEmailSender
{
    /** @var \Closure(): ?int */
    private readonly \Closure $tenant;

    /** @var \Closure(int): bool */
    private readonly \Closure $sentWithWhity;

    /**
     * @param string                    $appUrl        The app's absolute address, for the logo link.
     * @param (\Closure(): ?int)|null   $tenant        The workspace in scope; the request's or job's by default.
     * @param (\Closure(int): bool)|null $sentWithWhity Whether a workspace's emails say "Sent with Whity"; yes by default.
     */
    public function __construct(
        private readonly Mailer $mailer,
        private readonly SettingsService $settings,
        private readonly string $appUrl = '',
        ?\Closure $tenant = null,
        ?\Closure $sentWithWhity = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly EmailLayout $layout = new EmailLayout(),
    ) {
        $this->tenant = $tenant ?? static fn (): ?int => TenantContext::getTenantId();
        $this->sentWithWhity = $sentWithWhity ?? static fn (int $tenantId): bool => true;
    }

    /**
     * Make the service reachable as `\Whity\app(PluginEmailSender::class)`.
     *
     * @param (\Closure(int): bool)|null $sentWithWhity
     */
    public static function register(
        Mailer $mailer,
        SettingsService $settings,
        string $appUrl = '',
        ?\Closure $sentWithWhity = null,
        ?LoggerInterface $logger = null,
    ): void {
        \Whity\register_service(PluginEmailSender::class, new self($mailer, $settings, $appUrl, null, $sentWithWhity, $logger));
    }

    public function for(PluginInterface $plugin): EmailSender
    {
        $slug = PluginIdentity::slugOf($plugin);
        $registry = $plugin instanceof PluginEmailTemplatesInterface
            ? EmailTemplateRegistry::fromPlugin($plugin, $slug)
            : new EmailTemplateRegistry();

        return new HostEmailSender(
            $registry,
            $slug,
            $this->mailer,
            fn (): EmailBranding => $this->branding(),
            $this->layout,
            $this->logger,
        );
    }

    private function branding(): EmailBranding
    {
        $tenantId = ($this->tenant)() ?? 0;
        if ($tenantId <= 0) {
            return EmailBranding::forTenant($this->settings, 0, $this->appUrl);
        }
        try {
            $sentWithWhity = ($this->sentWithWhity)($tenantId);
        } catch (\Throwable) {
            $sentWithWhity = true;
        }

        return EmailBranding::forTenant($this->settings, $tenantId, $this->appUrl, $sentWithWhity);
    }
}
