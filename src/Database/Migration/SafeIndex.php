<?php

declare(strict_types=1);

namespace Whity\Database\Migration;

use PDO;
use Whity\Database\Database;

/**
 * Build or drop an index without blocking writes to the table.
 *
 * On PostgreSQL this is CREATE INDEX CONCURRENTLY: reads and writes carry on
 * while it builds. A concurrent build that fails (a duplicate for a UNIQUE
 * index, a cancelled statement) leaves an INVALID index behind, which
 * `IF NOT EXISTS` would then happily skip; so an invalid index of the same name
 * is dropped first and the build is retried from scratch. That keeps the
 * migration safe to run again.
 *
 * On SQLite (the test engine) it is a plain CREATE INDEX IF NOT EXISTS.
 *
 * Must not run inside a transaction: PostgreSQL refuses CONCURRENTLY there.
 *
 *     SafeIndex::create($db, 'idx_bookings_tenant_start', 'bookings', 'tenant_id, starts_at');
 *     SafeIndex::create($db, 'uq_x', 'x', 'tenant_id, ref', unique: true, where: 'ref IS NOT NULL');
 */
final class SafeIndex
{
    public static function create(
        Database $db,
        string $name,
        string $table,
        string $columns,
        bool $unique = false,
        string $where = '',
        string $using = '',
    ): void {
        $pdo = $db->getPdo();
        $kind = $unique ? 'UNIQUE INDEX' : 'INDEX';
        $method = $using !== '' ? " USING {$using}" : '';
        $predicate = $where !== '' ? " WHERE {$where}" : '';

        if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            $db->exec("CREATE {$kind} IF NOT EXISTS {$name} ON {$table}{$method} ({$columns}){$predicate}");

            return;
        }

        if ($pdo->inTransaction()) {
            throw new \LogicException("SafeIndex::create({$name}) can't run inside a transaction: CREATE INDEX CONCURRENTLY refuses to.");
        }

        if (self::isInvalid($pdo, $name)) {
            $db->exec("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
        }

        // The build may take long on a big table, and only reads its own
        // table's rows meanwhile: no statement timeout for it. The lock
        // timeout stays, for the brief lock it takes at the start and end.
        $previous = (string) Baseline::scalar($pdo, 'SHOW statement_timeout');
        $db->exec('SET statement_timeout = 0');
        try {
            $db->exec("CREATE {$kind} CONCURRENTLY IF NOT EXISTS {$name} ON {$table}{$method} ({$columns}){$predicate}");
        } finally {
            $db->exec('SET statement_timeout = ' . $pdo->quote($previous));
        }
    }

    public static function drop(Database $db, string $name): void
    {
        $pdo = $db->getPdo();
        if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql' || $pdo->inTransaction()) {
            $db->exec("DROP INDEX IF EXISTS {$name}");

            return;
        }

        $db->exec("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
    }

    private static function isInvalid(PDO $pdo, string $name): bool
    {
        $stmt = $pdo->prepare(
            'SELECT CASE WHEN i.indisvalid THEN 0 ELSE 1 END
             FROM pg_index i
             JOIN pg_class c ON c.oid = i.indexrelid
             JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE c.relname = :name AND n.nspname = current_schema()'
        );
        $stmt->execute([':name' => $name]);
        $invalid = $stmt->fetchColumn();
        $stmt->closeCursor();

        // An integer, not the boolean: drivers spell booleans differently.
        return $invalid !== false && (int) $invalid === 1;
    }
}
