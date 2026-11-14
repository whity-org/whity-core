<?php

declare(strict_types=1);

namespace Whity\Core\Form;

use PDO;
use Whity\Core\Document\Routing\RouteSubjectFinaliseInterface;
use Whity\Core\Document\Routing\RouteSubjectApprovedInterface;
use Whity\Core\Document\Routing\RouteSubjectPeopleInterface;
use Whity\Core\Document\Routing\RouteSubjectProviderInterface;
use Whity\Core\Document\Routing\RoutingRejectedException;

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
final class FormSubmissionRouteSubject implements RouteSubjectProviderInterface, RouteSubjectFinaliseInterface, RouteSubjectApprovedInterface, RouteSubjectPeopleInterface
{
    /**
     * WHIT-1018: the people the submission names in these questions (its
     * people pickers, such as a supervisor), from its answers as they are now.
     * A key that is not a people question names nobody.
     *
     * @param list<string> $keys
     * @return list<int>
     */
    public function peopleNamedIn(int $tenantId, int $subjectId, array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        $submission = (new FormSubmissionRepository($this->db))->find($tenantId, $subjectId);
        if ($submission === null) {
            return [];
        }
        $answers = is_array($submission['data']) ? $submission['data'] : [];
        $people = [];
        foreach ((new FormFieldRepository($this->db))->listForForm($tenantId, (int) $submission['form_id']) as $field) {
            if (($field['field_type'] ?? null) !== 'profile_ref' || !in_array((string) $field['field_key'], $keys, true)) {
                continue;
            }
            $answer = $answers[(string) $field['field_key']] ?? null;
            foreach (is_array($answer) ? $answer : [$answer] as $id) {
                if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                    $people[(int) $id] = (int) $id;
                }
            }
        }

        return array_values($people);
    }

    /**
     * WHIT-1017: a submission whose form gives references on approval takes
     * its reference now, printing its answers and the values entered on the
     * route.
     */
    public function approved(int $tenantId, int $subjectId, int $routeId): void
    {
        try {
            (new FormReferences($this->db))->onApproval($tenantId, $subjectId, $routeId);
        } catch (FormRejectedException $e) {
            // Refused rather than approved without the reference it was owed:
            // the approval rolls back and can simply be given again.
            throw RoutingRejectedException::because(
                'The reference for this submission could not be made, so it was not approved. Try again.'
            );
        }
    }

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

    /** WHIT-921: the form's "Final document template". */
    public function finalTemplateId(int $tenantId, int $subjectId): ?int
    {
        $stmt = $this->db->prepare(
            'SELECT f.final_template_id FROM form_submissions s
               JOIN forms f ON f.id = s.form_id AND f.tenant_id = s.tenant_id
              WHERE s.id = :id AND s.tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $subjectId, ':tenant_id' => $tenantId]);
        $id = $stmt->fetchColumn();

        return $id === false || $id === null ? null : (int) $id;
    }

    /**
     * WHIT-921: the approved answers, as {@see FormSubmissionFieldProvider}
     * names them, so a template written for the form prints them. Choices print
     * by their label, files as "1 file attached", as in the responses export.
     */
    public function finalValues(int $tenantId, int $subjectId, array $version, string $language, array $template = []): array
    {
        $stmt = $this->db->prepare(
            'SELECT s.form_id, s.reference, s.submitted_at, p.display_name
               FROM form_submissions s
               LEFT JOIN profiles p ON p.id = s.submitted_by_profile_id
              WHERE s.id = :id AND s.tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $subjectId, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return [];
        }

        $data = is_array($version['data']) ? $version['data'] : [];
        $answers = is_array($data['values'] ?? null) ? $data['values'] : [];
        $allFields = (new FormFieldRepository($this->db))->listForForm($tenantId, (int) $row['form_id']);

        $values = [];
        foreach (FormFieldRepository::applicantFields($allFields) as $field) {
            $key = (string) $field['field_key'];
            $answer = $answers[$key] ?? null;
            $text = ResponsesCsv::printed($field, $answer, $language);
            $values[FormSubmissionFieldProvider::path($key)] = $text !== '' ? $text : null;
        }

        // A template's own fields print the answer of the question that fits them.
        foreach (FormTemplateFit::ownFieldAnswers($allFields, $template) as $path => $question) {
            $values[$path] = $values[FormSubmissionFieldProvider::path($question)] ?? null;
        }

        return $values + [
            FormSubmissionFieldProvider::REFERENCE => isset($row['reference']) ? (string) $row['reference'] : null,
            FormSubmissionFieldProvider::SENT_BY => isset($row['display_name']) ? (string) $row['display_name'] : null,
            FormSubmissionFieldProvider::SENT_ON => substr((string) $row['submitted_at'], 0, 10),
            FormSubmissionFieldProvider::VERSION => (string) $version['version'],
            FormSubmissionFieldProvider::CONTENT_HASH => (string) $version['content_hash'],
        ];
    }

    public function finalRecord(int $tenantId, int $subjectId): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT f.form_key FROM form_submissions s
               JOIN forms f ON f.id = s.form_id AND f.tenant_id = s.tenant_id
              WHERE s.id = :id AND s.tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $subjectId, ':tenant_id' => $tenantId]);
        $key = $stmt->fetchColumn();

        return $key === false ? null : FormSubmissionFieldProvider::recordTypeFor((string) $key) . ':' . $subjectId;
    }

    /** WHIT-921: the submission now has its document, made by the finalise step. */
    public function attachDocument(int $tenantId, int $subjectId, int $documentId): void
    {
        $this->db->prepare(
            'UPDATE form_submissions SET document_id = :document_id
              WHERE id = :id AND tenant_id = :tenant_id AND document_id IS NULL'
        )->execute([':document_id' => $documentId, ':id' => $subjectId, ':tenant_id' => $tenantId]);
    }
}
