<?php

declare(strict_types=1);

namespace Whity\Api;

use PDO;
use Whity\Core\Db\DbBool;
use Whity\Auth\TwoFactorPoliciesRepository;
use Whity\Auth\TwoFactorPolicyResolver;
use Whity\Core\Audit\AuditContext;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Notification\NotificationDispatcher;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * Admin-enforced 2FA policy CRUD + status (WC-525 PR-3).
 *
 * Routes (registered in public/index.php), tenant-scoped via TenantContext and
 * gated on `security:manage`:
 *   GET    /api/2fa-policies         → list()   — every policy row for this tenant
 *   POST   /api/2fa-policies         → create() — add a tenant/OU/user-scoped policy
 *   PATCH  /api/2fa-policies/{id}    → update() — change the grace period
 *   DELETE /api/2fa-policies/{id}    → delete() — remove a policy
 *   GET    /api/2fa-policies/status  → status() — enrollment status across every
 *                                                  profile any policy covers
 *
 * Descriptors are policy DECLARATIONS only — the actual enforcement (grace-
 * period nag / past-deadline refusal) lives in {@see \Whity\Auth\AuthHandler}
 * via the same {@see TwoFactorPolicyResolver} this handler uses for status().
 */
final class TwoFactorPoliciesApiHandler
{
    private const SCOPE_TYPES = ['tenant', 'ou', 'user'];

    private const REMIND_EVERY_SECONDS = 86400;

    private const REMINDER_NOTIFICATION = 'security.two_factor_reminder';


    private PDO $db;
    private TwoFactorPoliciesRepository $repo;
    private TwoFactorPolicyResolver $resolver;
    private ?AuditLogger $auditLogger;

    public function __construct(
        PDO $db,
        TwoFactorPolicyResolver $resolver,
        ?AuditLogger $auditLogger = null,
        private readonly ?HookManager $hooks = null,
    ) {
        $this->db = $db;
        $this->repo = new TwoFactorPoliciesRepository($db);
        $this->resolver = $resolver;
        $this->auditLogger = $auditLogger;
    }

    public function list(Request $request): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 400);
        }

        $policies = $this->repo->listForTenant($tenantId);

        return Response::json(['data' => array_map(
            fn (array $policy): array => $policy + ['scope_name' => $this->scopeName($tenantId, $policy)],
            $policies
        )]);
    }

    /**
     * The name a rule's scope reads as: a unit's or a person's. Null for a rule on
     * the whole workspace, and for a scope that no longer exists here.
     *
     * @param array<string, mixed> $policy
     */
    private function scopeName(int $tenantId, array $policy): ?string
    {
        $scopeId = isset($policy['scope_id']) ? (int) $policy['scope_id'] : 0;
        if ($policy['scope_type'] === 'ou') {
            $stmt = $this->db->prepare('SELECT name FROM organizational_units WHERE id = ? AND tenant_id = ?');
        } elseif ($policy['scope_type'] === 'user') {
            $stmt = $this->db->prepare(
                'SELECT p.display_name FROM profiles p
                   JOIN memberships m ON m.profile_id = p.id
                  WHERE p.id = ? AND m.tenant_id = ?'
            );
        } else {
            return null;
        }
        $stmt->execute([$scopeId, $tenantId]);
        $name = $stmt->fetchColumn();

        return is_string($name) && $name !== '' ? $name : null;
    }

    public function create(Request $request): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 400);
        }

        $body = JsonBody::parsed($request);

        $scopeType = (string) ($body['scope_type'] ?? '');
        if (!in_array($scopeType, self::SCOPE_TYPES, true)) {
            return Response::error('scope_type must be one of: ' . implode(', ', self::SCOPE_TYPES), 422);
        }

        $rawScopeId = $body['scope_id'] ?? null;
        if ($scopeType === 'tenant') {
            if ($rawScopeId !== null) {
                return Response::error('scope_id must be omitted when scope_type is "tenant"', 422);
            }
            $scopeId = null;
        } else {
            if (!is_int($rawScopeId) && !(is_string($rawScopeId) && ctype_digit($rawScopeId))) {
                return Response::error('scope_id is required and must be an integer when scope_type is not "tenant"', 422);
            }
            $scopeId = (int) $rawScopeId;

            $existsError = $scopeType === 'ou'
                ? $this->validateOuExists($tenantId, $scopeId)
                : $this->validateProfileHasActiveMembership($tenantId, $scopeId);
            if ($existsError !== null) {
                return Response::error($existsError, 422);
            }
        }

        $rawGracePeriod = $body['grace_period_days'] ?? 0;
        if (!is_int($rawGracePeriod) && !(is_string($rawGracePeriod) && ctype_digit($rawGracePeriod))) {
            return Response::error('grace_period_days must be a non-negative integer', 422);
        }
        $gracePeriodDays = (int) $rawGracePeriod;
        if ($gracePeriodDays < 0) {
            return Response::error('grace_period_days must be a non-negative integer', 422);
        }

        $createdBy = AuditContext::getActorUserId();
        $id = $this->repo->create($tenantId, $scopeType, $scopeId, $gracePeriodDays, $createdBy);
        if ($id === null) {
            return Response::error('A policy already exists for this scope', 409);
        }

        $this->auditLogger?->record('security.2fa_policy.created', [
            'target_type' => 'two_factor_policy',
            'target_id' => $id,
            'metadata' => ['scope_type' => $scopeType, 'scope_id' => $scopeId, 'grace_period_days' => $gracePeriodDays],
        ]);

        $policy = $this->repo->find($tenantId, $id);
        return Response::json(['data' => $policy], 201);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function update(Request $request, array $params = []): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 400);
        }

        $id = isset($params['id']) ? (int) $params['id'] : 0;
        if ($id <= 0 || $this->repo->find($tenantId, $id) === null) {
            return Response::error('Policy not found', 404);
        }

        $body = JsonBody::parsed($request);
        $rawGracePeriod = $body['grace_period_days'] ?? null;
        if (!is_int($rawGracePeriod) && !(is_string($rawGracePeriod) && ctype_digit($rawGracePeriod))) {
            return Response::error('grace_period_days is required and must be a non-negative integer', 422);
        }
        $gracePeriodDays = (int) $rawGracePeriod;
        if ($gracePeriodDays < 0) {
            return Response::error('grace_period_days must be a non-negative integer', 422);
        }

        $this->repo->updateGracePeriod($tenantId, $id, $gracePeriodDays);

        $this->auditLogger?->record('security.2fa_policy.updated', [
            'target_type' => 'two_factor_policy',
            'target_id' => $id,
            'metadata' => ['grace_period_days' => $gracePeriodDays],
        ]);

        return Response::json(['data' => $this->repo->find($tenantId, $id)]);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function delete(Request $request, array $params = []): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 400);
        }

        $id = isset($params['id']) ? (int) $params['id'] : 0;
        if ($id <= 0 || !$this->repo->delete($tenantId, $id)) {
            return Response::error('Policy not found', 404);
        }

        $this->auditLogger?->record('security.2fa_policy.deleted', [
            'target_type' => 'two_factor_policy',
            'target_id' => $id,
        ]);

        return Response::json([], 204);
    }

    /**
     * Enrollment status across every profile any policy in this tenant covers:
     * tenant-wide → every active membership; OU-scoped → every active
     * membership whose OU is the target OU or a descendant of it;
     * user-scoped → that profile directly (if it still holds an active
     * membership).
     */
    public function status(Request $request): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 400);
        }

        $inScope = $this->profilesInScope($tenantId);
        if ($inScope === []) {
            return Response::json(['data' => []]);
        }

        $identity = $this->identityRows(array_keys($inScope));
        $reminded = $this->lastReminders($tenantId, array_keys($inScope));

        $data = [];
        foreach ($inScope as $profileId => $ouId) {
            $enrolled = $identity[$profileId]['enabled'] ?? false;
            $data[] = [
                'profile_id' => $profileId,
                'name' => $identity[$profileId]['name'] ?? '',
                'email' => $identity[$profileId]['email'] ?? '',
                'enrolled' => $enrolled,
                'enforcement_deadline' => $enrolled ? null : $this->resolver->enforcementDeadline($tenantId, $profileId, $ouId),
                'last_reminded_at' => $reminded[$profileId] ?? null,
            ];
        }

        return Response::json(['data' => $data]);
    }

    /**
     * POST /api/2fa-policies/remind — remind one person to set up two-step sign-in.
     *
     * Only somebody a rule in this workspace covers and who hasn't set it up. Once
     * a day per person, because this lands in their mailbox. The update is what
     * enforces that, so a double click sends one reminder.
     */
    public function remind(Request $request): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 400);
        }

        $body = JsonBody::parsed($request);
        $rawId = $body['profile_id'] ?? null;
        if (!is_int($rawId) && !(is_string($rawId) && ctype_digit($rawId))) {
            return Response::error('profile_id is required and must be an integer', 422);
        }
        $profileId = (int) $rawId;

        // One answer for a stranger and for somebody no rule covers, so this
        // can't be used to find out who belongs to another workspace.
        if (!array_key_exists($profileId, $this->profilesInScope($tenantId))) {
            return Response::error('No two-step rule in this workspace covers that person', 404);
        }

        $identity = $this->identityRows([$profileId])[$profileId] ?? null;
        if ($identity === null) {
            return Response::error('No two-step rule in this workspace covers that person', 404);
        }
        if ($identity['enabled']) {
            return Response::error('That person has already set up two-step sign-in', 409);
        }

        $now = gmdate('Y-m-d H:i:s');
        $actorId = isset($request->user->profile_id) ? (int) $request->user->profile_id : null;
        $stmt = $this->db->prepare(
            'INSERT INTO two_factor_reminders (tenant_id, profile_id, reminded_at, reminded_by)
             VALUES (:tenant_id, :profile_id, :now, :by)
             ON CONFLICT (tenant_id, profile_id) DO UPDATE
                SET reminded_at = EXCLUDED.reminded_at, reminded_by = EXCLUDED.reminded_by
              WHERE two_factor_reminders.reminded_at < :cutoff'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':profile_id' => $profileId,
            ':now' => $now,
            ':by' => $actorId,
            ':cutoff' => gmdate('Y-m-d H:i:s', time() - self::REMIND_EVERY_SECONDS),
        ]);
        if ($stmt->rowCount() === 0) {
            return Response::error(
                'That person was already reminded today',
                429,
                ['last_reminded_at' => $this->lastReminders($tenantId, [$profileId])[$profileId] ?? null]
            );
        }

        // Both languages, as a printed document has: this goes out in the
        // operator's request, which says nothing about what the recipient reads.
        $this->hooks?->dispatch(NotificationDispatcher::HOOK_EVENT, [
            'tenant_id' => $tenantId,
            'recipient_profile_id' => $profileId,
            'type' => self::REMINDER_NOTIFICATION,
            'options' => [
                'channels' => ['in_app', 'email'],
                'to' => $identity['email'],
                'subject' => 'Please set up two-step sign-in / يرجى إعداد التحقق بخطوتين',
                'body' => "Your workspace asks you to set up two-step sign-in. Open your account, then Sign-in and security.\n\n"
                    . 'تطلب منك مساحة العمل إعداد التحقق بخطوتين. افتح حسابك، ثم تسجيل الدخول والأمان.',
                'data' => ['profile_id' => $profileId],
            ],
        ]);

        $this->auditLogger?->record('security.2fa_policy.reminded', [
            'tenant_id' => $tenantId,
            'target_type' => 'user',
            'target_id' => $profileId,
        ]);

        return Response::json(['data' => ['profile_id' => $profileId, 'last_reminded_at' => $now]]);
    }

    /**
     * @param list<int> $profileIds
     * @return array<int, string>
     */
    private function lastReminders(int $tenantId, array $profileIds): array
    {
        if ($profileIds === []) {
            return [];
        }
        $stmt = $this->db->prepare(
            'SELECT profile_id, reminded_at FROM two_factor_reminders
              WHERE tenant_id = ? AND profile_id IN (' . implode(', ', array_fill(0, count($profileIds), '?')) . ')'
        );
        $stmt->execute([$tenantId, ...$profileIds]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['profile_id']] = (string) $row['reminded_at'];
        }

        return $out;
    }

    /**
     * Everybody a rule in this workspace covers.
     *
     * @return array<int, int|null> profileId => ouId
     */
    private function profilesInScope(int $tenantId): array
    {
        $policies = $this->repo->listForTenant($tenantId);
        if ($policies === []) {
            return [];
        }

        $hasTenantWide = false;
        $ouScopeIds = [];
        $userScopeIds = [];
        foreach ($policies as $policy) {
            if ($policy['scope_type'] === 'tenant') {
                $hasTenantWide = true;
            } elseif ($policy['scope_type'] === 'ou') {
                $ouScopeIds[] = $policy['scope_id'];
            } elseif ($policy['scope_type'] === 'user') {
                $userScopeIds[] = $policy['scope_id'];
            }
        }

        // profileId => ouId|null
        $inScope = [];

        if ($hasTenantWide) {
            foreach ($this->activeMemberships($tenantId) as $profileId => $ouId) {
                $inScope[$profileId] = $ouId;
            }
        } else {
            if ($ouScopeIds !== []) {
                $descendantOuIds = $this->descendantOuIds($tenantId, $ouScopeIds);
                foreach ($this->activeMemberships($tenantId, ouIds: $descendantOuIds) as $profileId => $ouId) {
                    $inScope[$profileId] = $ouId;
                }
            }
            if ($userScopeIds !== []) {
                foreach ($this->activeMemberships($tenantId, profileIds: $userScopeIds) as $profileId => $ouId) {
                    $inScope[$profileId] = $ouId;
                }
            }
        }

        return $inScope;
    }

    private function validateOuExists(int $tenantId, int $ouId): ?string
    {
        $stmt = $this->db->prepare('SELECT 1 FROM organizational_units WHERE id = ? AND tenant_id = ? LIMIT 1');
        $stmt->execute([$ouId, $tenantId]);

        return $stmt->fetchColumn() !== false ? null : 'scope_id does not reference an organizational unit in this tenant';
    }

    private function validateProfileHasActiveMembership(int $tenantId, int $profileId): ?string
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM memberships WHERE profile_id = ? AND tenant_id = ? AND status = 'active' LIMIT 1"
        );
        $stmt->execute([$profileId, $tenantId]);

        return $stmt->fetchColumn() !== false ? null : 'scope_id does not reference an active member of this tenant';
    }

    /**
     * Active memberships in the tenant, optionally filtered to a set of OU ids
     * or profile ids (never both — callers pass exactly one filter or none).
     *
     * @param list<int>|null $ouIds
     * @param list<int>|null $profileIds
     * @return array<int, int|null> profileId => ouId
     */
    private function activeMemberships(int $tenantId, ?array $ouIds = null, ?array $profileIds = null): array
    {
        $sql = "SELECT profile_id, ou_id FROM memberships WHERE is_primary AND tenant_id = ? AND status = 'active'";
        $params = [$tenantId];

        if ($ouIds !== null) {
            if ($ouIds === []) {
                return [];
            }
            $sql .= ' AND ou_id IN (' . implode(', ', array_fill(0, count($ouIds), '?')) . ')';
            array_push($params, ...$ouIds);
        } elseif ($profileIds !== null) {
            if ($profileIds === []) {
                return [];
            }
            $sql .= ' AND profile_id IN (' . implode(', ', array_fill(0, count($profileIds), '?')) . ')';
            array_push($params, ...$profileIds);
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['profile_id']] = $row['ou_id'] !== null ? (int) $row['ou_id'] : null;
        }

        return $out;
    }

    /**
     * Email + two_factor_enabled for a set of profile ids.
     *
     * @param list<int> $profileIds
     * @return array<int, array{name: string, email: string, enabled: bool}>
     */
    private function identityRows(array $profileIds): array
    {
        $placeholders = implode(', ', array_fill(0, count($profileIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT p.id, p.display_name, p.two_factor_enabled, pe.email
             FROM profiles p
             JOIN profile_emails pe ON pe.profile_id = p.id AND pe.is_primary = true
             WHERE p.id IN ({$placeholders})"
        );
        $stmt->execute($profileIds);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['id']] = [
                'name' => (string) ($row['display_name'] ?? ''),
                'email' => (string) $row['email'],
                'enabled' => self::dbTruthy($row['two_factor_enabled']),
            ];
        }

        return $out;
    }

    /**
     * Every descendant OU id (including the roots themselves) reachable from
     * the given OU ids, tenant scoped. Downward BFS over
     * organizational_units.parent_id — the reverse direction of
     * RoleChecker's upward ancestor walk. Bounded by
     * {@see \Whity\Core\Ou\OuSubtree::MAX_DEPTH} against a malformed/cyclic
     * hierarchy.
     *
     * @param list<int> $rootIds
     * @return list<int>
     */
    private function descendantOuIds(int $tenantId, array $rootIds): array
    {
        // Delegated to {@see \Whity\Core\Ou\OuSubtree} since #947 item 3, which
        // needs the same downward walk for its `role_below_actor` routing rule.
        // The two copies already differed in nothing that mattered, and a third
        // is how "below my unit" comes to mean two different sets of people in
        // two screens of the same product.
        return \Whity\Core\Ou\OuSubtree::descendantIds($this->db, $tenantId, $rootIds);
    }

        /**
     * Coerce a DB boolean column to a real bool.
     *
     * Delegates to the canonical coercion (#891). {@see DbBool} records which
     * spellings each driver actually returns — measured on the PHP this
     * platform ships, not assumed — and why a bare `(bool)` cast is not an
     * equivalent substitute for it.
     */
    private static function dbTruthy(mixed $value): bool
    {
        return DbBool::of($value);
    }
}
