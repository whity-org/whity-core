<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * When an operator rejected a self-service registration (#408).
 *
 * Rejecting a registration sets its owner membership to 'suspended', which is
 * also what a member suspended for cause looks like. Without a marker the two
 * could not be told apart, so a rejected registration could never be approved
 * after all: widening approve to 'suspended' would have let it reactivate any
 * suspended member. RegistrationsApiHandler::reject() stamps this column and
 * approve() accepts a suspended row only when it is set (and clears it).
 *
 * NULL for every existing row: an older rejection cannot be told apart from a
 * suspension now, so it stays as it is. Additive, idempotent and reversible.
 */
class AddRejectedAtToMemberships
{
    public static function up(Database $db): void
    {
        $db->exec('ALTER TABLE memberships ADD COLUMN IF NOT EXISTS rejected_at TIMESTAMP');
    }

    public static function down(Database $db): void
    {
        $db->exec('ALTER TABLE memberships DROP COLUMN IF EXISTS rejected_at');
    }
}
