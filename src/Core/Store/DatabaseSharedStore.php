<?php

declare(strict_types=1);

namespace Whity\Core\Store;

use PDO;

/**
 * PostgreSQL-backed (SQLite-compatible) SharedStoreInterface implementation.
 *
 * All writes use a single atomic UPSERT so concurrent FrankenPHP workers never
 * race on the same counter. The SQL dialect is compatible with both PostgreSQL
 * (production) and SQLite 3.35+ (integration tests via SchemaFromMigrations).
 *
 * Fixed-window semantics: the TTL is set on the first increment that creates a
 * key and is never extended by subsequent increments on the same window. Expired
 * rows are reset (counter → 1, fresh TTL) on the next increment that arrives
 * after expiry.
 */
final class DatabaseSharedStore implements SharedStoreInterface
{
    /**
     * @param bool $relaxedCounterCommits Commit {@see increment()} without waiting
     *        for the WAL flush (PostgreSQL `synchronous_commit = off`, scoped to
     *        that one statement). For counters whose loss on a database crash is
     *        harmless — the request rate limiter, which writes on EVERY request —
     *        and never for counters used as a once-only lock (the billing event
     *        ledger, the scheduler tick): those keep the default. On a server
     *        crash the last few hundred milliseconds of relaxed increments may be
     *        lost, which for a rate limiter means a window counts a little low.
     *        Measured on the perf stack (WHIT-1229): an upsert went from 2-4 ms
     *        to ~0.4 ms, and the platform-wide counter row is locked for that
     *        much less time by every request in the deployment.
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly bool $relaxedCounterCommits = false,
    ) {
    }

    public function increment(string $key, int $ttlSeconds): int
    {
        $expiresAt = date('Y-m-d H:i:s', time() + $ttlSeconds);

        // Single atomic UPSERT:
        //   • New key → insert with counter = 1 and the supplied TTL.
        //   • Existing key, not expired → increment counter, keep original expiry.
        //   • Existing key, expired → reset counter to 1, apply fresh TTL.
        // Relaxed: the same upsert, fed from a CTE that turns off the WAL-flush
        // wait for THIS statement's implicit transaction only (set_config(...,
        // true) is transaction-local, so nothing leaks into the session). Only
        // outside an explicit transaction — inside one it would relax the
        // caller's whole commit, which is not this store's call to make.
        $relaxed = $this->relaxedCounterCommits
            && !$this->pdo->inTransaction()
            && $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
        $values = $relaxed
            ? "WITH relax AS (SELECT set_config('synchronous_commit', 'off', true))
            INSERT INTO shared_store (store_key, counter, expires_at, updated_at)
            SELECT :key, 1, CAST(:expires_at AS TIMESTAMP), CURRENT_TIMESTAMP FROM relax"
            : "INSERT INTO shared_store (store_key, counter, expires_at, updated_at)
            VALUES (:key, 1, :expires_at, CURRENT_TIMESTAMP)";

        $stmt = $this->pdo->prepare($values . "
            ON CONFLICT (store_key) DO UPDATE SET
                counter    = CASE
                                 WHEN shared_store.expires_at IS NOT NULL
                                  AND shared_store.expires_at <= CURRENT_TIMESTAMP
                                 THEN 1
                                 ELSE shared_store.counter + 1
                             END,
                expires_at = CASE
                                 WHEN shared_store.expires_at IS NOT NULL
                                  AND shared_store.expires_at <= CURRENT_TIMESTAMP
                                 THEN EXCLUDED.expires_at
                                 ELSE shared_store.expires_at
                             END,
                updated_at = CURRENT_TIMESTAMP
            RETURNING counter
        ");
        $stmt->execute([':key' => $key, ':expires_at' => $expiresAt]);

        $row = $stmt->fetch(PDO::FETCH_NUM);
        return (int) ($row[0] ?? 1);
    }

    public function decrement(string $key): int
    {
        // Single atomic statement, like increment(): the CASE floors the counter
        // at zero in the same round trip, so two concurrent releases can never
        // drive the window negative. `expires_at` is deliberately NOT touched —
        // a refund belongs to the window that charged the unit.
        $stmt = $this->pdo->prepare("
            UPDATE shared_store
               SET counter    = CASE WHEN counter > 0 THEN counter - 1 ELSE 0 END,
                   updated_at = CURRENT_TIMESTAMP
             WHERE store_key = :key
               AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)
            RETURNING counter
        ");
        $stmt->execute([':key' => $key]);

        $row = $stmt->fetch(PDO::FETCH_NUM);

        // No row matched: the key is missing or its window already elapsed —
        // nothing to refund, and resurrecting it would be worse than a no-op.
        return $row === false ? 0 : (int) $row[0];
    }

    public function count(string $key): int
    {
        $stmt = $this->pdo->prepare("
            SELECT counter
              FROM shared_store
             WHERE store_key = :key
               AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)
        ");
        $stmt->execute([':key' => $key]);

        $row = $stmt->fetch(PDO::FETCH_NUM);
        return $row === false ? 0 : (int) $row[0];
    }

    public function ttl(string $key): int
    {
        $stmt = $this->pdo->prepare('SELECT expires_at FROM shared_store WHERE store_key = :key');
        $stmt->execute([':key' => $key]);

        $row = $stmt->fetch(PDO::FETCH_NUM);
        if ($row === false || $row[0] === null) {
            return 0;
        }

        // Compute remaining against PHP's clock — the same basis increment() used
        // to write expires_at (date('Y-m-d H:i:s', time() + ttl)) — so the result
        // is independent of any DB-session timezone offset.
        $expiresAt = strtotime((string) $row[0]);
        if ($expiresAt === false) {
            return 0;
        }

        $remaining = $expiresAt - time();
        return $remaining > 0 ? $remaining : 0;
    }

    public function delete(string $key): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM shared_store WHERE store_key = :key');
        $stmt->execute([':key' => $key]);
    }

    public function prune(): int
    {
        $stmt = $this->pdo->prepare("
            DELETE FROM shared_store
             WHERE expires_at IS NOT NULL
               AND expires_at <= CURRENT_TIMESTAMP
        ");
        $stmt->execute();
        return $stmt->rowCount();
    }
}
