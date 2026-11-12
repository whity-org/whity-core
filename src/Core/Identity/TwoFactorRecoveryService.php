<?php

declare(strict_types=1);

namespace Whity\Core\Identity;

use PDO;
use Whity\Auth\BackupCodesService;

/**
 * "I lost my 2FA device" recovery-request lifecycle
 * (WC-password-reset-2fa-recovery).
 *
 * For a user locked out because they lost BOTH their password and their 2FA
 * device/backup codes and so cannot log in at all. This is a REQUEST, never an
 * instant self-service action — there is no self-service way to disable
 * someone's second factor, which would defeat the point of having one. It
 * always lands in an admin approval queue.
 *
 * Two-step token lifecycle (mirrors {@see EmailVerificationService} /
 * {@see PasswordResetService}'s shape, `bin2hex(random_bytes(32))`, only
 * `sha256()` hash persisted, single-use):
 *   1. {@see self::issue()} — the user submitted their email; a token is
 *      minted proving nothing yet except that an email was sent. Any prior
 *      outstanding token for the profile is superseded.
 *   2. {@see self::confirm()} — the user presents the raw token from the
 *      emailed link. This PROVES mailbox ownership and is the point the
 *      pending request becomes admin-visible (status 'pending') — requiring
 *      this proof before queue-visibility prevents an unauthenticated caller
 *      from flooding the admin queue with requests against emails they do not
 *      control.
 *   3. An admin {@see self::approveForTenant()}s (clears the TARGET profile's
 *      2FA — mirrors {@see \Whity\Api\TwoFactorHandler::disable()}'s fields
 *      exactly, but applied to the target, not the caller — then issues+returns
 *      a fresh password-reset token via the injected {@see PasswordResetService}
 *      so the caller can email it, giving a full clean recovery) or
 *      {@see self::rejectForTenant()}s (profile untouched) a 'pending' row.
 *
 * A secondary fallback, {@see self::forceResetForTenant()}, lets an admin apply
 * the SAME clear-2FA-and-trigger-reset primitive directly to a named profile in
 * their own tenant with NO prior request — for the case where the locked-out
 * user genuinely cannot even receive email (no channel at all) and reaches an
 * admin out-of-band. Same permission, same audit trail as the queue path.
 *
 * `two_factor_recovery_requests` is a sanctioned GLOBAL table (no tenant_id) —
 * see {@see \Whity\Core\Tenant\SanctionedGlobalTables}; the admin approval QUEUE
 * is tenant-scoped at query time via a JOIN to `memberships`.
 */
final class TwoFactorRecoveryService
{
    /** Default token lifetime: 1 hour (mirrors PasswordResetService — sensitive, short-lived). */
    public const DEFAULT_TTL_SECONDS = 3600;

    private const TOKEN_BYTES = 32;

    /** Row states. */
    public const STATUS_PENDING_CONFIRMATION = 'pending_confirmation';
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    /** AG.1: why an admin's approval was refused. */
    public const REFUSED_OWN_REQUEST = 'own_request';
    public const REFUSED_SAME_ADMIN = 'same_admin';

    /**
     * How long a pending request stays approvable, from when the person
     * confirmed it (or an admin started it). Past this it is no longer listed
     * or approvable, and a first approval goes with it.
     */
    public const APPROVAL_TTL_SECONDS = 7 * 86400;

    public function __construct(
        private readonly PDO $db,
        private readonly PasswordResetService $passwordResets,
        private readonly BackupCodesService $backupCodes,
    ) {}

    /**
     * Mint an ownership-proof token for a profile. Supersedes any prior
     * outstanding (unconsumed) token for the same profile.
     *
     * @return string The raw token to embed in the confirmation link.
     */
    public function issue(int $profileId, int $ttlSeconds = self::DEFAULT_TTL_SECONDS, ?string $requestedFrom = null): string
    {
        $rawToken  = bin2hex(random_bytes(self::TOKEN_BYTES));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $ttlSeconds);

        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }

        try {
            $this->db->prepare(
                'DELETE FROM two_factor_recovery_requests WHERE profile_id = :pid AND consumed_at IS NULL'
            )->execute([':pid' => $profileId]);

            $this->db->prepare(
                "INSERT INTO two_factor_recovery_requests (profile_id, token_hash, status, requested_from, expires_at, created_at, updated_at)
                 VALUES (:pid, :hash, '" . self::STATUS_PENDING_CONFIRMATION . "', :from, :expires_at, NOW(), NOW())"
            )->execute([
                ':pid'        => $profileId,
                ':hash'       => $tokenHash,
                ':from'       => $requestedFrom !== null ? mb_substr($requestedFrom, 0, 255) : null,
                ':expires_at' => $expiresAt,
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

        return $rawToken;
    }

    /**
     * Consume the ownership-proof token: burns it (single-use) and flips the
     * row to 'pending' — the point it becomes visible in the admin queue.
     *
     * @return array{request_id: int, profile_id: int}|null null for an
     *   unknown, expired, or already-consumed token.
     */
    public function confirm(string $rawToken, ?string $reason = null): ?array
    {
        if ($rawToken === '') {
            return null;
        }

        $tokenHash = hash('sha256', $rawToken);
        $now       = gmdate('Y-m-d H:i:s');

        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }

        try {
            $stmt = $this->db->prepare(
                'SELECT id, profile_id
                 FROM two_factor_recovery_requests
                 WHERE token_hash = :hash
                   AND consumed_at IS NULL
                   AND expires_at > :now
                 LIMIT 1'
            );
            $stmt->execute([':hash' => $tokenHash, ':now' => $now]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row === false) {
                if ($ownTx) {
                    $this->db->commit();
                }
                return null;
            }

            $requestId = (int) $row['id'];

            $this->db->prepare(
                "UPDATE two_factor_recovery_requests
                    SET status = '" . self::STATUS_PENDING . "',
                        reason = :reason,
                        consumed_at = :now,
                        expires_at = :approvable_until,
                        updated_at = NOW()
                  WHERE id = :id AND consumed_at IS NULL"
            )->execute([
                ':reason' => $reason,
                ':now' => $now,
                ':approvable_until' => gmdate('Y-m-d H:i:s', time() + self::APPROVAL_TTL_SECONDS),
                ':id' => $requestId,
            ]);

            if ($ownTx) {
                $this->db->commit();
            }

            return [
                'request_id' => $requestId,
                'profile_id' => (int) $row['profile_id'],
            ];
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * List pending (admin-visible) 2FA-recovery requests for profiles that
     * hold an ACTIVE membership in the given tenant. Mirrors
     * {@see PasswordResetService::listPendingForTenant()}.
     *
     * `$limit` and `$offset` page the queue; {@see self::countPendingForTenant()} is its total.
     *
     * AG.1: each request also says where it came from, when the person last
     * signed in to this workspace, the checks admins made, and the first
     * admin's approval when there is one.
     *
     * @return list<array{id:int, profile_id:int, email:string, display_name:string, reason:string|null, created_at:string,
     *   requested_from:string|null, last_signed_in_at:string|null,
     *   checks:list<array{text:string, by_profile_id:int, by_name:string, at:string}>,
     *   first_approval:array{profile_id:int, display_name:string, at:string, method:string}|null}>
     */
    public function listPendingForTenant(int $tenantId, int $limit = PHP_INT_MAX, int $offset = 0): array
    {
        // Joins the global two_factor_recovery_requests/profiles tables to memberships, scoped by the tenant_id predicate below
        $stmt = $this->db->prepare(
            "SELECT DISTINCT tfr.id, tfr.profile_id, tfr.created_at, tfr.reason, tfr.requested_from, tfr.checks,
                    tfr.first_approver_profile_id, tfr.first_approver_tenant_id, tfr.first_approved_at, tfr.first_approval_method,
                    p.display_name, fa.display_name AS first_approver_name,
                    pe.email AS email,
                    (SELECT MAX(s.created_at) FROM sessions s
                      WHERE s.profile_id = tfr.profile_id AND s.tenant_id = m.tenant_id) AS last_signed_in_at
             FROM two_factor_recovery_requests tfr
             JOIN memberships m ON m.profile_id = tfr.profile_id AND m.tenant_id = :tenant_id AND m.status = 'active'
             JOIN profiles p ON p.id = tfr.profile_id
             LEFT JOIN profiles fa ON fa.id = tfr.first_approver_profile_id
             LEFT JOIN profile_emails pe ON pe.profile_id = tfr.profile_id AND pe.is_primary = TRUE
             WHERE tfr.status = '" . self::STATUS_PENDING . "' AND tfr.expires_at > :now
             ORDER BY tfr.created_at ASC, tfr.id ASC
             LIMIT :limit OFFSET :offset"
        );
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':now', gmdate('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $checks = [];
        foreach ($rows as $row) {
            // Only this workspace's own checks: another workspace's are not shown here.
            $checks[(int) $row['id']] = array_values(array_filter(
                self::decodeChecks($row['checks'] ?? null),
                static fn (array $c): bool => $c['tenant'] === $tenantId,
            ));
        }
        $names = $this->displayNames(array_merge(...array_map(
            static fn (array $list): array => array_column($list, 'by'),
            array_values($checks) ?: [[]],
        )));

        $items = [];
        foreach ($rows as $row) {
            // A first approval given in another workspace is not this workspace's to see or build on.
            $firstBy = isset($row['first_approver_tenant_id']) && (int) $row['first_approver_tenant_id'] === $tenantId
                ? ($row['first_approver_profile_id'] ?? null)
                : null;
            $items[] = [
                'id'                => (int) $row['id'],
                'profile_id'        => (int) $row['profile_id'],
                'email'             => (string) ($row['email'] ?? ''),
                'display_name'      => (string) ($row['display_name'] ?? ''),
                'reason'            => isset($row['reason']) ? (string) $row['reason'] : null,
                'created_at'        => (string) ($row['created_at'] ?? ''),
                'requested_from'    => isset($row['requested_from']) ? (string) $row['requested_from'] : null,
                'last_signed_in_at' => isset($row['last_signed_in_at']) ? (string) $row['last_signed_in_at'] : null,
                'checks'            => array_map(static fn (array $c): array => [
                    'text'          => $c['text'],
                    'by_profile_id' => $c['by'],
                    'by_name'       => $names[$c['by']] ?? '',
                    'at'            => $c['at'],
                ], $checks[(int) $row['id']]),
                'first_approval'    => $firstBy !== null ? [
                    'profile_id'   => (int) $firstBy,
                    'display_name' => (string) ($row['first_approver_name'] ?? ''),
                    'at'           => (string) ($row['first_approved_at'] ?? ''),
                    'method'       => (string) ($row['first_approval_method'] ?? ''),
                ] : null,
            ];
        }

        return $items;
    }

    /**
     * The total behind {@see self::listPendingForTenant()}, with the same scope.
     */
    public function countPendingForTenant(int $tenantId): int
    {
        // Joins the global two_factor_recovery_requests table to memberships, scoped by the tenant_id predicate below
        $stmt = $this->db->prepare(
            "SELECT COUNT(DISTINCT tfr.id)
             FROM two_factor_recovery_requests tfr
             JOIN memberships m ON m.profile_id = tfr.profile_id AND m.tenant_id = :tenant_id AND m.status = 'active'
             WHERE tfr.status = '" . self::STATUS_PENDING . "' AND tfr.expires_at > :now"
        );
        $stmt->execute([':tenant_id' => $tenantId, ':now' => gmdate('Y-m-d H:i:s')]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * An admin approves a pending 2FA-recovery request (AG.1). It takes TWO
     * different admins, and neither can be the person asking:
     *
     *  - the first approval is recorded (who, when, how they confirmed it was
     *    them) and the request stays pending: nothing about the account changes;
     *  - a second approval by a different admin clears the TARGET profile's 2FA
     *    (same fields {@see \Whity\Api\TwoFactorHandler::disable()} clears,
     *    plus invalidating its backup codes), marks the row approved, and issues
     *    a fresh password-reset token for the SAME profile so the caller can
     *    email it (a full clean recovery).
     *
     * Both steps are guarded in SQL as well as here: the first only lands on a
     * row with no first approver, and the clearing only on a row whose first
     * approver is someone else, so two tabs or a race can't let one admin do both.
     *
     * Tenant-scoped: the target profile must hold an ACTIVE membership in
     * $tenantId (never trust a bare request id).
     *
     * @param string $method how the approving admin confirmed it was them
     * @return array{stage:string, request_id:int, profile_id:int, email:string, reset_token:string}|string|null
     *   'first' or 'cleared' as the stage (email and reset_token empty for 'first'); a REFUSED_* reason; null = not found,
     *   not pending, not a member of $tenantId, or an account a sign-in provider governs.
     */
    public function approveForTenant(
        int $requestId,
        int $tenantId,
        int $approverProfileId,
        string $method,
        ?\Closure $mayStillApprove = null,
    ): array|string|null {
        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }

        try {
            // Joins the global two_factor_recovery_requests table to memberships, scoped by the tenant_id predicate below
            $stmt = $this->db->prepare(
                "SELECT tfr.id, tfr.profile_id, tfr.first_approver_profile_id, tfr.first_approver_tenant_id,
                        pe.email, p.two_factor_backup_codes_version
                 FROM two_factor_recovery_requests tfr
                 JOIN memberships m ON m.profile_id = tfr.profile_id AND m.tenant_id = :tenant_id AND m.status = 'active'
                 JOIN profiles p ON p.id = tfr.profile_id
                 LEFT JOIN profile_emails pe ON pe.profile_id = tfr.profile_id AND pe.is_primary = TRUE
                 WHERE tfr.id = :id AND tfr.status = '" . self::STATUS_PENDING . "' AND tfr.expires_at > :now
                 LIMIT 1"
            );
            $stmt->execute([':id' => $requestId, ':tenant_id' => $tenantId, ':now' => gmdate('Y-m-d H:i:s')]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            $outcome = $this->decide($row, $requestId, $tenantId, $approverProfileId, $method, $mayStillApprove);

            if ($ownTx) {
                is_array($outcome) ? $this->db->commit() : $this->db->rollBack();
            }

            return $outcome;
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array<string, mixed>|false $row
     * @return array{stage:string, request_id:int, profile_id:int, email:string, reset_token:string}|string|null
     */
    private function decide(
        array|false $row,
        int $requestId,
        int $tenantId,
        int $approverProfileId,
        string $method,
        ?\Closure $mayStillApprove,
    ): array|string|null {
        if ($row === false) {
            return null;
        }
        $profileId = (int) $row['profile_id'];
        if ($profileId === $approverProfileId) {
            return self::REFUSED_OWN_REQUEST;
        }

        // #917: recovery ends in a password-reset token, so for an
        // IdP-backed profile it would hand out a LOCAL credential for an
        // account the provider governs alone. Refused before either approval
        // lands, so the request stays pending and the target's 2FA enrolled.
        if ((new AuthMethod($this->db))->refusesLocalPassword($profileId)) {
            return null;
        }

        // Both approvals must come from the same workspace, and the first must
        // still stand: given here, by someone who is still an admin here. A
        // first approval that doesn't count is replaced by this one.
        $first = $row['first_approver_profile_id'] ?? null;
        $firstCounts = $first !== null
            && isset($row['first_approver_tenant_id'])
            && (int) $row['first_approver_tenant_id'] === $tenantId
            && ($mayStillApprove === null || $mayStillApprove((int) $first, $tenantId) === true);
        if (!$firstCounts) {
            $mark = $this->db->prepare(
                "UPDATE two_factor_recovery_requests
                    SET first_approver_profile_id = :approver, first_approver_tenant_id = :tenant_id,
                        first_approved_at = NOW(), first_approval_method = :method, updated_at = NOW()
                  WHERE id = :id AND status = '" . self::STATUS_PENDING . "'
                    AND first_approver_profile_id IS NOT DISTINCT FROM :previous"
            );
            $mark->bindValue(':approver', $approverProfileId, PDO::PARAM_INT);
            $mark->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
            $mark->bindValue(':method', $method, PDO::PARAM_STR);
            $mark->bindValue(':id', $requestId, PDO::PARAM_INT);
            $mark->bindValue(':previous', $first === null ? null : (int) $first, $first === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $mark->execute();

            return $mark->rowCount() === 1
                ? ['stage' => 'first', 'request_id' => $requestId, 'profile_id' => $profileId, 'email' => '', 'reset_token' => '']
                : null;
        }

        if ((int) $first === $approverProfileId) {
            return self::REFUSED_SAME_ADMIN;
        }

        // The guard makes a concurrent second approve a no-op for the loser,
        // and refuses a row whose first approver is this same admin.
        $close = $this->db->prepare(
            "UPDATE two_factor_recovery_requests
                SET status = '" . self::STATUS_APPROVED . "', updated_at = NOW()
              WHERE id = :id AND status = '" . self::STATUS_PENDING . "' AND expires_at > :now
                AND first_approver_profile_id IS NOT NULL AND first_approver_profile_id <> :approver
                AND first_approver_tenant_id = :tenant_id"
        );
        $close->execute([
            ':id' => $requestId,
            ':approver' => $approverProfileId,
            ':tenant_id' => $tenantId,
            ':now' => gmdate('Y-m-d H:i:s'),
        ]);
        if ($close->rowCount() === 0) {
            return null;
        }

        $this->clearTwoFactor($profileId, (int) $row['two_factor_backup_codes_version']);
        $resetToken = $this->passwordResets->issue($profileId);

        return [
            'stage'       => 'cleared',
            'request_id'  => $requestId,
            'profile_id'  => $profileId,
            'email'       => (string) ($row['email'] ?? ''),
            'reset_token' => $resetToken,
        ];
    }

    /**
     * Record a check an admin made that it is really the person (AG.1): "Called
     * him on the number on file", "His manager confirmed he lost his phone".
     * Checks are kept with who made them and when, and shown to the second admin.
     */
    public function addCheckForTenant(int $requestId, int $tenantId, int $byProfileId, string $text): bool
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 300) {
            return false;
        }

        // Joins the global two_factor_recovery_requests table to memberships, scoped by the tenant_id predicate below
        $stmt = $this->db->prepare(
            "SELECT tfr.checks
             FROM two_factor_recovery_requests tfr
             JOIN memberships m ON m.profile_id = tfr.profile_id AND m.tenant_id = :tenant_id AND m.status = 'active'
             WHERE tfr.id = :id AND tfr.status = '" . self::STATUS_PENDING . "' AND tfr.expires_at > :now
             LIMIT 1"
        );
        $stmt->execute([':id' => $requestId, ':tenant_id' => $tenantId, ':now' => gmdate('Y-m-d H:i:s')]);
        $current = $stmt->fetchColumn();
        if ($current === false) {
            return false;
        }

        $checks = self::decodeChecks($current);
        $ours = array_filter($checks, static fn (array $c): bool => $c['tenant'] === $tenantId);
        if (count($ours) >= 20) {
            return false;
        }
        $checks[] = ['text' => $text, 'by' => $byProfileId, 'tenant' => $tenantId, 'at' => gmdate('Y-m-d H:i:s')];

        $update = $this->db->prepare(
            "UPDATE two_factor_recovery_requests SET checks = :checks, updated_at = NOW()
              WHERE id = :id AND status = '" . self::STATUS_PENDING . "'"
        );
        $update->execute([':checks' => json_encode($checks, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), ':id' => $requestId]);

        return $update->rowCount() === 1;
    }

    /**
     * Admin-reject a pending 2FA-recovery request. The target profile is left
     * completely untouched.
     *
     * @return array{profile_id:int}|null null = not found, not pending, or the
     *   target is not a member of $tenantId.
     */
    public function rejectForTenant(int $requestId, int $tenantId): ?array
    {
        // Joins the global two_factor_recovery_requests table to memberships, scoped by the tenant_id predicate below
        $lookup = $this->db->prepare(
            "SELECT tfr.profile_id
             FROM two_factor_recovery_requests tfr
             JOIN memberships m ON m.profile_id = tfr.profile_id AND m.tenant_id = :tenant_id AND m.status = 'active'
             WHERE tfr.id = :id AND tfr.status = '" . self::STATUS_PENDING . "'
             LIMIT 1"
        );
        $lookup->execute([':id' => $requestId, ':tenant_id' => $tenantId]);
        $row = $lookup->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        // Joins the global two_factor_recovery_requests table to memberships; tenant_id predicate is on the correlated subquery below
        $stmt = $this->db->prepare(
            "UPDATE two_factor_recovery_requests
                SET status = '" . self::STATUS_REJECTED . "', updated_at = NOW()
              WHERE id = :id
                AND status = '" . self::STATUS_PENDING . "'
                AND EXISTS (
                    SELECT 1 FROM memberships m
                    WHERE m.profile_id = two_factor_recovery_requests.profile_id
                      AND m.tenant_id = :tenant_id
                      AND m.status = 'active'
                )"
        );
        $stmt->execute([':id' => $requestId, ':tenant_id' => $tenantId]);

        return $stmt->rowCount() > 0 ? ['profile_id' => (int) $row['profile_id']] : null;
    }

    /**
     * Secondary fallback (no prior request): an admin starts a two-step reset
     * for a named profile, for a user who cannot even receive email and
     * reaches an admin out-of-band.
     *
     * Since AG.1 this no longer clears anything by itself: it opens a pending
     * request carrying this admin's approval as the first one, and a second,
     * different admin clears it through {@see self::approveForTenant()}. Any
     * earlier unconfirmed request for the profile is superseded.
     *
     * Tenant-scoped: $targetProfileId must hold an ACTIVE membership in $tenantId.
     *
     * @return array{stage:string, request_id:int, profile_id:int}|string|null
     *   REFUSED_OWN_REQUEST for the admin's own account; null = not a member
     *   of $tenantId, or an account a sign-in provider governs.
     */
    public function forceResetForTenant(int $targetProfileId, int $tenantId, int $approverProfileId, string $method, ?string $reason = null): array|string|null
    {
        if ($targetProfileId === $approverProfileId) {
            return self::REFUSED_OWN_REQUEST;
        }

        // Joins the global profiles table to memberships, scoped by the tenant_id predicate below
        $stmt = $this->db->prepare(
            "SELECT p.id
             FROM profiles p
             JOIN memberships m ON m.profile_id = p.id AND m.tenant_id = :tenant_id AND m.status = 'active'
             WHERE p.id = :pid
             LIMIT 1"
        );
        $stmt->execute([':pid' => $targetProfileId, ':tenant_id' => $tenantId]);
        if ($stmt->fetchColumn() === false) {
            return null;
        }

        // #917: a reset for an IdP-backed profile would mint a local credential.
        if ((new AuthMethod($this->db))->refusesLocalPassword($targetProfileId)) {
            return null;
        }

        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }

        try {
            $this->db->prepare(
                'DELETE FROM two_factor_recovery_requests WHERE profile_id = :pid AND consumed_at IS NULL'
            )->execute([':pid' => $targetProfileId]);

            $this->db->prepare(
                "INSERT INTO two_factor_recovery_requests
                     (profile_id, token_hash, status, reason, requested_from, first_approver_profile_id, first_approver_tenant_id,
                      first_approved_at, first_approval_method, expires_at, consumed_at, created_at, updated_at)
                 VALUES (:pid, :hash, '" . self::STATUS_PENDING . "', :reason, 'admin', :approver, :tenant_id,
                      NOW(), :method, :expires_at, NOW(), NOW(), NOW())"
            )->execute([
                ':pid'        => $targetProfileId,
                // No link is ever sent for this row; the hash only fills the column.
                ':hash'       => hash('sha256', bin2hex(random_bytes(self::TOKEN_BYTES))),
                ':reason'     => $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 500) : null,
                ':approver'   => $approverProfileId,
                ':tenant_id'  => $tenantId,
                ':method'     => $method,
                ':expires_at' => gmdate('Y-m-d H:i:s', time() + self::APPROVAL_TTL_SECONDS),
            ]);
            $requestId = (int) $this->db->lastInsertId();

            if ($ownTx) {
                $this->db->commit();
            }

            return ['stage' => 'first', 'request_id' => $requestId, 'profile_id' => $targetProfileId];
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return list<array{text:string, by:int, tenant:int, at:string}>
     */
    private static function decodeChecks(mixed $json): array
    {
        $decoded = is_string($json) && $json !== '' ? json_decode($json, true) : null;
        if (!is_array($decoded)) {
            return [];
        }
        $checks = [];
        foreach ($decoded as $c) {
            if (is_array($c) && is_string($c['text'] ?? null) && is_numeric($c['by'] ?? null) && is_string($c['at'] ?? null)) {
                $checks[] = [
                    'text'   => $c['text'],
                    'by'     => (int) $c['by'],
                    // Checks recorded before workspaces were kept count for no workspace.
                    'tenant' => is_numeric($c['tenant'] ?? null) ? (int) $c['tenant'] : 0,
                    'at'     => $c['at'],
                ];
            }
        }

        return $checks;
    }

    /**
     * @param list<int> $profileIds
     * @return array<int, string>
     */
    private function displayNames(array $profileIds): array
    {
        $profileIds = array_values(array_unique($profileIds));
        if ($profileIds === []) {
            return [];
        }
        $placeholders = implode(', ', array_map(static fn (int $i): string => ':p' . $i, array_keys($profileIds)));
        // profiles is a sanctioned GLOBAL identity table (ADR 0005 §1)
        $stmt = $this->db->prepare('SELECT id, display_name FROM profiles WHERE id IN (' . $placeholders . ')');
        foreach ($profileIds as $i => $id) {
            $stmt->bindValue(':p' . $i, $id, PDO::PARAM_INT);
        }
        $stmt->execute();
        $names = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $names[(int) $row['id']] = (string) $row['display_name'];
        }

        return $names;
    }

    /**
     * Clear a profile's 2FA state. Mirrors
     * {@see \Whity\Api\TwoFactorHandler::disable()} exactly (same fields,
     * same backup-codes invalidation) but applied to a TARGET profile chosen
     * by an admin, never the caller.
     */
    private function clearTwoFactor(int $profileId, int $currentBackupCodesVersion): void
    {
        if ($currentBackupCodesVersion > 0) {
            $this->backupCodes->invalidateOldCodes($profileId, $currentBackupCodesVersion);
        }

        // Profiles is a sanctioned GLOBAL identity table (ADR 0005 §1)
        $this->db->prepare(
            'UPDATE profiles
                SET two_factor_secret = NULL, two_factor_enabled = false,
                    two_factor_backup_codes_version = 0, updated_at = NOW()
              WHERE id = :pid'
        )->execute([':pid' => $profileId]);
    }
}
