<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * Team chat (CH.1, CH.2; release 0.2.29): the team's own chats, people and
 * agents together, on the Messages tables (ADR 0016).
 *
 * - `conversations.kind`: `customer` (every Messages conversation, the default)
 *   or `team`. Messages lists and opens only `customer`, so a team chat never
 *   shows in the customer inbox.
 * - `messages.chat_meta`: what a chat line carries beyond its text: the agent
 *   that wrote it, mentions, a shared proposal, a held message, a system line's
 *   event. Customer messages leave it null. Team chat lines are `note` /
 *   `noted`, so no Messages job, cancel or delivery ever touches them.
 * - `chats`: one per team conversation: group or direct, the pair key of a
 *   direct chat, and what it is linked to (a board, a meeting, a record).
 * - `chat_members`: people and agents. An agent member works for a person in
 *   the chat, reads only messages after `reads_after_message_id` (from when it
 *   joined, by default) and has a mode per action (`off`, `ask`, `allowed`).
 *   A member who leaves keeps the row with `removed_at`, so history keeps names.
 *
 * Permissions `chat:read` and `chat:write` go to every staff role (anchor
 * `documents:read`, as migration 249) and to the roles holding
 * `settings:write`. Granted by capability, never by role name.
 */
final class CreateTeamChat
{
    /** @var array<string, string> */
    private const PERMISSIONS = [
        CorePermissions::CHAT_READ => 'Read the team chats you are in',
        CorePermissions::CHAT_WRITE => 'Start team chats, write in them and add people and agents',
    ];

    /** @var list<string> */
    private const AUDIENCE = [CorePermissions::DOCUMENTS_READ, CorePermissions::SETTINGS_WRITE];

    public static function up(Database $db): void
    {
        $db->exec("ALTER TABLE conversations ADD COLUMN IF NOT EXISTS kind VARCHAR(16) NOT NULL DEFAULT 'customer'");
        $db->exec('CREATE INDEX IF NOT EXISTS conversations_kind_idx ON conversations (tenant_id, kind, last_message_at DESC, id DESC)');
        $db->exec('ALTER TABLE messages ADD COLUMN IF NOT EXISTS chat_meta JSONB');

        $db->exec("
            CREATE TABLE IF NOT EXISTS chats (
                conversation_id       BIGINT       NOT NULL PRIMARY KEY REFERENCES conversations(id) ON DELETE CASCADE,
                tenant_id             INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                type                  VARCHAR(16)  NOT NULL,
                direct_key            VARCHAR(64),
                link_type             VARCHAR(16),
                link_id               VARCHAR(64),
                link_label            VARCHAR(255),
                created_by_profile_id INTEGER      REFERENCES profiles(id) ON DELETE SET NULL,
                created_at            TIMESTAMP    NOT NULL DEFAULT NOW(),
                CONSTRAINT chats_type_check CHECK (type IN ('group', 'direct')),
                CONSTRAINT chats_link_check CHECK (link_type IS NULL OR link_type IN ('board', 'meeting', 'record'))
            )
        ");
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS chats_direct_unique ON chats (tenant_id, direct_key) WHERE direct_key IS NOT NULL');
        $db->exec('CREATE INDEX IF NOT EXISTS chats_link_idx ON chats (tenant_id, link_type, link_id)');

        $db->exec("
            CREATE TABLE IF NOT EXISTS chat_members (
                id                     BIGSERIAL    PRIMARY KEY,
                tenant_id              INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                conversation_id        BIGINT       NOT NULL REFERENCES conversations(id) ON DELETE CASCADE,
                profile_id             INTEGER      REFERENCES profiles(id) ON DELETE CASCADE,
                agent_id               BIGINT       REFERENCES agents(id) ON DELETE CASCADE,
                works_for_profile_id   INTEGER      REFERENCES profiles(id) ON DELETE CASCADE,
                reads                  VARCHAR(16)  NOT NULL DEFAULT 'all',
                -- @reference-lint-ignore: a position in the chat's message ids (read from after it), not a message; 0 means from the start
                reads_after_message_id BIGINT       NOT NULL DEFAULT 0,
                actions                JSONB        NOT NULL DEFAULT '{}',
                added_by_profile_id    INTEGER      REFERENCES profiles(id) ON DELETE SET NULL,
                -- @reference-lint-ignore: a read mark, a position in the chat's message ids, not a message; it stays valid when that message is gone
                last_read_message_id   BIGINT       NOT NULL DEFAULT 0,
                joined_at              TIMESTAMP    NOT NULL DEFAULT NOW(),
                removed_at             TIMESTAMP,
                CONSTRAINT chat_members_who_check CHECK (
                    (profile_id IS NOT NULL AND agent_id IS NULL)
                    OR (agent_id IS NOT NULL AND profile_id IS NULL AND works_for_profile_id IS NOT NULL)
                ),
                CONSTRAINT chat_members_reads_check CHECK (reads IN ('now', 'week', 'all'))
            )
        ");
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS chat_members_person_unique ON chat_members (conversation_id, profile_id) WHERE profile_id IS NOT NULL AND removed_at IS NULL');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS chat_members_agent_unique ON chat_members (conversation_id, agent_id) WHERE agent_id IS NOT NULL AND removed_at IS NULL');
        $db->exec('CREATE INDEX IF NOT EXISTS chat_members_profile_idx ON chat_members (tenant_id, profile_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS chat_members_agent_idx ON chat_members (tenant_id, agent_id, works_for_profile_id)');

        foreach (self::PERMISSIONS as $name => $description) {
            $db->query(
                'INSERT INTO permissions (name, description, created_at)
                 VALUES (:name, :description, NOW())
                 ON CONFLICT (name) DO NOTHING',
                [':name' => $name, ':description' => $description]
            );
            $permissionId = self::permissionId($db, $name);
            if ($permissionId === null) {
                continue;
            }
            foreach (self::rolesHoldingAny($db, self::AUDIENCE) as $roleId) {
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
        foreach (array_keys(self::PERMISSIONS) as $name) {
            $permissionId = self::permissionId($db, $name);
            if ($permissionId !== null) {
                $db->query('DELETE FROM role_permissions WHERE permission_id = :permission_id', [':permission_id' => $permissionId]);
                $db->query('DELETE FROM permissions WHERE id = :id', [':id' => $permissionId]);
            }
        }

        $db->exec('DROP TABLE IF EXISTS chat_members');
        $db->exec('DROP TABLE IF EXISTS chats');
        $db->exec("DELETE FROM conversations WHERE kind = 'team'");
        $db->exec('DROP INDEX IF EXISTS conversations_kind_idx');
        $db->exec('ALTER TABLE messages DROP COLUMN IF EXISTS chat_meta');
        $db->exec('ALTER TABLE conversations DROP COLUMN IF EXISTS kind');
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
