<?php

declare(strict_types=1);

namespace Whity\Core\Form;

use PDO;
use Whity\Core\Document\RouteTemplate\RouteTemplateInstantiation;
use Whity\Core\Document\RouteTemplate\RouteTemplateRejectedException;
use Whity\Core\Document\RouteTemplate\RouteTemplateRepository;
use Whity\Core\Document\Routing\DocumentRouter;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\Document\Routing\RouteRepository;
use Whity\Core\Document\Routing\RoutingRejectedException;
use Whity\Database\SequenceCounters;

/**
 * Turns a validated answer set into a SUBMISSION and — when the form names a
 * route template — a live route on that submission.
 *
 * THE ROUTE TRAVELS ON THE SUBMISSION (WHIT-920, #1724)
 * ----------------------------------------------------
 * A submission used to raise a `documents` row at once and route the document.
 * It no longer does: the route's subject is the submission itself
 * (`form_submission`, {@see FormSubmissionRouteSubject}), and the finalise step
 * makes the document once the route is done (#1725). So while a submission is
 * being reviewed its author can be asked to change it, and resubmitting records
 * a new version of the answers rather than a new document.
 *
 * This class writes NO routing logic. It reads the design, hands it to
 * {@see RouteTemplateInstantiation} — the same converter the document routing
 * handler uses — and calls {@see DocumentRouter::issueOnSubject()}.
 *
 * ONE TRANSACTION
 * ---------------
 * The submission row, the attachment claims and the route are written inside
 * ONE transaction, opened here unless a caller already holds one. Nothing in
 * this path touches object storage, so the transaction is total. The state
 * being refused is a submission that looks sent and reaches nobody: if the
 * route cannot be issued, nothing is written and the person is told, so they
 * still have what they typed.
 *
 * A `file` ANSWER IS CLAIMED, NOT TRUSTED
 * ---------------------------------------
 * A form can ask "upload your paper", and the answer is the storage key of an
 * object {@see FormUploadStore} already wrote (migration 133). The claim
 * ({@see FormUploadRepository::claim()}) is a conditional UPDATE binding this
 * tenant, this form, this uploader and `claimed_at IS NULL`, and it records the
 * submission the file belongs to. A key under another tenant's prefix, or one
 * already spent, matches no row and is refused. The file is then served from
 * the submission ({@see \Whity\Api\FormSubmissionsApiHandler}), and the
 * finalise step carries it onto the document it makes.
 */
final class SubmissionIssuer
{
    public function __construct(
        private readonly PDO $db,
        private readonly FormSubmissionRepository $submissions,
        private readonly RouteTemplateRepository $routeTemplates,
        private readonly DocumentRouter $router,
        private readonly FormUploadRepository $uploads,
        private readonly RouteRepository $routes,
        private readonly RouteRecipientRepository $recipients,
    ) {
    }

    /**
     * Record one submission.
     *
     * @param array<string, mixed>       $form   A normalized `forms` row.
     * @param list<array<string, mixed>> $fields The form's fields, in order.
     * @param array<string, mixed>       $data   The RAW submitted answers.
     *
     * @return array{submission: array<string, mixed>, ignored: list<string>, routed: bool}
     *
     * @throws FormRejectedException When the form is not accepting submissions,
     *         when an answer fails validation, or when the form's route template
     *         cannot be run as drawn.
     */
    public function submit(int $tenantId, ?int $actorProfileId, array $form, array $fields, array $data): array
    {
        $status = (string) ($form['status'] ?? FormStatus::DRAFT);
        if (!FormStatus::acceptsSubmissions($status)) {
            // Named for the person, not for the state machine: "draft" is a word
            // about the author's workflow and means nothing to a submitter.
            throw new FormRejectedException('This form is not accepting submissions right now');
        }

        // Validation BEFORE anything is written, so somebody fixing a ten-field
        // form is told which field is wrong rather than watching a half-recorded
        // submission appear.
        $checked = SubmissionValidator::validate($fields, $data);
        $values = $checked['values'];

        // References are resolved against the CALLER'S TENANT, so a `profile_ref`
        // answer naming somebody in another organisation is refused rather than
        // stored as a cross-tenant pointer.
        $this->assertReferencesExist($tenantId, $fields, $values);

        $formId = (int) $form['id'];
        $routeTemplateId = $form['route_template_id'] ?? null;
        $routeTemplateId = is_int($routeTemplateId) ? $routeTemplateId : null;

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $title = $this->titleFor($form);

            // No document: the route travels on the submission (WHIT-920).
            $submissionId = $this->submissions->create(
                $tenantId,
                $formId,
                (int) ($form['version'] ?? 1),
                $actorProfileId,
                null,
                $values,
                $this->referenceFor($tenantId, $form),
            );

            // In this transaction, so the answers are either a draft or a submission,
            // and a refused submission leaves the person's draft alone.
            if ($actorProfileId !== null) {
                $this->db->prepare(
                    'DELETE FROM form_drafts WHERE tenant_id = :tenant_id AND form_id = :form_id AND profile_id = :profile_id'
                )->execute([':tenant_id' => $tenantId, ':form_id' => $formId, ':profile_id' => $actorProfileId]);
            }

            // Attachments BEFORE the route: a file that could not be claimed must
            // stop the submission before anybody has been sent an inbox item.
            $this->attach($tenantId, $actorProfileId, $formId, $submissionId, $fields, $values, []);

            $routed = false;
            $routeId = null;
            if ($routeTemplateId !== null) {
                $routeId = $this->route($tenantId, $actorProfileId, $submissionId, $title, $routeTemplateId);
                $routed = true;
            }

            $submission = $this->submissions->find($tenantId, $submissionId);

            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        if ($submission === null) {
            throw new \RuntimeException('The submission was recorded but could not be read back.');
        }

        // WHIT-921: a route that went straight to its final document makes it
        // only now, after the commit, so a refused submission makes nothing.
        if ($ownTransaction && $routeId !== null) {
            $this->router->finalisePending($tenantId, $routeId);
        }

        return ['submission' => $submission, 'ignored' => $checked['ignored'], 'routed' => $routed];
    }

    /**
     * The author changes a sent-back submission and sends it again (WHIT-920).
     *
     * Only the author, and only while an item sent back to them is open on the
     * submission's route: the answers are replaced and
     * {@see DocumentRouter::resubmit()} records the new version and resumes the
     * route where the sender chose, all in one transaction. Without an open
     * send-back nothing is changed: a submission under review is never edited
     * behind its reviewers' backs.
     *
     * The answers are checked exactly as a new submission's are. A file already
     * attached to this submission is kept as it is; a new one is claimed.
     *
     * @param array<string, mixed>       $submission The author's own submission.
     * @param list<array<string, mixed>> $fields     The questions the author answers.
     * @param array<string, mixed>       $data       The RAW new answers.
     *
     * @return array{submission: array<string, mixed>, ignored: list<string>}
     *
     * @throws FormRejectedException When nothing was sent back to this person, or
     *         an answer fails validation.
     */
    public function resubmit(
        int $tenantId,
        int $authorProfileId,
        array $submission,
        array $fields,
        array $data,
        ?string $note,
    ): array {
        $submissionId = (int) $submission['id'];
        $route = $this->sentBackRoute($tenantId, $submissionId, $authorProfileId);
        if ($route !== null && $route['document_id'] !== null) {
            // A submission sent before routes travelled on submissions keeps its
            // document, and its route goes on treating it as a document: an
            // issued document is not edited in place.
            throw new FormRejectedException(
                'This submission already has its document, so it cannot be changed. Send a new one instead.',
                '',
                null,
                'has_document',
            );
        }
        if ($route === null) {
            throw new FormRejectedException(
                'This submission is not waiting for your changes, so it cannot be edited.',
                '',
                null,
                'not_sent_back',
            );
        }

        $checked = SubmissionValidator::validate($fields, $data);
        $values = $checked['values'];
        $this->assertReferencesExist($tenantId, $fields, $values);

        $kept = [];
        foreach ($this->uploads->forSubmission($tenantId, $submissionId) as $upload) {
            $kept[$upload['storage_key']] = true;
        }

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $this->attach($tenantId, $authorProfileId, (int) $submission['form_id'], $submissionId, $fields, $values, $kept);
            $this->submissions->replaceValues($tenantId, $submissionId, $values);

            try {
                $this->router->resubmit($tenantId, $authorProfileId, $route, $note);
            } catch (RoutingRejectedException $e) {
                throw new FormRejectedException($e->clientMessage, 'resubmit refused: ' . $e->getMessage(), $e);
            }

            $fresh = $this->submissions->find($tenantId, $submissionId);

            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        if ($fresh === null) {
            throw new \RuntimeException('The submission was changed but could not be read back.');
        }

        // WHIT-921: after the commit, as for a new submission.
        if ($ownTransaction) {
            $this->router->finalisePending($tenantId, (int) $route['id']);
        }

        return ['submission' => $fresh, 'ignored' => $checked['ignored']];
    }

    /**
     * The route on this submission holding an open item sent back to this
     * person, or null.
     *
     * @return array<string, mixed>|null
     */
    public function sentBackRoute(int $tenantId, int $submissionId, int $profileId): ?array
    {
        foreach ($this->routes->listForSubject(FormSubmissionRouteSubject::TYPE, $submissionId, $tenantId) as $route) {
            $item = $this->recipients->findOpenForProfile($tenantId, (int) $route['id'], $profileId);
            if ($item !== null && $item['role'] === RouteRecipientRepository::ROLE_AUTHOR) {
                return $route;
            }
        }

        return null;
    }

    /**
     * Claim every `file` answer for this submission.
     *
     * THE CLAIM IS THE AUTHORIZATION. It asks {@see FormUploadRepository::claim()}
     * to spend an upload belonging to THIS tenant, on THIS form, made by THIS
     * caller, and not yet spent — and a null is every one of those failing. One
     * refusal sentence for all of them, about the file rather than the system.
     *
     * @param list<array<string, mixed>> $fields
     * @param array<string, mixed>       $values The NORMALIZED answers.
     * @param array<string, true>        $kept   Keys already attached to this
     *        submission (a resubmission keeps them without claiming them again).
     *
     * @throws FormRejectedException On the first `file` answer that is not a
     *         live, unspent upload of this caller's on this form.
     */
    private function attach(
        int $tenantId,
        ?int $actorProfileId,
        int $formId,
        int $submissionId,
        array $fields,
        array $values,
        array $kept,
    ): void {
        foreach ($fields as $field) {
            if ((string) ($field['field_type'] ?? '') !== FieldType::FILE) {
                continue;
            }
            $key = (string) ($field['field_key'] ?? '');
            $answer = $values[$key] ?? null;

            foreach (is_array($answer) ? $answer : [$answer] as $reference) {
                if (!is_string($reference) || $reference === '' || isset($kept[$reference])) {
                    continue;
                }

                $upload = $this->uploads->claim($tenantId, $formId, $reference, $actorProfileId, $submissionId);
                if ($upload === null) {
                    /** @var array<string, string> $label */
                    $label = is_array($field['label'] ?? null) ? $field['label'] : [];
                    $name = LocalizedLabel::preferred($label);
                    throw new FormRejectedException(
                        'We could not find the file attached to '
                            . ($name !== '' ? $name : $key)
                            . ' — please attach it again.',
                        'form upload claim failed on field ' . $key . ' in tenant ' . $tenantId,
                        null,
                        'file_not_found',
                        $key,
                    );
                }
            }
        }
    }

    /**
     * Start the form's route on the submission.
     *
     * No routing logic here — see the class docblock. Both exceptions are
     * re-thrown as {@see FormRejectedException} carrying the ORIGINAL client
     * message, which is already written for the person who can fix it.
     */
    private function route(int $tenantId, ?int $actorProfileId, int $submissionId, string $title, int $templateId): int
    {
        $template = $this->routeTemplates->findById($templateId, $tenantId);
        if ($template === null) {
            throw new FormRejectedException(
                'This form points at a route template that no longer exists — ask an administrator to update it'
            );
        }

        try {
            $steps = RouteTemplateInstantiation::toRouteSteps(
                $this->routeTemplates->stepsFor($templateId, $tenantId),
                $this->routeTemplates->edgesFor($templateId, $tenantId),
            );
        } catch (RouteTemplateRejectedException $e) {
            throw new FormRejectedException($e->clientMessage, 'route template not runnable: ' . $e->getMessage(), $e);
        }

        try {
            $issued = $this->router->issueOnSubject(
                $tenantId,
                $actorProfileId,
                FormSubmissionRouteSubject::TYPE,
                $submissionId,
                null,
                $title,
                $steps,
                $templateId,
                (string) $template['name'],
            );
        } catch (RoutingRejectedException $e) {
            throw new FormRejectedException($e->clientMessage, 'route issue failed: ' . $e->getMessage(), $e);
        }

        return (int) $issued['route']['id'];
    }

    /**
     * The form's next reference, or null when it gives none.
     *
     * In the submission's transaction, so a refused submission does not spend a
     * number. A sequence gives each format unique values, but two DIFFERENT formats
     * can still print the same text (`A{SEQ}` at 15 and `A1{SEQ}` at 5), so a value
     * already issued is skipped rather than left to fail on the unique index. The
     * limit only guards against looping forever on a broken counter.
     *
     * @param array<string, mixed> $form
     *
     * @throws FormRejectedException When no free reference can be found.
     */
    private function referenceFor(int $tenantId, array $form): ?string
    {
        $format = $form['reference_format'] ?? null;
        if (!is_string($format) || $format === '') {
            return null;
        }

        $at = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $counters = new SequenceCounters($this->db);

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $reference = FormReferenceFormat::render(
                $format,
                $counters->next($tenantId, FormReferenceFormat::counterName($format, $at)),
                $at,
            );
            if (!$this->submissions->referenceExists($tenantId, $reference)) {
                return $reference;
            }
        }

        throw new FormRejectedException(
            'This form could not give your submission a reference. Please try again.',
            'no free form reference for format on form ' . (string) ($form['id'] ?? '?'),
        );
    }

    /**
     * What the resulting document is CALLED.
     *
     * The form's own name, which is what a reader scanning a document list or an
     * inbox needs to see — "Equipment request", not "Submission #4821". The
     * submitter is not in the title: `documents.created_by` already records them
     * and a title that repeated it would be redundant in every list that shows
     * both, while being wrong in the one case that matters (a submission made by
     * a service principal, which has no profile at all).
     *
     * Truncated to 255 to match `documents.title`, so the column and this
     * function agree instead of the database silently truncating what this
     * accepted.
     *
     * @param array<string, mixed> $form
     */
    private function titleFor(array $form): string
    {
        /** @var array<string, string> $name */
        $name = is_array($form['name'] ?? null) ? $form['name'] : [];
        $title = LocalizedLabel::preferred($name);

        if ($title === '') {
            $title = (string) ($form['form_key'] ?? 'Form submission');
        }

        return mb_substr($title, 0, 255);
    }

    /**
     * Refuse a `profile_ref` / `ou_ref` answer naming a row this tenant does not
     * have.
     *
     * Both reads bind `tenant_id`, so an id from another tenant is ABSENT — the
     * answer is refused as "not a record in this tenant" rather than stored as a
     * pointer across an isolation boundary. `profiles` is global, so the tenant
     * predicate goes on `memberships`: what makes a person a record OF this
     * tenant is that they hold an active membership in it, which is a stronger
     * and more accurate question than "does this profile id exist".
     *
     * @param list<array<string, mixed>> $fields
     * @param array<string, mixed>       $values
     *
     * @throws FormRejectedException
     */
    public function assertReferencesExist(int $tenantId, array $fields, array $values): void
    {
        foreach ($fields as $field) {
            $type = (string) ($field['field_type'] ?? '');
            if (!FieldType::isReference($type)) {
                continue;
            }
            $key = (string) ($field['field_key'] ?? '');
            $value = $values[$key] ?? null;
            if (!is_int($value)) {
                continue;
            }

            $exists = $type === FieldType::PROFILE_REF
                ? $this->profileIsMember($tenantId, $value)
                : $this->ouExists($tenantId, $value);

            if (!$exists) {
                /** @var array<string, string> $label */
                $label = is_array($field['label'] ?? null) ? $field['label'] : [];
                $name = LocalizedLabel::preferred($label);
                throw new FormRejectedException(
                    ($name !== '' ? $name : $key) . ' must name a record in this tenant',
                    '',
                    null,
                    'bad_reference',
                    $key,
                );
            }
        }
    }

    private function profileIsMember(int $tenantId, int $profileId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM memberships
              WHERE tenant_id = :tenant_id AND profile_id = :profile_id AND status = :status
              LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':profile_id' => $profileId, ':status' => 'active']);

        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }

    private function ouExists(int $tenantId, int $ouId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM organizational_units WHERE tenant_id = :tenant_id AND id = :id LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $ouId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }
}
