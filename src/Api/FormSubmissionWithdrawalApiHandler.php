<?php

declare(strict_types=1);

namespace Whity\Api;

use PDO;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\DocumentWithdrawal;
use Whity\Core\Form\FormSubmissionRepository;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;

/**
 * `POST /api/v1/me/form-submissions/{id}/withdraw` — take back what you sent.
 *
 * No permission, for the same reason `/me/form-submissions` has none: the route only
 * ever reaches the caller's own row. Somebody else's is a 404, not a 403, so this
 * can't be used to learn which submissions exist.
 *
 * Refused once decided: a recorded review or a finished route means somebody has acted
 * on it, and taking it back would leave them having decided on nothing. The submission
 * and the document it produced change in one transaction, so a failure can't leave a
 * withdrawn submission with a document still out for approval.
 */
final class FormSubmissionWithdrawalApiHandler
{
    public function __construct(
        private readonly PDO $db,
        private readonly FormSubmissionRepository $submissions,
        private readonly DocumentRepository $documents,
        private readonly DocumentWithdrawal $documentWithdrawal,
        private readonly ?AuditLogger $audit = null,
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function withdraw(Request $request, array $params): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 403);
        }

        $actor = $request->user;
        $profileId = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id)
            ? $actor->profile_id
            : null;
        if ($profileId === null) {
            return Response::error('Submission not found', 404);
        }

        $id = (int) ($params['id'] ?? 0);

        try {
            $this->db->beginTransaction();

            if (!$this->submissions->markWithdrawn($tenantId, $id, $profileId)) {
                $this->db->rollBack();

                return $this->refusal($tenantId, $id, $profileId);
            }

            $submission = $this->submissions->find($tenantId, $id, $profileId);
            $documentId = $submission['document_id'] ?? null;
            $document = is_int($documentId) ? $this->documents->findById($documentId, $tenantId) : null;
            if ($document !== null) {
                $this->documentWithdrawal->withdraw($tenantId, $profileId, $document);
            }

            $this->audit?->record('forms.submission_withdrawn', [
                'tenant_id' => $tenantId,
                'target_type' => 'form_submission',
                'target_id' => $id,
                'metadata' => ['profile_id' => $profileId, 'document_id' => $documentId],
            ]);

            $this->db->commit();

            return Response::json(['data' => $this->submissions->find($tenantId, $id, $profileId)]);
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('[FormSubmissionWithdrawalApiHandler] withdraw failed: ' . $e->getMessage());

            return Response::error('Could not withdraw this submission', 500);
        }
    }

    /**
     * Why nothing changed, read after the fact: the update itself decides, in one
     * statement, so this only words the answer.
     */
    private function refusal(int $tenantId, int $id, int $profileId): Response
    {
        $submission = $this->submissions->find($tenantId, $id, $profileId);
        if ($submission === null) {
            return Response::error('Submission not found', 404);
        }

        return $submission['withdrawn_at'] !== null
            ? Response::error('This submission has already been withdrawn', 409, ['reason' => 'already_withdrawn'])
            : Response::error('This submission has already been decided', 409, ['reason' => 'already_decided']);
    }
}
