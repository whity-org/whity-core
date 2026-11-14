<?php

declare(strict_types=1);

namespace Whity\Core\Form;

use PDO;
use Whity\Core\Document\Routing\RouteStepValueRepository;
use Whity\Database\SequenceCounters;

/**
 * Hands out a form's references (WHIT-1017): on submit, or when the
 * submission's route finishes approved ({@see FormReferenceAssign}).
 *
 * Always inside the caller's transaction, so a refused submission or a rolled
 * back approval does not keep a number. A sequence gives each format unique
 * values, but two DIFFERENT formats can still print the same text (`A{SEQ}` at
 * 15 and `A1{SEQ}` at 5), so a value already issued is skipped rather than left
 * to fail on the unique index. The limit only guards against looping forever
 * on a broken counter.
 *
 * Stateless apart from the injected handle — worker-safe.
 */
final class FormReferences
{
    private const ATTEMPTS = 20;

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * The next reference in this format, or null when the format is empty.
     *
     * @param array<string, mixed>  $answers    The submission's answers by question key.
     * @param array<string, string> $stepValues Values entered at flow steps, by key.
     *
     * @throws FormRejectedException When no free reference can be found, or the
     *         format renders longer than a reference can be.
     */
    public function next(int $tenantId, ?string $format, array $answers = [], array $stepValues = [], ?int $formId = null): ?string
    {
        if ($format === null || $format === '') {
            return null;
        }

        $at = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $counters = new SequenceCounters($this->db);
        $submissions = new FormSubmissionRepository($this->db);

        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $reference = FormReferenceFormat::render(
                $format,
                $counters->next($tenantId, FormReferenceFormat::counterName($format, $at, $answers)),
                $at,
                $answers,
                $stepValues,
            );
            if (mb_strlen($reference) > FormReferenceFormat::REFERENCE_MAX) {
                throw new FormRejectedException(
                    'This form could not give your submission a reference. Please try again.',
                    'form reference longer than ' . FormReferenceFormat::REFERENCE_MAX . ' characters on form ' . (string) ($formId ?? '?'),
                );
            }
            if (!$submissions->referenceExists($tenantId, $reference)) {
                return $reference;
            }
        }

        throw new FormRejectedException(
            'This form could not give your submission a reference. Please try again.',
            'no free form reference for format on form ' . (string) ($formId ?? '?'),
        );
    }

    /**
     * Give a submission its reference now that its route finished approved,
     * when its form gives references on approval and it has none yet. The
     * answers are the approved version's; the step values are the route's.
     *
     * @return string|null The reference given by this call, or null.
     *
     * @throws FormRejectedException
     */
    public function onApproval(int $tenantId, int $submissionId, int $routeId): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT s.reference, s.form_id, f.reference_format, f.reference_assign
               FROM form_submissions s
               JOIN forms f ON f.id = s.form_id AND f.tenant_id = s.tenant_id
              WHERE s.id = :id AND s.tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $submissionId, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        if (!is_array($row)
            || $row['reference'] !== null
            || ($row['reference_assign'] ?? FormReferenceAssign::SUBMIT) !== FormReferenceAssign::APPROVAL
            || !is_string($row['reference_format']) || $row['reference_format'] === '') {
            return null;
        }

        // The stored answers: a resubmit replaces them, so they are the ones approved.
        $submission = (new FormSubmissionRepository($this->db))->find($tenantId, $submissionId);
        $answers = is_array($submission['data'] ?? null) ? $submission['data'] : [];

        $reference = $this->next(
            $tenantId,
            (string) $row['reference_format'],
            $answers,
            (new RouteStepValueRepository($this->db))->valuesForRoute($tenantId, $routeId),
            (int) $row['form_id'],
        );
        if ($reference === null) {
            return null;
        }

        $write = $this->db->prepare(
            'UPDATE form_submissions SET reference = :reference
              WHERE id = :id AND tenant_id = :tenant_id AND reference IS NULL'
        );
        $write->execute([':reference' => $reference, ':id' => $submissionId, ':tenant_id' => $tenantId]);

        return $write->rowCount() > 0 ? $reference : null;
    }
}
