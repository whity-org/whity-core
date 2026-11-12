<?php

declare(strict_types=1);

namespace Whity\Core\Taskaat;

use Closure;

/**
 * Calls one Taskaat tool for one workspace.
 *
 * Taskaat's API is its MCP endpoint (`/api/mcp`, JSON-RPC over plain HTTP, stateless: no
 * initialize or session needed for `tools/call`). Its tools answer in text written for a model,
 * which is what an agent calling through Whity's MCP wants. There is no machine-readable shape
 * yet, so nothing here parses that text: Whity's own pages wait for Taskaat to return JSON
 * (notes: A10-taskaat-api-gaps).
 */
final class TaskaatClient
{
    /**
     * @param Closure(string, array<string, mixed>, array<string, string>): ?array<mixed> $postJson
     */
    public function __construct(
        private readonly TaskaatConnection $connection,
        private readonly Closure $postJson,
    ) {
    }

    public function connection(): TaskaatConnection
    {
        return $this->connection;
    }

    /**
     * Call a Taskaat tool as this workspace.
     *
     * @param array<string, mixed> $arguments
     * @return array{text: string, isError: bool}
     * @throws TaskaatException When the workspace is not connected, or Taskaat cannot be reached.
     */
    public function callTool(int $tenantId, string $tool, array $arguments): array
    {
        $token = $this->connection->accessToken($tenantId);
        if ($token === null) {
            throw new TaskaatException($this->connection->isConnected($tenantId)
                ? 'The Taskaat connection has lapsed. A workspace admin needs to connect Taskaat again.'
                : 'This workspace is not connected to Taskaat. A workspace admin can connect it on the Tasks page.', 409);
        }

        $response = $this->post($token, $tool, $arguments);
        if (self::isUnauthenticated($response)) {
            // Revoked or expired early in Taskaat: refresh once and try again.
            $token = $this->connection->accessToken($tenantId, true);
            if ($token === null) {
                throw new TaskaatException('The Taskaat connection has lapsed. A workspace admin needs to connect Taskaat again.', 409);
            }
            $response = $this->post($token, $tool, $arguments);
        }

        if (!is_array($response)) {
            throw new TaskaatException('Taskaat is not answering. Try again in a minute.', 502);
        }
        if (isset($response['error'])) {
            $message = is_array($response['error']) && is_string($response['error']['message'] ?? null)
                ? $response['error']['message'] : 'Taskaat refused the request.';

            return ['text' => 'Taskaat: ' . $message, 'isError' => true];
        }

        $result = is_array($response['result'] ?? null) ? $response['result'] : [];
        $parts = [];
        foreach (is_array($result['content'] ?? null) ? $result['content'] : [] as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $parts[] = $block['text'];
            }
        }

        return ['text' => implode("\n\n", $parts), 'isError' => ($result['isError'] ?? false) === true];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<mixed>|null
     */
    private function post(string $token, string $tool, array $arguments): ?array
    {
        try {
            return ($this->postJson)($this->connection->mcpEndpoint(), [
                'jsonrpc' => '2.0',
                'id' => bin2hex(random_bytes(8)),
                'method' => 'tools/call',
                'params' => ['name' => $tool, 'arguments' => $arguments === [] ? new \stdClass() : $arguments],
            ], [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ]);
        } catch (\Throwable $e) {
            error_log('[Taskaat] tools/call ' . $tool . ' failed: ' . $e->getMessage());

            return null;
        }
    }

    /** @param array<mixed>|null $response */
    private static function isUnauthenticated(?array $response): bool
    {
        return is_array($response) && is_array($response['error'] ?? null) && ($response['error']['code'] ?? null) === -32001;
    }
}
