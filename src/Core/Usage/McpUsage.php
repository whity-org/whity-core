<?php

declare(strict_types=1);

namespace Whity\Core\Usage;

use Whity\Core\Store\SharedStoreInterface;

/**
 * What an MCP tool call adds to the usage ledger (BM.17, Q8).
 *
 * Whity never calls a model itself: agents and people's own AI reach it over
 * MCP. So one tool call is counted once, here, by who made it:
 *
 * - **An agent's call** is agent work: one `tool_calls`, and one `runs` when it
 *   starts a run. There is no run record to read, so a run is an agent's work
 *   in a window of {@see RUN_WINDOW_SECONDS} from its first call: the first
 *   call starts a run, calls inside the window belong to it, and the next call
 *   after it starts another. Model tokens are counted only when Whity supplies
 *   the model, which it does not yet, so nothing here counts tokens.
 * - **A person's call** (their own AI, a script with their MCP token) is an API
 *   call, dimension `mcp`. Bring-your-own AI counts here (Q8).
 *
 * A replayed call (the same idempotency key, answered from the stored result)
 * did no work and is not counted; the caller only calls this when a tool runs.
 */
final class McpUsage
{
    public const RUN_WINDOW_SECONDS = 1800;

    private const RUN_PREFIX = 'usage:agent-run:';

    public function __construct(
        private readonly UsageLedger $ledger,
        private readonly SharedStoreInterface $store,
    ) {
    }

    /** Returns whether this call started a run. */
    public function agentCalled(int $tenantId, string $agentPrincipalId): bool
    {
        if ($tenantId <= 0) {
            return false;
        }
        $this->ledger->add($tenantId, UsageMeters::AGENTS, 1, UsageMeters::AGENT_TOOL_CALLS);

        try {
            $calls = $this->store->increment(self::RUN_PREFIX . $tenantId . ':' . $agentPrincipalId, self::RUN_WINDOW_SECONDS);
        } catch (\Throwable $e) {
            error_log('[usage] could not tell whether an agent call starts a run: ' . $e->getMessage());

            return false;
        }
        if ($calls !== 1) {
            return false;
        }
        $this->ledger->add($tenantId, UsageMeters::AGENTS, 1, UsageMeters::AGENT_RUNS);

        return true;
    }

    public function personCalled(int $tenantId): void
    {
        $this->ledger->add($tenantId, UsageMeters::API_CALLS, 1, UsageMeters::CALLS_MCP);
    }
}
