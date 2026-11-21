<?php

declare(strict_types=1);

namespace Whity\Core\Contacts\Import;

use PDO;
use Whity\Core\Import\ImportJobRepository;

/**
 * The contacts' imports (WHIT-953), kept in the shared `import_jobs` and
 * `import_job_changes` since WHIT-1322 (migration 401) as kind `contacts`.
 * The shape {@see ContactImportService} reads is unchanged. TENANT-OWNED:
 * every statement binds `tenant_id`.
 */
final class ContactImportRepository
{
    public const KIND = 'contacts';
    private const ENTITY = 'contact';

    private readonly ImportJobRepository $jobs;

    public function __construct(PDO $db)
    {
        $this->jobs = new ImportJobRepository($db, self::KIND);
    }

    /**
     * @param list<string> $header
     * @param list<list<string>> $rows
     */
    public function create(int $tenantId, ?int $actor, string $fileName, array $header, array $rows): int
    {
        return $this->jobs->create($tenantId, $actor, $fileName, $header, $rows);
    }

    /**
     * @return array{id: int, file_name: string, status: string, header: list<string>, rows: list<list<string>>, options: array<string, mixed>, summary: array<string, mixed>, created_by_profile_id: ?int, imported_at: ?string, undone_at: ?string, created_at: string}|null
     */
    public function find(int $tenantId, int $id, bool $forUpdate = false): ?array
    {
        return $this->jobs->find($tenantId, $id, $forUpdate);
    }

    /** @return list<array<string, mixed>> */
    public function recent(int $tenantId, int $limit): array
    {
        return $this->jobs->recent($tenantId, $limit);
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $summary
     */
    public function saveCheck(int $tenantId, int $id, array $options, array $summary): void
    {
        $this->jobs->saveCheck($tenantId, $id, $options, $summary);
    }

    /** @param array<string, mixed> $summary */
    public function markImported(int $tenantId, int $id, array $summary): void
    {
        $this->jobs->markImported($tenantId, $id, $summary);
    }

    public function markUndone(int $tenantId, int $id): void
    {
        $this->jobs->markUndone($tenantId, $id);
    }

    /**
     * @param array<string, mixed>|null $before
     * @param list<int> $tagsAdded
     */
    public function recordChange(int $tenantId, int $importId, int $contactId, string $action, ?array $before, array $tagsAdded): void
    {
        $this->jobs->recordChange($tenantId, $importId, self::ENTITY, $contactId, $action, $before, ['tags_added' => $tagsAdded]);
    }

    /**
     * @return list<array{contact_id: int, action: string, before: ?array<string, mixed>, tags_added: list<int>}>
     */
    public function changes(int $tenantId, int $importId): array
    {
        $out = [];
        foreach ($this->jobs->changes($tenantId, $importId) as $change) {
            if ($change['entity_type'] !== self::ENTITY) {
                continue;
            }
            $tags = $change['extra']['tags_added'] ?? [];
            $out[] = [
                'contact_id' => $change['entity_id'],
                'action' => $change['action'],
                'before' => $change['before'],
                'tags_added' => is_array($tags) ? array_values(array_map('intval', $tags)) : [],
            ];
        }

        return $out;
    }

    public function sweepAbandoned(int $tenantId): void
    {
        $this->jobs->sweepAbandoned($tenantId);
    }

    public function now(): int
    {
        return $this->jobs->now();
    }
}
