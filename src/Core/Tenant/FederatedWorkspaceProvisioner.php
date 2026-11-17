<?php

declare(strict_types=1);

namespace Whity\Core\Tenant;

use PDO;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Identity\FederatedProviderContext;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;

/**
 * A workspace of their own for somebody whose first federated sign-in finds
 * them a member of nothing.
 *
 * WHY THIS EXISTS
 * ---------------
 * A person who signs in through the OPERATOR's identity provider (global trust,
 * tenant 0) for the first time gets a profile from
 * {@see \Whity\Core\Identity\FederatedIdentityLinker} and no membership, because
 * nothing says which tenant they belong to. They then bounce with
 * `no_membership`, and the only way in is an administrator creating a tenant for
 * them by hand. For an instance that sells self-service workspaces through an
 * external identity provider (the provider owns sign-up and billing, this
 * instance owns the product) that hand step is exactly the step that must not
 * exist. This is the federated twin of {@see \Whity\Api\RegisterApiHandler}:
 * the provider has already verified the address, so there is no password to
 * set and no approval queue to wait in.
 *
 * ASKED, NOT SILENT (WHIT-994)
 * ---------------------------
 * The sign-in callback no longer creates the workspace itself. It signs the
 * person in to a short-lived "no workspace yet" state, and they land on a
 * "Create your workspace" step ({@see stateFor()} picks the screen); only
 * their POST of that step calls {@see provisionFor()}, with the name they
 * chose. Every gate below is still checked here, under the lock, on that POST.
 *
 * WHEN IT ACTS — every condition, checked HERE, or nothing
 * --------------------------------------------------------
 * Each gate is re-checked inside this class rather than trusted from the
 * caller, so a future caller (an invite flow, a CLI) cannot mint admin tenants
 * by skipping the callback's checks:
 *
 *  - The operator turned it on: {@see SettingsRegistry::SSO_SELF_PROVISION_WORKSPACE},
 *    global-only, default OFF. A sovereign instance stays operator-provisioned.
 *    Do NOT turn it on for a consumer identity provider anyone can sign up to
 *    (Google, say) unless one workspace per throwaway account is acceptable:
 *    there is no cap beyond the provider's own sign-up friction.
 *  - The provider is GLOBAL TRUST ({@see FederatedProviderContext::isGlobalTrust()}).
 *    A tenant's own bring-your-own IdP must never mint tenants on this instance.
 *  - The profile is not DEACTIVATED. `profiles.status = 'inactive'` is the
 *    operator's deny switch; removing somebody's last membership must not turn
 *    it into a free new workspace.
 *  - The profile holds NO membership row in ANY status. An invited, suspended
 *    or pending membership means somebody already decided where this person
 *    belongs, and a fresh workspace would route around that decision.
 *  - It runs in a transaction of its OWN. Called inside somebody else's, a
 *    failure would abort theirs and the "post-commit" announcements would claim
 *    a membership their rollback could still erase, so it refuses.
 *
 * The profile row is locked for the check-then-create (PostgreSQL, with a lock
 * timeout so a stuck transaction cannot pin a sign-in worker), so two callbacks
 * racing for one person produce one workspace, not two.
 *
 * WHAT THEY GET
 * -------------
 *  - A tenant created through {@see TenantProvisioner} (announced with
 *    `tenant.created`, given everything core gives every tenant). `tenant.created`
 *    runs INSIDE this transaction, exactly as on the admin path: a listener that
 *    throws rolls the workspace back and the person bounces with no_membership.
 *    Plugin work with outside side effects belongs on `tenant.created.async`.
 *  - Its NAME is the one they typed on the "Create your workspace" step
 *    (WHIT-994), or else their display name, with control and bidi-override
 *    characters removed, numbered when taken; never their email address.
 *  - Its SLUG is opaque (`ws-` + 12 hex). A slug is a host label under
 *    BRANDING_BASE_DOMAIN, and a display name an outsider chose must never be
 *    able to claim `app.` or `admin.` or a future customer's brand.
 *  - An ACTIVE, primary membership with the base `admin` role, the same owner
 *    role self-registration grants, and the owner row (WHIT-1169), written by
 *    {@see WorkspaceFounder}.
 *  - THE PAYMENT GATE, in the same transaction, exactly as registration writes
 *    it: with `auth.registration_payment_required` on, the tenant starts
 *    `expired` / `block_all` and stays closed until billing says otherwise. A
 *    listener can only open a workspace later, never be the thing that closes
 *    it, so a listener that fails cannot leave one unbilled.
 */
final class FederatedWorkspaceProvisioner
{
    /** The base (global, NULL-tenant) role the workspace owner is granted. */
    public const OWNER_ROLE = WorkspaceFounder::OWNER_ROLE;

    /** May create a workspace: every gate holds (WHIT-994). */
    public const STATE_CREATE = 'create';
    /** Already placed somewhere (invited, pending approval or suspended), nothing active. */
    public const STATE_WAITING = 'waiting';
    /** Belongs nowhere, and this instance does not let them create a workspace. */
    public const STATE_NEEDS_INVITE = 'needs_invite';
    /** Deactivated, gone, or not this instance's own provider: nothing to offer. */
    public const STATE_REFUSED = 'refused';

    public function __construct(
        private readonly PDO $db,
        private readonly SettingsService $settings,
        private readonly ?HookManager $hooks = null,
    ) {
    }

    public function enabled(): bool
    {
        return $this->globalFlag(SettingsRegistry::SSO_SELF_PROVISION_WORKSPACE, 'false');
    }

    /**
     * Which "no workspace yet" screen this person gets after a sign-in that
     * found no active membership (WHIT-994). The same gates
     * {@see provisionFor()} applies, read WITHOUT a lock: it chooses a screen
     * and grants nothing, and {@see provisionFor()} checks every gate again
     * under the row lock before anything is created.
     *
     * A tenant's own provider is always REFUSED here: its sign-ins are
     * confined to that tenant, so it is never offered a workspace or told
     * about another tenant's invitations.
     *
     * @return self::STATE_*
     */
    public function stateFor(int $profileId, FederatedProviderContext $provider): string
    {
        if (!$provider->isGlobalTrust()) {
            return self::STATE_REFUSED;
        }
        // Profiles is a sanctioned GLOBAL table (ADR 0005 §1)
        $stmt = $this->db->prepare('SELECT status FROM profiles WHERE id = :id');
        $stmt->execute([':id' => $profileId]);
        $status = $stmt->fetchColumn();
        if ($status === false || (string) $status === 'inactive') {
            return self::STATE_REFUSED;
        }
        if ($this->hasAnyMembership($profileId)) {
            return self::STATE_WAITING;
        }
        return $this->enabled() ? self::STATE_CREATE : self::STATE_NEEDS_INVITE;
    }

    public function mayOffer(int $profileId, FederatedProviderContext $provider): bool
    {
        return $this->stateFor($profileId, $provider) === self::STATE_CREATE;
    }

    /**
     * The workspaces this person holds a membership in that is not active yet
     * (invited, pending approval, suspended), so the "waiting" screen can say
     * which. Their own memberships only.
     *
     * @return list<array{name: string, status: string}>
     */
    public function waitingWorkspaces(int $profileId): array
    {
        // The person's OWN memberships across every tenant, as at sign-in (ADR 0005 §6)
        $stmt = $this->db->prepare(
            "SELECT t.name, m.status
               FROM memberships m
               JOIN tenants t ON t.id = m.tenant_id
              WHERE m.profile_id = :id AND m.status <> 'active' AND m.tenant_id <> 0
              ORDER BY m.id ASC"
        );
        $stmt->execute([':id' => $profileId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = ['name' => (string) $row['name'], 'status' => (string) $row['status']];
        }
        return $out;
    }

    /**
     * The person's display name as a workspace name, to prefill the step.
     */
    public function suggestedName(int $profileId): string
    {
        // Profiles is a sanctioned GLOBAL table (ADR 0005 §1)
        $stmt = $this->db->prepare('SELECT display_name FROM profiles WHERE id = :id');
        $stmt->execute([':id' => $profileId]);
        $name = $stmt->fetchColumn();
        $clean = self::cleanName(is_string($name) ? $name : '');
        return $clean === 'Workspace' ? '' : $clean;
    }

    /**
     * Create the person's workspace, or do nothing.
     *
     * @param string|null $name What the person typed on the create step, or null
     *                          for their display name. Cleaned the same way.
     *
     * @return int|null the new tenant id, or null when nothing was created
     *                  (switched off, not global trust, deactivated, already a
     *                  member somewhere, or the owner role is missing)
     *
     * @throws \LogicException when called inside an open transaction
     */
    public function provisionFor(int $profileId, string $email, FederatedProviderContext $provider, ?string $name = null): ?int
    {
        if (!$provider->isGlobalTrust() || !$this->enabled()) {
            return null;
        }
        if ($this->db->inTransaction()) {
            throw new \LogicException('FederatedWorkspaceProvisioner must own its transaction; it was called inside one.');
        }
        $paymentRequired = $this->globalFlag(SettingsRegistry::REGISTRATION_PAYMENT_REQUIRED, 'false');

        $this->db->beginTransaction();
        try {
            $founder = new WorkspaceFounder($this->db, $this->hooks);
            $profile = $founder->lockProfile($profileId);
            if ($profile === null || $profile['status'] === 'inactive' || $this->hasAnyMembership($profileId)) {
                $this->db->rollBack();
                return null;
            }

            $roleId = $founder->ownerRoleId();
            if ($roleId === null) {
                error_log('[sso-workspace] base "' . self::OWNER_ROLE . '" role missing; platform not seeded');
                $this->db->rollBack();
                return null;
            }

            $founded = $founder->found($profileId, $name ?? $profile['display_name'], $roleId, $paymentRequired);

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        // Post-commit, and only because this method owned the transaction: an
        // assertion for an append-only trail must not be made before the write
        // it describes is durable.
        $founder->afterCommit($founded, $profileId, 'sso_self_provision');

        return $founded['tenant_id'];
    }

    /** {@see WorkspaceFounder::cleanName()}; kept for existing callers. */
    public static function cleanName(string $displayName): string
    {
        return WorkspaceFounder::cleanName($displayName);
    }

    private function globalFlag(string $key, string $default): bool
    {
        $global = $this->settings->getGlobal();
        return ($global[$key] ?? $default) === 'true';
    }

    private function hasAnyMembership(int $profileId): bool
    {
        // @tenant-guard-ignore: the question is "a member of ANY tenant?", which is cross-tenant by definition
        $stmt = $this->db->prepare('SELECT 1 FROM memberships WHERE profile_id = :id LIMIT 1');
        $stmt->execute([':id' => $profileId]);
        return $stmt->fetchColumn() !== false;
    }
}
