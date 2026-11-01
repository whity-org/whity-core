<?php

declare(strict_types=1);

namespace Whity\Core\Document\Comment;

use PDO;

/**
 * Data access for `document_comment_threads` and `document_comments`
 * (editor M5, migrations 184 and 185).
 *
 * TENANT-OWNED: every statement binds an explicit `tenant_id` predicate
 * (literal SQL, so the CI tenant-predicate guard verifies it). A thread is
 * read only through its document, and a message only through its thread.
 *
 * Every reply, resolve and reopen moves the thread's `updated_at`, which is
 * what GET .../changes reads.
 */
final class DocumentCommentRepository
{
    private const THREAD_COLUMNS = 'id, tenant_id, source_id, anchor_block_id, quote, created_by_profile_id,
        resolved_by_profile_id, resolved_at, created_at, updated_at';

    private const COMMENT_COLUMNS = 'id, tenant_id, thread_id, created_by_profile_id, actor_kind, agent_principal_id,
        on_behalf_of_profile_id, body, mentions, created_at, edited_at';

    public function __construct(private readonly PDO $db)
    {
    }

    public function createThread(int $tenantId, int $sourceId, string $anchorBlockId, string $quote, int $createdBy): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO document_comment_threads (tenant_id, source_id, anchor_block_id, quote, created_by_profile_id, created_at, updated_at)
             VALUES (:tenant_id, :source_id, :anchor, :quote, :created_by, NOW(), NOW())'
        );
        $stmt->execute([
            ':tenant_id'  => $tenantId,
            ':source_id'  => $sourceId,
            ':anchor'     => $anchorBlockId,
            ':quote'      => $quote,
            ':created_by' => $createdBy,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Adds a message to a thread and moves the thread's updated_at.
     *
     * @param array{created_by_profile_id: int, actor_kind: string, agent_principal_id: ?string,
     *              on_behalf_of_profile_id: ?int, body: list<array<string, mixed>>, mentions: list<int>} $rec
     */
    public function addComment(int $tenantId, int $threadId, array $rec): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO document_comments
                (tenant_id, thread_id, created_by_profile_id, actor_kind, agent_principal_id, on_behalf_of_profile_id, body, mentions, created_at)
             VALUES (:tenant_id, :thread_id, :created_by, :actor_kind, :agent_principal_id, :on_behalf_of, :body, :mentions, NOW())'
        );
        $stmt->execute([
            ':tenant_id'          => $tenantId,
            ':thread_id'          => $threadId,
            ':created_by'         => $rec['created_by_profile_id'],
            ':actor_kind'         => $rec['actor_kind'],
            ':agent_principal_id' => $rec['agent_principal_id'],
            ':on_behalf_of'       => $rec['on_behalf_of_profile_id'],
            ':body'               => self::encode($rec['body']),
            ':mentions'           => self::encode($rec['mentions']),
        ]);
        $id = (int) $this->db->lastInsertId();
        $this->touch($tenantId, $threadId);

        return $id;
    }

    /** @return array<string, mixed>|null */
    public function findThread(int $id, int $tenantId, int $sourceId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::THREAD_COLUMNS . ' FROM document_comment_threads
             WHERE id = :id AND tenant_id = :tenant_id AND source_id = :source_id'
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId, ':source_id' => $sourceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? self::thread($row) : null;
    }

    /**
     * A document's threads in id order: resolved ones, unresolved ones or all
     * ($resolved null), optionally only those changed at or after $since.
     *
     * @return list<array<string, mixed>>
     */
    public function threadsForSource(int $tenantId, int $sourceId, ?bool $resolved = null, ?string $since = null): array
    {
        $sql = 'SELECT ' . self::THREAD_COLUMNS . ' FROM document_comment_threads
                WHERE tenant_id = :tenant_id AND source_id = :source_id';
        $params = [':tenant_id' => $tenantId, ':source_id' => $sourceId];
        if ($resolved === true) {
            $sql .= ' AND resolved_at IS NOT NULL';
        } elseif ($resolved === false) {
            $sql .= ' AND resolved_at IS NULL';
        }
        if ($since !== null) {
            $sql .= ' AND updated_at >= :since';
            $params[':since'] = $since;
        }
        $stmt = $this->db->prepare($sql . ' ORDER BY id');
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row)) {
                $out[] = self::thread($row);
            }
        }

        return $out;
    }

    /**
     * The messages of these threads, by thread id, each in id order.
     *
     * @param list<int> $threadIds
     * @return array<int, list<array<string, mixed>>>
     */
    public function commentsFor(int $tenantId, array $threadIds): array
    {
        if ($threadIds === []) {
            return [];
        }
        $placeholders = [];
        $params = [':tenant_id' => $tenantId];
        foreach (array_values($threadIds) as $i => $id) {
            $placeholders[] = ':t' . $i;
            $params[':t' . $i] = $id;
        }
        $stmt = $this->db->prepare(
            'SELECT ' . self::COMMENT_COLUMNS . ' FROM document_comments
             WHERE tenant_id = :tenant_id AND thread_id IN (' . implode(', ', $placeholders) . ') ORDER BY id'
        );
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row)) {
                $comment = self::comment($row);
                $out[$comment['thread_id']][] = $comment;
            }
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public function findComment(int $id, int $tenantId, int $threadId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COMMENT_COLUMNS . ' FROM document_comments
             WHERE id = :id AND tenant_id = :tenant_id AND thread_id = :thread_id'
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId, ':thread_id' => $threadId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? self::comment($row) : null;
    }

    /** Resolves ($by set) or reopens ($by null) a thread. */
    public function setResolved(int $id, int $tenantId, ?int $by): void
    {
        if ($by !== null) {
            $stmt = $this->db->prepare(
                'UPDATE document_comment_threads
                    SET resolved_by_profile_id = :by, resolved_at = NOW(), updated_at = NOW()
                  WHERE id = :id AND tenant_id = :tenant_id'
            );
            $stmt->execute([':by' => $by, ':id' => $id, ':tenant_id' => $tenantId]);

            return;
        }
        $stmt = $this->db->prepare(
            'UPDATE document_comment_threads
                SET resolved_by_profile_id = NULL, resolved_at = NULL, updated_at = NOW()
              WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
    }

    private function touch(int $tenantId, int $threadId): void
    {
        $stmt = $this->db->prepare('UPDATE document_comment_threads SET updated_at = NOW() WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([':id' => $threadId, ':tenant_id' => $tenantId]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function thread(array $row): array
    {
        return [
            'id'                     => (int) $row['id'],
            'tenant_id'              => (int) $row['tenant_id'],
            'source_id'              => (int) $row['source_id'],
            'anchor_block_id'        => (string) $row['anchor_block_id'],
            'quote'                  => (string) $row['quote'],
            'created_by_profile_id'  => $row['created_by_profile_id'] !== null ? (int) $row['created_by_profile_id'] : null,
            'resolved_by_profile_id' => $row['resolved_by_profile_id'] !== null ? (int) $row['resolved_by_profile_id'] : null,
            'resolved_at'            => $row['resolved_at'] !== null ? (string) $row['resolved_at'] : null,
            'created_at'             => (string) $row['created_at'],
            'updated_at'             => (string) $row['updated_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, thread_id: int, created_by_profile_id: ?int, actor_kind: string, agent_principal_id: ?string,
     *               on_behalf_of_profile_id: ?int, body: list<mixed>, mentions: list<int>, created_at: string, edited_at: ?string}
     */
    private static function comment(array $row): array
    {
        $body = json_decode((string) $row['body'], true);
        $mentions = json_decode((string) $row['mentions'], true);

        return [
            'id'                      => (int) $row['id'],
            'thread_id'               => (int) $row['thread_id'],
            'created_by_profile_id'   => $row['created_by_profile_id'] !== null ? (int) $row['created_by_profile_id'] : null,
            'actor_kind'              => (string) $row['actor_kind'],
            'agent_principal_id'      => $row['agent_principal_id'] !== null ? (string) $row['agent_principal_id'] : null,
            'on_behalf_of_profile_id' => $row['on_behalf_of_profile_id'] !== null ? (int) $row['on_behalf_of_profile_id'] : null,
            'body'                    => is_array($body) ? array_values($body) : [],
            'mentions'                => is_array($mentions) ? array_values(array_map('intval', array_filter($mentions, 'is_numeric'))) : [],
            'created_at'              => (string) $row['created_at'],
            'edited_at'               => $row['edited_at'] !== null ? (string) $row['edited_at'] : null,
        ];
    }

    /** @param array<mixed> $value */
    private static function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
    }
}
