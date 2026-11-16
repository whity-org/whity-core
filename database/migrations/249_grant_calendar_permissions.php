<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * GrantCalendarPermissions — the three calendar slugs and who holds them
 * (WHIT-941, ADR 0017).
 *
 * Granted BY CAPABILITY, the shape migration 110 set and
 * ci-grant-by-role-name-guard enforces: never to a role by name, so a
 * deployment that renamed its administrative role still gets them.
 *
 * - `calendar:read` and `calendar:write` go to every role holding
 *   `documents:read`, the anchor migration 128 uses for `forms:submit`
 *   because every staff role holds it. A calendar is something the whole team
 *   uses: seeing the day, keeping one's own events, booking a customer in.
 * - `calendar:manage` goes to every role holding `settings:write` or
 *   `roles:write`: services, everyone's hours and time off, the booking page.
 *   Both anchors are held by the administrative role on every install, so the
 *   administrator holds all three after upgrade.
 *
 * Direct grants only, as migration 110 explains.
 */
final class GrantCalendarPermissions
{
    /** @var array<string, string> */
    private const PERMISSIONS = [
        CorePermissions::CALENDAR_READ => 'See the team\'s calendars, bookings and services',
        CorePermissions::CALENDAR_WRITE => 'Keep your own calendar events, and book, move and cancel customer bookings',
        CorePermissions::CALENDAR_MANAGE => 'Set up services, everyone\'s working hours and time off, and the public booking page',
    ];

    /** @var array<string, list<string>> */
    private const AUDIENCES = [
        CorePermissions::CALENDAR_READ => [CorePermissions::DOCUMENTS_READ, CorePermissions::SETTINGS_WRITE],
        CorePermissions::CALENDAR_WRITE => [CorePermissions::DOCUMENTS_READ, CorePermissions::SETTINGS_WRITE],
        CorePermissions::CALENDAR_MANAGE => [CorePermissions::SETTINGS_WRITE, CorePermissions::ROLES_WRITE],
    ];

    public static function up(Database $db): void
    {
        foreach (self::PERMISSIONS as $name => $description) {
            $db->query(
                'INSERT INTO permissions (name, description, created_at)
                 VALUES (:name, :description, NOW())
                 ON CONFLICT (name) DO NOTHING',
                [':name' => $name, ':description' => $description]
            );
        }

        foreach (self::AUDIENCES as $slug => $audiencePermissions) {
            $permissionId = self::permissionId($db, $slug);
            if ($permissionId === null) {
                continue;
            }
            foreach (self::rolesHoldingAny($db, $audiencePermissions) as $roleId) {
                $db->query(
                    'INSERT INTO role_permissions (role_id, permission_id, created_at)
                     VALUES (:role_id, :permission_id, NOW())
                     ON CONFLICT (role_id, permission_id) DO NOTHING',
                    [':role_id' => $roleId, ':permission_id' => $permissionId]
                );
            }
        }
    }

    public static function down(Database $db): void
    {
        foreach (self::AUDIENCES as $slug => $audiencePermissions) {
            $permissionId = self::permissionId($db, $slug);
            if ($permissionId === null) {
                continue;
            }
            foreach (self::rolesHoldingAny($db, $audiencePermissions) as $roleId) {
                $db->query(
                    'DELETE FROM role_permissions WHERE role_id = :role_id AND permission_id = :permission_id',
                    [':role_id' => $roleId, ':permission_id' => $permissionId]
                );
            }
        }

        foreach (array_keys(self::PERMISSIONS) as $name) {
            $db->query(
                'DELETE FROM permissions
                  WHERE name = :name
                    AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.permission_id = permissions.id)',
                [':name' => $name]
            );
        }
    }

    /**
     * @param list<string> $permissions
     * @return list<int>
     */
    private static function rolesHoldingAny(Database $db, array $permissions): array
    {
        $roleIds = [];
        foreach ($permissions as $permission) {
            $rows = $db->query(
                'SELECT rp.role_id
                   FROM role_permissions rp
                   JOIN permissions p ON p.id = rp.permission_id
                  WHERE p.name = :name',
                [':name' => $permission]
            )->fetchAll();
            if ($rows === false) {
                continue;
            }
            foreach ($rows as $row) {
                $roleIds[(int) $row['role_id']] = true;
            }
        }

        return array_map('intval', array_keys($roleIds));
    }

    private static function permissionId(Database $db, string $name): ?int
    {
        $result = $db->query('SELECT id FROM permissions WHERE name = :name', [':name' => $name])->fetch();

        return $result === false ? null : (int) $result['id'];
    }
}
