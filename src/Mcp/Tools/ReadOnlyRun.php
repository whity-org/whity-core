<?php

declare(strict_types=1);

namespace Whity\Mcp\Tools;

use PDO;

/**
 * Runs plugin code that must not write (WHIT-991): a proposal preview.
 *
 * The code runs inside a transaction that is always rolled back, so nothing it
 * writes through the shared connection survives. On PostgreSQL the transaction
 * is also READ ONLY, so a write is refused outright rather than undone. Inside a
 * transaction someone else opened, it runs inside a savepoint that is rolled back.
 *
 * Worker-safe: holds only the PDO.
 */
final class ReadOnlyRun
{
    private const SAVEPOINT = 'whity_read_only_run';

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @template T
     * @param callable(): T $run
     * @return T
     */
    public function __invoke(callable $run): mixed
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
            }

            return $run();
        } finally {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
        }
    }
}
