<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Form\FormFieldRepository;
use Whity\Core\Form\FormRejectedException;
use Whity\Core\Form\FormSubmissionRepository;
use Whity\Core\Form\FormSubmissionReviewRepository;
use Whity\Core\Form\SubmissionIssuer;
use Whity\Core\Form\SubmissionValidator;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * What the office recorded about a submission: its decision, its notes, anything
 * the form's staff fields ask.
 *
 * Reading is on `forms:read`, because somebody who can read a submission needs to
 * know what became of it. Recording is on `forms:review` (migration 195). The
 * applicant has no route here at all, and `/me/form-submissions` carries none of it:
 * telling them the outcome is a decision for the office, and it has no surface yet.
 *
 * A submission stays immutable. See {@see FormSubmissionsApiHandler}: this records
 * beside it, and each save is a new row, so changing a decision leaves the old one
 * readable.
 */
final class FormSubmissionReviewsApiHandler
{
    public function __construct(
        private readonly FormFieldRepository $fields,
        private readonly FormSubmissionRepository $submissions,
        private readonly FormSubmissionReviewRepository $reviews,
        private readonly SubmissionIssuer $issuer,
    ) {
    }

    /**
     * GET /api/v1/form-submissions/{id}/review — the current review, its history,
     * and the staff fields it answers.
     *
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null) {
                return Response::error('Tenant context is required', 403);
            }

            $submission = $this->submissions->find($tenantId, (int) ($params['id'] ?? 0));
            if ($submission === null) {
                return Response::error('Submission not found', 404);
            }

            $history = $this->reviews->listForSubmission($tenantId, (int) $submission['id']);

            return Response::json(['data' => [
                'current' => $history[0] ?? null,
                'history' => $history,
                'fields' => FormFieldRepository::staffFields(
                    $this->fields->listForForm($tenantId, (int) $submission['form_id'])
                ),
            ]]);
        } catch (\Exception $e) {
            error_log('[FormSubmissionReviewsApiHandler] show failed: ' . $e->getMessage());

            return Response::error('Failed to fetch the review', 500);
        }
    }

    /**
     * POST /api/v1/form-submissions/{id}/review — record a review, checked against
     * the form's staff fields exactly as a submission is checked against its own.
     *
     * Sent whole rather than as a patch, so the newest row alone says what the office
     * currently holds. It works on a closed form too: an office decides after the
     * form has stopped taking submissions.
     *
     * @param array<string, string> $params
     */
    public function record(Request $request, array $params): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null) {
                return Response::error('Tenant context is required', 403);
            }

            $submission = $this->submissions->find($tenantId, (int) ($params['id'] ?? 0));
            if ($submission === null) {
                return Response::error('Submission not found', 404);
            }

            $staffFields = FormFieldRepository::staffFields(
                $this->fields->listForForm($tenantId, (int) $submission['form_id'])
            );
            if ($staffFields === []) {
                return Response::error('This form has no staff fields to record', 422);
            }

            $body = JsonBody::parsed($request);
            $data = $body['data'] ?? [];
            if (!is_array($data)) {
                return Response::error('data must be an object of answers keyed by field key', 422);
            }

            /** @var array<string, mixed> $answers */
            $answers = [];
            foreach ($data as $key => $value) {
                if (is_string($key)) {
                    $answers[$key] = $value;
                }
            }

            $checked = SubmissionValidator::validate($staffFields, $answers);
            $this->issuer->assertReferencesExist($tenantId, $staffFields, $checked['values']);

            $actor = $request->user;
            $actorProfileId = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id)
                ? $actor->profile_id
                : null;

            $this->reviews->record($tenantId, (int) $submission['id'], $checked['values'], $actorProfileId);

            return Response::json([
                'data' => $this->reviews->listForSubmission($tenantId, (int) $submission['id'])[0],
                'meta' => ['ignored_keys' => $checked['ignored']],
            ], 201);
        } catch (FormRejectedException $e) {
            return Response::error($e->clientMessage, 422, $e->details());
        } catch (\Exception $e) {
            error_log('[FormSubmissionReviewsApiHandler] record failed: ' . $e->getMessage());

            return Response::error('Failed to record the review', 500);
        }
    }
}
