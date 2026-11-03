<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * AddFormMemberAccess — a per-form switch that lets a workspace's members fill it in.
 *
 * THE PROBLEM. On a default install only `admin` holds any `forms:*` slug. Migration
 * 128 gave `forms:submit` to holders of `documents:read`, assuming that meant
 * everybody who works with the records, and migration 060 gave that to `admin` alone.
 * The base `user` role holds no permissions at all, so no capability identifies an
 * ordinary member, and granting to a role by name is what #834 forbids.
 *
 * WHY A SWITCH AND NOT A GRANT. Giving every member `forms:submit` would let them
 * open and submit every published form in every existing workspace the moment this
 * shipped, with no way to keep a form to some of them. The owner chose the switch.
 *
 * WHO GAINS WHAT ON UPGRADE: NOBODY. The column is FALSE on every existing row and no
 * role or permission changes. It becomes true for one form only because somebody
 * holding `forms:manage` opened it, and {@see \Whity\Api\FormsApiHandler} records who
 * and when in the other two columns, as migration 132 does for the public link.
 *
 * Additive and idempotent; down() drops the three columns.
 */
final class AddFormMemberAccess
{
    public static function up(Database $db): void
    {
        $db->exec('ALTER TABLE forms ADD COLUMN IF NOT EXISTS open_to_members BOOLEAN NOT NULL DEFAULT FALSE');
        $db->exec('ALTER TABLE forms ADD COLUMN IF NOT EXISTS members_opened_at TIMESTAMP');
        $db->exec(
            'ALTER TABLE forms ADD COLUMN IF NOT EXISTS members_opened_by_profile_id INTEGER
                 REFERENCES profiles(id) ON DELETE SET NULL'
        );
    }

    public static function down(Database $db): void
    {
        $db->exec('ALTER TABLE forms DROP COLUMN IF EXISTS members_opened_by_profile_id');
        $db->exec('ALTER TABLE forms DROP COLUMN IF EXISTS members_opened_at');
        $db->exec('ALTER TABLE forms DROP COLUMN IF EXISTS open_to_members');
    }
}
