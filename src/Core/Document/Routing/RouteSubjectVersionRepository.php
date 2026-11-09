<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

use PDO;

/**
 * The versions of a route subject (WHIT-919, #1723, migration 226).
 *
 * Insert-only, like the trail. Each submit and resubmit records what the
 * reviewers were looking at and its SHA-256, and every trail entry after that
 * names the version it was made on. A version is never edited: a changed
 * subject is a new version.
 *
 * Stateless apart from the injected handle — worker-safe.
 */
final class RouteSubjectVersionRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * The SHA-256 of a snapshot, over a canonical encoding (keys sorted at every
     * level), so the same content always hashes the same.
     *
     * @param array<string, mixed> $data
     */
    public static function hash(array $data): string
    {
        return hash('sha256', self::canonical($data));
    }

    /**
     * Record a version of the subject unless the latest one already has this
     * content, and return the version now current.
     *
     * Two routes on an unchanged document share its version rather than minting
     * a new one for content nobody changed.
     *
     * @param array<string, mixed> $data
     * @return array{version: int, content_hash: string}
     */
    public function record(int $tenantId, string $subjectType, int $subjectId, array $data, ?int $createdBy): array
    {
        $hash = self::hash($data);
        $latest = $this->latest($tenantId, $subjectType, $subjectId);
        if ($latest !== null && $latest['content_hash'] === $hash) {
            return $latest;
        }

        $version = $latest === null ? 1 : $latest['version'] + 1;
        $stmt = $this->db->prepare(
            'INSERT INTO route_subject_versions
                 (tenant_id, subject_type, subject_id, version, content_hash, data, created_by, created_at)
             VALUES (:tenant_id, :subject_type, :subject_id, :version, :content_hash, :data, :created_by, NOW())'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':subject_type' => $subjectType,
            ':subject_id' => $subjectId,
            ':version' => $version,
            ':content_hash' => $hash,
            ':data' => self::canonical($data),
            ':created_by' => $createdBy,
        ]);

        return ['version' => $version, 'content_hash' => $hash];
    }

    /**
     * The subject's current version, or null when none has been recorded.
     *
     * @return array{version: int, content_hash: string}|null
     */
    public function latest(int $tenantId, string $subjectType, int $subjectId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT version, content_hash FROM route_subject_versions
              WHERE tenant_id = :tenant_id AND subject_type = :subject_type AND subject_id = :subject_id
              ORDER BY version DESC LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':subject_type' => $subjectType, ':subject_id' => $subjectId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return ['version' => (int) $row['version'], 'content_hash' => (string) $row['content_hash']];
    }

    /**
     * One version of a subject with its recorded content (WHIT-921: the version a
     * final document is made from), or null.
     *
     * @return array{version: int, content_hash: string, data: mixed}|null
     */
    public function find(int $tenantId, string $subjectType, int $subjectId, int $version): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT version, content_hash, data FROM route_subject_versions
              WHERE tenant_id = :tenant_id AND subject_type = :subject_type AND subject_id = :subject_id
                AND version = :version'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':subject_type' => $subjectType,
            ':subject_id' => $subjectId,
            ':version' => $version,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'version' => (int) $row['version'],
            'content_hash' => (string) $row['content_hash'],
            'data' => json_decode((string) $row['data'], true),
        ];
    }

    /**
     * Every version of a subject, oldest first, with its recorded content.
     *
     * @return list<array{version: int, content_hash: string, data: mixed, created_by: ?int, created_at: string}>
     */
    public function listForSubject(int $tenantId, string $subjectType, int $subjectId): array
    {
        $stmt = $this->db->prepare(
            'SELECT version, content_hash, data, created_by, created_at FROM route_subject_versions
              WHERE tenant_id = :tenant_id AND subject_type = :subject_type AND subject_id = :subject_id
              ORDER BY version ASC'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':subject_type' => $subjectType, ':subject_id' => $subjectId]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'version' => (int) $row['version'],
                'content_hash' => (string) $row['content_hash'],
                'data' => json_decode((string) $row['data'], true),
                'created_by' => $row['created_by'] !== null ? (int) $row['created_by'] : null,
                'created_at' => (string) $row['created_at'],
            ];
        }

        return $out;
    }

    /** @param array<mixed> $data */
    private static function canonical(array $data): string
    {
        return (string) json_encode(self::sortKeys($data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::sortKeys(...), $value);
    }
}
