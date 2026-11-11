<?php

declare(strict_types=1);

namespace Whity\Core\Messaging;

use PDO;
use Whity\Core\Db\DbBool;

/**
 * `messages` (migration 243). Every statement binds `tenant_id`.
 */
final class MessageRepository
{
    public const KIND_INBOUND = 'inbound';
    public const KIND_OUTBOUND = 'outbound';
    public const KIND_NOTE = 'note';

    /** A status only moves forward along this order; `failed` and `cancelled` end it. */
    private const RANK = ['queued' => 1, 'sent' => 2, 'delivered' => 3, 'read' => 4];

    private const COLUMNS = 'm.id, m.tenant_id, m.conversation_id, m.kind, m.author_profile_id, m.by_agent, m.source,
        m.body_text, m.body_html, m.external_id, m.in_reply_to, m.status, m.status_detail, m.attachments_not_shown,
        m.send_after, m.sent_at, m.created_at, p.display_name AS author_name';

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param array{kind: string, status: string, body_text: string, body_html?: ?string, author_profile_id?: ?int,
     *              by_agent?: bool, source?: ?string, external_id?: ?string, in_reply_to?: ?string,
     *              attachments_not_shown?: int, send_after_seconds?: int} $m
     */
    public function insert(int $tenantId, int $conversationId, array $m): int
    {
        $hold = max(0, (int) ($m['send_after_seconds'] ?? 0));
        $sendAfter = $hold > 0 ? gmdate('Y-m-d H:i:s', time() + $hold) : null;

        $stmt = $this->db->prepare(
            'INSERT INTO messages (tenant_id, conversation_id, kind, author_profile_id, by_agent, source, body_text, body_html,
                                   external_id, in_reply_to, status, attachments_not_shown, send_after, created_at)
             VALUES (:tid, :cid, :kind, :author, :by_agent, :source, :body, :html, :external, :reply_to, :status, :attachments,
                     :send_after, NOW())
             RETURNING id'
        );
        $stmt->bindValue(':tid', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':cid', $conversationId, PDO::PARAM_INT);
        $stmt->bindValue(':kind', $m['kind']);
        $author = $m['author_profile_id'] ?? null;
        $stmt->bindValue(':author', $author, $author === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':by_agent', ($m['by_agent'] ?? false) === true, PDO::PARAM_BOOL);
        $stmt->bindValue(':source', $m['source'] ?? null);
        $stmt->bindValue(':body', $m['body_text']);
        $stmt->bindValue(':html', $m['body_html'] ?? null);
        $stmt->bindValue(':external', isset($m['external_id']) ? mb_substr((string) $m['external_id'], 0, 512) : null);
        $stmt->bindValue(':reply_to', isset($m['in_reply_to']) ? mb_substr((string) $m['in_reply_to'], 0, 512) : null);
        $stmt->bindValue(':status', $m['status']);
        $stmt->bindValue(':attachments', (int) ($m['attachments_not_shown'] ?? 0), PDO::PARAM_INT);
        $stmt->bindValue(':send_after', $sendAfter);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /** @return array<string, mixed>|null */
    public function find(int $tenantId, int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT ' . self::COLUMNS . ' FROM messages m LEFT JOIN profiles p ON p.id = m.author_profile_id
            WHERE m.tenant_id = :tid AND m.id = :id');
        $stmt->execute([':tid' => $tenantId, ':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::shape($row);
    }

    /**
     * A conversation's messages, oldest first.
     *
     * @param bool $customerView Leaves out notes, and outbound messages not yet sent
     *                           (held or queued), which the customer has not received.
     *
     * @return list<array<string, mixed>>
     */
    public function forConversation(int $tenantId, int $conversationId, ?int $afterId, int $limit, bool $customerView = false): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM messages m LEFT JOIN profiles p ON p.id = m.author_profile_id
                 WHERE m.tenant_id = :tid AND m.conversation_id = :cid AND m.id > :after';
        if ($customerView) {
            $sql .= " AND m.kind <> 'note' AND m.status NOT IN ('queued', 'cancelled', 'failed')";
        }
        $sql .= ' ORDER BY m.id ASC LIMIT :limit';
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':tid', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':cid', $conversationId, PDO::PARAM_INT);
        $stmt->bindValue(':after', $afterId ?? 0, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_values(array_map([self::class, 'shape'], $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * The conversation one of these channel ids belongs to, newest first.
     *
     * @param list<string> $externalIds
     */
    public function conversationForExternalIds(int $tenantId, array $externalIds): ?int
    {
        $externalIds = array_values(array_unique(array_filter($externalIds, static fn (string $id): bool => $id !== '')));
        if ($externalIds === []) {
            return null;
        }
        $externalIds = array_slice($externalIds, -50);
        $marks = implode(', ', array_map(static fn (int $i): string => ':e' . $i, array_keys($externalIds)));
        $stmt = $this->db->prepare(
            "SELECT conversation_id FROM messages WHERE tenant_id = :tid AND external_id IN ({$marks}) ORDER BY id DESC LIMIT 1"
        );
        $stmt->bindValue(':tid', $tenantId, PDO::PARAM_INT);
        foreach ($externalIds as $i => $id) {
            $stmt->bindValue(':e' . $i, $id);
        }
        $stmt->execute();
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public function hasExternalId(int $tenantId, int $conversationId, string $externalId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM messages WHERE tenant_id = :tid AND conversation_id = :cid AND external_id = :e'
        );
        $stmt->execute([':tid' => $tenantId, ':cid' => $conversationId, ':e' => $externalId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * The channel ids already in a conversation, oldest first, for an email's
     * References header and the id of the message being answered.
     *
     * @return list<string>
     */
    public function threadIds(int $tenantId, int $conversationId): array
    {
        $stmt = $this->db->prepare(
            "SELECT external_id FROM messages
              WHERE tenant_id = :tid AND conversation_id = :cid AND external_id IS NOT NULL AND kind <> 'note'
              ORDER BY id ASC"
        );
        $stmt->execute([':tid' => $tenantId, ':cid' => $conversationId]);

        return array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    public function markSent(int $tenantId, int $id, ?string $externalId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE messages SET status = 'sent', sent_at = NOW(), status_detail = NULL,
                    external_id = COALESCE(:external, external_id)
              WHERE tenant_id = :tid AND id = :id AND status = 'queued'"
        );
        $stmt->execute([':external' => $externalId, ':tid' => $tenantId, ':id' => $id]);
    }

    public function markFailed(int $tenantId, int $id, string $detail): void
    {
        $this->db->prepare(
            "UPDATE messages SET status = 'failed', status_detail = :detail WHERE tenant_id = :tid AND id = :id AND status = 'queued'"
        )->execute([':detail' => mb_substr($detail, 0, 500), ':tid' => $tenantId, ':id' => $id]);
    }

    /** Cancel a message still waiting to be sent. False when it has gone or was never queued. */
    public function cancel(int $tenantId, int $id): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE messages SET status = 'cancelled' WHERE tenant_id = :tid AND id = :id AND status = 'queued'"
        );
        $stmt->execute([':tid' => $tenantId, ':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * A provider's delivery news, matched on the channel id. Moves forward only.
     *
     * @return int how many messages changed
     */
    public function applyStatus(int $tenantId, string $externalId, string $status, ?string $detail): int
    {
        $stmt = $this->db->prepare(
            "SELECT id, status FROM messages WHERE tenant_id = :tid AND external_id = :e AND kind = 'outbound'"
        );
        $stmt->execute([':tid' => $tenantId, ':e' => $externalId]);
        $changed = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $current = (string) $row['status'];
            if (!self::movesForward($current, $status)) {
                continue;
            }
            $this->db->prepare(
                'UPDATE messages SET status = :status, status_detail = :detail WHERE tenant_id = :tid AND id = :id'
            )->execute([
                ':status' => $status,
                ':detail' => $detail !== null ? mb_substr($detail, 0, 500) : null,
                ':tid' => $tenantId,
                ':id' => (int) $row['id'],
            ]);
            $changed++;
        }

        return $changed;
    }

    public static function movesForward(string $current, string $next): bool
    {
        if (in_array($current, ['failed', 'cancelled', 'received', 'noted'], true)) {
            return false;
        }
        if ($next === 'failed') {
            return in_array($current, ['queued', 'sent'], true);
        }

        return (self::RANK[$next] ?? 0) > (self::RANK[$current] ?? 0);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function shape(array $row): array
    {
        $row['by_agent'] = DbBool::of($row['by_agent'] ?? false);
        $row['id'] = (int) $row['id'];
        $row['conversation_id'] = (int) $row['conversation_id'];

        return $row;
    }
}
