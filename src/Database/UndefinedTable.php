<?php

declare(strict_types=1);

namespace Whity\Database;

use PDOException;

/**
 * Whether a query failed because one particular table does not exist yet.
 *
 * During an upgrade the new code starts before `migrate run` has finished, and
 * on a container deployment it has to: migrations run inside the container, so a
 * worker that dies at boot on a table the release adds leaves nothing to run them
 * in. Code reached while booting uses this to answer "nothing here yet" for a
 * table the release adds.
 *
 * It asks about a named table on purpose. A lost connection, a permission error,
 * a lock timeout, or a different table being gone are real faults and must still
 * be raised.
 */
final class UndefinedTable
{
    private const PG_UNDEFINED_TABLE = '42P01';

    public static function is(PDOException $e, string $table): bool
    {
        $message = $e->getMessage();
        if ((string) $e->getCode() === self::PG_UNDEFINED_TABLE) {
            return stripos($message, '"' . $table . '"') !== false;
        }

        return preg_match('/no such table: (?:main\.)?' . preg_quote($table, '/') . '\b/i', $message) === 1;
    }
}
