<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Core\RBAC\CorePermissionDescriptions;
use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * Workspace agents (WHIT-815; docs/design/AGENT-PERSONAS-AND-WHITY-AGENT.md
 * §4.2): a workspace's assistant definitions, each a persona plus the tools it
 * may use. Owner decision 2026-10-31 (Q1): workspace agents only, created by
 * people holding `agents:manage`.
 *
 * An agent is a definition, not a principal. It never acts by itself: a person
 * grants it for themselves (agent assignments, WHIT-816, migration 181), and it
 * then acts for that person under their grant, never above their RBAC.
 *
 * - `slug`: `[a-z0-9-]`, unique per tenant; names the agent in URLs and in the
 *   MCP server name a client stores.
 * - `name`/`name_ar`, `role`/`role_ar`: named by role (register 5.6b).
 * - `instructions`/`instructions_ar`: the workspace's guidance for the model,
 *   at most 4,000 characters each.
 * - `tone`: plain · formal · brief. `language`: auto · en · ar.
 * - `toolsets`: toolset ids or `prefix.*`; empty means every toolset.
 * - `grant_template`: `{permission: off|ask|allow}`, where a new assignment
 *   starts, clamped to the person when it is created.
 * - `preset_key`/`preset_version`: the preset it was copied from, if any.
 * - `status`: active · archived.
 *
 * Also registers `agents:manage`, granted to every role that may already mint
 * MCP credentials (`mcp:tokens:manage`), by capability rather than by role name
 * (the rule migration 110 set): whoever may hand an AI client a credential may
 * already decide what agents the workspace offers.
 */
class CreateAgents
{
    /** Grant to every role that already holds this, never to a role by name. */
    private const AUDIENCE_PERMISSION = CorePermissions::MCP_TOKENS_MANAGE;

    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS agents (
                id                     BIGSERIAL    NOT NULL PRIMARY KEY,
                tenant_id              INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                slug                   VARCHAR(48)  NOT NULL,
                name                   VARCHAR(60)  NOT NULL,
                name_ar                VARCHAR(60)  NOT NULL DEFAULT '',
                role                   VARCHAR(200) NOT NULL DEFAULT '',
                role_ar                VARCHAR(200) NOT NULL DEFAULT '',
                instructions           TEXT         NOT NULL DEFAULT '',
                instructions_ar        TEXT         NOT NULL DEFAULT '',
                tone                   VARCHAR(16)  NOT NULL DEFAULT 'plain',
                language               VARCHAR(8)   NOT NULL DEFAULT 'auto',
                toolsets               JSONB        NOT NULL DEFAULT '[]'::jsonb,
                grant_template         JSONB        NOT NULL DEFAULT '{}'::jsonb,
                preset_key             VARCHAR(120),
                preset_version         INTEGER,
                status                 VARCHAR(16)  NOT NULL DEFAULT 'active',
                created_by_profile_id  INTEGER      REFERENCES profiles(id) ON DELETE SET NULL,
                updated_by_profile_id  INTEGER      REFERENCES profiles(id) ON DELETE SET NULL,
                created_at             TIMESTAMP    NOT NULL DEFAULT NOW(),
                updated_at             TIMESTAMP    NOT NULL DEFAULT NOW(),
                CONSTRAINT uq_agents_tenant_slug UNIQUE (tenant_id, slug)
            )
        ");

        $db->query(
            'INSERT INTO permissions (name, description, created_at)
             VALUES (:name, :description, NOW())
             ON CONFLICT (name) DO NOTHING',
            [':name' => CorePermissions::AGENTS_MANAGE, ':description' => CorePermissionDescriptions::all()[CorePermissions::AGENTS_MANAGE]]
        );
        $permissionId = self::permissionId($db, CorePermissions::AGENTS_MANAGE);
        if ($permissionId === null) {
            return;
        }
        foreach (self::rolesHolding($db, self::AUDIENCE_PERMISSION) as $roleId) {
            $db->query(
                'INSERT INTO role_permissions (role_id, permission_id, created_at)
                 VALUES (:role_id, :permission_id, NOW())
                 ON CONFLICT (role_id, permission_id) DO NOTHING',
                [':role_id' => $roleId, ':permission_id' => $permissionId]
            );
        }
    }

    public static function down(Database $db): void
    {
        $permissionId = self::permissionId($db, CorePermissions::AGENTS_MANAGE);
        if ($permissionId !== null) {
            foreach (self::rolesHolding($db, self::AUDIENCE_PERMISSION) as $roleId) {
                $db->query(
                    'DELETE FROM role_permissions WHERE role_id = :role_id AND permission_id = :permission_id',
                    [':role_id' => $roleId, ':permission_id' => $permissionId]
                );
            }
            $db->query(
                'DELETE FROM permissions
                 WHERE name = :name
                   AND NOT EXISTS (
                       SELECT 1 FROM role_permissions rp WHERE rp.permission_id = permissions.id
                   )',
                [':name' => CorePermissions::AGENTS_MANAGE]
            );
        }
        $db->exec('DROP TABLE IF EXISTS agents');
    }

    /**
     * Role ids holding a permission, by slug.
     *
     * @return list<int>
     */
    private static function rolesHolding(Database $db, string $permission): array
    {
        $rows = $db->query(
            'SELECT rp.role_id
               FROM role_permissions rp
               JOIN permissions p ON p.id = rp.permission_id
              WHERE p.name = :name',
            [':name' => $permission]
        )->fetchAll();

        return array_values(array_unique(array_map(static fn (array $row): int => (int) $row['role_id'], $rows)));
    }

    private static function permissionId(Database $db, string $name): ?int
    {
        $result = $db->query('SELECT id FROM permissions WHERE name = :name', [':name' => $name])->fetch();

        return $result === false ? null : (int) $result['id'];
    }
}
