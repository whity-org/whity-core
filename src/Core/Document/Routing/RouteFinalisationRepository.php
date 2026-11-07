<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

use PDO;

/**
 * What the engine did when a route reached its finalise step (WHIT-921, #1725,
 * migration 234): one row per route.
 *
 * Not a trail entry. The trail records what PEOPLE did, and nobody acts at a
 * finalise step; this records the engine's own work: which version the final
 * document was made from (with its SHA-256), the document and the PDF it made,
 * and whether it is still waiting. `status` moves from `waiting` to `done` or
 * `failed` and never back.
 *
 * TENANT-OWNED. Every statement binds a literal `tenant_id` predicate.
 * Stateless apart from the injected handle — worker-safe.
 */
final class RouteFinalisationRepository
{
    public const WAITING = 'waiting';
    public const DONE = 'done';
    public const FAILED = 'failed';

    /** How long one attempt may hold the row before another may try (seconds). */
    private const CLAIM_SECONDS = 300;

    private const COLUMNS = 'id, tenant_id, route_id, step_id, subject_type, subject_id, template_id,
                             subject_version, content_hash, status, reason, document_id, artifact_id,
                             attempts, attempt_started_at, reached_at, finalised_at';

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Record that a route reached its finalise step, with the version it will be
     * made from. A route that reaches it again (after a send-back and a
     * resubmit) updates the version while it is still waiting; a finished one
     * is left as it is.
     */
    public function reach(
        int $tenantId,
        int $routeId,
        int $stepId,
        string $subjectType,
        int $subjectId,
        ?int $templateId,
        ?int $subjectVersion,
        ?string $contentHash,
    ): void {
        $existing = $this->findForRoute($tenantId, $routeId);
        if ($existing !== null) {
            if ($existing['status'] !== self::WAITING) {
                return;
            }
            $stmt = $this->db->prepare(
                'UPDATE route_finalisations
                    SET subject_version = :version, content_hash = :hash, template_id = :template_id
                  WHERE id = :id AND tenant_id = :tenant_id AND status = :waiting'
            );
            $stmt->execute([
                ':version' => $subjectVersion,
                ':hash' => $contentHash,
                ':template_id' => $templateId,
                ':id' => $existing['id'],
                ':tenant_id' => $tenantId,
                ':waiting' => self::WAITING,
            ]);

            return;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO route_finalisations
                 (tenant_id, route_id, step_id, subject_type, subject_id, template_id,
                  subject_version, content_hash, status, attempts, reached_at)
             VALUES (:tenant_id, :route_id, :step_id, :subject_type, :subject_id, :template_id,
                     :version, :hash, :status, 0, NOW())'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':route_id' => $routeId,
            ':step_id' => $stepId,
            ':subject_type' => $subjectType,
            ':subject_id' => $subjectId,
            ':template_id' => $templateId,
            ':version' => $subjectVersion,
            ':hash' => $contentHash,
            ':status' => self::WAITING,
        ]);
    }

    /** @return array<string, mixed>|null */
    public function findForRoute(int $tenantId, int $routeId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNS . ' FROM route_finalisations
              WHERE tenant_id = :tenant_id AND route_id = :route_id'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':route_id' => $routeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::normalize($row) : null;
    }

    /**
     * The newest finalisation on a subject, or null.
     *
     * @return array<string, mixed>|null
     */
    public function latestForSubject(int $tenantId, string $subjectType, int $subjectId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNS . ' FROM route_finalisations
              WHERE tenant_id = :tenant_id AND subject_type = :subject_type AND subject_id = :subject_id
              ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':subject_type' => $subjectType, ':subject_id' => $subjectId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::normalize($row) : null;
    }

    /**
     * Take the row for one attempt. False when it is finished, or another
     * attempt took it in the last few minutes, so two attempts never render the
     * same final document twice.
     */
    public function claim(int $tenantId, int $id): bool
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $stale = $now->modify('-' . self::CLAIM_SECONDS . ' seconds');
        $stmt = $this->db->prepare(
            'UPDATE route_finalisations
                SET attempt_started_at = :now, attempts = attempts + 1
              WHERE id = :id AND tenant_id = :tenant_id AND status = :waiting
                AND (attempt_started_at IS NULL OR attempt_started_at < :stale)'
        );
        $stmt->execute([
            ':now' => $now->format('Y-m-d H:i:s'),
            ':id' => $id,
            ':tenant_id' => $tenantId,
            ':waiting' => self::WAITING,
            ':stale' => $stale->format('Y-m-d H:i:s'),
        ]);

        return $stmt->rowCount() === 1;
    }

    /** Let the next attempt take the row at once, recording why this one stopped. */
    public function release(int $tenantId, int $id, string $reason, ?int $documentId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE route_finalisations
                SET attempt_started_at = NULL, reason = :reason, document_id = COALESCE(:document_id, document_id)
              WHERE id = :id AND tenant_id = :tenant_id AND status = :waiting'
        );
        $stmt->execute([
            ':reason' => $reason,
            ':document_id' => $documentId,
            ':id' => $id,
            ':tenant_id' => $tenantId,
            ':waiting' => self::WAITING,
        ]);
    }

    public function complete(int $tenantId, int $id, int $documentId, int $artifactId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE route_finalisations
                SET status = :done, reason = NULL, document_id = :document_id, artifact_id = :artifact_id,
                    attempt_started_at = NULL, finalised_at = NOW()
              WHERE id = :id AND tenant_id = :tenant_id AND status = :waiting'
        );
        $stmt->execute([
            ':done' => self::DONE,
            ':document_id' => $documentId,
            ':artifact_id' => $artifactId,
            ':id' => $id,
            ':tenant_id' => $tenantId,
            ':waiting' => self::WAITING,
        ]);
    }

    public function fail(int $tenantId, int $id, string $reason, ?int $documentId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE route_finalisations
                SET status = :failed, reason = :reason, document_id = COALESCE(:document_id, document_id),
                    attempt_started_at = NULL
              WHERE id = :id AND tenant_id = :tenant_id AND status = :waiting'
        );
        $stmt->execute([
            ':failed' => self::FAILED,
            ':reason' => $reason,
            ':document_id' => $documentId,
            ':id' => $id,
            ':tenant_id' => $tenantId,
            ':waiting' => self::WAITING,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalize(array $row): array
    {
        $int = static fn (mixed $v): ?int => $v === null ? null : (int) $v;

        return [
            'id' => (int) $row['id'],
            'tenant_id' => (int) $row['tenant_id'],
            'route_id' => (int) $row['route_id'],
            'step_id' => (int) $row['step_id'],
            'subject_type' => (string) $row['subject_type'],
            'subject_id' => (int) $row['subject_id'],
            'template_id' => $int($row['template_id']),
            'subject_version' => $int($row['subject_version']),
            'content_hash' => $row['content_hash'] !== null ? (string) $row['content_hash'] : null,
            'status' => (string) $row['status'],
            'reason' => $row['reason'] !== null ? (string) $row['reason'] : null,
            'document_id' => $int($row['document_id']),
            'artifact_id' => $int($row['artifact_id']),
            'attempts' => (int) $row['attempts'],
            'reached_at' => (string) $row['reached_at'],
            'finalised_at' => $row['finalised_at'] !== null ? (string) $row['finalised_at'] : null,
        ];
    }
}
