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
        x.status, x.chat_meta, x.created_at, p.display_name AS author_name,
        x.reply_to_message_id, x.thread_root_id, x.in_chat, x.edited_at, x.deleted_at, x.pinned_at, x.pinned_by_profile_id, x.changed_at';

    /** Lines in the chat itself: everything but thread replies that were not also sent to the chat (UC.6). */
    private const IN_CHAT = '(x.thread_root_id IS NULL OR x.in_chat = TRUE)';

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

    /** The oldest group chat linked to this board, meeting or record, if any. */
    public function findLinked(int $tenantId, string $type, string $linkId): ?int
    {
        $stmt = $this->db->prepare(
            "SELECT conversation_id FROM chats
              WHERE tenant_id = :tid AND type = 'group' AND link_type = :type AND link_id = :lid
              ORDER BY conversation_id ASC LIMIT 1"
        );
        $stmt->execute([':tid' => $tenantId, ':type' => $type, ':lid' => $linkId]);
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
    public function insertMessage(
        int $tenantId,
        int $chatId,
        ?int $authorProfileId,
        bool $byAgent,
        string $source,
        string $body,
        array $meta,
        string $status = 'noted',
        ?int $replyTo = null,
        ?int $threadRoot = null,
        bool $inChat = true,
    ): int {
        $stmt = $this->db->prepare(
            "INSERT INTO messages (tenant_id, conversation_id, kind, author_profile_id, by_agent, source, body_text, status, chat_meta,
                                   reply_to_message_id, thread_root_id, in_chat, created_at)
             VALUES (:tid, :cid, 'note', :author, :by_agent, :source, :body, :status, :meta, :reply_to, :root, :in_chat, NOW())
             RETURNING id"
        );
        $stmt->bindValue(':reply_to', $replyTo, $replyTo === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':root', $threadRoot, $threadRoot === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':in_chat', $threadRoot === null || $inChat, PDO::PARAM_BOOL);
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
                 WHERE x.tenant_id = :tid AND x.conversation_id = :cid AND x.id > :floor AND ' . self::IN_CHAT;
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
            WHERE x.tenant_id = :tid AND x.conversation_id = :cid AND x.id > :mark AND ' . self::IN_CHAT . '
              AND (x.author_profile_id IS NULL OR x.author_profile_id <> :pid OR x.by_agent = :mine)');
        $stmt->bindValue(':tid', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':cid', $chatId, PDO::PARAM_INT);
        $stmt->bindValue(':mark', $readMark, PDO::PARAM_INT);
        $stmt->bindValue(':pid', $viewerProfileId, PDO::PARAM_INT);
        $stmt->bindValue(':mine', !$viewerIsAgent, PDO::PARAM_BOOL);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * How many lines people and agents wrote above `$readMark` that the person
     * has not written themselves, leaving out system lines ("Amro added Ahmad")
     * and declined agent lines: what a push counts as new
     * messages (WHIT-1077).
     */
    public function unreadLines(int $tenantId, int $chatId, int $readMark, int $profileId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM messages x
            WHERE x.tenant_id = :tid AND x.conversation_id = :cid AND x.id > :mark
              AND x.source IN ('chat.person', 'chat.agent') AND x.status = 'noted'
              AND (x.author_profile_id IS NULL OR x.author_profile_id <> :pid OR x.by_agent = :agent)");
        $stmt->bindValue(':tid', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':cid', $chatId, PDO::PARAM_INT);
        $stmt->bindValue(':mark', $readMark, PDO::PARAM_INT);
        $stmt->bindValue(':pid', $profileId, PDO::PARAM_INT);
        $stmt->bindValue(':agent', true, PDO::PARAM_BOOL);
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

    // ── Replies, threads, reactions, pins, edits (UC.6–UC.12) ────────────────

    /**
     * A thread's replies, oldest first, above the floor.
     *
     * @return list<array<string, mixed>>
     */
    public function threadMessages(int $tenantId, int $chatId, int $rootId, int $floorId, int $limit = 200): array
    {
        $stmt = $this->db->prepare('SELECT ' . self::MESSAGE_COLUMNS . ' FROM messages x LEFT JOIN profiles p ON p.id = x.author_profile_id
            WHERE x.tenant_id = :tid AND x.conversation_id = :cid AND x.thread_root_id = :root AND x.id > :floor
            ORDER BY x.id ASC LIMIT :limit');
        $stmt->bindValue(':tid', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':cid', $chatId, PDO::PARAM_INT);
        $stmt->bindValue(':root', $rootId, PDO::PARAM_INT);
        $stmt->bindValue(':floor', $floorId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_values(array_map(self::shapeMessage(...), $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * How many replies each message's thread has, when the latest came, and who wrote in it.
     *
     * @param list<int> $rootIds
     * @return array<int, array{count: int, last_at: string, people: list<string>}>
     */
    public function threadSummaries(int $tenantId, array $rootIds, int $floorId): array
    {
        if ($rootIds === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($rootIds), '?'));
        $stmt = $this->db->prepare("SELECT x.thread_root_id, x.created_at, p.display_name AS author_name, x.chat_meta, x.by_agent
              FROM messages x LEFT JOIN profiles p ON p.id = x.author_profile_id
             WHERE x.tenant_id = ? AND x.id > ? AND x.thread_root_id IN ({$marks})
             ORDER BY x.id ASC");
        $stmt->execute([$tenantId, $floorId, ...$rootIds]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $root = (int) $r['thread_root_id'];
            $meta = self::decode($r['chat_meta']);
            $name = DbBool::of($r['by_agent']) ? (string) ($meta['agent']['name'] ?? '') : (string) ($r['author_name'] ?? '');
            $entry = $out[$root] ?? ['count' => 0, 'last_at' => '', 'people' => []];
            $entry['count']++;
            $entry['last_at'] = (string) $r['created_at'];
            if ($name !== '' && !in_array($name, $entry['people'], true)) {
                $entry['people'][] = $name;
            }
            $out[$root] = $entry;
        }

        return $out;
    }

    /**
     * Reactions on the given messages, oldest first.
     *
     * @param list<int> $messageIds
     * @return array<int, list<array{emoji: string, profile_id: int, name: string}>>
     */
    public function reactions(int $tenantId, array $messageIds): array
    {
        if ($messageIds === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($messageIds), '?'));
        $stmt = $this->db->prepare("SELECT r.message_id, r.emoji, r.profile_id, p.display_name
              FROM chat_message_reactions r JOIN profiles p ON p.id = r.profile_id
             WHERE r.tenant_id = ? AND r.message_id IN ({$marks})
             ORDER BY r.created_at ASC, r.profile_id ASC");
        $stmt->execute([$tenantId, ...$messageIds]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['message_id']][] = ['emoji' => (string) $r['emoji'], 'profile_id' => (int) $r['profile_id'], 'name' => (string) $r['display_name']];
        }

        return $out;
    }

    /** Adds the reaction, or takes it back when the person already gave it. Returns whether it is there now. */
    public function toggleReaction(int $tenantId, int $messageId, int $profileId, string $emoji): bool
    {
        $del = $this->db->prepare('DELETE FROM chat_message_reactions WHERE tenant_id = :tid AND message_id = :mid AND profile_id = :pid AND emoji = :emoji');
        $del->execute([':tid' => $tenantId, ':mid' => $messageId, ':pid' => $profileId, ':emoji' => $emoji]);
        $added = $del->rowCount() === 0;
        if ($added) {
            $this->db->prepare('INSERT INTO chat_message_reactions (tenant_id, message_id, profile_id, emoji, created_at) VALUES (:tid, :mid, :pid, :emoji, NOW())')
                ->execute([':tid' => $tenantId, ':mid' => $messageId, ':pid' => $profileId, ':emoji' => $emoji]);
        }
        $this->touch($tenantId, $messageId);

        return $added;
    }

    public function setPinned(int $tenantId, int $messageId, ?int $byProfileId): void
    {
        $stmt = $this->db->prepare('UPDATE messages SET pinned_at = ' . ($byProfileId === null ? 'NULL' : 'NOW()') . ', pinned_by_profile_id = :by, changed_at = NOW()
            WHERE tenant_id = :tid AND id = :id');
        $stmt->bindValue(':by', $byProfileId, $byProfileId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':tid', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':id', $messageId, PDO::PARAM_INT);
        $stmt->execute();
    }

    /** @param array<string, mixed> $meta */
    public function editMessage(int $tenantId, int $messageId, string $body, array $meta): void
    {
        $this->db->prepare('UPDATE messages SET body_text = :body, chat_meta = :meta, edited_at = NOW(), changed_at = NOW() WHERE tenant_id = :tid AND id = :id')
            ->execute([':tid' => $tenantId, ':id' => $messageId, ':body' => $body, ':meta' => self::encode($meta === [] ? new \stdClass() : $meta)]);
    }

    /** The words go; the row stays as "This message was deleted", unpinned and without reactions. */
    public function deleteMessage(int $tenantId, int $messageId): void
    {
        $this->db->prepare("UPDATE messages SET body_text = '', chat_meta = :meta, deleted_at = NOW(), pinned_at = NULL, pinned_by_profile_id = NULL, changed_at = NOW()
            WHERE tenant_id = :tid AND id = :id")
            ->execute([':tid' => $tenantId, ':id' => $messageId, ':meta' => '{}']);
        $this->db->prepare('DELETE FROM chat_message_reactions WHERE tenant_id = :tid AND message_id = :id')
            ->execute([':tid' => $tenantId, ':id' => $messageId]);
    }

    /** Marks a message changed (a reaction, a thread reply), so open chats fetch it again. */
    public function touch(int $tenantId, int $messageId): void
    {
        $this->db->prepare('UPDATE messages SET changed_at = NOW() WHERE tenant_id = :tid AND id = :id')
            ->execute([':tid' => $tenantId, ':id' => $messageId]);
    }

    /**
     * Messages changed at or after `$since` (UTC), above the floor.
     *
     * @return list<array<string, mixed>>
     */
    public function changedSince(int $tenantId, int $chatId, int $floorId, string $since, int $limit = 100): array
    {
        $stmt = $this->db->prepare('SELECT ' . self::MESSAGE_COLUMNS . ' FROM messages x LEFT JOIN profiles p ON p.id = x.author_profile_id
            WHERE x.tenant_id = :tid AND x.conversation_id = :cid AND x.id > :floor AND x.changed_at IS NOT NULL AND x.changed_at >= :since
            ORDER BY x.id ASC LIMIT :limit');
        $stmt->bindValue(':tid', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':cid', $chatId, PDO::PARAM_INT);
        $stmt->bindValue(':floor', $floorId, PDO::PARAM_INT);
        $stmt->bindValue(':since', $since);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_values(array_map(self::shapeMessage(...), $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /** @return list<array<string, mixed>> the chat's pinned messages, newest pin first */
    public function pinnedMessages(int $tenantId, int $chatId, int $floorId): array
    {
        $stmt = $this->db->prepare('SELECT ' . self::MESSAGE_COLUMNS . ' FROM messages x LEFT JOIN profiles p ON p.id = x.author_profile_id
            WHERE x.tenant_id = :tid AND x.conversation_id = :cid AND x.id > :floor AND x.pinned_at IS NOT NULL
            ORDER BY x.pinned_at DESC, x.id DESC LIMIT 20');
        $stmt->bindValue(':tid', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':cid', $chatId, PDO::PARAM_INT);
        $stmt->bindValue(':floor', $floorId, PDO::PARAM_INT);
        $stmt->execute();

        return array_values(array_map(self::shapeMessage(...), $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * People's and agents' messages containing `$q` (any case), newest first,
     * optionally only those one person wrote.
     *
     * @return list<array<string, mixed>>
     */
    public function search(int $tenantId, int $chatId, int $floorId, string $q, ?int $authorProfileId, int $limit = 50): array
    {
        $sql = 'SELECT ' . self::MESSAGE_COLUMNS . " FROM messages x LEFT JOIN profiles p ON p.id = x.author_profile_id
            WHERE x.tenant_id = :tid AND x.conversation_id = :cid AND x.id > :floor AND x.deleted_at IS NULL
              AND x.source IN ('chat.person', 'chat.agent')";
        $params = [':tid' => $tenantId, ':cid' => $chatId, ':floor' => $floorId];
        if ($q !== '') {
            $sql .= " AND LOWER(x.body_text) LIKE :q ESCAPE '!'";
            $params[':q'] = '%' . strtr(mb_strtolower($q), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        }
        if ($authorProfileId !== null) {
            $sql .= ' AND x.author_profile_id = :author AND x.by_agent = FALSE';
            $params[':author'] = $authorProfileId;
        }
        $stmt = $this->db->prepare($sql . ' ORDER BY x.id DESC LIMIT :limit');
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_values(array_map(self::shapeMessage(...), $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * Each person's read mark in the chat, for "Seen by" (UC.11).
     *
     * @return list<array{member_id: int, profile_id: int, name: string, last_read_message_id: int}>
     */
    public function readMarks(int $tenantId, int $chatId): array
    {
        $stmt = $this->db->prepare('SELECT m.id, m.profile_id, p.display_name, m.last_read_message_id
              FROM chat_members m JOIN profiles p ON p.id = m.profile_id
             WHERE m.tenant_id = :tid AND m.conversation_id = :cid AND m.removed_at IS NULL AND m.profile_id IS NOT NULL
             ORDER BY m.id');
        $stmt->execute([':tid' => $tenantId, ':cid' => $chatId]);

        return array_values(array_map(static fn (array $r): array => [
            'member_id' => (int) $r['id'],
            'profile_id' => (int) $r['profile_id'],
            'name' => (string) $r['display_name'],
            'last_read_message_id' => (int) $r['last_read_message_id'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * Messages by id, for the quotes above replies.
     *
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    public function messagesByIds(int $tenantId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare('SELECT ' . self::MESSAGE_COLUMNS . " FROM messages x LEFT JOIN profiles p ON p.id = x.author_profile_id
            WHERE x.tenant_id = ? AND x.id IN ({$marks})");
        $stmt->execute([$tenantId, ...$ids]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['id']] = self::shapeMessage($row);
        }

        return $out;
    }

    /** Whether this person muted this chat (UC.8). */
    public function isMuted(int $tenantId, int $chatId, int $profileId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM chat_person_state WHERE tenant_id = :tid AND conversation_id = :cid AND profile_id = :pid AND muted = TRUE');
        $stmt->execute([':tid' => $tenantId, ':cid' => $chatId, ':pid' => $profileId]);

        return $stmt->fetchColumn() !== false;
    }

    /** Bubbles (the default) or compact rows, the person's own choice (UC.7). */
    public function chatLayout(int $profileId): string
    {
        $stmt = $this->db->prepare('SELECT chat_layout FROM profiles WHERE id = :id');
        $stmt->execute([':id' => $profileId]);

        return $stmt->fetchColumn() === 'rows' ? 'rows' : 'bubbles';
    }

    public function setChatLayout(int $profileId, string $layout): void
    {
        $this->db->prepare('UPDATE profiles SET chat_layout = :layout WHERE id = :id')
            ->execute([':layout' => $layout === 'rows' ? 'rows' : 'bubbles', ':id' => $profileId]);
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
        if (array_key_exists('in_chat', $row)) {
            $row['in_chat'] = DbBool::of($row['in_chat']);
        }

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
