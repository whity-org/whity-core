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
 * `failed`; a person may send a failed one back to `waiting` to try again.
 *
 * ONE ATTEMPT AT A TIME. {@see claim()} hands out a token, and only the attempt
 * holding the current token can finish the row ({@see complete()},
 * {@see release()}, {@see fail()}). An attempt that ran past its claim and was
 * overtaken finds its token gone and changes nothing.
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
     * made from. Two branches reaching it at once both succeed: the insert is
     * ignored when the row exists. A route that reaches it again (after a
     * send-back and a resubmit) updates the version while it is still waiting; a
     * finished one is left as it is.
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
        $insert = $this->db->prepare(
            'INSERT INTO route_finalisations
                 (tenant_id, route_id, step_id, subject_type, subject_id, template_id,
                  subject_version, content_hash, status, attempts, reached_at)
             VALUES (:tenant_id, :route_id, :step_id, :subject_type, :subject_id, :template_id,
                     :version, :hash, :status, 0, NOW())
             ON CONFLICT (route_id) DO NOTHING'
        );
        $insert->execute([
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
        if ($insert->rowCount() === 1) {
            return;
        }

        $update = $this->db->prepare(
            'UPDATE route_finalisations
                SET subject_version = :version, content_hash = :hash, template_id = :template_id
              WHERE tenant_id = :tenant_id AND route_id = :route_id AND status = :waiting'
        );
        $update->execute([
            ':version' => $subjectVersion,
            ':hash' => $contentHash,
            ':template_id' => $templateId,
            ':tenant_id' => $tenantId,
            ':route_id' => $routeId,
            ':waiting' => self::WAITING,
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
     * Take the row for one attempt, and return the token that attempt must
     * finish it with. Null when it is finished, or another attempt took it in
     * the last few minutes, so two attempts never render the same final
     * document twice.
     */
    public function claim(int $tenantId, int $id): ?string
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $stale = $now->modify('-' . self::CLAIM_SECONDS . ' seconds');
        $token = bin2hex(random_bytes(16));
        $stmt = $this->db->prepare(
            'UPDATE route_finalisations
                SET attempt_started_at = :now, attempts = attempts + 1, claim_token = :token
              WHERE id = :id AND tenant_id = :tenant_id AND status = :waiting
                AND (attempt_started_at IS NULL OR attempt_started_at < :stale)'
        );
        $stmt->execute([
            ':now' => $now->format('Y-m-d H:i:s'),
            ':token' => $token,
            ':id' => $id,
            ':tenant_id' => $tenantId,
            ':waiting' => self::WAITING,
            ':stale' => $stale->format('Y-m-d H:i:s'),
        ]);

        return $stmt->rowCount() === 1 ? $token : null;
    }

    /** Let the next attempt take the row at once, recording why this one stopped. */
    public function release(int $tenantId, int $id, string $token, string $reason, ?int $documentId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE route_finalisations
                SET attempt_started_at = NULL, claim_token = NULL, reason = :reason,
                    document_id = COALESCE(:document_id, document_id)
              WHERE id = :id AND tenant_id = :tenant_id AND status = :waiting AND claim_token = :token'
        );
        $stmt->execute([
            ':reason' => $reason,
            ':document_id' => $documentId,
            ':id' => $id,
            ':tenant_id' => $tenantId,
            ':waiting' => self::WAITING,
            ':token' => $token,
        ]);
    }

    /** False when this attempt was overtaken and finished nothing. */
    public function complete(int $tenantId, int $id, string $token, int $documentId, int $artifactId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE route_finalisations
                SET status = :done, reason = NULL, document_id = :document_id, artifact_id = :artifact_id,
                    attempt_started_at = NULL, claim_token = NULL, finalised_at = NOW()
              WHERE id = :id AND tenant_id = :tenant_id AND status = :waiting AND claim_token = :token'
        );
        $stmt->execute([
            ':done' => self::DONE,
            ':document_id' => $documentId,
            ':artifact_id' => $artifactId,
            ':id' => $id,
            ':tenant_id' => $tenantId,
            ':waiting' => self::WAITING,
            ':token' => $token,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function fail(int $tenantId, int $id, string $token, string $reason, ?int $documentId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE route_finalisations
                SET status = :failed, reason = :reason, document_id = COALESCE(:document_id, document_id),
                    attempt_started_at = NULL, claim_token = NULL
              WHERE id = :id AND tenant_id = :tenant_id AND status = :waiting AND claim_token = :token'
        );
        $stmt->execute([
            ':failed' => self::FAILED,
            ':reason' => $reason,
            ':document_id' => $documentId,
            ':id' => $id,
            ':tenant_id' => $tenantId,
            ':waiting' => self::WAITING,
            ':token' => $token,
        ]);
    }

    /**
     * Somebody asked for another try: a failed or waiting row goes back to
     * waiting with its attempts counted from zero. A finished one is left as it
     * is. False when there was nothing to try again.
     */
    public function reopen(int $tenantId, int $routeId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE route_finalisations
                SET status = :waiting, reason = NULL, attempts = 0, attempt_started_at = NULL, claim_token = NULL
              WHERE tenant_id = :tenant_id AND route_id = :route_id AND status <> :done'
        );
        $stmt->execute([
            ':waiting' => self::WAITING,
            ':tenant_id' => $tenantId,
            ':route_id' => $routeId,
            ':done' => self::DONE,
        ]);

        return $stmt->rowCount() === 1;
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
