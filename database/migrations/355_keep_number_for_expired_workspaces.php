<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * KeepNumberForExpiredWorkspaces — a placeholder that changes nothing.
 *
 * Number 355 was reserved for #2432 (WHIT-1362, expired workspaces become
 * read-only), which merged without a migration, and no branch holds it.
 * Migration numbers must stay contiguous below the newest ten
 * (MigrationSchemaTest), so this file keeps the number. It creates, alters
 * and drops nothing; running it again, or rolling it back, does nothing.
 */
final class KeepNumberForExpiredWorkspaces
{
    public static function up(Database $db): void
    {
        // Nothing to do: see the class comment.
    }

    public static function down(Database $db): void
    {
        // Nothing to undo.
    }
}
