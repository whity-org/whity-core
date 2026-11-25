<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Audit\AuditLogger;
use Whity\Core\Contacts\ContactRepository;
use Whity\Core\CustomerArea\CustomerAccountInviteMailer;
use Whity\Core\Identity\CustomerSafeRole;
use Whity\Core\Identity\InvitationService;
use Whity\Core\Log\ScrubbedErrorLog;
use Whity\Core\RateLimit\RateLimits;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Signup\WorkspaceSignupSettings;
use Whity\Core\Store\SharedStoreInterface;
use Whity\Core\Tenant\TenantContext;

/**
 * Invite to their account (WHIT-1428; design section 41 Q4, CA.6):
 * `POST /api/contacts/{id}/invite`, for staff with `contacts:write`.
 *
 * Emails the contact a link to their account in the workspace's customer
 * area. Accepting (with a password, an existing account or a sign-in
 * provider) makes a customer membership with the workspace's customer role
 * and links THIS contact to the person, whatever the public sign-up mode.
 *
 * Refused in plain words when it cannot work: the contact is archived, has no
 * email, already has an account here, the customer area is off, or the
 * workspace has not chosen the role its customers get (Settings › Sign-up).
 */
final class ContactInviteApiHandler
{
    public const TTL_DAYS = 7;

    public function __construct(
        private readonly ContactRepository $contacts,
        private readonly InvitationService $invitations,
        private readonly CustomerAccountInviteMailer $mailer,
        private readonly WorkspaceSignupSettings $signup,
        private readonly CustomerSafeRole $safeRoles,
        private readonly SettingsService $settings,
        private readonly SharedStoreInterface $store,
        private readonly ?AuditLogger $audit = null,
        /** @var (\Closure(int): ?string)|null the workspace's own name, as the customer area shows it */
        private readonly ?\Closure $workspaceName = null,
    ) {
    }

    /** @param array<string, string> $params */
    public function invite(Request $request, array $params = []): Response
    {
        $tenantId = TenantContext::getTenantId();
        $user = $request->user;
        $profileId = is_object($user) ? ($user->profile_id ?? null) : null;
        if ($tenantId === null || $tenantId <= 0 || !is_int($profileId)) {
            return Response::error('Open a workspace first.', 404);
        }
        $id = (int) ($params['id'] ?? 0);
        $contact = $this->contacts->find($tenantId, $id);
        if ($contact === null) {
            return Response::error('Contact not found', 404);
        }
        $email = strtolower(trim((string) ($contact['email'] ?? '')));
        $refusal = match (true) {
            ($contact['archived_at'] ?? null) !== null => 'This contact is archived.',
            $email === '' => 'Add an email address to this contact first.',
            ($contact['profile_id'] ?? null) !== null => 'This contact already has an account.',
            ($this->settings->effective($tenantId)[SettingsRegistry::CUSTOMER_AREA_ENABLED] ?? 'true') !== 'true' => 'Turn on the customer area in Settings first.',
            default => null,
        };
        if ($refusal !== null) {
            return Response::error($refusal, 409);
        }
        $roleId = $this->signup->get($tenantId)['role_id'];
        if ($roleId === null || !$this->safeRoles->isCustomerSafe($roleId, $tenantId)) {
            return Response::error('Choose the role customers get in Settings › Sign-up first.', 409, ['reason' => 'no_customer_role']);
        }

        $keys = [
            [RateLimits::invitationsPerWorkspace(), 'contact-invite:ws:' . $tenantId],
            [RateLimits::invitationsPerPerson(), 'contact-invite:person:' . $tenantId . ':' . $profileId],
        ];
        foreach ($keys as [$rule, $key]) {
            $limited = $rule->blocked($this->store, $key);
            if ($limited !== null) {
                return $limited;
            }
        }
        foreach ($keys as [$rule, $key]) {
            $rule->record($this->store, $key);
        }

        $out = $this->invitations->inviteContact($tenantId, $id, $email, $roleId, $profileId, self::TTL_DAYS);
        if ($out['result'] === InvitationService::INVITE_ALREADY_MEMBER) {
            return Response::error('This address already has an account here. Link the contact to them instead.', 409, ['reason' => 'already_member']);
        }
        if ($out['result'] === InvitationService::INVITE_CREATED) {
            try {
                $this->mailer->send($tenantId, $email, (string) ($contact['name'] ?? ''), $out['token'], self::TTL_DAYS, $this->workspaceName !== null ? ($this->workspaceName)($tenantId) : null);
            } catch (\Throwable $e) {
                // The invitation stands; staff can send it again.
                ScrubbedErrorLog::failure('[ContactInviteApiHandler] mail', $e);
            }
            $this->audit?->record('tenant.invitation.created', [
                'tenant_id' => $tenantId,
                'actor_user_id' => $profileId,
                'target_type' => 'invitation',
                'target_id' => $out['id'],
                'metadata' => ['kind' => InvitationService::KIND_CUSTOMER, 'role_id' => $roleId, 'contact_id' => $id],
            ]);
        }

        return Response::json(['data' => ['result' => 'sent', 'email' => $email, 'expires_in_days' => self::TTL_DAYS]], 202);
    }
}
