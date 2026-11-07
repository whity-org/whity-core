<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

use PDO;

/**
 * The `document` route subject (WHIT-918, #1722): every route issued before the
 * routes plan, and every route on a document after it.
 *
 * Stateless apart from the injected handle — worker-safe.
 */
final class DocumentRouteSubject implements RouteSubjectProviderInterface
{
    public const TYPE = 'document';

    public function __construct(private readonly PDO $db)
    {
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function title(int $tenantId, int $subjectId): ?string
    {
        $stmt = $this->db->prepare('SELECT title FROM documents WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([':id' => $subjectId, ':tenant_id' => $tenantId]);
        $title = $stmt->fetchColumn();

        return $title === false ? null : (string) $title;
    }

    public function documentId(int $tenantId, int $subjectId): ?int
    {
        return $this->title($tenantId, $subjectId) === null ? null : $subjectId;
    }

    /** The person who raised the document. */
    public function author(int $tenantId, int $subjectId): ?int
    {
        $stmt = $this->db->prepare('SELECT created_by FROM documents WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([':id' => $subjectId, ':tenant_id' => $tenantId]);
        $author = $stmt->fetchColumn();

        return $author === false || $author === null ? null : (int) $author;
    }

    /**
     * What a reviewer of a document is looking at: its title, its template, the
     * values filled into it and the source version it was made from. Not its
     * number or its timestamps, which change without the content changing.
     *
     * @return array<string, mixed>|null
     */
    public function snapshot(int $tenantId, int $subjectId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT title, template_name, variable_data, document_source_id, document_source_version, revision
               FROM documents WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $subjectId, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $values = is_string($row['variable_data'] ?? null) ? json_decode($row['variable_data'], true) : null;

        return [
            'title' => (string) $row['title'],
            'template_name' => (string) $row['template_name'],
            'values' => is_array($values) ? $values : null,
            'source_id' => isset($row['document_source_id']) ? (int) $row['document_source_id'] : null,
            'source_version' => isset($row['document_source_version']) ? (int) $row['document_source_version'] : null,
            'revision' => isset($row['revision']) ? (int) $row['revision'] : 1,
        ];
    }

    /** An issued document is not edited in place; a changed document is a new revision. */
    public function editableWhenSentBack(): bool
    {
        return false;
    }
}
