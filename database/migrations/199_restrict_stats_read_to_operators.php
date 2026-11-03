<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * RestrictStatsReadToOperators (WHIT-872, design 2.9 H.3).
 *
 * Server statistics belong to the platform's operators, so `stats:read` no longer
 * follows `users:read`. Migration 157 granted it to every role that could list
 * users, on the reasoning that the numbers were an aggregate of those rows. That
 * stopped being true of the page: it now shows the database, the server and the
 * migrations, which are the operator's business.
 *
 * WHAT THIS CANNOT DO, AND WHAT DOES IT INSTEAD
 * ---------------------------------------------
 * A grant can't express "operators only". The seeded `admin` is one GLOBAL role,
 * held by the system tenant's people and by every workspace's owners alike, so
 * taking `stats:read` from it would lock the operators out too, and leave a gated
 * route with no holder, which `ci-permission-holder-guard` exists to refuse. The
 * rule is therefore enforced where the tenant is known:
 * {@see \Whity\Api\AdminApiHandler::stats()} answers only in the system tenant, and
 * the System nav item is `systemTenantOnly`.
 *
 * This migration only removes the grants that can never mean anything: those on
 * roles a workspace owns (`tenant_id` set and not the system tenant). They would
 * otherwise sit in a workspace's role editor promising something the route refuses.
 * Global roles keep theirs, because any of them may be held in the system tenant.
 * Selected by which tenant owns the role, never by a role's name (#834).
 *
 * Who loses what: workspace owners lose `/admin/stats`. Nobody in the system tenant
 * loses anything. Nobody gains anything.
 *
 * Idempotent and reversible. down() gives the slug back to the audience 157 chose
 * among workspace-owned roles, and restores the description.
 */
final class RestrictStatsReadToOperators
{
    private const DESCRIPTION = 'Read the server statistics. Only takes effect in the system tenant';

    private const PREVIOUS_DESCRIPTION = 'Read the admin dashboard aggregates for the current tenant (counts, role breakdown, trends)';

    public static function up(Database $db): void
    {
        $db->query(
            'UPDATE permissions SET description = :description WHERE name = :name',
            [':description' => self::DESCRIPTION, ':name' => CorePermissions::STATS_READ]
        );
        $db->query(
            'DELETE FROM role_permissions
              WHERE permission_id IN (SELECT id FROM permissions WHERE name = :name)
                AND role_id IN (SELECT id FROM roles WHERE tenant_id IS NOT NULL AND tenant_id <> 0)',
            [':name' => CorePermissions::STATS_READ]
        );
    }

    public static function down(Database $db): void
    {
        $db->query(
            'UPDATE permissions SET description = :description WHERE name = :name',
            [':description' => self::PREVIOUS_DESCRIPTION, ':name' => CorePermissions::STATS_READ]
        );
        $db->query(
            'INSERT INTO role_permissions (role_id, permission_id, created_at)
             SELECT holder.role_id, stats.id, NOW()
               FROM role_permissions holder
               JOIN permissions users_read ON users_read.id = holder.permission_id AND users_read.name = :audience
               JOIN roles owned ON owned.id = holder.role_id AND owned.tenant_id IS NOT NULL AND owned.tenant_id <> 0
               JOIN permissions stats ON stats.name = :name
             ON CONFLICT (role_id, permission_id) DO NOTHING',
            [':audience' => CorePermissions::USERS_READ, ':name' => CorePermissions::STATS_READ]
        );
    }
}
