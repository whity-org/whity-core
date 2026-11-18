<?php

declare(strict_types=1);

namespace Whity\Mcp\Tools;

use Whity\Sdk\JobInterface;

/**
 * Deletes MCP idempotency keys older than a day (WHIT-931). Queued once an
 * hour by `schedule:run`.
 */
final class McpCallKeysPruneJob implements JobInterface
{
    public const NAME = 'core.mcp.call_keys_prune';

    public function __construct(private readonly McpCallKeys $keys)
    {
    }

    public function handle(array $payload): array
    {
        return ['pruned' => $this->keys->prune()];
    }
}
