<?php

declare(strict_types=1);

namespace Whity\Sdk\Mail;

/**
 * Send an email as a workspace (SDK 1.99, WHIT-978).
 *
 * A host service, resolved from the container:
 *
 *     $emails = \Whity\app(\Whity\Sdk\Mail\WorkspaceEmails::class);
 *     $emails?->send($tenantId, 'customer@example.com', 'Invoice INV-000042', new WorkspaceEmail(...));
 *
 * The email goes out through the instance's mail transport, in the
 * workspace's brand (name, colour, white-label by plan) and in the EM.1
 * layout, in English or Arabic. It is how a plugin sends what a business
 * sends its customers (Front Office's invoices, quotes, receipts and payment
 * reminders; Whity Pay's receipts) without drawing HTML of its own.
 *
 * WHICH WORKSPACE. Pass the workspace the caller is already authorized in:
 * a route handler's `Request::getWorkspaceId()`, an MCP tool's
 * `McpToolContext::$tenantId`. Never a workspace id from the request body.
 *
 * BEST EFFORT. `send()` returns false and the host logs why, rather than
 * throwing: an email is a side channel and must not undo the sale that
 * caused it. A caller that must know records the result.
 *
 * Absent (null from the container) on a host older than 1.99; check before
 * use.
 */
interface WorkspaceEmails
{
    /** True when the instance's mail transport accepted the email. */
    public function send(int $tenantId, string $to, string $subject, WorkspaceEmail $email): bool;

    /** The workspace's language, 'en' or 'ar', for the subject line a plugin writes. */
    public function language(int $tenantId): string;
}
