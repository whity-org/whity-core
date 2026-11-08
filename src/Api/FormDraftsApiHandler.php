<?php

declare(strict_types=1);

namespace Whity\Api;

use PDO;
use Whity\Auth\RoleChecker;
use Whity\Core\Form\FieldType;
use Whity\Core\Form\FormDraftRepository;
use Whity\Core\Form\FormFieldRepository;
use Whity\Core\Form\FormRepository;
use Whity\Core\Form\FormStatus;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * A person's own unsent answers (migration 210), so they can carry on from another
 * device.
 *
 * No route permission, like `/api/me/inbox`: every row names the caller. The handler
 * checks they may fill this form in at all, which means holding `forms:submit` or being
 * an active member of a workspace that opened it to members. Nobody else reads a draft,
 * including an administrator, because nobody has declared it yet.
 *
 * NOT VALIDATED, on purpose: half-finished answers are what a draft is. Only the shape
 * is bounded. Staff questions and `file` answers are dropped, because the first was
 * never asked of this person and an upload reference is single-use and expires.
 */
final class FormDraftsApiHandler
{
    /** A draft is one form's typed answers, and text answers are capped per question. */
    private const MAX_BYTES = 256 * 1024;

    public function __construct(
        private readonly PDO $db,
        private readonly FormRepository $forms,
        private readonly FormFieldRepository $fields,
        private readonly FormDraftRepository $drafts,
        private readonly RoleChecker $roles,
    ) {
    }

    /**
     * GET /api/v1/me/form-drafts — "Continue where you left off".
     */
    public function list(Request $request): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();
            $profileId = self::actorProfileId($request);
            if ($tenantId === null || $profileId === null) {
                return Response::json(['data' => []]);
            }

            $mayFillAny = $this->roles->hasPermissionForProfile($profileId, CorePermissions::FORMS_SUBMIT, $tenantId);
            $member = $this->isActiveMember($tenantId, $profileId);

            $rows = array_values(array_filter(
                $this->drafts->listForProfile($tenantId, $profileId),
                static fn (array $row): bool => $mayFillAny || ($member && $row['open_to_members'] === true),
            ));

            return Response::json(['data' => array_map(static function (array $row): array {
                unset($row['open_to_members']);

                return $row;
            }, $rows)]);
        } catch (\Exception $e) {
            error_log('[FormDraftsApiHandler] list failed: ' . $e->getMessage());

            return Response::error('Failed to fetch drafts', 500);
        }
    }

    /**
     * GET /api/v1/me/forms/{id}/draft — null when there isn't one, which is ordinary.
     *
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        try {
            $context = $this->context($request, $params);
            if ($context === null) {
                return Response::error('Form not found', 404);
            }
            [$tenantId, $profileId, $formId] = $context;

            return Response::json(['data' => $this->drafts->find($tenantId, $profileId, $formId)]);
        } catch (\Exception $e) {
            error_log('[FormDraftsApiHandler] show failed: ' . $e->getMessage());

            return Response::error('Failed to fetch the draft', 500);
        }
    }

    /**
     * PUT /api/v1/me/forms/{id}/draft — replace it with these answers.
     *
     * @param array<string, string> $params
     */
    public function save(Request $request, array $params): Response
    {
        try {
            $context = $this->context($request, $params);
            if ($context === null) {
                return Response::error('Form not found', 404);
            }
            [$tenantId, $profileId, $formId] = $context;

            $body = JsonBody::parsed($request);
            $data = $body['data'] ?? [];
            if (!is_array($data)) {
                return Response::error('data must be an object of answers keyed by field key', 422);
            }
            $step = $body['step'] ?? null;
            if ($step !== null && (!is_int($step) || $step < 0)) {
                return Response::error('step must be a whole number or null', 422);
            }

            $kept = [];
            foreach (FormFieldRepository::applicantFields($this->fields->listForForm($tenantId, $formId)) as $field) {
                $key = (string) $field['field_key'];
                if (array_key_exists($key, $data) && $field['field_type'] !== FieldType::FILE) {
                    $kept[$key] = $data[$key];
                }
            }

            if (strlen((string) json_encode($kept)) > self::MAX_BYTES) {
                return Response::error('This draft is too large to save', 422, ['reason' => 'draft_too_large']);
            }

            $this->drafts->save($tenantId, $profileId, $formId, $kept, $step);

            return Response::json(['data' => $this->drafts->find($tenantId, $profileId, $formId)]);
        } catch (\Exception $e) {
            error_log('[FormDraftsApiHandler] save failed: ' . $e->getMessage());

            return Response::error('Failed to save the draft', 500);
        }
    }

    /**
     * DELETE /api/v1/me/forms/{id}/draft — start over. Idempotent, and it works on a
     * form the person can no longer fill in, because their answers are still theirs to
     * remove.
     *
     * @param array<string, string> $params
     */
    public function delete(Request $request, array $params): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();
            $profileId = self::actorProfileId($request);
            if ($tenantId === null || $profileId === null) {
                return Response::error('Form not found', 404);
            }

            $this->drafts->delete($tenantId, $profileId, (int) ($params['id'] ?? 0));

            return Response::json(['data' => ['deleted' => true]]);
        } catch (\Exception $e) {
            error_log('[FormDraftsApiHandler] delete failed: ' . $e->getMessage());

            return Response::error('Failed to delete the draft', 500);
        }
    }

    /**
     * The same 404 for every reason, so this can't be used to learn which forms exist.
     *
     * @param array<string, string> $params
     * @return array{0: int, 1: int, 2: int}|null
     */
    private function context(Request $request, array $params): ?array
    {
        $tenantId = TenantContext::getTenantId();
        $profileId = self::actorProfileId($request);
        if ($tenantId === null || $profileId === null) {
            return null;
        }

        $formId = (int) ($params['id'] ?? 0);
        $form = $this->forms->find($tenantId, $formId);
        if ($form === null || !FormStatus::acceptsSubmissions((string) ($form['status'] ?? ''))) {
            return null;
        }

        $mayFill = $this->roles->hasPermissionForProfile($profileId, CorePermissions::FORMS_SUBMIT, $tenantId)
            || (MemberFormsApiHandler::openToSignedIn($form) && $this->isActiveMember($tenantId, $profileId));

        return $mayFill ? [$tenantId, $profileId, $formId] : null;
    }

    private function isActiveMember(int $tenantId, int $profileId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM memberships
              WHERE tenant_id = :tenant_id AND profile_id = :profile_id AND status = :status
              LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':profile_id' => $profileId, ':status' => 'active']);

        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }

    private static function actorProfileId(Request $request): ?int
    {
        $actor = $request->user;

        return is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id)
            ? $actor->profile_id
            : null;
    }
}
