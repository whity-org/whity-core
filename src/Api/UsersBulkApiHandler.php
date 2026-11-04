<?php

declare(strict_types=1);

namespace Whity\Api;

use PDO;
use Whity\Auth\RoleChecker;
use Whity\Auth\TwoFactorPoliciesRepository;
use Whity\Core\Audit\AuditContext;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Hooks\HookManager;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * The users screen's bulk bar and its Require two-step button (design 2.9, A10).
 *
 *   POST   /api/users/bulk/role               users:write
 *   POST   /api/users/bulk/require-two-step   security:manage
 *   PUT    /api/users/{id}/two-step-requirement   security:manage
 *   DELETE /api/users/{id}/two-step-requirement   security:manage
 *
 * A TARGET IS A PERSON IN A TENANT, NOT A PROFILE ID
 * --------------------------------------------------
 * For the system tenant a profile id does not name one membership: the same person
 * can hold a primary membership in several tenants, and the list shows each as a
 * row. So a system caller must say which tenant each target is in, and is refused
 * if it does not. Any other caller's targets are in the caller's own tenant, and a
 * target naming another tenant is reported exactly as one that does not exist.
 *
 * A BULK ROLE CHANGE IS ALL OR NOTHING, AND STRICTER THAN PATCH
 * -------------------------------------------------------------
 * One click moves authority for many people at once, so it is refused whole if any
 * part of it is wrong, with the reason for each person. What it refuses:
 *
 *  - A role by name. Names repeat across tenants; only an id is unambiguous.
 *  - A role the TARGET'S tenant cannot see. Only that tenant's own roles and global
 *    ones qualify, for a system caller as well: a system caller acting in tenant B
 *    must not be able to plant tenant A's private role there.
 *  - A role carrying a permission the caller does not hold, which would let somebody
 *    hand out more than they have. A global role passes only by this same test.
 *  - A target whose current role carries more than the caller holds, so a lesser
 *    administrator cannot demote a greater one.
 *  - The caller themselves, because a click that removes your own access cannot be
 *    undone by you.
 *  - A change that leaves a tenant with nobody who can manage its people. Holding
 *    `users:write` is the test, never a role's name.
 *
 * Only a person's PRIMARY, ACTIVE membership changes, which is the one the list shows.
 * Secondary memberships in the same tenant are other grants and stay as they are.
 */
final class UsersBulkApiHandler
{
    /** More than a page at the largest size, and few enough to run in one transaction. */
    private const MAX_TARGETS = 200;

    private const SYSTEM_TENANT_ID = 0;

    private const REFUSED_NOT_FOUND = 'not_found';
    private const REFUSED_SELF = 'self';
    private const REFUSED_OUTRANKS = 'outranks_you';

    private TwoFactorPoliciesRepository $policies;

    public function __construct(
        private readonly PDO $db,
        private readonly RoleChecker $roleChecker,
        private readonly HookManager $hookManager,
        private readonly ?AuditLogger $auditLogger = null,
    ) {
        $this->policies = new TwoFactorPoliciesRepository($db);
    }

    /**
     * POST /api/users/bulk/role — body `{role_id: int, targets: [{profile_id, tenant_id?}]}`.
     */
    public function changeRole(Request $request): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 400);
        }
        $actor = self::actor($request);
        if ($actor === null) {
            return Response::error('Authenticated user is required', 401);
        }

        $body = JsonBody::parsed($request);

        $rawRole = $body['role_id'] ?? null;
        if (!is_int($rawRole) && !(is_string($rawRole) && ctype_digit($rawRole))) {
            return Response::error('role_id is required and must be an integer', 422);
        }
        $roleId = (int) $rawRole;

        $targets = $this->parseTargets($body, $tenantId);
        if ($targets instanceof Response) {
            return $targets;
        }

        $held = array_flip($this->roleChecker->getEffectivePermissionsForProfile($actor, $tenantId));

        $refused = [];
        $members = [];
        foreach ($targets as [$profileId, $ownerTenantId]) {
            $member = $this->primaryMembership($profileId, $ownerTenantId);
            if ($member === null) {
                $refused[] = ['profile_id' => $profileId, 'tenant_id' => $ownerTenantId, 'reason' => self::REFUSED_NOT_FOUND];
                continue;
            }

            $reason = null;
            if ($profileId === $actor) {
                $reason = self::REFUSED_SELF;
            } elseif (array_diff_key(array_flip($this->roleChecker->getEffectivePermissionsForRole((int) $member['role_id'], $ownerTenantId)), $held) !== []) {
                $reason = self::REFUSED_OUTRANKS;
            }

            if ($reason !== null) {
                $refused[] = ['profile_id' => $profileId, 'tenant_id' => $ownerTenantId, 'reason' => $reason];
            } else {
                $members[] = $member;
            }
        }
        if ($refused !== []) {
            return Response::error('Nothing was changed', 422, ['refused' => $refused]);
        }

        $affectedTenants = array_values(array_unique(array_map(static fn (array $m): int => (int) $m['tenant_id'], $members)));
        foreach ($affectedTenants as $ownerTenantId) {
            if (!$this->roleVisibleTo($roleId, $ownerTenantId)) {
                return Response::error('Role not found', 404);
            }
        }

        // After visibility, so somebody else's private role reads as not found and
        // never as "it has permissions you do not hold".
        foreach ($this->roleChecker->getEffectivePermissionsForRole($roleId, $tenantId) as $permission) {
            if (!isset($held[$permission])) {
                return Response::error('This role has permissions you do not hold', 403, ['reason' => 'exceeds_your_access']);
            }
        }

        $changed = [];
        $unchanged = [];
        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }
        try {
            $hadManager = [];
            foreach ($affectedTenants as $ownerTenantId) {
                $hadManager[$ownerTenantId] = $this->tenantHasPeopleManager($ownerTenantId);
            }

            $update = $this->db->prepare(
                "UPDATE memberships SET role_id = :role_id
                  WHERE id = :id AND tenant_id = :tenant_id AND is_primary AND status = 'active'"
            );
            foreach ($members as $member) {
                $pair = ['profile_id' => (int) $member['profile_id'], 'tenant_id' => (int) $member['tenant_id']];
                if ((int) $member['role_id'] === $roleId) {
                    $unchanged[] = $pair;
                    continue;
                }
                $update->execute([':role_id' => $roleId, ':id' => (int) $member['id'], ':tenant_id' => (int) $member['tenant_id']]);
                $changed[] = $pair;
            }

            foreach ($affectedTenants as $ownerTenantId) {
                if ($hadManager[$ownerTenantId] && !$this->tenantHasPeopleManager($ownerTenantId)) {
                    if ($ownTx) {
                        $this->db->rollBack();
                    }

                    return Response::error(
                        'This would leave nobody able to manage people in the workspace',
                        409,
                        ['reason' => 'last_people_manager', 'tenant_id' => $ownerTenantId]
                    );
                }
            }

            if ($ownTx) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('[UsersBulkApiHandler] changeRole failed: ' . $e->getMessage());

            return Response::error('Failed to change roles', 500);
        }

        RoleChecker::clearCache();

        // One audit row per person, in the shape PATCH /api/users/{id} writes, so
        // "who held what on the 14th" reads the same however the change was made.
        $newRoleName = $this->roleName($roleId);
        foreach ($members as $member) {
            if ((int) $member['role_id'] === $roleId) {
                continue;
            }
            $this->hookManager->dispatch('user.updated', [
                'id' => (int) $member['profile_id'],
                'tenant_id' => (int) $member['tenant_id'],
                'role_changed' => true,
                'ou_changed' => false,
                'account_status_changed' => false,
                'previous_role_id' => (int) $member['role_id'],
                'previous_role_name' => (string) $member['role_name'],
                'role_id' => $roleId,
                'role_name' => $newRoleName,
                'bulk' => true,
            ]);
        }

        return Response::json(['data' => ['changed' => $changed, 'unchanged' => $unchanged]], 200);
    }

    /**
     * POST /api/users/bulk/require-two-step — body `{targets: [...], grace_period_days?: int}`.
     *
     * Gated on `security:manage` like the rest of the 2FA policies, not `users:write`.
     * It writes a `user`-scoped policy into the TARGET'S tenant, because that is the
     * tenant whose policies are read when they sign in there.
     */
    public function requireTwoStep(Request $request): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 400);
        }

        $body = JsonBody::parsed($request);

        $targets = $this->parseTargets($body, $tenantId);
        if ($targets instanceof Response) {
            return $targets;
        }

        $grace = self::parseGrace($body);
        if ($grace === null) {
            return Response::error('grace_period_days must be a non-negative integer', 422);
        }

        return $this->applyRequirement($targets, $grace);
    }

    /**
     * PUT /api/users/{id}/two-step-requirement — body `{tenant_id?, grace_period_days?}`.
     *
     * @param array<string, mixed> $params
     */
    public function setRequirement(Request $request, array $params = []): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 400);
        }

        $body = JsonBody::parsed($request);

        $target = $this->singleTarget($params, $body, $tenantId);
        if ($target instanceof Response) {
            return $target;
        }

        $grace = self::parseGrace($body);
        if ($grace === null) {
            return Response::error('grace_period_days must be a non-negative integer', 422);
        }

        return $this->applyRequirement([$target], $grace);
    }

    /**
     * DELETE /api/users/{id}/two-step-requirement — body `{tenant_id?}`.
     *
     * Removes only the policy naming this person. If their tenant or unit also
     * requires two-step, it stays required, and the list keeps saying so.
     *
     * @param array<string, mixed> $params
     */
    public function clearRequirement(Request $request, array $params = []): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 400);
        }

        $target = $this->singleTarget($params, JsonBody::parsed($request), $tenantId);
        if ($target instanceof Response) {
            return $target;
        }
        [$profileId, $ownerTenantId] = $target;

        if ($this->primaryMembership($profileId, $ownerTenantId) === null) {
            return Response::error('User not found', 404);
        }

        $policyId = $this->directPolicyId($ownerTenantId, $profileId);
        if ($policyId !== null && $this->policies->delete($ownerTenantId, $policyId)) {
            $this->auditLogger?->record('security.2fa_policy.deleted', [
                'tenant_id' => $ownerTenantId,
                'target_type' => 'two_factor_policy',
                'target_id' => $policyId,
                'metadata' => ['scope_type' => 'user', 'scope_id' => $profileId],
            ]);
        }

        return Response::json(['data' => ['profile_id' => $profileId, 'tenant_id' => $ownerTenantId, 'requiredDirectly' => false]], 200);
    }

    /**
     * @param list<array{0: int, 1: int}> $targets
     */
    private function applyRequirement(array $targets, int $grace): Response
    {
        $refused = [];
        foreach ($targets as [$profileId, $ownerTenantId]) {
            if ($this->primaryMembership($profileId, $ownerTenantId) === null) {
                $refused[] = ['profile_id' => $profileId, 'tenant_id' => $ownerTenantId, 'reason' => self::REFUSED_NOT_FOUND];
            }
        }
        if ($refused !== []) {
            return Response::error('Nothing was changed', 422, ['refused' => $refused]);
        }

        $changed = [];
        $unchanged = [];
        $createdBy = AuditContext::getActorUserId();
        foreach ($targets as [$profileId, $ownerTenantId]) {
            $pair = ['profile_id' => $profileId, 'tenant_id' => $ownerTenantId];
            $policyId = $this->directPolicyId($ownerTenantId, $profileId) === null
                ? $this->policies->create($ownerTenantId, 'user', $profileId, $grace, $createdBy)
                : null;
            if ($policyId === null) {
                $unchanged[] = $pair;
                continue;
            }
            $changed[] = $pair;
            $this->auditLogger?->record('security.2fa_policy.created', [
                'tenant_id' => $ownerTenantId,
                'target_type' => 'two_factor_policy',
                'target_id' => $policyId,
                'metadata' => ['scope_type' => 'user', 'scope_id' => $profileId, 'grace_period_days' => $grace],
            ]);
        }

        return Response::json(['data' => ['changed' => $changed, 'unchanged' => $unchanged]], 200);
    }

    /**
     * @param array<string, mixed> $body
     * @return list<array{0: int, 1: int}>|Response [profileId, tenantId] pairs, de-duplicated.
     */
    private function parseTargets(array $body, int $actingTenantId): array|Response
    {
        $raw = $body['targets'] ?? null;
        if (!is_array($raw) || $raw === []) {
            return Response::error('targets is required', 422);
        }
        if (count($raw) > self::MAX_TARGETS) {
            return Response::error('Too many people at once. The limit is ' . self::MAX_TARGETS . '.', 422);
        }

        $pairs = [];
        foreach ($raw as $target) {
            if (!is_array($target) || !self::isId($target['profile_id'] ?? null)) {
                return Response::error('Each target needs a profile_id', 422);
            }
            $owner = self::ownerTenant($target['tenant_id'] ?? null, $actingTenantId);
            if ($owner === null) {
                return Response::error('Each target needs a tenant_id when acting across workspaces', 422);
            }
            $pairs[$owner . ':' . (int) $target['profile_id']] = [(int) $target['profile_id'], $owner];
        }

        return array_values($pairs);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $body
     * @return array{0: int, 1: int}|Response
     */
    private function singleTarget(array $params, array $body, int $actingTenantId): array|Response
    {
        if (!self::isId($params['id'] ?? null)) {
            return Response::error('User ID is required', 400);
        }
        $owner = self::ownerTenant($body['tenant_id'] ?? null, $actingTenantId);
        if ($owner === null) {
            return Response::error('tenant_id is required when acting across workspaces', 422);
        }

        return [(int) $params['id'], $owner];
    }

    /**
     * The tenant a target is in. A non-system caller's targets are always in its own
     * tenant, and naming another keeps that tenant in the lookup, where it finds
     * nothing visible: see {@see self::primaryMembership()}.
     */
    private static function ownerTenant(mixed $named, int $actingTenantId): ?int
    {
        if ($actingTenantId !== self::SYSTEM_TENANT_ID) {
            return self::isId($named) && (int) $named !== $actingTenantId ? -1 : $actingTenantId;
        }

        return self::isId($named) ? (int) $named : null;
    }

    private static function isId(mixed $value): bool
    {
        return is_int($value) ? $value >= 0 : is_string($value) && ctype_digit($value);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function parseGrace(array $body): ?int
    {
        $raw = $body['grace_period_days'] ?? 0;
        if (is_int($raw) && $raw >= 0) {
            return $raw;
        }

        return is_string($raw) && ctype_digit($raw) ? (int) $raw : null;
    }

    private static function actor(Request $request): ?int
    {
        $user = $request->user;

        return is_object($user) && isset($user->profile_id) && is_int($user->profile_id) ? $user->profile_id : null;
    }

    /**
     * The one row the users list shows for this person in this tenant.
     *
     * @return array<string, mixed>|null
     */
    private function primaryMembership(int $profileId, int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT m.id, m.profile_id, m.tenant_id, m.role_id, r.name AS role_name
               FROM memberships m
               JOIN roles r ON r.id = m.role_id
              WHERE m.profile_id = :profile_id AND m.tenant_id = :tenant_id
                AND m.is_primary AND m.status = 'active'
              LIMIT 1"
        );
        $stmt->execute([':profile_id' => $profileId, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function roleVisibleTo(int $roleId, int $tenantId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM roles WHERE id = :id AND (tenant_id = :tenant_id OR tenant_id IS NULL)');
        $stmt->execute([':id' => $roleId, ':tenant_id' => $tenantId]);

        return $stmt->fetchColumn() !== false;
    }

    private function roleName(int $roleId): ?string
    {
        // @tenant-guard-ignore: the role was proved visible to every affected tenant before anything changed
        $stmt = $this->db->prepare('SELECT name FROM roles WHERE id = :id');
        $stmt->execute([':id' => $roleId]);
        $name = $stmt->fetchColumn();

        return $name === false ? null : (string) $name;
    }

    /**
     * Whether somebody active in the tenant still holds `users:write` through their
     * primary role. Delegations and unit roles are not counted, so this can refuse a
     * change that would strictly have been survivable, which is the safe direction.
     */
    private function tenantHasPeopleManager(int $tenantId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT DISTINCT m.role_id
               FROM memberships m
               JOIN profiles p ON p.id = m.profile_id
              WHERE m.tenant_id = :tenant_id AND m.is_primary AND m.status = 'active' AND p.status = 'active'"
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $roleId) {
            if (in_array(CorePermissions::USERS_WRITE, $this->roleChecker->getEffectivePermissionsForRole((int) $roleId, $tenantId), true)) {
                return true;
            }
        }

        return false;
    }

    private function directPolicyId(int $tenantId, int $profileId): ?int
    {
        $stmt = $this->db->prepare(
            "SELECT id FROM two_factor_policies
              WHERE tenant_id = :tenant_id AND scope_type = 'user' AND scope_id = :profile_id"
        );
        $stmt->execute([':tenant_id' => $tenantId, ':profile_id' => $profileId]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }
}
