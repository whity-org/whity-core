<?php

declare(strict_types=1);

namespace Whity\Core\Chat;

use PDO;
use Whity\Core\Db\DbBool;

/**
 * One chat (design 3.5, UC.1, WHIT-1093): the one list of Chat.
 *
 * Team chats (the viewer's own, as {@see ChatService::list()} gives them) and,
 * for people who may read customer conversations, the workspace's customer
 * conversations, newest activity first. Each item says its `kind` (`team` or
 * `customer`) and whether the viewer pinned it.
 *
 * Both kinds live in the `conversations` table, so an item's `id` is the
 * conversation id whatever its kind, and one address (`/chat?c=<id>`) opens
 * either.
 *
 * Unread for a customer conversation is the customer's messages after the
 * person's read mark that nobody on the team has answered or noted since: an
 * answer means somebody read it, so a person who never opened Chat before 3.5
 * does not see every old message as unread. Closed conversations have none.
 */
final class ChatInbox
{
    /** Closed customer conversations stay in the list this long after their last message. */
    private const CLOSED_DAYS = 30;

    private const CUSTOMER_LIMIT = 200;

    public function __construct(
        private readonly PDO $db,
        private readonly ChatService $chat,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(int $tenantId, ChatViewer $viewer, bool $customers): array
    {
        $team = $this->chat->list($tenantId, $viewer);
        if ($viewer->isAgent()) {
            // Agents keep the team list they had: customer conversations reach
            // them through the Messages tools, under their own permissions.
            return array_map(static fn (array $c): array => ['kind' => 'team', 'pinned' => false] + $c, $team);
        }

        $pins = $this->pins($tenantId, $viewer->profileId, array_map(static fn (array $c): int => (int) $c['id'], $team));
        $items = [];
        foreach ($team as $c) {
            $items[] = ['kind' => 'team', 'pinned' => isset($pins[(int) $c['id']])] + $c;
        }
        if ($customers) {
            foreach ($this->customerRows($tenantId, $viewer->profileId) as $row) {
                $items[] = self::presentCustomer($row);
            }
        }

        usort($items, static fn (array $a, array $b): int => [(string) $b['last_message_at'], (int) $b['id']] <=> [(string) $a['last_message_at'], (int) $a['id']]);

        return $items;
    }

    /**
     * Pin or unpin a chat or a customer conversation for this person.
     *
     * @throws ChatException when the viewer can't see it
     */
    public function pin(int $tenantId, ChatViewer $viewer, int $conversationId, bool $pinned, bool $customers): void
    {
        $this->assertVisible($tenantId, $viewer, $conversationId, $customers);
        $this->upsert($tenantId, $conversationId, $viewer->profileId, 'pinned_at', $pinned ? date('Y-m-d H:i:s') : null);
    }

    /**
     * How far this person has read a customer conversation. Team chats keep
     * their mark on their membership ({@see ChatService::markRead()}).
     *
     * @throws ChatException when it is not a customer conversation the viewer may read
     */
    public function readCustomer(int $tenantId, ChatViewer $viewer, int $conversationId, int $messageId, bool $customers): void
    {
        if (!$customers || $this->kindOf($tenantId, $conversationId) !== 'customer') {
            throw ChatException::notFound();
        }
        $stmt = $this->db->prepare('SELECT COALESCE(MAX(id), 0) FROM messages WHERE tenant_id = :tid AND conversation_id = :cid');
        $stmt->execute([':tid' => $tenantId, ':cid' => $conversationId]);
        $max = (int) $stmt->fetchColumn();
        $mark = max(0, min($messageId, $max));

        $current = $this->db->prepare('SELECT last_read_message_id FROM chat_person_state WHERE conversation_id = :cid AND profile_id = :pid');
        $current->execute([':cid' => $conversationId, ':pid' => $viewer->profileId]);
        $was = $current->fetchColumn();
        if ($was !== false && (int) $was >= $mark) {
            return;
        }
        $this->upsert($tenantId, $conversationId, $viewer->profileId, 'last_read_message_id', $mark);
    }

    /**
     * How many chats have something unread for this person: the Chat badge.
     */
    public function unreadChats(int $tenantId, ChatViewer $viewer, bool $customers): int
    {
        $n = 0;
        foreach ($this->list($tenantId, $viewer, $customers) as $item) {
            if ((int) ($item['unread'] ?? 0) > 0) {
                $n++;
            }
        }

        return $n;
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private function assertVisible(int $tenantId, ChatViewer $viewer, int $conversationId, bool $customers): void
    {
        $kind = $this->kindOf($tenantId, $conversationId);
        if ($kind === 'customer' && $customers) {
            return;
        }
        if ($kind === 'team') {
            $this->chat->assertMember($tenantId, $viewer, $conversationId);

            return;
        }

        throw ChatException::notFound();
    }

    private function kindOf(int $tenantId, int $conversationId): ?string
    {
        $stmt = $this->db->prepare('SELECT kind FROM conversations WHERE tenant_id = :tid AND id = :id');
        $stmt->execute([':tid' => $tenantId, ':id' => $conversationId]);
        $kind = $stmt->fetchColumn();

        return $kind === false ? null : (string) $kind;
    }

    private function upsert(int $tenantId, int $conversationId, int $profileId, string $column, int|string|null $value): void
    {
        // $column is one of two names written in this class, never input.
        $stmt = $this->db->prepare(
            "INSERT INTO chat_person_state (tenant_id, conversation_id, profile_id, {$column}, updated_at)
             VALUES (:tid, :cid, :pid, :value, :now)
             ON CONFLICT (conversation_id, profile_id) DO UPDATE SET {$column} = excluded.{$column}, updated_at = excluded.updated_at"
        );
        $stmt->bindValue(':tid', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':cid', $conversationId, PDO::PARAM_INT);
        $stmt->bindValue(':pid', $profileId, PDO::PARAM_INT);
        $stmt->bindValue(':value', $value, $value === null ? PDO::PARAM_NULL : (is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR));
        $stmt->bindValue(':now', date('Y-m-d H:i:s'));
        $stmt->execute();
    }

    /**
     * @param list<int> $ids
     * @return array<int, true>
     */
    private function pins(int $tenantId, int $profileId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("SELECT conversation_id FROM chat_person_state
             WHERE tenant_id = ? AND profile_id = ? AND pinned_at IS NOT NULL AND conversation_id IN ({$marks})");
        $stmt->execute([$tenantId, $profileId, ...$ids]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $out[(int) $id] = true;
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function customerRows(int $tenantId, int $profileId): array
    {
        $stmt = $this->db->prepare(
            "SELECT c.id, c.channel, c.subject, c.status, c.contact_name, c.contact_address,
                    c.customer_profile_id, cp.display_name AS customer_name,
                    c.assigned_profile_id, ap.display_name AS assigned_name,
                    c.last_message_at, c.last_inbound_at, c.created_at,
                    s.pinned_at,
                    lm.body_text AS last_body, lm.kind AS last_kind, lm.by_agent AS last_by_agent,
                    lm.author_profile_id AS last_author_id, lp.display_name AS last_author_name,
                    CASE WHEN c.status = 'closed' THEN 0 ELSE (
                        SELECT COUNT(*) FROM messages u
                         WHERE u.tenant_id = c.tenant_id AND u.conversation_id = c.id AND u.kind = 'inbound'
                           AND u.id > COALESCE(s.last_read_message_id, 0)
                           AND NOT EXISTS (
                               SELECT 1 FROM messages o
                                WHERE o.tenant_id = c.tenant_id AND o.conversation_id = c.id
                                  AND o.kind IN ('outbound', 'note') AND o.id > u.id
                           )
                    ) END AS unread
               FROM conversations c
               LEFT JOIN chat_person_state s ON s.conversation_id = c.id AND s.profile_id = :pid
               LEFT JOIN profiles ap ON ap.id = c.assigned_profile_id
               LEFT JOIN profiles cp ON cp.id = c.customer_profile_id
               LEFT JOIN messages lm ON lm.id = (
                    SELECT MAX(m.id) FROM messages m
                     WHERE m.tenant_id = c.tenant_id AND m.conversation_id = c.id AND m.kind <> 'note'
               )
               LEFT JOIN profiles lp ON lp.id = lm.author_profile_id
              WHERE c.tenant_id = :tid AND c.kind = 'customer'
                AND (c.status <> 'closed' OR c.last_message_at >= :since OR s.pinned_at IS NOT NULL)
              ORDER BY c.last_message_at DESC, c.id DESC
              LIMIT :limit"
        );
        $stmt->bindValue(':tid', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':pid', $profileId, PDO::PARAM_INT);
        $stmt->bindValue(':since', date('Y-m-d H:i:s', time() - self::CLOSED_DAYS * 86400));
        $stmt->bindValue(':limit', self::CUSTOMER_LIMIT, PDO::PARAM_INT);
        $stmt->execute();

        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function presentCustomer(array $row): array
    {
        $name = $row['contact_name'] !== null && $row['contact_name'] !== ''
            ? (string) $row['contact_name']
            : ($row['customer_name'] !== null ? (string) $row['customer_name'] : null);
        $lastKind = $row['last_kind'] !== null ? (string) $row['last_kind'] : null;
        $status = (string) $row['status'];

        return [
            'id' => (int) $row['id'],
            'kind' => 'customer',
            'type' => 'customer',
            'title' => $name,
            'channel' => (string) $row['channel'],
            'status' => $status,
            'subject' => $row['subject'] !== null ? (string) $row['subject'] : null,
            'contact' => [
                'name' => $row['contact_name'] !== null ? (string) $row['contact_name'] : null,
                'address' => (string) $row['contact_address'],
            ],
            'customer' => $row['customer_profile_id'] !== null
                ? ['id' => (int) $row['customer_profile_id'], 'name' => (string) ($row['customer_name'] ?? '')]
                : null,
            'assignee' => $row['assigned_profile_id'] !== null
                ? ['id' => (int) $row['assigned_profile_id'], 'name' => (string) ($row['assigned_name'] ?? '')]
                : null,
            // The customer wrote last and nobody has answered: the "Waiting" tag.
            'waiting_since' => $status !== 'closed' && $lastKind === 'inbound' && $row['last_inbound_at'] !== null
                ? self::iso($row['last_inbound_at'])
                : null,
            'unread' => (int) $row['unread'],
            'pinned' => $row['pinned_at'] !== null,
            'last_message' => $lastKind === null ? null : [
                'from' => $lastKind === 'inbound' ? 'customer' : 'team',
                'body' => mb_substr((string) $row['last_body'], 0, 160),
                'by_agent' => DbBool::of($row['last_by_agent']),
                'author' => $lastKind !== 'inbound' && $row['last_author_id'] !== null
                    ? ['id' => (int) $row['last_author_id'], 'name' => (string) ($row['last_author_name'] ?? '')]
                    : null,
            ],
            'last_message_at' => self::iso($row['last_message_at']),
            'created_at' => self::iso($row['created_at']),
        ];
    }

    private static function iso(mixed $at): ?string
    {
        if ($at === null || $at === '') {
            return null;
        }
        $t = strtotime((string) $at . (preg_match('/[zZ]|[+-]\d{2}:?\d{2}$/', (string) $at) === 1 ? '' : ' UTC'));

        return $t === false ? (string) $at : gmdate('Y-m-d\TH:i:s\Z', $t);
    }
}
