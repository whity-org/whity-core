<?php

declare(strict_types=1);

namespace Whity\Mcp\Tools;

use Whity\Core\Container\HostWiredService;
use Whity\Sdk\Mcp\McpToolContext;

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
 * A plugin's authored tool declares its own `preview` (SDK 1.76, WHIT-991). The
 * host runs it through {@see ReadOnlyRun}, so nothing it writes survives, and
 * keeps only the rows the approvals card shows: `label`, `before` and `after`,
 * as short text. A plugin's preview only informs the person: it can't approve,
 * decline or change the proposal.
 *
 * A preview never blocks a proposal: one that throws or returns nothing
 * leaves the proposal without a diff.
 *
 * Registered while the worker boots and read-only after that: worker-safe.
 */
final class ProposalDiffRegistry implements HostWiredService
{
    /** Rows kept from a plugin's preview, and the length of each text in them. */
    public const MAX_ROWS = 50;
    public const MAX_TEXT = 500;

    /** @var array<string, callable(array<string, mixed>, int, ?McpToolContext): ?array<string, mixed>> */
    private array $previews = [];

    private ?AuthoredToolRegistry $authored = null;

    private ?ReadOnlyRun $readOnly = null;

    /**
     * @param callable(array<string, mixed> $arguments, int $tenantId, ?McpToolContext $context): ?array<string, mixed> $preview
     */
    public function register(string $tool, callable $preview): void
    {
        $this->previews[$tool] = $preview;
    }

    /**
     * Use the `preview` plugins declare on their authored tools, run read-only
     * (WHIT-991). Without this, plugin previews are never run.
     */
    public function useAuthoredTools(AuthoredToolRegistry $authored, ReadOnlyRun $readOnly): void
    {
        $this->authored = $authored;
        $this->readOnly = $readOnly;
    }

    /**
     * The tool's preview of a call, or null when it has none or it failed.
     *
     * @param array<string, mixed> $arguments
     * @return array<array-key, mixed>|null
     */
    public function diffFor(string $tool, array $arguments, int $tenantId, ?McpToolContext $context = null): ?array
    {
        $preview = $this->previews[$tool] ?? null;
        if ($preview !== null) {
            try {
                $diff = $preview($arguments, $tenantId, $context);
            } catch (\Throwable $e) {
                error_log("[mcp] proposal preview for {$tool} failed (tenant {$tenantId}): " . $e->getMessage());
                return null;
            }
            return is_array($diff) ? $diff : null;
        }

        return $this->pluginDiff($tool, $arguments, $tenantId, $context);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return list<array{label: string, before?: string, after: string}>|null
     */
    private function pluginDiff(string $tool, array $arguments, int $tenantId, ?McpToolContext $context): ?array
    {
        $declared = $this->authored?->get($tool);
        $preview  = $declared['preview'] ?? null;
        if ($declared === null || !is_callable($preview) || $this->readOnly === null || $context === null || $context->tenantId !== $tenantId) {
            return null;
        }
        try {
            $diff = ($this->readOnly)(static fn (): mixed => $preview($arguments, $context));
        } catch (\Throwable $e) {
            error_log("[mcp] plugin '{$declared['plugin']}' preview for {$tool} failed (tenant {$tenantId}): " . $e->getMessage());
            return null;
        }

        return self::rows($diff);
    }

    /**
     * Only what the approvals card shows: a list of rows with a `label`, an
     * optional `before` and an `after`, each as short text.
     *
     * @return list<array{label: string, before?: string, after: string}>|null
     */
    public static function rows(mixed $diff): ?array
    {
        if (!is_array($diff) || !array_is_list($diff)) {
            return null;
        }
        $rows = [];
        foreach (array_slice($diff, 0, self::MAX_ROWS) as $row) {
            if (!is_array($row) || !is_string($row['label'] ?? null) || trim($row['label']) === '' || !array_key_exists('after', $row)) {
                continue;
            }
            $kept = ['label' => self::text($row['label'])];
            if (array_key_exists('before', $row) && $row['before'] !== null) {
                $kept['before'] = self::text($row['before']);
            }
            $kept['after'] = self::text($row['after']);
            $rows[] = $kept;
        }

        return $rows === [] ? null : $rows;
    }

    private static function text(mixed $value): string
    {
        $text = match (true) {
            is_string($value)            => $value,
            is_bool($value)              => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            $value === null              => '',
            default                      => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };

        return mb_substr($text, 0, self::MAX_TEXT);
    }
}
