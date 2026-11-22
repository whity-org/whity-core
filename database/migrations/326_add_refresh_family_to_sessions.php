<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;
use Whity\Database\Migration\SafeIndex;

/**
 * Refresh-token reuse ends the whole session (#2264, #2191; owner 2026-11-19:
 * 30-second grace).
 *
 *   family                The session's family id. Every refresh token minted
 *                         for the session carries it as the `fam` claim, so a
 *                         superseded token, however old, still finds its session.
 *   previous_refresh_jti  The refresh jti the last rotation replaced.
 *   rotated_at            When that rotation happened. Presenting
 *                         previous_refresh_jti within 30 seconds of it is a
 *                         benign race (two tabs refreshing at once) and gets a
 *                         plain 401; any other reuse revokes the session.
 *
 * Additive and nullable: sessions recorded before this get a family on their
 * next rotation. Idempotent and reversible.
 */
class AddRefreshFamilyToSessions
{
    public static function up(Database $db): void
    {
        $db->exec('ALTER TABLE sessions ADD COLUMN IF NOT EXISTS family VARCHAR(64)');
        $db->exec('ALTER TABLE sessions ADD COLUMN IF NOT EXISTS previous_refresh_jti VARCHAR(255)');
        $db->exec('ALTER TABLE sessions ADD COLUMN IF NOT EXISTS rotated_at TIMESTAMP');
        SafeIndex::create($db, 'idx_sessions_family', 'sessions', 'family', where: 'family IS NOT NULL');
        SafeIndex::create($db, 'idx_sessions_previous_refresh_jti', 'sessions', 'previous_refresh_jti', where: 'previous_refresh_jti IS NOT NULL');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP INDEX IF EXISTS idx_sessions_previous_refresh_jti');
        $db->exec('DROP INDEX IF EXISTS idx_sessions_family');
        $db->exec('ALTER TABLE sessions DROP COLUMN IF EXISTS rotated_at');
        $db->exec('ALTER TABLE sessions DROP COLUMN IF EXISTS previous_refresh_jti');
        $db->exec('ALTER TABLE sessions DROP COLUMN IF EXISTS family');
    }
}
