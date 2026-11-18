<?php

declare(strict_types=1);

namespace Whity\Mcp\Tools;

use PDO;

/**
 * Idempotency keys for MCP tools/call (WHIT-931, #1798; migration 308).
 *
 * A client that sends `params._meta["dev.whity/idempotencyKey"]` with a
 * tools/call gets the call run at most once for that key, in its workspace,
 * as its principal, for that tool. The row is claimed BEFORE the call runs:
 *
 *   - the first call claims it, runs, and stores its result;
 *   - a retry while the first is still running is told it is still running;
 *   - a retry once it is done gets the stored result back;
 *   - the same key with other arguments is refused.
 *
 * A call that failed (a protocol error, or a result marked isError) releases
 * its key, so the client can try again with the same key. A call whose process
 * died leaves a running row; after {@see self::STALE_SECONDS} the next retry
 * takes it over. Rows are deleted after {@see self::LIFETIME_SECONDS} by the
 * hourly {@see McpCallKeysPruneJob}.
 */
final class McpCallKeys
{
    public const RUNNING = 'running';
    public const DONE    = 'done';

    /** Keys are kept a day; a retry later than that runs again. */
    public const LIFETIME_SECONDS = 24 * 3600;

    /** A call still "running" after this long died with its process. */
    public const STALE_SECONDS = 15 * 60;

    public const MAX_KEY_LENGTH = 255;

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Claim a key for a call about to run.
     *
     * @return array{state: 'claimed', id: int}|array{state: 'running'}|array{state: 'done', result: mixed}|array{state: 'mismatch'}
     */
    public function claim(int $tenantId, string $principal, string $tool, string $key, string $argumentsHash): array
    {
        $now    = gmdate('Y-m-d H:i:s');
        $insert = $this->db->prepare(
            'INSERT INTO mcp_call_keys (tenant_id, principal, tool, idempotency_key, arguments_hash, status, created_at)
             VALUES (:tenant_id, :principal, :tool, :key, :hash, :status, :now)
             ON CONFLICT (tenant_id, principal, tool, idempotency_key) DO NOTHING'
        );
        $insert->execute([
            ':tenant_id' => $tenantId,
            ':principal' => $principal,
            ':tool'      => $tool,
            ':key'       => $key,
            ':hash'      => $argumentsHash,
            ':status'    => self::RUNNING,
            ':now'       => $now,
        ]);
        $inserted = $insert->rowCount() === 1;

        $find = $this->db->prepare(
            'SELECT id, arguments_hash, status, result, created_at FROM mcp_call_keys
              WHERE tenant_id = :tenant_id AND principal = :principal AND tool = :tool AND idempotency_key = :key'
        );
        $find->execute([':tenant_id' => $tenantId, ':principal' => $principal, ':tool' => $tool, ':key' => $key]);
        $row = $find->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            // Released between the two statements: claim it again.
            return $this->claim($tenantId, $principal, $tool, $key, $argumentsHash);
        }
        $id = (int) $row['id'];

        if ($inserted) {
            return ['state' => 'claimed', 'id' => $id];
        }
        if (!hash_equals((string) $row['arguments_hash'], $argumentsHash)) {
            return ['state' => 'mismatch'];
        }
        if ($row['status'] === self::DONE) {
            $result = is_string($row['result']) ? json_decode($row['result'], true) : null;
            return ['state' => 'done', 'result' => $result];
        }

        // Still running. Taken over only when its process must have died, and
        // by exactly one retry: the UPDATE matches the created_at it read.
        if ((string) $row['created_at'] <= gmdate('Y-m-d H:i:s', time() - self::STALE_SECONDS)) {
            $takeOver = $this->db->prepare(
                'UPDATE mcp_call_keys SET created_at = :now
                  WHERE id = :id AND tenant_id = :tenant_id AND status = :running AND created_at = :seen'
            );
            $takeOver->execute([':now' => $now, ':id' => $id, ':tenant_id' => $tenantId, ':running' => self::RUNNING, ':seen' => $row['created_at']]);
            if ($takeOver->rowCount() === 1) {
                return ['state' => 'claimed', 'id' => $id];
            }
        }

        return ['state' => 'running'];
    }

    /** Store a finished call's result for its retries. */
    public function finish(int $id, int $tenantId, mixed $result): void
    {
        $stmt = $this->db->prepare(
            'UPDATE mcp_call_keys SET status = :done, result = :result, finished_at = :now
              WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([
            ':done'      => self::DONE,
            ':result'    => (string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':now'       => gmdate('Y-m-d H:i:s'),
            ':id'        => $id,
            ':tenant_id' => $tenantId,
        ]);
    }

    /** Forget a key whose call failed, so the same key can try again. */
    public function release(int $id, int $tenantId): void
    {
        $stmt = $this->db->prepare('DELETE FROM mcp_call_keys WHERE id = :id AND tenant_id = :tenant_id AND status = :running');
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId, ':running' => self::RUNNING]);
    }

    /** Delete keys older than $olderThanSeconds, in every workspace. Returns how many. */
    public function prune(int $olderThanSeconds = self::LIFETIME_SECONDS): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - max(0, $olderThanSeconds));
        // @tenant-guard-ignore: retention GC deletes expired keys across all tenants (system infra).
        $stmt = $this->db->prepare('DELETE FROM mcp_call_keys WHERE created_at <= :cutoff');
        $stmt->execute([':cutoff' => $cutoff]);

        return $stmt->rowCount();
    }

    /**
     * SHA-256 of the arguments in a canonical form: object keys sorted at every
     * level, so `{"a":1,"b":2}` and `{"b":2,"a":1}` are the same call. List
     * order is kept, because it can mean something.
     *
     * @param array<array-key, mixed> $arguments
     */
    public static function hashArguments(array $arguments): string
    {
        return hash('sha256', (string) json_encode(self::canonical($arguments), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $k => $v) {
            $value[$k] = self::canonical($v);
        }
        // An empty object and an empty list both arrive as []; one hash for both.
        return $value === [] ? [] : $value;
    }
}
