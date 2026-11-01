<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * ReservedUnused: does nothing, on purpose.
 *
 * Migration numbers 170-174 were reserved for editor M4d (document themes),
 * which needed only 170-173. Migration numbers must be contiguous below the
 * newest ten (MigrationSchemaTest), because a gap far below the top usually
 * means a migration was deleted or renamed. This file fills the gap so that
 * check stays meaningful. Installs that already ran 175 and later simply
 * record this one when it first runs; there is nothing to apply or undo.
 */
class ReservedUnused
{
    public static function up(Database $db): void
    {
    }

    public static function down(Database $db): void
    {
    }
}
