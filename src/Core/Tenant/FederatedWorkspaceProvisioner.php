<?php

declare(strict_types=1);

namespace Whity\Core\Tenant;

use PDO;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Identity\FederatedProviderContext;
use Whity\Core\Plan\DefaultPlanAssigner;
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
 *    role self-registration grants.
 *  - THE PAYMENT GATE, in the same transaction, exactly as registration writes
 *    it: with `auth.registration_payment_required` on, the tenant starts
 *    `expired` / `block_all` and stays closed until billing says otherwise. A
 *    listener can only open a workspace later, never be the thing that closes
 *    it, so a listener that fails cannot leave one unbilled.
 */
final class FederatedWorkspaceProvisioner
{
    /** The base (global, NULL-tenant) role the workspace owner is granted. */
    public const OWNER_ROLE = 'admin';

    /** May create a workspace: every gate holds (WHIT-994). */
    public const STATE_CREATE = 'create';
    /** Already placed somewhere (invited, pending approval or suspended), nothing active. */
    public const STATE_WAITING = 'waiting';
    /** Belongs nowhere, and this instance does not let them create a workspace. */
    public const STATE_NEEDS_INVITE = 'needs_invite';
    /** Deactivated, gone, or not this instance's own provider: nothing to offer. */
    public const STATE_REFUSED = 'refused';

    /** How many "Name 2", "Name 3"… to try before giving up on a free name. */
    private const NAME_ATTEMPTS = 50;

    /** Longest a sign-in waits on somebody else holding the profile row. */
    private const LOCK_TIMEOUT = '5s';

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
        // @tenant-guard-ignore: the person's OWN memberships across every tenant, as at sign-in (ADR 0005 §6)
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
            $this->setLockTimeout();
            $profile = $this->lockProfile($profileId);
            if ($profile === null || $profile['status'] === 'inactive' || $this->hasAnyMembership($profileId)) {
                $this->db->rollBack();
                return null;
            }

            $roleId = $this->ownerRoleId();
            if ($roleId === null) {
                error_log('[sso-workspace] base "' . self::OWNER_ROLE . '" role missing; platform not seeded');
                $this->db->rollBack();
                return null;
            }

            $name = $this->freeName(self::cleanName($name ?? $profile['display_name']));
            $slug = $this->freeSlug();

            $tenantId = TenantProvisioner::withCoreSteps($this->db, $this->hooks)->create($name, $slug);

            // is_primary: this is the person's first and only membership.
            // Written as a boolean literal, not 1 — PostgreSQL rejects the integer.
            $this->db->prepare(
                "INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, is_primary, status, created_at)
                 VALUES (:profile_id, :tenant_id, :role_id, NULL, true, 'active', NOW())"
            )->execute([
                ':profile_id' => $profileId,
                ':tenant_id'  => $tenantId,
                ':role_id'    => $roleId,
            ]);
            $membershipId = (int) $this->db->lastInsertId();

            // The payment gate, written exactly as RegisterApiHandler writes it
            // and for the same reason: a tenant with no plan row is "not billed,
            // never block", so creating it and closing it are one act or neither.
            if ($paymentRequired) {
                $this->db->prepare(
                    'INSERT INTO tenant_plan (tenant_id, status, enforcement_mode, assigned_at)
                     VALUES (:tenant_id, :status, :mode, NOW())'
                )->execute([
                    ':tenant_id' => $tenantId,
                    ':status'    => 'expired',
                    ':mode'      => 'block_all',
                ]);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        // After the commit: applying a plan opens its own transaction. A closed
        // payment gate is a plan row already, so it stays closed.
        DefaultPlanAssigner::forDatabase($this->db)->assign($tenantId);

        // Post-commit, and only because this method owned the transaction: an
        // assertion for an append-only trail must not be made before the write
        // it describes is durable. Best-effort — a listener failure must not
        // undo a workspace that now exists.
        if ($this->hooks !== null) {
            try {
                $this->hooks->dispatch('user.membership.added', [
                    'profile_id'    => $profileId,
                    'tenant_id'     => $tenantId,
                    'membership_id' => $membershipId,
                    'role_id'       => $roleId,
                    'role_name'     => self::OWNER_ROLE,
                    'ou_id'         => null,
                    'status'        => 'active',
                    'is_primary'    => true,
                    'via'           => 'sso_self_provision',
                ]);
                $this->hooks->dispatchAsync('tenant.created.async', ['id' => $tenantId, 'name' => $name]);
            } catch (\Throwable $e) {
                error_log('[sso-workspace] post-commit hooks failed for tenant ' . $tenantId . ': ' . $e->getMessage());
            }
        }

        return $tenantId;
    }

    /**
     * A display name fit to be a tenant name: control characters, bidi
     * overrides/isolates/marks and zero-width characters removed, so one
     * workspace cannot visually impersonate another in an operator's list.
     */
    public static function cleanName(string $displayName): string
    {
        // \p{Cc} control, \p{Cf} format (bidi controls, zero-width, BOM).
        $clean = (string) preg_replace('/[\p{Cc}\p{Cf}]+/u', '', $displayName);
        $clean = trim((string) preg_replace('/\s+/u', ' ', $clean));
        if ($clean === '') {
            $clean = 'Workspace';
        }
        // Leave room for a " 50" suffix inside the VARCHAR(255) column.
        return mb_substr($clean, 0, 240);
    }

    private function globalFlag(string $key, string $default): bool
    {
        $global = $this->settings->getGlobal();
        return ($global[$key] ?? $default) === 'true';
    }

    private function setLockTimeout(): void
    {
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
            $this->db->exec("SET LOCAL lock_timeout = '" . self::LOCK_TIMEOUT . "'");
        }
    }

    /**
     * Lock the profile row for the duration of the transaction.
     *
     * @return array{display_name: string, status: string}|null
     */
    private function lockProfile(int $profileId): ?array
    {
        $forUpdate = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? ' FOR UPDATE' : '';
        // Profiles is a sanctioned GLOBAL table (ADR 0005 §1)
        $stmt = $this->db->prepare('SELECT display_name, status FROM profiles WHERE id = :id' . $forUpdate);
        $stmt->execute([':id' => $profileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'display_name' => trim((string) ($row['display_name'] ?? '')),
            'status'       => (string) ($row['status'] ?? 'active'),
        ];
    }

    private function hasAnyMembership(int $profileId): bool
    {
        // @tenant-guard-ignore: the question is "a member of ANY tenant?", which is cross-tenant by definition
        $stmt = $this->db->prepare('SELECT 1 FROM memberships WHERE profile_id = :id LIMIT 1');
        $stmt->execute([':id' => $profileId]);
        return $stmt->fetchColumn() !== false;
    }

    private function ownerRoleId(): ?int
    {
        // Base roles are global (NULL tenant_id); looked up by unique name
        $stmt = $this->db->prepare('SELECT id FROM roles WHERE name = :name AND tenant_id IS NULL LIMIT 1');
        $stmt->execute([':name' => self::OWNER_ROLE]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** $base, or "$base 2", "$base 3"… whichever no tenant holds yet. */
    private function freeName(string $base): string
    {
        // The tenants table is the tenant registry itself, not a tenant-scoped resource
        $stmt = $this->db->prepare('SELECT 1 FROM tenants WHERE name = :name LIMIT 1');
        for ($n = 1; $n <= self::NAME_ATTEMPTS; $n++) {
            $name = $n === 1 ? $base : $base . ' ' . $n;
            $stmt->execute([':name' => $name]);
            if ($stmt->fetchColumn() === false) {
                return $name;
            }
        }
        // Fifty workspaces of the same name: stop counting and make it unique.
        return $base . ' ' . bin2hex(random_bytes(3));
    }

    /** An opaque slug no tenant holds: `ws-` and twelve hex digits. */
    private function freeSlug(): string
    {
        // The tenants table is the tenant registry itself, not a tenant-scoped resource
        $stmt = $this->db->prepare('SELECT 1 FROM tenants WHERE slug = :slug LIMIT 1');
        do {
            $slug = 'ws-' . bin2hex(random_bytes(6));
            $stmt->execute([':slug' => $slug]);
        } while ($stmt->fetchColumn() !== false);
        return $slug;
    }
}
