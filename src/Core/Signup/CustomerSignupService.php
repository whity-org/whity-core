<?php

declare(strict_types=1);

namespace Whity\Core\Signup;

use PDO;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Identity\CustomerSafeRole;
use Whity\Core\Identity\MembershipRepository;

/**
 * Workspace-controlled customer sign-up (WHIT-915).
 *
 * The second kind of self-service sign-up. Platform sign-up
 * ({@see \Whity\Api\RegisterApiHandler}) creates a workspace and is governed by
 * the operator; this one joins ONE existing workspace as a customer and is
 * governed by that workspace's admins. It never creates a tenant.
 *
 * THE LIFECYCLE, and what exists at each step:
 *
 *   request  → a `customer_signups` row (`unverified`) and an emailed token.
 *              Nothing about the address is looked up, so the response and the
 *              work done are the same whether or not it has an account.
 *   confirm  → the token proves the mailbox. The profile is found or created
 *              (profiles are global, ADR 0005), then:
 *                Open: a `customer` membership, active now (`joined`).
 *                Approval required: no membership yet (`pending`).
 *   approve  → a workspace admin creates the active `customer` membership.
 *   reject   → the request is closed; no membership ever existed.
 *
 * No membership row exists before the last step on purpose: an `invited` row
 * could be completed by other paths (an invitation, the email-domain policy),
 * which would let a pending customer skip the admin.
 *
 * TENANT BINDING. The request's tenant comes from the public endpoint's host or
 * slug; from then on every step reads it from the row, never from the caller,
 * so a sign-up started for workspace A can only ever produce a membership in A.
 * Admin steps bind the caller's tenant in every statement.
 *
 * Every grant is announced after commit as `user.membership.added` (the audit
 * trail) and `membership.customer_joined` (SDK 1.73).
 */
final class CustomerSignupService
{
    public const STATUS_UNVERIFIED = 'unverified';
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_JOINED = 'joined';
    public const STATUS_SUPERSEDED = 'superseded';

    /** {@see self::confirm()} outcomes. */
    public const CONFIRM_INVALID = 'invalid';
    public const CONFIRM_PASSWORD_REQUIRED = 'password_required';
    public const CONFIRM_CLOSED = 'closed';
    public const CONFIRM_JOINED = 'joined';
    public const CONFIRM_PENDING = 'pending_approval';
    public const CONFIRM_ALREADY_MEMBER = 'already_member';
    public const CONFIRM_REFUSED = 'refused';
    /** {@see self::joinVerified()} only: the address is outside the workspace's domain list. */
    public const CONFIRM_DOMAIN = 'domain';

    /** {@see self::approve()} / {@see self::reject()} outcomes. */
    public const DECIDE_DONE = 'done';
    public const DECIDE_NOT_FOUND = 'not_found';
    public const DECIDE_ROLE_UNSAFE = 'role_unsafe';
    public const DECIDE_CONFLICT = 'conflict';

    /** The hook a plugin listens on (SDK 1.73). */
    public const HOOK_CUSTOMER_JOINED = 'membership.customer_joined';

    /** How long the emailed link works. */
    public const TTL_SECONDS = 86400;

    private const TOKEN_BYTES = 32;

    public function __construct(
        private readonly PDO $db,
        private readonly WorkspaceSignupSettings $settings,
        private readonly CustomerSafeRole $safeRoles,
        private readonly ?HookManager $hooks = null,
    ) {
    }

    /**
     * Record a sign-up request and return the raw token for the mail.
     *
     * Supersedes any request still unverified for the same address here, so
     * only the newest link works. Does not look at profiles or memberships.
     *
     * @param string $email Validated and lowercased by the caller.
     * @return string The raw token, returned once and never stored.
     */
    public function request(int $tenantId, string $email, string $displayName): string
    {
        $token = bin2hex(random_bytes(self::TOKEN_BYTES));

        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }

        try {
            $this->db->prepare(
                'UPDATE customer_signups SET status = :superseded, token_hash = NULL
                  WHERE tenant_id = :tid AND email = :email AND status = :unverified'
            )->execute([
                ':superseded' => self::STATUS_SUPERSEDED,
                ':tid' => $tenantId,
                ':email' => $email,
                ':unverified' => self::STATUS_UNVERIFIED,
            ]);

            $this->db->prepare(
                'INSERT INTO customer_signups (tenant_id, email, display_name, token_hash, status, expires_at, created_at)
                 VALUES (:tid, :email, :name, :hash, :status, :expires, NOW())'
            )->execute([
                ':tid' => $tenantId,
                ':email' => $email,
                ':name' => $displayName,
                ':hash' => hash('sha256', $token),
                ':status' => self::STATUS_UNVERIFIED,
                ':expires' => gmdate('Y-m-d H:i:s', time() + self::TTL_SECONDS),
            ]);

            if ($ownTx) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return $token;
    }

    /**
     * What the holder of a live token is about to confirm. Whether the address
     * has an account is shown here and only here: the caller has proven the
     * mailbox, as with an invitation link.
     *
     * @return array{tenant_id: int, workspace_name: string, email: string, requires_password: bool}|null
     */
    public function preview(string $rawToken): ?array
    {
        $row = $this->findLive($rawToken);
        if ($row === null) {
            return null;
        }

        return [
            'tenant_id' => (int) $row['tenant_id'],
            'workspace_name' => (string) ($row['tenant_name'] ?? ''),
            'email' => (string) $row['email'],
            'requires_password' => $this->profileIdForEmail((string) $row['email']) === null,
        ];
    }

    /**
     * Consume a token.
     *
     * @param string|null $passwordHash Already hashed; used only when the
     *        address has no profile. An existing credential is never changed.
     * @return array{result: string, tenant_id: int|null, profile_id: int|null}
     */
    public function confirm(string $rawToken, ?string $passwordHash): array
    {
        $none = ['result' => self::CONFIRM_INVALID, 'tenant_id' => null, 'profile_id' => null];
        $announce = null;

        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }

        try {
            $row = $this->findLive($rawToken);
            if ($row === null) {
                return $this->finish($ownTx, $none);
            }

            $signupId = (int) $row['id'];
            $tenantId = (int) $row['tenant_id'];
            $email = (string) $row['email'];
            $settings = $this->settings->get($tenantId);

            // Re-checked here, not trusted from request time: the workspace may
            // have closed sign-up, narrowed its domains or changed its role.
            if ($settings['mode'] === WorkspaceSignupSettings::MODE_OFF
                || !WorkspaceSignupSettings::emailAllowed($email, $settings['allowed_domains'])
            ) {
                return $this->finish($ownTx, ['result' => self::CONFIRM_CLOSED, 'tenant_id' => $tenantId, 'profile_id' => null]);
            }
            $roleId = $settings['role_id'];
            if ($roleId === null || !$this->safeRoles->isCustomerSafe($roleId, $tenantId)) {
                error_log('[customer-signup] tenant ' . $tenantId . ' has no customer-safe starting role; sign-up refused');

                return $this->finish($ownTx, ['result' => self::CONFIRM_CLOSED, 'tenant_id' => $tenantId, 'profile_id' => null]);
            }

            $profileId = $this->profileIdForEmail($email);
            if ($profileId === null && ($passwordHash === null || $passwordHash === '')) {
                // Nothing written; the link stays usable.
                return $this->finish($ownTx, ['result' => self::CONFIRM_PASSWORD_REQUIRED, 'tenant_id' => $tenantId, 'profile_id' => null]);
            }
            if ($profileId === null) {
                $profileId = $this->createProfile($email, (string) $row['display_name'], (string) $passwordHash);
            }

            $statuses = $this->membershipStatuses($profileId, $tenantId);
            $earlier = $this->earlierDecision($tenantId, $profileId, $signupId);

            if ($statuses['active']) {
                $outcome = self::CONFIRM_ALREADY_MEMBER;
                $newStatus = self::STATUS_JOINED;
            } elseif ($statuses['suspended'] || $statuses['invited'] || $earlier === self::STATUS_REJECTED) {
                // A suspension or a rejection is an admin's decision, and a staged
                // invitation is completed through the invitation. A new sign-up is
                // not a way around any of them.
                $outcome = self::CONFIRM_REFUSED;
                $newStatus = self::STATUS_SUPERSEDED;
            } elseif ($earlier === self::STATUS_PENDING) {
                // Already waiting; don't queue the same person twice.
                $outcome = self::CONFIRM_PENDING;
                $newStatus = self::STATUS_SUPERSEDED;
            } elseif ($settings['mode'] === WorkspaceSignupSettings::MODE_APPROVAL) {
                $outcome = self::CONFIRM_PENDING;
                $newStatus = self::STATUS_PENDING;
            } else {
                $membershipId = $this->grant($profileId, $tenantId, $roleId, 'customer_signup_open');
                $announce = $this->joinedPayload($profileId, $tenantId, $membershipId, $roleId, 'open', null);
                $outcome = self::CONFIRM_JOINED;
                $newStatus = self::STATUS_JOINED;
            }

            $burn = $this->db->prepare(
                'UPDATE customer_signups
                    SET status = :status, token_hash = NULL, profile_id = :pid, verified_at = NOW()
                  WHERE id = :id AND tenant_id = :tid AND status = :unverified'
            );
            $burn->execute([
                ':status' => $newStatus,
                ':pid' => $profileId,
                ':id' => $signupId,
                ':tid' => $tenantId,
                ':unverified' => self::STATUS_UNVERIFIED,
            ]);
            if ($burn->rowCount() === 0) {
                // A concurrent confirm won. Undo everything this one wrote.
                if ($ownTx && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                return $none;
            }

            $result = $this->finish($ownTx, ['result' => $outcome, 'tenant_id' => $tenantId, 'profile_id' => $profileId]);
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        if ($announce !== null) {
            $this->announce(self::HOOK_CUSTOMER_JOINED, $announce);
        }

        return $result;
    }

    /**
     * Whether this workspace takes sign-ups at all right now: on, with a
     * customer-safe starting role. Asked before a visitor is sent to an identity
     * provider (WHIT-917), and again by {@see self::joinVerified()} afterwards.
     */
    public function isOpen(int $tenantId): bool
    {
        $settings = $this->settings->get($tenantId);
        $roleId = $settings['role_id'];

        return $settings['mode'] !== WorkspaceSignupSettings::MODE_OFF
            && $roleId !== null
            && $this->safeRoles->isCustomerSafe($roleId, $tenantId);
    }

    /**
     * Join a workspace as a customer with an identity a sign-in provider has
     * already verified (WHIT-917: "Continue with Whity ID" on /join/<slug>).
     *
     * The same rules as {@see self::confirm()}: sign-up must be on, the
     * provider-verified address must be on the workspace's domain list, the
     * starting role must be customer-safe, staff stay staff, a suspension, an
     * open invitation or an earlier rejection is not bypassed, and Approval
     * required puts the person in the queue. The provider's verified email
     * stands in for the emailed link, and the provider round trip for the bot
     * check. The profile was found or created by the federated sign-in itself
     * (FederatedIdentityLinker, `auth_method = 'idp'`), so nothing here touches
     * credentials, and no workspace is ever created.
     *
     * @param string $verifiedEmail The address the provider asserted as verified.
     * @return array{result: string, tenant_id: int, profile_id: int}
     */
    public function joinVerified(int $tenantId, int $profileId, string $verifiedEmail, string $displayName): array
    {
        $announce = null;
        $out = static fn (string $result): array => ['result' => $result, 'tenant_id' => $tenantId, 'profile_id' => $profileId];

        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }

        try {
            $settings = $this->settings->get($tenantId);
            $roleId = $settings['role_id'];
            if ($settings['mode'] === WorkspaceSignupSettings::MODE_OFF
                || $roleId === null
                || !$this->safeRoles->isCustomerSafe($roleId, $tenantId)
            ) {
                return $this->finishJoin($ownTx, $out(self::CONFIRM_CLOSED));
            }
            if (!WorkspaceSignupSettings::emailAllowed(strtolower(trim($verifiedEmail)), $settings['allowed_domains'])) {
                return $this->finishJoin($ownTx, $out(self::CONFIRM_DOMAIN));
            }

            $statuses = $this->membershipStatuses($profileId, $tenantId);
            $earlier = $this->earlierDecision($tenantId, $profileId, 0);

            if ($statuses['active']) {
                return $this->finishJoin($ownTx, $out(self::CONFIRM_ALREADY_MEMBER));
            }
            if ($statuses['suspended'] || $statuses['invited'] || $earlier === self::STATUS_REJECTED) {
                return $this->finishJoin($ownTx, $out(self::CONFIRM_REFUSED));
            }
            if ($earlier === self::STATUS_PENDING) {
                return $this->finishJoin($ownTx, $out(self::CONFIRM_PENDING));
            }

            $approval = $settings['mode'] === WorkspaceSignupSettings::MODE_APPROVAL;
            $this->db->prepare(
                'INSERT INTO customer_signups
                    (tenant_id, email, display_name, token_hash, status, profile_id, expires_at, verified_at, created_at)
                 VALUES (:tid, :email, :name, NULL, :status, :pid, NOW(), NOW(), NOW())'
            )->execute([
                ':tid' => $tenantId,
                ':email' => strtolower(trim($verifiedEmail)),
                ':name' => trim($displayName) !== '' ? mb_substr(trim($displayName), 0, 255) : strtolower(trim($verifiedEmail)),
                ':status' => $approval ? self::STATUS_PENDING : self::STATUS_JOINED,
                ':pid' => $profileId,
            ]);

            if ($approval) {
                return $this->finishJoin($ownTx, $out(self::CONFIRM_PENDING));
            }

            $membershipId = $this->grant($profileId, $tenantId, $roleId, 'customer_signup_open');
            $announce = $this->joinedPayload($profileId, $tenantId, $membershipId, $roleId, 'open', null);
            $result = $this->finishJoin($ownTx, $out(self::CONFIRM_JOINED));
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        $this->announce(self::HOOK_CUSTOMER_JOINED, $announce);

        return $result;
    }

    /**
     * @param array{result: string, tenant_id: int, profile_id: int} $outcome
     * @return array{result: string, tenant_id: int, profile_id: int}
     */
    private function finishJoin(bool $ownTx, array $outcome): array
    {
        if ($ownTx && $this->db->inTransaction()) {
            $this->db->commit();
        }

        return $outcome;
    }

    /**
     * The workspace's sign-up requests in one status, oldest first.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function listForTenant(int $tenantId, string $status, int $limit, int $offset): array
    {
        $count = $this->db->prepare('SELECT COUNT(*) FROM customer_signups WHERE tenant_id = :tid AND status = :status');
        $count->execute([':tid' => $tenantId, ':status' => $status]);
        $total = (int) $count->fetchColumn();

        $stmt = $this->db->prepare(
            'SELECT s.id, s.email, s.display_name, s.status, s.created_at, s.verified_at, s.decided_at,
                    p.display_name AS profile_name, d.display_name AS decided_by_name
               FROM customer_signups s
               LEFT JOIN profiles p ON p.id = s.profile_id
               LEFT JOIN profiles d ON d.id = s.decided_by
              WHERE s.tenant_id = :tid AND s.status = :status
              ORDER BY s.created_at ASC, s.id ASC
              LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':tid', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':status', $status);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = trim((string) ($row['profile_name'] ?? ''));
            $items[] = [
                'id' => (int) $row['id'],
                'email' => (string) $row['email'],
                'display_name' => $name !== '' ? $name : (string) $row['display_name'],
                'status' => (string) $row['status'],
                'requested_at' => (string) $row['created_at'],
                'verified_at' => $row['verified_at'] !== null ? (string) $row['verified_at'] : null,
                'decided_at' => $row['decided_at'] !== null ? (string) $row['decided_at'] : null,
                'decided_by_name' => $row['decided_by_name'] !== null ? (string) $row['decided_by_name'] : null,
            ];
        }

        return ['items' => $items, 'total' => $total];
    }

    /** How many verified requests wait for this workspace's admins. */
    public function pendingCount(int $tenantId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM customer_signups WHERE tenant_id = :tid AND status = :status');
        $stmt->execute([':tid' => $tenantId, ':status' => self::STATUS_PENDING]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Let a waiting person in. A rejected request may be approved later: a
     * rejection is reversible until someone else decides otherwise.
     *
     * @return array{result: string, membership_id: int|null}
     */
    public function approve(int $signupId, int $tenantId, int $actorId): array
    {
        $announce = null;
        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }

        try {
            $row = $this->decidable($signupId, $tenantId, [self::STATUS_PENDING, self::STATUS_REJECTED]);
            if ($row === null) {
                return $this->finishDecision($ownTx, self::DECIDE_NOT_FOUND, null);
            }
            $profileId = (int) $row['profile_id'];

            $roleId = $this->settings->get($tenantId)['role_id'];
            if ($roleId === null || !$this->safeRoles->isCustomerSafe($roleId, $tenantId)) {
                return $this->finishDecision($ownTx, self::DECIDE_ROLE_UNSAFE, null);
            }

            $statuses = $this->membershipStatuses($profileId, $tenantId);
            if ($statuses['suspended'] || $statuses['invited']) {
                return $this->finishDecision($ownTx, self::DECIDE_CONFLICT, null);
            }

            $membershipId = null;
            if (!$statuses['active']) {
                $membershipId = $this->grant($profileId, $tenantId, $roleId, 'customer_signup_approved');
                $announce = $this->joinedPayload($profileId, $tenantId, $membershipId, $roleId, 'approval', $actorId);
            }

            $this->decide($signupId, $tenantId, self::STATUS_APPROVED, $actorId);
            $result = $this->finishDecision($ownTx, self::DECIDE_DONE, $membershipId);
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        if ($announce !== null) {
            $this->announce(self::HOOK_CUSTOMER_JOINED, $announce);
        }

        return $result;
    }

    /**
     * Turn a waiting person away. Nothing to undo: no membership exists yet.
     *
     * @return array{result: string, membership_id: int|null}
     */
    public function reject(int $signupId, int $tenantId, int $actorId): array
    {
        $row = $this->decidable($signupId, $tenantId, [self::STATUS_PENDING]);
        if ($row === null) {
            return ['result' => self::DECIDE_NOT_FOUND, 'membership_id' => null];
        }

        $this->decide($signupId, $tenantId, self::STATUS_REJECTED, $actorId);

        return ['result' => self::DECIDE_DONE, 'membership_id' => null];
    }

    // ── internals ────────────────────────────────────────────────────────────

    /** @return array<string, mixed>|null */
    private function findLive(string $rawToken): ?array
    {
        if ($rawToken === '' || strlen($rawToken) > 128) {
            return null;
        }

        // @tenant-guard-ignore: reached from the PUBLIC confirm endpoint, which has no tenant context; the 256-bit single-use token is the authority and names its own tenant
        $stmt = $this->db->prepare(
            'SELECT s.id, s.tenant_id, s.email, s.display_name, t.name AS tenant_name
               FROM customer_signups s
               JOIN tenants t ON t.id = s.tenant_id
              WHERE s.token_hash = :hash AND s.status = :status AND s.expires_at > :now
              LIMIT 1'
        );
        $stmt->execute([
            ':hash' => hash('sha256', $rawToken),
            ':status' => self::STATUS_UNVERIFIED,
            ':now' => gmdate('Y-m-d H:i:s'),
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param list<string> $statuses
     * @return array<string, mixed>|null
     */
    private function decidable(int $signupId, int $tenantId, array $statuses): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, profile_id, status FROM customer_signups
              WHERE id = :id AND tenant_id = :tid AND profile_id IS NOT NULL'
        );
        $stmt->execute([':id' => $signupId, ':tid' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) && in_array((string) $row['status'], $statuses, true) ? $row : null;
    }

    private function decide(int $signupId, int $tenantId, string $status, int $actorId): void
    {
        $this->db->prepare(
            'UPDATE customer_signups SET status = :status, decided_at = NOW(), decided_by = :actor
              WHERE id = :id AND tenant_id = :tid'
        )->execute([':status' => $status, ':actor' => $actorId, ':id' => $signupId, ':tid' => $tenantId]);
    }

    /**
     * The latest pending or rejected request this profile already has here,
     * other than this one.
     */
    private function earlierDecision(int $tenantId, int $profileId, int $exceptId): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT status FROM customer_signups
              WHERE tenant_id = :tid AND profile_id = :pid AND id <> :id
                AND status IN (:pending, :rejected)
              ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([
            ':tid' => $tenantId,
            ':pid' => $profileId,
            ':id' => $exceptId,
            ':pending' => self::STATUS_PENDING,
            ':rejected' => self::STATUS_REJECTED,
        ]);
        $status = $stmt->fetchColumn();

        return $status === false ? null : (string) $status;
    }

    private function grant(int $profileId, int $tenantId, int $roleId, string $via): int
    {
        return (new MembershipRepository($this->db, $this->hooks))->insert(
            $profileId,
            $tenantId,
            $roleId,
            null,
            MembershipRepository::STATUS_ACTIVE,
            MembershipRepository::KIND_CUSTOMER,
            $via,
        );
    }

    private function createProfile(string $email, string $displayName, string $passwordHash): int
    {
        $name = trim($displayName) !== '' ? trim($displayName) : $email;

        // `local`, stated rather than left to migration 104's default: this
        // path only creates a profile when the person chose a password (an
        // address that already has one keeps its own profile).
        // Profiles is a sanctioned GLOBAL identity table (ADR 0005 §1)
        $this->db->prepare(
            "INSERT INTO profiles
                 (display_name, password_hash, auth_method, two_factor_enabled,
                  two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (?, ?, 'local', false, 0, 0, NOW(), NOW())"
        )->execute([$name, $passwordHash]);
        $profileId = (int) $this->db->lastInsertId();

        // Verified: the person reached this point by following the mailed link.
        // Profile_emails is a sanctioned GLOBAL identity table (ADR 0005 §2)
        $this->db->prepare(
            'INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES (?, ?, true, true, NOW())'
        )->execute([$profileId, $email]);

        return $profileId;
    }

    private function profileIdForEmail(string $email): ?int
    {
        // Profile_emails is a sanctioned GLOBAL identity table (ADR 0005 §2); UNIQUE(email)
        $stmt = $this->db->prepare('SELECT profile_id FROM profile_emails WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    /** @return array{active: bool, invited: bool, suspended: bool} */
    private function membershipStatuses(int $profileId, int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT status FROM memberships WHERE profile_id = :pid AND tenant_id = :tid'
        );
        $stmt->execute([':pid' => $profileId, ':tid' => $tenantId]);
        $statuses = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        return [
            'active' => in_array(MembershipRepository::STATUS_ACTIVE, $statuses, true),
            'invited' => in_array(MembershipRepository::STATUS_INVITED, $statuses, true),
            'suspended' => in_array(MembershipRepository::STATUS_SUSPENDED, $statuses, true),
        ];
    }

    /** @return array<string, mixed> */
    private function joinedPayload(int $profileId, int $tenantId, int $membershipId, int $roleId, string $via, ?int $approvedBy): array
    {
        $stmt = $this->db->prepare(
            'SELECT name FROM roles WHERE id = :id AND (tenant_id = :tid OR tenant_id IS NULL)'
        );
        $stmt->execute([':id' => $roleId, ':tid' => $tenantId]);
        $roleName = $stmt->fetchColumn();

        return [
            'tenant_id' => $tenantId,
            'profile_id' => $profileId,
            'membership_id' => $membershipId,
            'role_id' => $roleId,
            'role_name' => $roleName === false ? null : (string) $roleName,
            'via' => $via,
            'approved_by' => $approvedBy,
        ];
    }

    /**
     * @param array{result: string, tenant_id: int|null, profile_id: int|null} $outcome
     * @return array{result: string, tenant_id: int|null, profile_id: int|null}
     */
    private function finish(bool $ownTx, array $outcome): array
    {
        if ($ownTx && $this->db->inTransaction()) {
            $this->db->commit();
        }

        return $outcome;
    }

    /** @return array{result: string, membership_id: int|null} */
    private function finishDecision(bool $ownTx, string $result, ?int $membershipId): array
    {
        if ($ownTx && $this->db->inTransaction()) {
            if ($result === self::DECIDE_DONE) {
                $this->db->commit();
            } else {
                $this->db->rollBack();
            }
        }

        return ['result' => $result, 'membership_id' => $membershipId];
    }

    /**
     * Fail-soft and after commit: a listener must never undo a membership, and
     * the return value is ignored, so a listener cannot change what happened.
     *
     * @param array<string, mixed> $payload
     */
    private function announce(string $event, array $payload): void
    {
        if ($this->hooks === null) {
            return;
        }

        try {
            $this->hooks->dispatch($event, $payload);
        } catch (\Throwable $e) {
            error_log('[customer-signup] ' . $event . ' listener failed: ' . $e->getMessage());
        }
    }
}
