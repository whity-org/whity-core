<?php

declare(strict_types=1);

namespace Whity\Sdk\Usage;

/**
 * A workspace's usage meters, read-only, for a plugin (SDK 1.133, section 33 §5).
 *
 * A host service, resolved from the container:
 *
 *     $usage = \Whity\app(\Whity\Sdk\Usage\Usage::class);
 *     foreach ($usage?->meters($tenantId) ?? [] as $meter) {
 *         if ($meter->isOver()) { ... }
 *     }
 *
 * What it shows is what the workspace's owners see on Billing > Usage: seats,
 * customers, items, invoices, storage, messages (by channel), agents (runs and
 * tool calls) and API calls, each with what was used, its base, and whether it
 * is near or past it.
 *
 * NOTHING IS REFUSED. Past a base is a warning only (BM.4): a plugin must not
 * stop work or hide a feature because a meter is over. Use it to say so, or to
 * spend less. A base of null means no limit, which is every meter on a
 * self-hosted install.
 *
 * WHICH WORKSPACE. Pass the workspace the caller is already authorized in: a
 * route handler's `Request::getWorkspaceId()`, an MCP tool's
 * `McpToolContext::$tenantId`, a job's own workspace. Never a workspace id
 * from a request body. Show it only to people who may see Billing
 * (`billing:view`).
 *
 * Absent (null from the container) on a host older than 1.133; check before use.
 */
interface Usage
{
    /** Meter names, as {@see UsageMeter::$meter} carries them. */
    public const SEATS = 'seats';
    public const CUSTOMERS = 'customers';
    public const ITEMS = 'inventory.items';
    public const INVOICES = 'invoices';
    public const STORAGE = 'storage.bytes';
    public const MESSAGES = 'messages.outbound';
    public const AGENTS = 'agents';
    public const API_CALLS = 'api.calls';

    /**
     * Every meter for one month, in the order Billing shows them.
     *
     * @param string|null $period `YYYY-MM` in the workspace's own time zone; null for this month.
     * @return list<UsageMeter>
     * @throws \InvalidArgumentException when $period is not written `YYYY-MM`
     */
    public function meters(int $tenantId, ?string $period = null): array;

    /** One meter for this month, or null for a name the host does not know. */
    public function meter(int $tenantId, string $meter): ?UsageMeter;
}
