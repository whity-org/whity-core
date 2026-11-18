<?php

declare(strict_types=1);

namespace Whity\Database\Migration;

use PDO;
use Whity\Database\Database;

/**
 * Change rows of a big table in small batches, each its own short
 * transaction, so a backfill never holds locks on (or bloats) the whole table
 * at once and other requests keep running between batches.
 *
 *     Backfill::inBatches(
 *         $db,
 *         table: 'bookings',
 *         set: "status_v2 = CASE status WHEN 'ok' THEN 'confirmed' ELSE status END",
 *         where: 'status_v2 IS NULL',
 *     );
 *
 * Batches walk the key (default `id`, which must be unique and sortable) in
 * order, so the run finishes even when `$where` stays true for a row, and a
 * re-run after a failure starts over cheaply: rows already done no longer match
 * `$where`. Write `$where` so that is true, which makes the backfill idempotent.
 *
 * Must not run inside a transaction: the point is that each batch commits.
 */
final class Backfill
{
    /**
     * @param string              $table     Table to change (an identifier, not user input).
     * @param string              $set       The SET clause, without the word SET.
     * @param string              $where     Which rows still need it (without WHERE); '' for all.
     * @param array<string,mixed> $params    Named parameters used in $set or $where.
     * @param int                 $batchSize Rows per batch.
     * @param string              $key       Unique, sortable key column.
     * @param int                 $pauseMs   Pause between batches, to let replicas and vacuum keep up.
     * @return int Rows changed.
     */
    public static function inBatches(
        Database $db,
        string $table,
        string $set,
        string $where = '',
        array $params = [],
        int $batchSize = 5000,
        string $key = 'id',
        int $pauseMs = 0,
    ): int {
        return self::run($db, $table, "UPDATE %t SET {$set} WHERE %range" . ($where !== '' ? " AND ({$where})" : ''), $params, $batchSize, $key, $pauseMs);
    }

    /**
     * Delete matching rows in batches. Same rules as {@see inBatches()}.
     *
     * @param array<string,mixed> $params
     * @return int Rows deleted.
     */
    public static function deleteInBatches(
        Database $db,
        string $table,
        string $where,
        array $params = [],
        int $batchSize = 5000,
        string $key = 'id',
        int $pauseMs = 0,
    ): int {
        return self::run($db, $table, "DELETE FROM %t WHERE %range AND ({$where})", $params, $batchSize, $key, $pauseMs);
    }

    /**
     * @param array<string,mixed> $params
     */
    private static function run(Database $db, string $table, string $template, array $params, int $batchSize, string $key, int $pauseMs): int
    {
        if ($batchSize < 1) {
            throw new \InvalidArgumentException('Batch size must be at least 1.');
        }
        $pdo = $db->getPdo();
        if ($pdo->inTransaction()) {
            throw new \LogicException('Backfill::inBatches must not run inside a transaction: each batch commits on its own.');
        }

        $t = Baseline::quoteIdent($table);
        $k = Baseline::quoteIdent($key);
        $sql = str_replace(['%t', '%range'], [$t, "{$k} > :__after AND {$k} <= :__upto"], $template);
        $change = $pdo->prepare($sql);
        $next = $pdo->prepare("SELECT {$k} FROM {$t} WHERE {$k} > :__after ORDER BY {$k} LIMIT {$batchSize}");

        $done = 0;
        $after = self::lowestKeyMinusOne($pdo, $t, $k);
        while (true) {
            $next->execute([':__after' => $after]);
            $keys = $next->fetchAll(PDO::FETCH_COLUMN);
            $next->closeCursor();
            if ($keys === []) {
                break;
            }
            $upto = end($keys);

            $change->execute([':__after' => $after, ':__upto' => $upto] + $params);
            $done += $change->rowCount();
            $after = $upto;

            if ($pauseMs > 0) {
                usleep($pauseMs * 1000);
            }
        }

        return $done;
    }

    /** A value below every key: the smallest key minus one, or 0 for an empty table. */
    private static function lowestKeyMinusOne(PDO $pdo, string $t, string $k): int|string
    {
        $min = Baseline::scalar($pdo, "SELECT MIN({$k}) FROM {$t}");
        if ($min === null || $min === false) {
            return 0;
        }

        return is_numeric($min) ? (int) $min - 1 : '';
    }
}
