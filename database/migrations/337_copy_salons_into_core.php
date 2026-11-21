<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Core\Calendar\SalonsCopy;
use Whity\Database\Database;

/**
 * CopySalonsIntoCore — the retired Salons plugin's walk-in queue and
 * commission rates move into core (WHIT-1334, design section 35 §6, register
 * SL.9). On app.whity.dev that is the salon demo workspaces.
 *
 * Copies `salons_walkins` into `calendar_walkins` (keyed by `legacy_id`) and
 * `salons_commission_rates` into `calendar_commission_rates`, gives
 * `calendar:front_desk` and `calendar:commission` to every role that held the
 * Salons permission, and turns walk-ins on where Salons was used. All in
 * {@see SalonsCopy}, which `whity-cli calendar:copy-salons` runs again before
 * the plugin is removed.
 *
 * Idempotent; nothing happens where Salons never ran. down() leaves the copy:
 * the rows are people's data now, and Salons' own tables were never touched.
 */
final class CopySalonsIntoCore
{
    public static function up(Database $db): void
    {
        (new SalonsCopy($db->getPdo()))->run();
    }

    public static function down(Database $db): void
    {
        // Nothing to undo: the copied rows are the workspace's own now.
    }
}
