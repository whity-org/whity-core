<?php

declare(strict_types=1);

namespace Whity\Mcp\Tools;

use PDO;
use Whity\Core\Sandbox\SideEffectGuard;

/**
 * Runs plugin code that must not write (WHIT-991): a proposal preview.
 *
 * The code runs inside a transaction that is always rolled back, so nothing it
 * writes through the shared connection survives. On PostgreSQL the transaction
 * is also READ ONLY, so a write is refused outright rather than undone. Inside a
 * transaction someone else opened, it runs inside a savepoint that is rolled back.
 *
 * Effects a rollback can't undo are refused while it runs: mail, notifications,
 * queued jobs and hook events ({@see SideEffectGuard}). And it has a time limit:
 * on PostgreSQL each statement is cut off at the limit, and anything that takes
 * longer overall is thrown away as if it had failed. PHP can't interrupt plain
 * code in a worker, so a preview that loops without the database still runs
 * to the end; its result is then discarded.
 *
 * Worker-safe: holds only the PDO.
 */
final class ReadOnlyRun
{
    private const SAVEPOINT = 'whity_read_only_run';

    /** The time limit, in milliseconds. */
    public const DEFAULT_LIMIT_MS = 2000;

    public function __construct(
        private readonly PDO $db,
        private readonly int $limitMs = self::DEFAULT_LIMIT_MS,
    ) {
    }

    /**
     * @template T
     * @param callable(): T $run
     * @return T
     */
    public function __invoke(callable $run): mixed
    {
        $started = hrtime(true);
        $result  = SideEffectGuard::blocking('a proposal preview', fn (): mixed => $this->inTransaction($run));
        $tookMs  = intdiv(hrtime(true) - $started, 1_000_000);
        if ($tookMs > $this->limitMs) {
            throw new \RuntimeException("took {$tookMs} ms, over the {$this->limitMs} ms limit");
        }

        return $result;
    }

    /**
     * @template T
     * @param callable(): T $run
     * @return T
     */
    private function inTransaction(callable $run): mixed
    {
        if ($this->db->inTransaction()) {
            $this->db->exec('SAVEPOINT ' . self::SAVEPOINT);
            try {
                return $run();
            } finally {
                $this->db->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
                $this->db->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            }
        }

        $this->db->beginTransaction();
        try {
            if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
                $this->db->exec('SET TRANSACTION READ ONLY');
                $this->db->exec('SET LOCAL statement_timeout = ' . max(1, $this->limitMs));
            }

            return $run();
        } finally {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
        }
    }
}
