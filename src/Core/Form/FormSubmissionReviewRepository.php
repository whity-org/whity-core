<?php

declare(strict_types=1);

namespace Whity\Core\Form;

use PDO;

/**
 * Data-access layer for `form_submission_reviews` (migration 194): what the office
 * recorded about a submission. All SQL touching the table lives here.
 *
 * APPEND-ONLY, so there is no update and no delete: the newest row is the current
 * review, and the older ones are who decided what, and when.
 *
 * Stateless apart from the injected handle — worker-safe.
 */
final class FormSubmissionReviewRepository
{
    private const COLUMNS = 'id, tenant_id, submission_id, data, recorded_by_profile_id, recorded_at';

    /** Bounds the history read: a submission reviewed more often than this has a different problem. */
    private const HISTORY_MAX = 100;

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param array<string, mixed> $values The normalized staff answers.
     */
    public function record(int $tenantId, int $submissionId, array $values, ?int $recordedByProfileId): int
    {
        $json = json_encode($values === [] ? new \stdClass() : $values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $stmt = $this->db->prepare(
            'INSERT INTO form_submission_reviews (tenant_id, submission_id, data, recorded_by_profile_id, recorded_at)
             VALUES (:tenant_id, :submission_id, :data, :recorded_by, NOW())'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':submission_id' => $submissionId,
            ':data' => $json === false ? '{}' : $json,
            ':recorded_by' => $recordedByProfileId,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * One submission's reviews, newest first. The first is the current one.
     *
     * @return list<array<string, mixed>>
     */
    public function listForSubmission(int $tenantId, int $submissionId): array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNS . '
               FROM form_submission_reviews
              WHERE tenant_id = :tenant_id AND submission_id = :submission_id
              ORDER BY id DESC
              LIMIT ' . self::HISTORY_MAX
        );
        $stmt->execute([':tenant_id' => $tenantId, ':submission_id' => $submissionId]);

        return array_map([self::class, 'normalizeRow'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalizeRow(array $row): array
    {
        $decoded = json_decode((string) ($row['data'] ?? '{}'), true);

        return [
            'id' => (int) $row['id'],
            'submission_id' => (int) $row['submission_id'],
            'data' => is_array($decoded) && $decoded !== [] ? $decoded : new \stdClass(),
            'recorded_by_profile_id' => $row['recorded_by_profile_id'] === null ? null : (int) $row['recorded_by_profile_id'],
            'recorded_at' => (string) $row['recorded_at'],
        ];
    }
}
