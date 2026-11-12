<?php

declare(strict_types=1);

namespace Whity\Sdk\Mail;

/**
 * Optional capability: the emails a plugin sends (WHIT-959). A plugin that
 * declares none cannot send any through {@see PluginEmailSender}.
 *
 * Each template's key is stored under the plugin's own namespace, so `invoice`
 * from `front-office` is `front-office:invoice`: two plugins never share one,
 * and a plugin cannot pose as one of core's emails. Every email is laid out by
 * the host in the same branded shell as core's (EM.1), so a plugin never
 * writes HTML and never patches core to add an email.
 *
 *     public function getEmailTemplates(): array
 *     {
 *         return [new InvoiceEmail(), new ReceiptEmail()];
 *     }
 */
interface PluginEmailTemplatesInterface
{
    /**
     * A malformed declaration (a bad or repeated key, something that is not an
     * {@see EmailTemplate}) is refused whole.
     *
     * @return list<EmailTemplate>
     */
    public function getEmailTemplates(): array;
}
