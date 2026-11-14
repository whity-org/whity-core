<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Who a step actually left out (design 3.4, P1; WHIT-1023).
 *
 * WHIT-1018's `exclude_named_in` leaves a step's members out when the subject
 * names them (a committee member who supervises the project). This records,
 * on the route's step, which of the step's people were left out and through
 * which question, as a JSON list of `{profile_id, key}`, so the printed
 * approvals can say "Not counted: Dr Emad Nasser, named as co-supervisor" for
 * exactly those people and nobody the step never reached anyway.
 *
 * NULL is nobody left out, which is every step written before this migration.
 * Idempotent (IF NOT EXISTS) and reversible.
 */
final class AddRouteStepLeftOut
{
    public static function up(Database $db): void
    {
        $db->exec('ALTER TABLE document_route_steps ADD COLUMN IF NOT EXISTS left_out TEXT');
    }

    public static function down(Database $db): void
    {
        $db->exec('ALTER TABLE document_route_steps DROP COLUMN IF EXISTS left_out');
    }
}
