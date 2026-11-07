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
}
