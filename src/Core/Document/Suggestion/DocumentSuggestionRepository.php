<?php

declare(strict_types=1);

namespace Whity\Core\Document\Suggestion;

use PDO;

/**
 * Data access for `document_suggestions` (editor M5, migration 183).
 *
 * TENANT-OWNED: every statement binds an explicit `tenant_id` predicate
 * (literal SQL, so the CI tenant-predicate guard verifies it), and every read
 * is also bound to its document.
 *
 * A decision is taken with `WHERE status = 'open'` in the same statement that
 * records it, so two people deciding the same suggestion can't both win.
 */
final class DocumentSuggestionRepository
{
    public const OPEN = 'open';
    public const ACCEPTED = 'accepted';
    public const REJECTED = 'rejected';
    public const STALE = 'stale';

    public const STATUSES = [self::OPEN, self::ACCEPTED, self::REJECTED, self::STALE];

    private const COLUMNS = 'id, tenant_id, source_id, kind, summary, request, permission, routine_id,
        created_by_profile_id, actor_kind, agent_principal_id, on_behalf_of_profile_id,
        base_version, patch, status, decided_by_profile_id, decided_at, created_at, updated_at';

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param array{kind: string, summary: string, request: ?string, permission: string, routine_id?: ?int,
     *              created_by_profile_id: int, actor_kind: string, agent_principal_id: ?string,
     *              on_behalf_of_profile_id: ?int, base_version: int, patch: array<string, mixed>} $rec
     */
    public function create(int $tenantId, int $sourceId, array $rec): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO document_suggestions
                (tenant_id, source_id, kind, summary, request, permission, routine_id, created_by_profile_id,
                 actor_kind, agent_principal_id, on_behalf_of_profile_id, base_version, patch, status, created_at, updated_at)
             VALUES (:tenant_id, :source_id, :kind, :summary, :request, :permission, :routine_id, :created_by,
                 :actor_kind, :agent_principal_id, :on_behalf_of, :base_version, :patch, :status, NOW(), NOW())'
        );
        $stmt->execute([
            ':tenant_id'          => $tenantId,
            ':source_id'          => $sourceId,
            ':kind'               => $rec['kind'],
            ':summary'            => $rec['summary'],
            ':request'            => $rec['request'],
            ':permission'         => $rec['permission'],
            ':routine_id'         => $rec['routine_id'] ?? null,
            ':created_by'         => $rec['created_by_profile_id'],
            ':actor_kind'         => $rec['actor_kind'],
            ':agent_principal_id' => $rec['agent_principal_id'],
            ':on_behalf_of'       => $rec['on_behalf_of_profile_id'],
            ':base_version'       => $rec['base_version'],
            ':patch'              => self::encode($rec['patch']),
            ':status'             => self::OPEN,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id, int $tenantId, int $sourceId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNS . ' FROM document_suggestions
             WHERE id = :id AND tenant_id = :tenant_id AND source_id = :source_id'
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId, ':source_id' => $sourceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? self::row($row) : null;
    }

    /**
     * A document's suggestions in id order: one status, or all of them.
     *
     * @return list<array<string, mixed>>
     */
    public function listForSource(int $tenantId, int $sourceId, ?string $status): array
    {
        if ($status !== null) {
            $stmt = $this->db->prepare(
                'SELECT ' . self::COLUMNS . ' FROM document_suggestions
                 WHERE tenant_id = :tenant_id AND source_id = :source_id AND status = :status ORDER BY id'
            );
            $stmt->execute([':tenant_id' => $tenantId, ':source_id' => $sourceId, ':status' => $status]);
        } else {
            $stmt = $this->db->prepare(
                'SELECT ' . self::COLUMNS . ' FROM document_suggestions
                 WHERE tenant_id = :tenant_id AND source_id = :source_id ORDER BY id'
            );
            $stmt->execute([':tenant_id' => $tenantId, ':source_id' => $sourceId]);
        }

        return self::rows($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Every suggestion of a document created or changed at or after $since
     * (a database timestamp, `Y-m-d H:i:s`).
     *
     * @return list<array<string, mixed>>
     */
    public function changedSince(int $tenantId, int $sourceId, string $since): array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNS . ' FROM document_suggestions
             WHERE tenant_id = :tenant_id AND source_id = :source_id AND updated_at >= :since ORDER BY id'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':source_id' => $sourceId, ':since' => $since]);

        return self::rows($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function countOpen(int $tenantId, int $sourceId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM document_suggestions
             WHERE tenant_id = :tenant_id AND source_id = :source_id AND status = 'open'"
        );
        $stmt->execute([':tenant_id' => $tenantId, ':source_id' => $sourceId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * The open suggestions a bulk decision covers, in id order: all of them,
     * those ids, and/or those one agent principal made.
     *
     * @param list<int>|null $ids
     * @return list<array<string, mixed>>
     */
    public function openFor(int $tenantId, int $sourceId, ?array $ids, ?string $agentPrincipalId): array
    {
        $open = array_values(array_filter(
            $this->listForSource($tenantId, $sourceId, self::OPEN),
            static fn (array $s): bool => ($ids === null || in_array($s['id'], $ids, true))
                && ($agentPrincipalId === null || ($s['actor_kind'] === 'agent' && $s['agent_principal_id'] === $agentPrincipalId))
        ));

        return $open;
    }

    /**
     * Records a decision while the suggestion is still open. False when it was
     * decided (or went stale) in between.
     */
    public function decide(int $id, int $tenantId, string $status, int $deciderId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE document_suggestions
                SET status = :status, decided_by_profile_id = :decider, decided_at = NOW(), updated_at = NOW()
              WHERE id = :id AND tenant_id = :tenant_id AND status = 'open'"
        );
        $stmt->execute([':status' => $status, ':decider' => $deciderId, ':id' => $id, ':tenant_id' => $tenantId]);

        return $stmt->rowCount() === 1;
    }

    /** Marks an open suggestion stale: its block or anchor text has gone. */
    public function markStale(int $id, int $tenantId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE document_suggestions SET status = 'stale', updated_at = NOW()
              WHERE id = :id AND tenant_id = :tenant_id AND status = 'open'"
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);

        return $stmt->rowCount() === 1;
    }

    /**
     * @param array<int, mixed> $rows
     * @return list<array<string, mixed>>
     */
    private static function rows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = self::row($row);
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function row(array $row): array
    {
        $patch = json_decode((string) $row['patch'], true);

        return [
            'id'                      => (int) $row['id'],
            'tenant_id'               => (int) $row['tenant_id'],
            'source_id'               => (int) $row['source_id'],
            'kind'                    => (string) $row['kind'],
            'summary'                 => (string) $row['summary'],
            'request'                 => $row['request'] !== null ? (string) $row['request'] : null,
            'permission'              => (string) $row['permission'],
            'routine_id'              => $row['routine_id'] !== null ? (int) $row['routine_id'] : null,
            'created_by_profile_id'   => $row['created_by_profile_id'] !== null ? (int) $row['created_by_profile_id'] : null,
            'actor_kind'              => (string) $row['actor_kind'],
            'agent_principal_id'      => $row['agent_principal_id'] !== null ? (string) $row['agent_principal_id'] : null,
            'on_behalf_of_profile_id' => $row['on_behalf_of_profile_id'] !== null ? (int) $row['on_behalf_of_profile_id'] : null,
            'base_version'            => (int) $row['base_version'],
            'patch'                   => is_array($patch) ? $patch : [],
            'status'                  => (string) $row['status'],
            'decided_by_profile_id'   => $row['decided_by_profile_id'] !== null ? (int) $row['decided_by_profile_id'] : null,
            'decided_at'              => $row['decided_at'] !== null ? (string) $row['decided_at'] : null,
            'created_at'              => (string) $row['created_at'],
            'updated_at'              => (string) $row['updated_at'],
        ];
    }

    /** @param array<mixed> $value */
    private static function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
