<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Identity\PasswordResetMailer;
use Whity\Core\Identity\TwoFactorRecoveryService;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Security\IdentityCheck;
use Whity\Http\PaginationParams;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * Pending 2FA-recovery-request review API (WC-password-reset-2fa-recovery;
 * two admins since AG.1):
 *  - GET  /api/v1/2fa-recovery/pending            — list pending requests
 *  - POST /api/v1/2fa-recovery/identity-check     — send or name the admin's "confirm it's you" check
 *  - POST /api/v1/2fa-recovery/{id}/approve       — first or second admin's approval
 *  - POST /api/v1/2fa-recovery/{id}/checks        — record a check that it's really the person
 *  - POST /api/v1/2fa-recovery/{id}/reject        — leave the target untouched
 *  - POST /api/v1/2fa-recovery/force-reset        — start a reset for a named
 *    profile with NO prior request (a user who cannot even receive email);
 *    this is the first approval, and a second admin still has to approve
 *
 * TWO ADMINS (AG.1). Clearing someone's two-step takes two different admins,
 * and the person asking can never approve their own. Each approval first
 * confirms the admin is who they say ({@see IdentityCheck}: their
 * authenticator code, or a code emailed to them). The first approval only
 * records itself; the second clears the two-step and sends the reset link.
 *
 * TENANT-SCOPED to the TARGET profile's OWN tenant (mirrors
 * {@see PasswordResetApprovalsApiHandler}). Gated on
 * {@see CorePermissions::TWO_FACTOR_RECOVERY_APPROVE}.
 */
final class TwoFactorRecoveryApprovalsApiHandler
{
    private const WHAT = 'approving a two-step reset';

    public function __construct(
        private readonly TwoFactorRecoveryService $service,
        private readonly RoleChecker $roleChecker,
        private readonly AuditLogger $audit,
        private readonly ?PasswordResetMailer $resetMailer = null,
        private readonly ?IdentityCheck $identity = null,
    ) {}

    /**
     * GET /api/v1/2fa-recovery/pending — list pending requests for profiles
     * that hold an active membership in the caller's own tenant.
     */
    public function listPending(Request $request): Response
    {
        $ctx = $this->authorize($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }

        try {
            $p = PaginationParams::fromPath($request->getPath());
            $items = $this->service->listPendingForTenant($ctx['tenantId'], $p->perPage, $p->offset);

            return Response::json([
                'data' => $items,
                'pagination' => $p->meta($this->service->countPendingForTenant($ctx['tenantId'])),
            ], 200);
        } catch (\Throwable $e) {
            error_log('[2fa-recovery-approvals] list failed: ' . $e->getMessage());

            return Response::error('Failed to list pending 2FA-recovery requests', 500);
        }
    }

    /**
     * POST /api/v1/2fa-recovery/identity-check — what the admin confirms with,
     * and for an emailed code, send it. Body: `{ "request_id": <int> }` to
     * approve a request, or `{ "profile_id": <int> }` to start one.
     */
    public function identityCheck(Request $request): Response
    {
        $ctx = $this->authorize($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        if ($this->identity === null) {
            return Response::error('Confirming it is you is not available, so nothing can be approved', 503);
        }

        $purpose = self::purposeFrom(JsonBody::parsed($request));
        if ($purpose === null) {
            return Response::error('A request_id or profile_id is required', 422);
        }

        $started = $this->identity->start($ctx['tenantId'], $ctx['userId'], $purpose, self::WHAT);
        if ($started === null) {
            return Response::error('Too many codes were sent, or your account has no email address. Wait a few minutes and try again.', 429);
        }

        return Response::json(['data' => $started], 200);
    }

    /**
     * POST /api/v1/2fa-recovery/{id}/approve — `{ "code": "<code>" }`.
     * The first admin's approval is recorded; the second, different admin's
     * clears the TARGET profile's 2FA and sends a fresh password-reset link.
     *
     * @param array<string, mixed> $params
     */
    public function approve(Request $request, array $params = []): Response
    {
        $ctx = $this->authorize($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }

        $requestId = (int) ($params['id'] ?? 0);
        if ($requestId <= 0) {
            return Response::error('A valid request id is required', 422);
        }

        $method = $this->confirmIdentity($ctx, 'request:' . $requestId, JsonBody::parsed($request));
        if ($method instanceof Response) {
            return $method;
        }

        try {
            $result = $this->service->approveForTenant($requestId, $ctx['tenantId'], $ctx['userId'], $method);
            if (is_string($result)) {
                return self::refusal($result);
            }
            if ($result === null) {
                return Response::error('No pending 2FA-recovery request found for that id', 404);
            }

            if ($result['stage'] === 'first') {
                $this->audit->record('auth.2fa_recovery.first_approved', [
                    'actor_user_id' => $ctx['userId'],
                    'target_type'   => 'profile',
                    'target_id'     => $result['profile_id'],
                    'metadata'      => ['request_id' => $requestId, 'identity_check' => $method],
                ]);

                return Response::json(['data' => ['id' => $requestId, 'status' => 'waiting_for_second_admin']], 200);
            }

            $this->auditApproval($ctx['userId'], $result['profile_id'], $method);
            $this->sendResetLink((string) ($result['email'] ?? ''), (string) ($result['reset_token'] ?? ''));

            return Response::json(['data' => ['id' => $requestId, 'status' => 'approved']], 200);
        } catch (\Throwable $e) {
            error_log('[2fa-recovery-approvals] approve failed: ' . $e->getMessage());

            return Response::error('Failed to approve the 2FA-recovery request', 500);
        }
    }

    /**
     * POST /api/v1/2fa-recovery/{id}/checks — `{ "text": "Called him on the number on file" }`.
     *
     * @param array<string, mixed> $params
     */
    public function addCheck(Request $request, array $params = []): Response
    {
        $ctx = $this->authorize($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }

        $requestId = (int) ($params['id'] ?? 0);
        $body = JsonBody::parsed($request);
        $text = is_string($body['text'] ?? null) ? trim($body['text']) : '';
        if ($requestId <= 0 || $text === '' || mb_strlen($text) > 300) {
            return Response::error('Say what you checked, in up to 300 characters', 422);
        }

        if (!$this->service->addCheckForTenant($requestId, $ctx['tenantId'], $ctx['userId'], $text)) {
            return Response::error('No pending 2FA-recovery request found for that id', 404);
        }
        $this->audit->record('auth.2fa_recovery.check_recorded', [
            'actor_user_id' => $ctx['userId'],
            'target_type'   => 'two_factor_recovery_request',
            'target_id'     => $requestId,
        ]);

        return Response::json(['data' => ['id' => $requestId, 'status' => 'pending']], 200);
    }

    /**
     * POST /api/v1/2fa-recovery/{id}/reject — the target profile is left
     * completely untouched. Either admin may reject, without an identity check:
     * saying no can't weaken an account.
     *
     * @param array<string, mixed> $params
     */
    public function reject(Request $request, array $params = []): Response
    {
        $ctx = $this->authorize($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }

        $requestId = (int) ($params['id'] ?? 0);
        if ($requestId <= 0) {
            return Response::error('A valid request id is required', 422);
        }

        try {
            $result = $this->service->rejectForTenant($requestId, $ctx['tenantId']);
            if ($result === null) {
                return Response::error('No pending 2FA-recovery request found for that id', 404);
            }

            $this->audit->record('auth.2fa_recovery.rejected', [
                'actor_user_id' => $ctx['userId'],
                'target_type'   => 'profile',
                'target_id'     => $result['profile_id'],
            ]);

            return Response::json(['data' => ['id' => $requestId, 'status' => 'rejected']], 200);
        } catch (\Throwable $e) {
            error_log('[2fa-recovery-approvals] reject failed: ' . $e->getMessage());

            return Response::error('Failed to reject the 2FA-recovery request', 500);
        }
    }

    /**
     * POST /api/v1/2fa-recovery/force-reset — an admin starts a two-step
     * reset for a named profile in their own tenant, with NO prior self-service
     * request. It counts as the first approval; a second, different admin
     * approves it to clear the two-step. Audited distinctly.
     *
     * Body: `{ "profile_id": <int>, "code": "<code>", "reason": "<why>" }`.
     */
    public function forceReset(Request $request): Response
    {
        $ctx = $this->authorize($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }

        $body = JsonBody::parsed($request);
        $targetProfileId = isset($body['profile_id']) && is_numeric($body['profile_id'])
            ? (int) $body['profile_id']
            : 0;
        if ($targetProfileId <= 0) {
            return Response::error('A valid profile_id is required', 422);
        }

        $method = $this->confirmIdentity($ctx, 'profile:' . $targetProfileId, $body);
        if ($method instanceof Response) {
            return $method;
        }

        try {
            $reason = is_string($body['reason'] ?? null) ? $body['reason'] : null;
            $result = $this->service->forceResetForTenant($targetProfileId, $ctx['tenantId'], $ctx['userId'], $method, $reason);
            if (is_string($result)) {
                return self::refusal($result);
            }
            if ($result === null) {
                return Response::error('No such profile in your tenant', 404);
            }

            $this->audit->record('auth.2fa_recovery.forced', [
                'actor_user_id' => $ctx['userId'],
                'target_type'   => 'profile',
                'target_id'     => $result['profile_id'],
                'metadata'      => ['request_id' => $result['request_id'], 'identity_check' => $method],
            ]);

            return Response::json(['data' => [
                'id'         => $result['request_id'],
                'profile_id' => $targetProfileId,
                'status'     => 'waiting_for_second_admin',
            ]], 200);
        } catch (\Throwable $e) {
            error_log('[2fa-recovery-approvals] force-reset failed: ' . $e->getMessage());

            return Response::error('Failed to start the two-step reset', 500);
        }
    }

    /**
     * @param array{tenantId:int,userId:int} $ctx
     * @param array<string, mixed>           $body
     */
    private function confirmIdentity(array $ctx, string $purpose, array $body): string|Response
    {
        if ($this->identity === null) {
            return Response::error('Confirming it is you is not available, so nothing can be approved', 503);
        }
        $code = is_string($body['code'] ?? null) ? $body['code'] : null;
        $method = $this->identity->verify($ctx['tenantId'], $ctx['userId'], $purpose, $code);
        if ($method === null) {
            return Response::error('Confirm it is you first: that code is not correct or has expired.', 403, ['reason' => 'identity_check_failed']);
        }

        return $method;
    }

    /** @param array<string, mixed> $body */
    private static function purposeFrom(array $body): ?string
    {
        if (isset($body['request_id']) && is_numeric($body['request_id']) && (int) $body['request_id'] > 0) {
            return 'request:' . (int) $body['request_id'];
        }
        if (isset($body['profile_id']) && is_numeric($body['profile_id']) && (int) $body['profile_id'] > 0) {
            return 'profile:' . (int) $body['profile_id'];
        }

        return null;
    }

    private static function refusal(string $reason): Response
    {
        return $reason === TwoFactorRecoveryService::REFUSED_OWN_REQUEST
            ? Response::error('You can\'t approve a two-step reset for your own account. Another admin has to.', 403, ['reason' => $reason])
            : Response::error('You gave the first approval. A different admin has to give the second.', 409, ['reason' => $reason]);
    }

    private function auditApproval(int $actorUserId, int $targetProfileId, string $method): void
    {
        // Two distinct, separately-auditable steps per the brief: the 2FA was
        // actually cleared, and a follow-up reset was issued+sent — never
        // collapsed into one vague "approved" entry.
        $this->audit->record('auth.2fa_recovery.approved', [
            'actor_user_id' => $actorUserId,
            'target_type'   => 'profile',
            'target_id'     => $targetProfileId,
            'metadata'      => ['identity_check' => $method],
        ]);
        $this->audit->record('auth.2fa_recovery.two_factor_cleared', [
            'actor_user_id' => $actorUserId,
            'target_type'   => 'profile',
            'target_id'     => $targetProfileId,
        ]);
        $this->audit->record('auth.2fa_recovery.reset_link_sent', [
            'actor_user_id' => $actorUserId,
            'target_type'   => 'profile',
            'target_id'     => $targetProfileId,
        ]);
    }

    private function sendResetLink(string $email, string $rawToken): void
    {
        if ($this->resetMailer === null || $email === '' || $rawToken === '') {
            return;
        }
        try {
            $this->resetMailer->sendResetLink($email, $rawToken);
        } catch (\Throwable $e) {
            error_log('[2fa-recovery-approvals] reset-link dispatch failed: ' . $e->getMessage());
        }
    }

    /**
     * two_factor_recovery:approve gate, scoped to the caller's OWN tenant.
     *
     * @return array{tenantId:int,userId:int}|Response
     */
    private function authorize(Request $request): array|Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 403);
        }

        $actor = $request->user;
        $userId = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id)
            ? $actor->profile_id
            : null;

        if ($userId === null
            || !$this->roleChecker->hasPermissionForProfile($userId, CorePermissions::TWO_FACTOR_RECOVERY_APPROVE, $tenantId)) {
            return Response::error('Insufficient permissions', 403, ['required' => CorePermissions::TWO_FACTOR_RECOVERY_APPROVE]);
        }

        return ['tenantId' => $tenantId, 'userId' => $userId];
    }
}
