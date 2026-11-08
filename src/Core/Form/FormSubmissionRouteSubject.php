<?php

declare(strict_types=1);

namespace Whity\Core\Form;

use PDO;
use Whity\Core\Document\Routing\RouteSubjectProviderInterface;

/**
 * The `form_submission` route subject (WHIT-920, #1724).
 *
 * A submission travels on its route as itself: sending a form raises no
 * document, and the finalise step (#1725) makes one. Routes on submissions sent
 * before this were re-pointed here by migration 233 and keep their documents.
 *
 *  - The AUTHOR is the person who sent it, while they are still an active member
 *    or customer of the workspace. An anonymous public submission has none, so
 *    it cannot be sent back.
 *  - The SNAPSHOT is the answers as sent, with the form version they answered.
 *  - The author CAN edit it while it is sent back, and resubmitting records a
 *    new version.
 *
 * Stateless apart from the injected handle — worker-safe.
 */
final class FormSubmissionRouteSubject implements RouteSubjectProviderInterface
{
    public const TYPE = 'form_submission';

    public function __construct(private readonly PDO $db)
    {
    }

    public function type(): string
    {
        return self::TYPE;
    }

    /** The form's name, in the language its author wrote first. */
    public function title(int $tenantId, int $subjectId): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT f.name, f.form_key FROM form_submissions s
               JOIN forms f ON f.id = s.form_id AND f.tenant_id = s.tenant_id
              WHERE s.id = :id AND s.tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $subjectId, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $name = LocalizedLabel::preferred(LocalizedLabel::decode(isset($row['name']) ? (string) $row['name'] : null));

        return $name !== '' ? $name : (string) $row['form_key'];
    }

    public function documentId(int $tenantId, int $subjectId): ?int
    {
        $stmt = $this->db->prepare(
            'SELECT document_id FROM form_submissions WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $subjectId, ':tenant_id' => $tenantId]);
        $documentId = $stmt->fetchColumn();

        return $documentId === false || $documentId === null ? null : (int) $documentId;
    }

    /**
     * The person who sent it, while they still belong to the workspace. Null for
     * an anonymous submission and for somebody who has left.
     */
    public function author(int $tenantId, int $subjectId): ?int
    {
        $stmt = $this->db->prepare(
            "SELECT s.submitted_by_profile_id FROM form_submissions s
              WHERE s.id = :id AND s.tenant_id = :tenant_id
                AND s.submitted_by_profile_id IS NOT NULL
                AND EXISTS (
                    SELECT 1 FROM memberships m
                     WHERE m.tenant_id = :tenant_id AND m.profile_id = s.submitted_by_profile_id
                       AND m.status = 'active'
                )"
        );
        $stmt->execute([':id' => $subjectId, ':tenant_id' => $tenantId]);
        $author = $stmt->fetchColumn();

        return $author === false || $author === null ? null : (int) $author;
    }

    /**
     * What the reviewers are looking at: the answers, and which version of the
     * form they answer. Not the reference or the timestamps, which do not change
     * when the answers do.
     *
     * @return array<string, mixed>|null
     */
    public function snapshot(int $tenantId, int $subjectId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT form_id, form_version, data FROM form_submissions WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $subjectId, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $values = is_string($row['data'] ?? null) ? json_decode($row['data'], true) : null;

        return [
            'form_id' => (int) $row['form_id'],
            'form_version' => (int) $row['form_version'],
            // An object even when empty, so the hash of "no answers" is stable.
            'values' => is_array($values) && $values !== [] ? $values : new \stdClass(),
        ];
    }

    /** The author edits the answers while it is sent back. */
    public function editableWhenSentBack(): bool
    {
        return true;
    }

    public function isWithdrawn(int $tenantId, int $subjectId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT withdrawn_at FROM form_submissions WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $subjectId, ':tenant_id' => $tenantId]);
        $at = $stmt->fetchColumn();

        return $at !== false && $at !== null;
    }
}
