<?php

declare(strict_types=1);

namespace Whity\Core\Contacts\Import;

use PDO;

/**
 * Data access for `contact_imports` and `contact_import_changes` (WHIT-953,
 * migration 261). TENANT-OWNED: every statement binds `tenant_id`.
 */
final class ContactImportRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param list<string> $header
     * @param list<list<string>> $rows
     */
    public function create(int $tenantId, ?int $actor, string $fileName, array $header, array $rows): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO contact_imports (tenant_id, created_by_profile_id, file_name, status, header_json, rows_json, options_json, summary_json, created_at, updated_at)
             VALUES (:tenant_id, :actor, :file_name, 'uploaded', :header, :rows, '{}', '{}', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
             RETURNING id"
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
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
               FROM contact_imports WHERE tenant_id = :tenant_id AND id = :id' . ($forUpdate && $this->isPostgres() ? ' FOR UPDATE' : '')
        );
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $id]);
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
     * The workspace's recent imports, newest first, without their rows.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(int $tenantId, int $limit): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, file_name, status, summary_json, created_by_profile_id, imported_at, undone_at, created_at
               FROM contact_imports WHERE tenant_id = :tenant_id ORDER BY id DESC LIMIT ' . max(1, $limit)
        );
        $stmt->execute([':tenant_id' => $tenantId]);

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
            "UPDATE contact_imports SET status = 'checked', options_json = :options, summary_json = :summary, updated_at = CURRENT_TIMESTAMP
              WHERE tenant_id = :tenant_id AND id = :id"
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':id' => $id,
            ':options' => json_encode($options, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ':summary' => json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** @param array<string, mixed> $summary */
    public function markImported(int $tenantId, int $id, array $summary): void
    {
        $stmt = $this->db->prepare(
            "UPDATE contact_imports SET status = 'imported', summary_json = :summary, imported_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
              WHERE tenant_id = :tenant_id AND id = :id"
        );
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $id, ':summary' => json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
    }

    public function markUndone(int $tenantId, int $id): void
    {
        $stmt = $this->db->prepare(
            "UPDATE contact_imports SET status = 'undone', undone_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
              WHERE tenant_id = :tenant_id AND id = :id"
        );
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $id]);
    }

    /**
     * @param array<string, mixed>|null $before
     * @param list<int> $tagsAdded
     */
    public function recordChange(int $tenantId, int $importId, int $contactId, string $action, ?array $before, array $tagsAdded): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO contact_import_changes (tenant_id, import_id, contact_id, action, before_json, tags_added, created_at)
             VALUES (:tenant_id, :import_id, :contact_id, :action, :before, :tags, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':import_id' => $importId,
            ':contact_id' => $contactId,
            ':action' => $action,
            ':before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ':tags' => json_encode($tagsAdded, JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * @return list<array{contact_id: int, action: string, before: ?array<string, mixed>, tags_added: list<int>}>
     */
    public function changes(int $tenantId, int $importId): array
    {
        $stmt = $this->db->prepare(
            'SELECT contact_id, action, before_json, tags_added FROM contact_import_changes
              WHERE tenant_id = :tenant_id AND import_id = :import_id ORDER BY id DESC'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':import_id' => $importId]);

        /** @var list<array{contact_id: int, action: string, before: ?array<string, mixed>, tags_added: list<int>}> */
        return array_values(array_map(static fn (array $r): array => [
            'contact_id' => (int) $r['contact_id'],
            'action' => (string) $r['action'],
            'before' => $r['before_json'] === null ? null : self::decode($r['before_json']),
            'tags_added' => array_values(array_map('intval', self::decode($r['tags_added']))),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /** Imports never finished are dropped after a day, rows and all. */
    public function sweepAbandoned(int $tenantId): void
    {
        $stmt = $this->db->prepare(
            "SELECT id, created_at FROM contact_imports WHERE tenant_id = :tenant_id AND status IN ('uploaded', 'checked')"
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        $cutoff = $this->now() - 86400;
        $delete = $this->db->prepare('DELETE FROM contact_imports WHERE tenant_id = :tenant_id AND id = :id');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $created = strtotime((string) $row['created_at']);
            if ($created !== false && $created < $cutoff) {
                $delete->execute([':tenant_id' => $tenantId, ':id' => (int) $row['id']]);
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
