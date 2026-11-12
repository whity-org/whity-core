<?php

declare(strict_types=1);

namespace Whity\Sdk\Mail;

use Whity\Sdk\PluginBillingEmailsInterface;
use Whity\Sdk\PluginInterface;

/**
 * Whity's own email to the people who pay for a workspace (SDK 1.102, WHIT-985).
 *
 * A host service, resolved from the container:
 *
 *     $billing = \Whity\app(\Whity\Sdk\Mail\BillingEmails::class);
 *     $billing?->send($this, $tenantId, 'Your Whity receipt for November', new WorkspaceEmail(...));
 *
 * For what the instance tells a workspace about its own plan with the
 * instance (EM.1 `payment-failed` and `whity-receipt`), not what a workspace
 * tells its customers (that is {@see WorkspaceEmails}). So it is drawn in the
 * INSTANCE's brand, in the workspace's language, and it goes to the
 * workspace's active members who can pay for its plan (`billing:pay`). The
 * plugin names the workspace, never an address.
 *
 * OPERATOR-GRANTED. Mail in the instance's brand to any workspace's payers is
 * a phishing path, so only a plugin that declares it
 * ({@see PluginBillingEmailsInterface}) and that the operator has granted
 * (the global setting `plugins.billing_email_senders`, by the exact name the
 * plugin was loaded under) may send. Everyone
 * else is refused: {@see isAllowed()} says which, and {@see send()} sends
 * nothing. Pass the plugin itself (`$this`): the host knows it by how it was
 * loaded, not by the name it reports.
 *
 * The one button, if any, must open this app ({@see billingUrl()} or below
 * the same address), and the words may name no other web address or domain
 * (mail clients make them clickable); such an email is refused.
 *
 * CAPPED. A workspace gets at most a few of these a day, whoever sends them,
 * so a bug can't flood its payers. A send over the cap is refused like any
 * other.
 *
 * BEST EFFORT. `send()` returns how many people it reached, 0 when it sent
 * nothing, and the host logs why; it never throws.
 *
 * Registered in the web process, where billing notices arrive; absent (null
 * from the container) in the queue worker and on a host older than 1.102.
 * Check before use.
 */
interface BillingEmails
{
    /**
     * Send to the workspace's members who hold `billing:pay`.
     *
     * @return int how many addresses the mail transport accepted
     */
    public function send(PluginInterface $from, int $tenantId, string $subject, WorkspaceEmail $email): int;

    /** Whether this plugin may send: it declares {@see PluginBillingEmailsInterface} and the operator granted it. */
    public function isAllowed(PluginInterface $from): bool;

    /** The workspace's language, 'en' or 'ar', for the subject line a plugin writes. */
    public function language(int $tenantId): string;

    /** The workspace's Billing page in the app (https), for the email's button. */
    public function billingUrl(int $tenantId): string;
}
