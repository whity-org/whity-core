<?php

declare(strict_types=1);

namespace Whity\Sdk;

use Whity\Sdk\Mail\BillingEmails;

/**
 * A plugin's declaration that it sends Whity's own billing emails to the
 * people who pay for a workspace (SDK 1.102, WHIT-985), through
 * {@see BillingEmails}.
 *
 * Declaring is not enough: the operator grants it too, by adding the plugin
 * to the global setting `plugins.billing_email_senders`. Nothing is granted by
 * default.
 */
interface PluginBillingEmailsInterface
{
    /**
     * Why the plugin sends billing emails, in a sentence the operator reads
     * before granting it (for example "Receipts and failed payments for the
     * plans this instance sells").
     */
    public function billingEmailsPurpose(): string;
}
