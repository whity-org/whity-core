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
     * Call one of Taskaat's record tools (`list_boards`, `get_board`, `list_task_records`,
     * `get_task_record`), which answer with `structuredContent` for apps that render Taskaat.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     * @throws TaskaatException When not connected, unreachable, refused, or Taskaat is too old to know the tool.
     */
    public function callJson(int $tenantId, string $tool, array $arguments): array
    {
        $token = $this->connection->accessToken($tenantId);
        if ($token === null) {
            throw new TaskaatException($this->connection->isConnected($tenantId)
                ? 'The Taskaat connection has lapsed. Someone who manages tasks needs to connect Taskaat again.'
                : 'This workspace is not connected to Taskaat yet.', 409);
        }

        $response = $this->post($token, $tool, $arguments);
        if (self::isUnauthenticated($response)) {
            $token = $this->connection->accessToken($tenantId, true);
            if ($token === null) {
                throw new TaskaatException('The Taskaat connection has lapsed. Someone who manages tasks needs to connect Taskaat again.', 409);
            }
            $response = $this->post($token, $tool, $arguments);
        }
        if (!is_array($response)) {
            throw new TaskaatException('Taskaat is not answering. Try again in a minute.', 502);
        }

        $result = is_array($response['result'] ?? null) ? $response['result'] : null;
        $structured = is_array($result['structuredContent'] ?? null) ? $result['structuredContent'] : null;
        if ($result !== null && ($result['isError'] ?? false) !== true && $structured !== null) {
            /** @var array<string, mixed> $structured */
            return $structured;
        }

        $text = '';
        foreach (is_array($result['content'] ?? null) ? $result['content'] : [] as $block) {
            if (is_array($block) && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }
        $rpcError = is_array($response['error'] ?? null) ? $response['error'] : null;
        if ($rpcError !== null) {
            $text = is_string($rpcError['message'] ?? null) ? $rpcError['message'] : 'Taskaat refused the request.';
        }
        if (($rpcError['code'] ?? null) === -32601 || stripos($text, 'unknown tool') !== false) {
            // An older Taskaat that doesn't know the record tools yet.
            error_log('[Taskaat] ' . $tool . ' unavailable: ' . (is_array($response['error'] ?? null) ? (string) ($response['error']['message'] ?? '') : $text));
            throw new TaskaatException('Taskaat needs an update before tasks can show here. Open them in Taskaat meanwhile.', 501);
        }
        // A refusal Taskaat wrote for a person (a gate, a missing task): pass it on as it came.
        throw new TaskaatException($text !== '' ? self::plain($text) : 'Taskaat refused the request.', 422);
    }

    /** Taskaat prefixes its refusals with "Error: "; the person doesn't need that. */
    private static function plain(string $text): string
    {
        $text = trim((string) preg_replace('/^Error:\s*/', '', $text));

        return mb_strlen($text) > 500 ? mb_substr($text, 0, 500) . '…' : $text;
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
