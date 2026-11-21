<?php

declare(strict_types=1);

namespace Whity\Core\Import;

use PDO;

/**
 * Data access for `import_jobs` and `import_job_changes` (WHIT-1322,
 * migration 401, design section 34 §6): one repository per kind of import, so
 * a kind only ever sees its own imports. TENANT-OWNED: every statement binds
 * `tenant_id`, and every read of a job binds its `kind` too.
 */
final class ImportJobRepository
{
    public function __construct(
        private readonly PDO $db,
        private readonly string $kind,
    ) {
    }

    public function kind(): string
    {
        return $this->kind;
    }

    /**
     * @param list<string> $header
     * @param list<list<string>> $rows
     */
    public function create(int $tenantId, ?int $actor, string $fileName, array $header, array $rows): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO import_jobs (tenant_id, kind, created_by_profile_id, file_name, status, header_json, rows_json, options_json, summary_json, created_at, updated_at)
             VALUES (:tenant_id, :kind, :actor, :file_name, 'uploaded', :header, :rows, '{}', '{}', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
             RETURNING id"
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':kind' => $this->kind,
            ':actor' => $actor,
            ':file_name' => mb_substr($fileName, 0, 255),
            ':header' => json_encode($header, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ':rows' => json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array{id: int, file_name: string, status: string, header: list<string>, rows: list<list<string>>, options: array<string, mixed>, summary: array<string, mixed>, created_by_profile_id: ?int, imported_at: ?string, undone_at: ?string, created_at: string}|null
     */
    public function find(int $tenantId, int $id, bool $forUpdate = false): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, file_name, status, header_json, rows_json, options_json, summary_json, created_by_profile_id, imported_at, undone_at, created_at
               FROM import_jobs WHERE tenant_id = :tenant_id AND id = :id AND kind = :kind' . ($forUpdate && $this->isPostgres() ? ' FOR UPDATE' : '')
        );
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $id, ':kind' => $this->kind]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        /** @var array{id: int, file_name: string, status: string, header: list<string>, rows: list<list<string>>, options: array<string, mixed>, summary: array<string, mixed>, created_by_profile_id: ?int, imported_at: ?string, undone_at: ?string, created_at: string} */
        return [
            'id' => (int) $row['id'],
            'file_name' => (string) $row['file_name'],
            'status' => (string) $row['status'],
            'header' => self::decode($row['header_json']),
            'rows' => self::decode($row['rows_json']),
            'options' => self::decode($row['options_json']),
            'summary' => self::decode($row['summary_json']),
            'created_by_profile_id' => $row['created_by_profile_id'] === null ? null : (int) $row['created_by_profile_id'],
            'imported_at' => $row['imported_at'] === null ? null : (string) $row['imported_at'],
            'undone_at' => $row['undone_at'] === null ? null : (string) $row['undone_at'],
            'created_at' => (string) $row['created_at'],
        ];
    }

    /**
     * The workspace's recent imports of this kind, newest first, without their rows.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(int $tenantId, int $limit): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, file_name, status, summary_json, created_by_profile_id, imported_at, undone_at, created_at
               FROM import_jobs WHERE tenant_id = :tenant_id AND kind = :kind ORDER BY id DESC LIMIT ' . max(1, $limit)
        );
        $stmt->execute([':tenant_id' => $tenantId, ':kind' => $this->kind]);

        /** @var list<array<string, mixed>> */
        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $summary
     */
    public function saveCheck(int $tenantId, int $id, array $options, array $summary): void
    {
        $stmt = $this->db->prepare(
            "UPDATE import_jobs SET status = 'checked', options_json = :options, summary_json = :summary, updated_at = CURRENT_TIMESTAMP
              WHERE tenant_id = :tenant_id AND id = :id AND kind = :kind"
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':id' => $id,
            ':kind' => $this->kind,
            ':options' => json_encode($options, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ':summary' => json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** @param array<string, mixed> $summary */
    public function markImported(int $tenantId, int $id, array $summary): void
    {
        $stmt = $this->db->prepare(
            "UPDATE import_jobs SET status = 'imported', summary_json = :summary, imported_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
              WHERE tenant_id = :tenant_id AND id = :id AND kind = :kind"
        );
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $id, ':kind' => $this->kind, ':summary' => json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
    }

    public function markUndone(int $tenantId, int $id): void
    {
        $stmt = $this->db->prepare(
            "UPDATE import_jobs SET status = 'undone', undone_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
              WHERE tenant_id = :tenant_id AND id = :id AND kind = :kind"
        );
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $id, ':kind' => $this->kind]);
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed> $extra
     */
    public function recordChange(int $tenantId, int $jobId, string $entityType, int $entityId, string $action, ?array $before, array $extra = []): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO import_job_changes (tenant_id, job_id, entity_type, entity_id, action, before_json, extra_json, created_at)
             VALUES (:tenant_id, :job_id, :entity_type, :entity_id, :action, :before, :extra, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':job_id' => $jobId,
            ':entity_type' => $entityType,
            ':entity_id' => $entityId,
            ':action' => $action,
            ':before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ':extra' => json_encode($extra === [] ? new \stdClass() : $extra, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * What a job did, newest first, ready to put back in that order.
     *
     * @return list<array{entity_type: string, entity_id: int, action: string, before: ?array<string, mixed>, extra: array<string, mixed>}>
     */
    public function changes(int $tenantId, int $jobId): array
    {
        $stmt = $this->db->prepare(
            'SELECT entity_type, entity_id, action, before_json, extra_json FROM import_job_changes
              WHERE tenant_id = :tenant_id AND job_id = :job_id ORDER BY id DESC'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':job_id' => $jobId]);

        /** @var list<array{entity_type: string, entity_id: int, action: string, before: ?array<string, mixed>, extra: array<string, mixed>}> */
        return array_values(array_map(static fn (array $r): array => [
            'entity_type' => (string) $r['entity_type'],
            'entity_id' => (int) $r['entity_id'],
            'action' => (string) $r['action'],
            'before' => $r['before_json'] === null ? null : self::decode($r['before_json']),
            'extra' => self::decode($r['extra_json']),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /** Imports never finished are dropped after a day, rows and all. */
    public function sweepAbandoned(int $tenantId): void
    {
        $stmt = $this->db->prepare(
            "SELECT id, created_at FROM import_jobs WHERE tenant_id = :tenant_id AND kind = :kind AND status IN ('uploaded', 'checked')"
        );
        $stmt->execute([':tenant_id' => $tenantId, ':kind' => $this->kind]);
        $cutoff = $this->now() - 86400;
        $delete = $this->db->prepare('DELETE FROM import_jobs WHERE tenant_id = :tenant_id AND id = :id AND kind = :kind');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $created = strtotime((string) $row['created_at']);
            if ($created !== false && $created < $cutoff) {
                $delete->execute([':tenant_id' => $tenantId, ':id' => (int) $row['id'], ':kind' => $this->kind]);
            }
        }
    }

    /**
     * The database's own clock, as a Unix time, so ages compare with the
     * timestamps it wrote whatever its session time zone.
     */
    public function now(): int
    {
        $stmt = $this->db->query('SELECT CURRENT_TIMESTAMP');
        $value = $stmt === false ? false : $stmt->fetchColumn();
        $time = is_string($value) ? strtotime($value) : false;

        return $time === false ? time() : $time;
    }

    private function isPostgres(): bool
    {
        return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
    }

    /** @return array<mixed> */
    private static function decode(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }
        $value = json_decode($json, true);

        return is_array($value) ? $value : [];
    }
}
