<?php

declare(strict_types=1);

namespace Whity\Api;

use PDO;
use Whity\Auth\RoleChecker;
use Whity\Core\Audit\Actor;
use Whity\Core\Audit\AuditContext;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Http\AfterResponse;
use Whity\Core\Identity\CustomerSafeRole;
use Whity\Core\Identity\InvitationMailer;
use Whity\Core\Identity\InvitationService;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Signup\WorkspaceSignupSettings;
use Whity\Core\Store\SharedStoreInterface;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;
use Whity\Sdk\Hooks\Events;
use Whity\Sdk\Hooks\HookVetoException;

/**
 * A workspace's customers invite their own team (#1872, WHIT-996):
 *   GET    /api/v1/team-invitations       whether I may invite, and what I sent
 *   POST   /api/v1/team-invitations       invite a colleague
 *   DELETE /api/v1/team-invitations/{id}  withdraw one I sent
 *
 * WHO. Only a person who is ONLY a customer in this workspace, and only when an
 * admin turned it on in Settings > Sign-up (off by default). Staff invite
 * through People (`users:write`), never here.
 *
 * INTO WHAT. The inviter's own role, as a customer, with no unit. The caller
 * names nobody's role: the body carries only the address. The role must still
 * be customer-safe, the same test sign-up uses, so a customer whose role was
 * later given admin permissions can't pass it on. RBAC caps every customer
 * anyway; this keeps the invitee from even being offered more.
 *
 * WHAT IT REVEALS. Nothing about who is here. Every address the workspace's
 * domain list allows gets the same 202, whether an invitation went out or not
 * ({@see InvitationService::inviteCustomer()} says when it doesn't).
 *
 * PLUGINS. `membership.customer_inviting` runs before anything is written: a
 * listener may refuse with {@see HookVetoException} (409, its reason shown), or
 * return `annotations`, kept on the invitation and handed back in
 * `membership.customer_joined` when it is accepted. Everything else a listener
 * returns is ignored.
 *
 * LIMITS. Per inviter and per workspace, counted before any work. The email
 * needs the instance's mail transport; without one nothing is delivered. It
 * is sent after the response ({@see AfterResponse}), so the time taken
 * doesn't tell a sent invitation from one that wasn't.
 */
final class TeamInvitationsApiHandler
{
    private const HOUR = 3600;
    private const DAY = 86400;
    private const INVITER_PER_HOUR = 10;
    private const INVITER_PER_DAY = 30;
    private const WORKSPACE_PER_HOUR = 100;

    private const ACCEPTED = 'If this address can join, we sent an invitation to it.';

    public function __construct(
        private readonly PDO $db,
        private readonly InvitationService $service,
        private readonly RoleChecker $roleChecker,
        private readonly WorkspaceSignupSettings $signupSettings,
        private readonly CustomerSafeRole $safeRoles,
        private readonly AuditLogger $audit,
        private readonly SharedStoreInterface $store,
        private readonly SettingsService $settings,
        private readonly ?HookManager $hooks = null,
        private readonly ?InvitationMailer $mailer = null,
    ) {
    }

    public function list(Request $request): Response
    {
        $ctx = $this->customer($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }

        $enabled = $this->signupSettings->get($ctx['tenantId'])['customer_invites'];

        try {
            return Response::json(['data' => [
                'enabled' => $enabled,
                'items' => $this->service->listSentBy($ctx['tenantId'], $ctx['profileId']),
            ]], 200);
        } catch (\Throwable $e) {
            error_log('[team-invitations] list failed: ' . $e->getMessage());

            return Response::error('Failed to list your invitations', 500);
        }
    }

    public function create(Request $request): Response
    {
        $ctx = $this->customer($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        $tenantId = $ctx['tenantId'];
        $profileId = $ctx['profileId'];

        $settings = $this->signupSettings->get($tenantId);
        if (!$settings['customer_invites']) {
            return Response::error('This workspace does not let customers invite people.', 403, ['code' => 'team_invites_off']);
        }

        $roleId = $this->ownRole($profileId, $tenantId);
        if ($roleId === null || !$this->safeRoles->isCustomerSafe($roleId, $tenantId)) {
            return Response::error(
                'Your role here can\'t be passed on. Ask the workspace to invite this person.',
                409,
                ['code' => 'role_not_customer_safe']
            );
        }

        $body = JsonBody::parsed($request);
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 255) {
            return Response::error('Validation failed', 422, ['email' => 'Enter an email address.', 'code' => 'email_invalid']);
        }
        if (!WorkspaceSignupSettings::emailAllowed($email, $settings['allowed_domains'])) {
            return Response::error('Validation failed', 422, [
                'email' => 'This workspace only takes addresses on its own list of domains.',
                'code' => 'domain_not_allowed',
            ]);
        }

        $throttled = $this->throttle($tenantId, $profileId);
        if ($throttled instanceof Response) {
            return $throttled;
        }

        $annotations = [];
        if ($this->hooks !== null) {
            try {
                $result = $this->hooks->dispatch(Events::MEMBERSHIP_CUSTOMER_INVITING, [
                    'tenant_id' => $tenantId,
                    'inviter_profile_id' => $profileId,
                    'email' => $email,
                    'role_id' => $roleId,
                    'annotations' => [],
                ]);
                $annotations = $this->annotations($result['annotations'] ?? []);
            } catch (HookVetoException $e) {
                // A plugin's reason is shown as-is; the SDK docs ask plugins
                // never to refuse in a way that says who is already here.
                return Response::error($e->reason(), 409, ['code' => 'invite_refused', 'reason' => $e->reason()]);
            }
        }

        $ttlDays = $this->ttlDays($tenantId);

        try {
            $result = $this->service->inviteCustomer($tenantId, $email, $roleId, $profileId, $annotations, $ttlDays);
        } catch (\Throwable $e) {
            error_log('[team-invitations] create failed: ' . $e->getMessage());

            return Response::error('Failed to send the invitation', 500);
        }

        if ($result['result'] === InvitationService::INVITE_CREATED) {
            // Who invited whom: the actor is the inviter, the target the
            // invitation, which holds the address and the role.
            $this->audit->record('tenant.invitation.created', [
                'tenant_id' => $tenantId,
                'actor_user_id' => $profileId,
                'target_type' => 'invitation',
                'target_id' => $result['id'],
                'metadata' => ['kind' => InvitationService::KIND_CUSTOMER, 'role_id' => $roleId],
            ]);
            $this->dispatchMail($email, $tenantId, $result['token'], $ttlDays);
        }

        return Response::json(['data' => ['status' => 'sent_if_possible', 'message' => self::ACCEPTED]], 202);
    }

    /** @param array<string, mixed> $params */
    public function revoke(Request $request, array $params = []): Response
    {
        $ctx = $this->customer($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }

        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            return Response::error('A valid invitation id is required', 422);
        }

        try {
            $revoked = $this->service->revokeSentBy($id, $ctx['tenantId'], $ctx['profileId']);
        } catch (\Throwable $e) {
            error_log('[team-invitations] revoke failed: ' . $e->getMessage());

            return Response::error('Failed to withdraw the invitation', 500);
        }

        if (!$revoked) {
            return Response::error('You have no open invitation with that id', 404);
        }

        $this->audit->record('tenant.invitation.revoked', [
            'tenant_id' => $ctx['tenantId'],
            'actor_user_id' => $ctx['profileId'],
            'target_type' => 'invitation',
            'target_id' => $id,
            'metadata' => ['kind' => InvitationService::KIND_CUSTOMER],
        ]);

        return Response::json(['data' => ['id' => $id, 'status' => InvitationService::STATUS_REVOKED]], 200);
    }

    // ── internals ────────────────────────────────────────────────────────────

    /**
     * The caller, when they are only a customer in this workspace.
     *
     * @return array{tenantId: int, profileId: int}|Response
     */
    private function customer(Request $request): array|Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null || $tenantId <= 0) {
            return Response::error('Tenant context is required', 403);
        }

        $actor = $request->user;
        $profileId = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id)
            ? $actor->profile_id
            : null;
        if ($profileId === null) {
            return Response::error('Authentication required', 401);
        }

        // An agent acting for a customer is not the customer: inviting people
        // is theirs to do.
        if (AuditContext::getActor()->kind === Actor::AGENT) {
            return Response::error('Only a person can invite people.', 403, ['code' => 'people_only']);
        }

        if (!$this->roleChecker->isCustomerOnly($profileId, $tenantId)) {
            return Response::error(
                'Team invitations are for customers. Staff invite people from People.',
                403,
                ['code' => 'not_a_customer']
            );
        }

        return ['tenantId' => $tenantId, 'profileId' => $profileId];
    }

    /**
     * The one role the caller's active customer membership carries here. Null
     * when they hold several different ones: then no single role is "their
     * own", and the workspace should decide.
     */
    private function ownRole(int $profileId, int $tenantId): ?int
    {
        $stmt = $this->db->prepare(
            "SELECT DISTINCT role_id FROM memberships
              WHERE profile_id = :pid AND tenant_id = :tid AND status = 'active' AND kind = 'customer'"
        );
        $stmt->execute([':pid' => $profileId, ':tid' => $tenantId]);
        $roles = array_values(array_filter($stmt->fetchAll(PDO::FETCH_COLUMN), static fn ($r): bool => $r !== null));

        return count($roles) === 1 ? (int) $roles[0] : null;
    }

    /**
     * What plugins attached, if it is a small JSON object; anything else is
     * dropped rather than refusing the invite.
     *
     * @return array<string, mixed>
     */
    private function annotations(mixed $raw): array
    {
        if (!is_array($raw) || $raw === [] || array_is_list($raw)) {
            return [];
        }
        $json = json_encode($raw);
        if ($json === false || strlen($json) > InvitationService::MAX_ANNOTATIONS_BYTES) {
            error_log('[team-invitations] plugin annotations dropped: not JSON or over ' . InvitationService::MAX_ANNOTATIONS_BYTES . ' bytes');

            return [];
        }

        /** @var array<string, mixed> $raw */
        return $raw;
    }

    /**
     * Counted before any work, so the limit doesn't depend on the outcome. The
     * threat is a customer account turned into a mail cannon from the
     * workspace's own domain.
     */
    private function throttle(int $tenantId, int $profileId): ?Response
    {
        $keys = [
            ['team-invite:hour:' . $tenantId . ':' . $profileId, self::INVITER_PER_HOUR, self::HOUR],
            ['team-invite:day:' . $tenantId . ':' . $profileId, self::INVITER_PER_DAY, self::DAY],
            ['team-invite:tenant:' . $tenantId, self::WORKSPACE_PER_HOUR, self::HOUR],
        ];

        foreach ($keys as [$key, $max]) {
            if ($this->store->count($key) >= $max) {
                return Response::error('You have sent a lot of invitations. Try again later.', 429, ['code' => 'too_many'])
                    ->withHeaders(['Retry-After' => (string) max($this->store->ttl($key), 1)]);
            }
        }
        foreach ($keys as [$key, , $window]) {
            $this->store->increment($key, $window);
        }

        return null;
    }

    private function ttlDays(int $tenantId): int
    {
        try {
            $raw = (string) ($this->settings->effective($tenantId)[SettingsRegistry::INVITATION_TTL_DAYS] ?? '');
        } catch (\Throwable) {
            $raw = '';
        }

        return preg_match('/^\d+$/', $raw) === 1 ? (int) $raw : InvitationService::DEFAULT_TTL_DAYS;
    }

    /**
     * Sent after the response, so how long the request took says nothing
     * about whether an invitation went out. Delivery failures never change
     * the response.
     */
    private function dispatchMail(string $email, int $tenantId, string $token, int $ttlDays): void
    {
        $mailer = $this->mailer;
        if ($mailer === null || $token === '') {
            return;
        }

        $stmt = $this->db->prepare('SELECT name FROM tenants WHERE id = :id');
        $stmt->execute([':id' => $tenantId]);
        $workspace = (string) ($stmt->fetchColumn() ?: '');

        AfterResponse::defer(static function () use ($mailer, $email, $workspace, $token, $ttlDays): void {
            try {
                $mailer->sendInvitation($email, $workspace, $token, $ttlDays);
            } catch (\Throwable $e) {
                error_log('[team-invitations] mail dispatch failed: ' . $e->getMessage());
            }
        });
    }
}
