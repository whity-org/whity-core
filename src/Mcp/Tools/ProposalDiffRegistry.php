<?php

declare(strict_types=1);

namespace Whity\Mcp\Tools;

use Whity\Core\Container\HostWiredService;

/**
 * What an agent's proposal would change, by tool (WHIT-833; register 7.5a; MCP-
 * TOOL-SURFACE §5.11, proposals item 3).
 *
 * A tool can register a preview: a read-only dry run that returns what the
 * call would change. When an agent's call becomes a proposal, its preview is
 * stored as the proposal's `diff`, and the approvals card shows it, so the
 * person sees the effect before approving. A tool without one stores only its
 * arguments.
 *
 * A preview never blocks a proposal: one that throws or returns nothing
 * leaves the proposal without a diff.
 *
 * Registered while the worker boots and read-only after that: worker-safe.
 */
final class ProposalDiffRegistry implements HostWiredService
{
    /** @var array<string, callable(array<string, mixed>, int): ?array<string, mixed>> */
    private array $previews = [];

    /**
     * @param callable(array<string, mixed> $arguments, int $tenantId): ?array<string, mixed> $preview
     */
    public function register(string $tool, callable $preview): void
    {
        $this->previews[$tool] = $preview;
    }

    /**
     * The tool's preview of a call, or null when it has none or it failed.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>|null
     */
    public function diffFor(string $tool, array $arguments, int $tenantId): ?array
    {
        $preview = $this->previews[$tool] ?? null;
        if ($preview === null) {
            return null;
        }
        try {
            $diff = $preview($arguments, $tenantId);
        } catch (\Throwable $e) {
            error_log("[mcp] proposal preview for {$tool} failed (tenant {$tenantId}): " . $e->getMessage());
            return null;
        }
        return is_array($diff) ? $diff : null;
    }
}
