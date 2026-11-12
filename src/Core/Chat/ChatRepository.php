<?php

declare(strict_types=1);

namespace Whity\Core\Chat;

use PDO;
use Whity\Core\Db\DbBool;

/**
 * Storage for team chats (migration 253): `conversations` and `messages` rows
 * of kind `team`, with `chats` and `chat_members` beside them.
 *
 * Every statement binds `tenant_id`. Chat lines are written here, not through
 * the Messages repositories, because they carry `chat_meta` and must never be
 * picked up by Messages' delivery: they are always `note` / `noted`.
 */
final class ChatRepository
{
    public const CHANNEL = 'team';

    private const CHAT_COLUMNS = 'c.id, c.tenant_id, c.subject, c.last_message_at, c.created_at,
        h.type, h.direct_key, h.link_type, h.link_id, h.link_label, h.created_by_profile_id';

    private const MEMBER_COLUMNS = 'm.id, m.conversation_id, m.profile_id, m.agent_id, m.works_for_profile_id, m.reads,
        m.reads_after_message_id, m.actions, m.added_by_profile_id, m.last_read_message_id, m.joined_at, m.removed_at,
        p.display_name AS person_name, a.name AS agent_name, a.name_ar AS agent_name_ar, a.role AS agent_role,
        a.role_ar AS agent_role_ar, w.display_name AS works_for_name, ab.display_name AS added_by_name';

    private const MESSAGE_COLUMNS = 'x.id, x.conversation_id, x.author_profile_id, x.by_agent, x.source, x.body_text,
        x.status, x.chat_meta, x.created_at, p.display_name AS author_name';

    public function __construct(private readonly PDO $db)
    {
    }

    // ── Chats ────────────────────────────────────────────────────────────────

    /** @param array{type: string, id: string, label: string}|null $link */
    public function createChat(int $tenantId, string $type, string $title, ?string $directKey, ?array $link, int $createdBy): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO conversations (tenant_id, channel, subject, status, contact_address, kind, last_message_at, created_at, updated_at)
             VALUES (:tid, :channel, :subject, 'open', :address, 'team', NOW(), NOW(), NOW())
             RETURNING id"
        );
        $stmt->execute([':tid' => $tenantId, ':channel' => self::CHANNEL, ':subject' => $title, ':address' => self::CHANNEL]);
        $id = (int) $stmt->fetchColumn();

        $this->db->prepare(
            'INSERT INTO chats (conversation_id, tenant_id, type, direct_key, link_type, link_id, link_label, created_by_profile_id, created_at)
             VALUES (:id, :tid, :type, :key, :link_type, :link_id, :link_label, :by, NOW())'
        )->execute([
            ':id' => $id,
            ':tid' => $tenantId,
            ':type' => $type,
            ':key' => $directKey,
            ':link_type' => $link['type'] ?? null,
            ':link_id' => $link['id'] ?? null,
            ':link_label' => $link['label'] ?? null,
            ':by' => $createdBy,
        ]);

        return $id;
    }

    /** @return array<string, mixed>|null */
    public function findChat(int $tenantId, int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT ' . self::CHAT_COLUMNS . ' FROM conversations c
            JOIN chats h ON h.conversation_id = c.id AND h.tenant_id = c.tenant_id
            WHERE c.tenant_id = :tid AND c.id = :id AND c.kind = \'team\'');
        $stmt->execute([':tid' => $tenantId, ':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function findDirect(int $tenantId, string $key): ?int
    {
        $stmt = $this->db->prepare('SELECT conversation_id FROM chats WHERE tenant_id = :tid AND direct_key = :key');
        $stmt->execute([':tid' => $tenantId, ':key' => $key]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public function rename(int $tenantId, int $id, string $title): void
    {
        $this->db->prepare('UPDATE conversations SET subject = :title, updated_at = NOW() WHERE tenant_id = :tid AND id = :id')
            ->execute([':tid' => $tenantId, ':id' => $id, ':title' => $title]);
    }

    /** @param array{type: string, id: string, label: string}|null $link */
    public function setLink(int $tenantId, int $id, ?array $link): void
    {
        $this->db->prepare('UPDATE chats SET link_type = :type, link_id = :lid, link_label = :label WHERE tenant_id = :tid AND conversation_id = :id')
            ->execute([':tid' => $tenantId, ':id' => $id, ':type' => $link['type'] ?? null, ':lid' => $link['id'] ?? null, ':label' => $link['label'] ?? null]);
    }

    /** A direct chat that gains a third person becomes a group. */
    public function makeGroup(int $tenantId, int $id): void
    {
        $this->db->prepare("UPDATE chats SET type = 'group', direct_key = NULL WHERE tenant_id = :tid AND conversation_id = :id")
            ->execute([':tid' => $tenantId, ':id' => $id]);
    }

    /**
     * The chats a person is in, newest activity first.
     *
     * @return list<array<string, mixed>>
     */
    public function chatsForPerson(int $tenantId, int $profileId, int $limit = 200): array
    {
        $stmt = $this->db->prepare('SELECT ' . self::CHAT_COLUMNS . ', m.id AS member_id, m.last_read_message_id, 0 AS reads_after_message_id
              FROM chat_members m
              JOIN conversations c ON c.id = m.conversation_id AND c.tenant_id = m.tenant_id
              JOIN chats h ON h.conversation_id = c.id AND h.tenant_id = c.tenant_id
             WHERE m.tenant_id = :tid AND m.profile_id = :pid AND m.removed_at IS NULL AND c.kind = \'team\'
             ORDER BY c.last_message_at DESC, c.id DESC LIMIT :limit');
        $stmt->bindValue(':tid', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':pid', $profileId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * The chats an agent is in for one person: the only chats it can see.
     *
     * @return list<array<string, mixed>>
     */
    public function chatsForAgent(int $tenantId, int $agentId, int $worksFor, int $limit = 200): array
    {
        $stmt = $this->db->prepare('SELECT ' . self::CHAT_COLUMNS . ', m.id AS member_id, m.last_read_message_id, m.reads_after_message_id
              FROM chat_members m
              JOIN conversations c ON c.id = m.conversation_id AND c.tenant_id = m.tenant_id
              JOIN chats h ON h.conversation_id = c.id AND h.tenant_id = c.tenant_id
             WHERE m.tenant_id = :tid AND m.agent_id = :aid AND m.works_for_profile_id = :pid AND m.removed_at IS NULL AND c.kind = \'team\'
             ORDER BY c.last_message_at DESC, c.id DESC LIMIT :limit');
        $stmt->bindValue(':tid', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':aid', $agentId, PDO::PARAM_INT);
        $stmt->bindValue(':pid', $worksFor, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // ── Members ──────────────────────────────────────────────────────────────

    /**
     * Members of the given chats, keyed by chat id, oldest first.
     *
     * @param list<int> $chatIds
     * @return array<int, list<array<string, mixed>>>
     */
    public function membersOf(int $tenantId, array $chatIds, bool $includeRemoved = false): array
    {
        $chatIds = array_values(array_unique(array_map('intval', $chatIds)));
        if ($chatIds === []) {
            return [];
        }
        $marks = [];
        $params = [':tid' => $tenantId];
        foreach ($chatIds as $i => $id) {
            $marks[] = ':c' . $i;
            $params[':c' . $i] = $id;
        }
        $stmt = $this->db->prepare('SELECT ' . self::MEMBER_COLUMNS . ' FROM chat_members m
              LEFT JOIN profiles p ON p.id = m.profile_id
              LEFT JOIN agents a ON a.id = m.agent_id AND a.tenant_id = m.tenant_id
              LEFT JOIN profiles w ON w.id = m.works_for_profile_id
              LEFT JOIN profiles ab ON ab.id = m.added_by_profile_id
             WHERE m.tenant_id = :tid AND m.conversation_id IN (' . implode(', ', $marks) . ')'
            . ($includeRemoved ? '' : ' AND m.removed_at IS NULL') . '
             ORDER BY m.id ASC');
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['actions'] = self::decode($row['actions']);
            $out[(int) $row['conversation_id']][] = $row;
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public function member(int $tenantId, int $memberId): ?array
    {
        $stmt = $this->db->prepare('SELECT conversation_id FROM chat_members WHERE tenant_id = :tid AND id = :id');
        $stmt->execute([':tid' => $tenantId, ':id' => $memberId]);
        $chatId = $stmt->fetchColumn();
        if ($chatId === false) {
            return null;
        }
        foreach ($this->membersOf($tenantId, [(int) $chatId])[(int) $chatId] ?? [] as $row) {
            if ((int) $row['id'] === $memberId) {
                return $row;
            }
        }

        return null;
    }

    public function addPerson(int $tenantId, int $chatId, int $profileId, ?int $addedBy): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO chat_members (tenant_id, conversation_id, profile_id, reads, added_by_profile_id, joined_at)
             VALUES (:tid, :cid, :pid, 'all', :by, NOW()) RETURNING id"
        );
        $stmt->bindValue(':tid', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':cid', $chatId, PDO::PARAM_INT);
        $stmt->bindValue(':pid', $profileId, PDO::PARAM_INT);
        $stmt->bindValue(':by', $addedBy, $addedBy === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /** @param array<string, string> $actions */
    public function addAgent(int $tenantId, int $chatId, int $agentId, int $worksFor, string $reads, int $readsAfter, array $actions, int $addedBy): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO chat_members (tenant_id, conversation_id, agent_id, works_for_profile_id, reads, reads_after_message_id, actions, added_by_profile_id, joined_at)
             VALUES (:tid, :cid, :aid, :for, :reads, :after, :actions, :by, NOW()) RETURNING id'
        );
        $stmt->bindValue(':tid', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':cid', $chatId, PDO::PARAM_INT);
        $stmt->bindValue(':aid', $agentId, PDO::PARAM_INT);
        $stmt->bindValue(':for', $worksFor, PDO::PARAM_INT);
        $stmt->bindValue(':reads', $reads);
        $stmt->bindValue(':after', $readsAfter, PDO::PARAM_INT);
        $stmt->bindValue(':actions', self::encode($actions === [] ? new \stdClass() : $actions));
        $stmt->bindValue(':by', $addedBy, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    public function removeMember(int $tenantId, int $memberId): void
    {
        $this->db->prepare('UPDATE chat_members SET removed_at = NOW() WHERE tenant_id = :tid AND id = :id AND removed_at IS NULL')
            ->execute([':tid' => $tenantId, ':id' => $memberId]);
    }

    /** Moves the member's read mark forward, never back. */
    public function markRead(int $tenantId, int $memberId, int $messageId): void
    {
        $this->db->prepare('UPDATE chat_members SET last_read_message_id = :mid
             WHERE tenant_id = :tid AND id = :id AND last_read_message_id < :mid2')
            ->execute([':tid' => $tenantId, ':id' => $memberId, ':mid' => $messageId, ':mid2' => $messageId]);
    }

    // ── Messages ─────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $meta */
    public function insertMessage(int $tenantId, int $chatId, ?int $authorProfileId, bool $byAgent, string $source, string $body, array $meta, string $status = 'noted'): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO messages (tenant_id, conversation_id, kind, author_profile_id, by_agent, source, body_text, status, chat_meta, created_at)
             VALUES (:tid, :cid, 'note', :author, :by_agent, :source, :body, :status, :meta, NOW())
             RETURNING id"
        );
        $stmt->bindValue(':tid', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':cid', $chatId, PDO::PARAM_INT);
        $stmt->bindValue(':author', $authorProfileId, $authorProfileId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':by_agent', $byAgent, PDO::PARAM_BOOL);
        $stmt->bindValue(':source', $source);
        $stmt->bindValue(':body', $body);
        $stmt->bindValue(':status', $status);
        $stmt->bindValue(':meta', self::encode($meta === [] ? new \stdClass() : $meta));
        $stmt->execute();
        $id = (int) $stmt->fetchColumn();

        $this->db->prepare('UPDATE conversations SET last_message_at = NOW(), updated_at = NOW() WHERE tenant_id = :tid AND id = :id')
            ->execute([':tid' => $tenantId, ':id' => $chatId]);

        return $id;
    }

    /**
     * A chat's messages oldest first: after `$afterId`, or the latest page before
     * `$beforeId` (or the latest page when neither is given). Nothing at or below
     * `$floorId` is returned: an agent's read-from mark.
     *
     * @return list<array<string, mixed>>
     */
    public function messages(int $tenantId, int $chatId, int $floorId, ?int $afterId, ?int $beforeId, int $limit): array
    {
        $sql = 'SELECT ' . self::MESSAGE_COLUMNS . ' FROM messages x LEFT JOIN profiles p ON p.id = x.author_profile_id
                 WHERE x.tenant_id = :tid AND x.conversation_id = :cid AND x.id > :floor';
        $params = [':tid' => $tenantId, ':cid' => $chatId, ':floor' => $floorId];
        if ($afterId !== null) {
            $sql .= ' AND x.id > :after ORDER BY x.id ASC LIMIT :limit';
            $params[':after'] = $afterId;
        } else {
            if ($beforeId !== null) {
                $sql .= ' AND x.id < :before';
                $params[':before'] = $beforeId;
            }
            $sql .= ' ORDER BY x.id DESC LIMIT :limit';
        }
        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = array_map(self::shapeMessage(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
        if ($afterId === null) {
            $rows = array_reverse($rows);
        }

        return array_values($rows);
    }

    /** @return array<string, mixed>|null */
    public function message(int $tenantId, int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT ' . self::MESSAGE_COLUMNS . ' FROM messages x LEFT JOIN profiles p ON p.id = x.author_profile_id
            JOIN conversations c ON c.id = x.conversation_id AND c.tenant_id = x.tenant_id
            WHERE x.tenant_id = :tid AND x.id = :id AND c.kind = \'team\'');
        $stmt->execute([':tid' => $tenantId, ':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::shapeMessage($row) : null;
    }

    /** @param array<string, mixed> $meta */
    public function updateMessage(int $tenantId, int $id, array $meta, string $status): void
    {
        $this->db->prepare('UPDATE messages SET chat_meta = :meta, status = :status WHERE tenant_id = :tid AND id = :id')
            ->execute([':tid' => $tenantId, ':id' => $id, ':meta' => self::encode($meta), ':status' => $status]);
    }

    /**
     * The newest message of each chat, above each chat's floor.
     *
     * @param array<int, int> $floors chat id => floor message id
     * @return array<int, array<string, mixed>>
     */
    public function lastMessages(int $tenantId, array $floors): array
    {
        $out = [];
        foreach ($floors as $chatId => $floor) {
            $rows = $this->messages($tenantId, $chatId, $floor, null, null, 1);
            if ($rows !== []) {
                $out[$chatId] = $rows[0];
            }
        }

        return $out;
    }

    /** How many messages above `$readMark` (and the floor) someone other than the viewer wrote. */
    public function unreadCount(int $tenantId, int $chatId, int $readMark, int $viewerProfileId, bool $viewerIsAgent): int
    {
        // A person's own lines are those they wrote themselves (not their agents');
        // an agent's own lines are never counted for it either, but they are
        // indistinguishable by author, so an agent counts every line it did not
        // write as a person — the agent reads its own lines as already read.
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM messages x
            WHERE x.tenant_id = :tid AND x.conversation_id = :cid AND x.id > :mark
              AND (x.author_profile_id IS NULL OR x.author_profile_id <> :pid OR x.by_agent = :mine)');
        $stmt->bindValue(':tid', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':cid', $chatId, PDO::PARAM_INT);
        $stmt->bindValue(':mark', $readMark, PDO::PARAM_INT);
        $stmt->bindValue(':pid', $viewerProfileId, PDO::PARAM_INT);
        $stmt->bindValue(':mine', !$viewerIsAgent, PDO::PARAM_BOOL);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    public function maxMessageId(int $tenantId, int $chatId): int
    {
        $stmt = $this->db->prepare('SELECT MAX(id) FROM messages WHERE tenant_id = :tid AND conversation_id = :cid');
        $stmt->execute([':tid' => $tenantId, ':cid' => $chatId]);

        return (int) $stmt->fetchColumn();
    }

    /** The id just below the first message written at or after `$since` (UTC), or the newest id when there is none. */
    public function floorSince(int $tenantId, int $chatId, string $since): int
    {
        $stmt = $this->db->prepare('SELECT MIN(id) FROM messages WHERE tenant_id = :tid AND conversation_id = :cid AND created_at >= :since');
        $stmt->execute([':tid' => $tenantId, ':cid' => $chatId, ':since' => $since]);
        $first = $stmt->fetchColumn();

        return $first === null || $first === false ? $this->maxMessageId($tenantId, $chatId) : max(0, (int) $first - 1);
    }

    // ── People, agents, proposals ────────────────────────────────────────────

    public function isStaff(int $tenantId, int $profileId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM memberships WHERE tenant_id = :tid AND profile_id = :pid AND kind = 'member' AND status = 'active' LIMIT 1"
        );
        $stmt->execute([':tid' => $tenantId, ':pid' => $profileId]);

        return $stmt->fetchColumn() !== false;
    }

    public function personName(int $profileId): string
    {
        $stmt = $this->db->prepare('SELECT display_name FROM profiles WHERE id = :id');
        $stmt->execute([':id' => $profileId]);
        $name = $stmt->fetchColumn();

        return is_string($name) ? $name : '';
    }

    /** @return list<array{id: int, name: string}> staff of the workspace, by name */
    public function staff(int $tenantId, string $q, int $limit): array
    {
        $sql = "SELECT p.id, p.display_name FROM memberships ms JOIN profiles p ON p.id = ms.profile_id
                 WHERE ms.tenant_id = :tid AND ms.kind = 'member' AND ms.status = 'active'";
        $params = [':tid' => $tenantId];
        if ($q !== '') {
            $sql .= ' AND LOWER(p.display_name) LIKE :q';
            $params[':q'] = '%' . mb_strtolower(addcslashes($q, '%_\\')) . '%';
        }
        $sql .= ' ORDER BY p.display_name, p.id LIMIT :limit';
        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_values(array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['display_name']], $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /** @return list<array<string, mixed>> the workspace's active agents, by name */
    public function agents(int $tenantId, string $q, int $limit): array
    {
        $sql = "SELECT id, name, name_ar, role, role_ar FROM agents WHERE tenant_id = :tid AND status = 'active'";
        $params = [':tid' => $tenantId];
        if ($q !== '') {
            $sql .= ' AND LOWER(name) LIKE :q';
            $params[':q'] = '%' . mb_strtolower(addcslashes($q, '%_\\')) . '%';
        }
        $sql .= ' ORDER BY name, id LIMIT :limit';
        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed>|null an active agent of the workspace */
    public function agent(int $tenantId, int $agentId): ?array
    {
        $stmt = $this->db->prepare("SELECT id, name, name_ar, role, role_ar FROM agents WHERE tenant_id = :tid AND id = :id AND status = 'active'");
        $stmt->execute([':tid' => $tenantId, ':id' => $agentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** Whether the person has set the agent up to work for them (an active assignment). */
    public function worksFor(int $tenantId, int $agentId, int $profileId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM agent_assignments
            WHERE tenant_id = :tid AND agent_id = :aid AND profile_id = :pid AND revoked_at IS NULL LIMIT 1');
        $stmt->execute([':tid' => $tenantId, ':aid' => $agentId, ':pid' => $profileId]);

        return $stmt->fetchColumn() !== false;
    }

    /** @return array{agent_id: int, profile_id: int, name: string}|null an active assignment and its agent */
    public function assignment(int $tenantId, int $assignmentId): ?array
    {
        $stmt = $this->db->prepare("SELECT a.agent_id, a.profile_id, g.name FROM agent_assignments a
            JOIN agents g ON g.id = a.agent_id AND g.tenant_id = a.tenant_id
            WHERE a.tenant_id = :tid AND a.id = :id AND a.revoked_at IS NULL AND g.status = 'active'");
        $stmt->execute([':tid' => $tenantId, ':id' => $assignmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row)
            ? ['agent_id' => (int) $row['agent_id'], 'profile_id' => (int) $row['profile_id'], 'name' => (string) $row['name']]
            : null;
    }

    /**
     * Agent proposals by id, with the person who decides them.
     *
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    public function proposals(int $tenantId, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $marks = [];
        $params = [':tid' => $tenantId];
        foreach ($ids as $i => $id) {
            $marks[] = ':p' . $i;
            $params[':p' . $i] = $id;
        }
        $stmt = $this->db->prepare('SELECT ap.id, ap.agent_principal_id, ap.on_behalf_of_profile_id, ap.tool, ap.summary, ap.status,
                   ap.expires_at, p.display_name AS decider_name
              FROM agent_proposals ap LEFT JOIN profiles p ON p.id = ap.on_behalf_of_profile_id
             WHERE ap.tenant_id = :tid AND ap.id IN (' . implode(', ', $marks) . ')');
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['id']] = $row;
        }

        return $out;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function shapeMessage(array $row): array
    {
        $row['by_agent'] = DbBool::of($row['by_agent']);
        $row['chat_meta'] = self::decode($row['chat_meta']);

        return $row;
    }

    /** @return array<string, mixed> */
    private static function decode(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }
        $value = json_decode($json, true);

        return is_array($value) ? $value : [];
    }

    private static function encode(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
