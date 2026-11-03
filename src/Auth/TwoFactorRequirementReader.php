<?php

declare(strict_types=1);

namespace Whity\Auth;

use PDO;

/**
 * Which people of a page must use two-step, in one read for the whole page.
 *
 * {@see TwoFactorPolicyResolver} answers the same question for one person at sign-in
 * and walks the unit tree with a query per level. The users list asks for a page at a
 * time, so this reads every policy the page could touch once and works it out in PHP.
 * The rule is the resolver's: a policy applies through the tenant, the person's unit
 * or an ancestor of it, or the person directly.
 *
 * Only the TARGET tenant's policies count, and for the system tenant a page mixes
 * tenants, so each row is judged against its own tenant's policies. That matches
 * enforcement, which reads the policies of the tenant being signed in to.
 */
final class TwoFactorRequirementReader
{
    private const MAX_HIERARCHY_DEPTH = 64;

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param array<int, array<string, mixed>> $rows Rows carrying `id` (profile), `tenant_id` and `ou_id`.
     * @return array<string, array{required: bool, requiredDirectly: bool}> Keyed `tenantId:profileId`.
     */
    public function forRows(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $tenants = [];
        foreach ($rows as $row) {
            $tenants[(int) ($row['tenant_id'] ?? 0)] = true;
        }
        $tenantIds = array_keys($tenants);
        $in = implode(', ', array_fill(0, count($tenantIds), '?'));

        $stmt = $this->db->prepare(
            "SELECT tenant_id, scope_type, scope_id FROM two_factor_policies WHERE tenant_id IN ({$in})"
        );
        $stmt->execute($tenantIds);

        $tenantWide = [];
        $direct = [];
        $byUnit = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $policy) {
            $tenant = (int) ($policy['tenant_id'] ?? 0);
            $scopeId = (int) ($policy['scope_id'] ?? 0);
            match ($policy['scope_type'] ?? null) {
                'tenant' => $tenantWide[$tenant] = true,
                'user' => $direct[$tenant . ':' . $scopeId] = true,
                'ou' => $byUnit[$tenant][$scopeId] = true,
                default => null,
            };
        }

        $parents = $this->unitParents(array_keys($byUnit));

        $result = [];
        foreach ($rows as $row) {
            $tenant = (int) ($row['tenant_id'] ?? 0);
            $key = $tenant . ':' . (int) ($row['id'] ?? 0);
            $isDirect = isset($direct[$key]);
            $ouId = isset($row['ou_id']) ? (int) $row['ou_id'] : null;
            $result[$key] = [
                'required' => $isDirect
                    || isset($tenantWide[$tenant])
                    || ($ouId !== null && self::underPolicy($ouId, $byUnit[$tenant] ?? [], $parents[$tenant] ?? [])),
                'requiredDirectly' => $isDirect,
            ];
        }

        return $result;
    }

    /**
     * @param array<int, int> $tenantIds Tenants that have at least one unit policy.
     * @return array<int, array<int, int|null>> tenant => unit => parent.
     */
    private function unitParents(array $tenantIds): array
    {
        if ($tenantIds === []) {
            return [];
        }
        $in = implode(', ', array_fill(0, count($tenantIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT id, parent_id, tenant_id FROM organizational_units WHERE tenant_id IN ({$in})"
        );
        $stmt->execute($tenantIds);

        $parents = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $unit) {
            $parents[(int) $unit['tenant_id']][(int) $unit['id']] = isset($unit['parent_id']) ? (int) $unit['parent_id'] : null;
        }

        return $parents;
    }

    /**
     * @param array<int, true>     $policyUnits Units carrying a policy.
     * @param array<int, int|null> $parents     unit => parent.
     */
    private static function underPolicy(int $ouId, array $policyUnits, array $parents): bool
    {
        $current = $ouId;
        for ($depth = 0; $current !== null && $depth < self::MAX_HIERARCHY_DEPTH; $depth++) {
            if (isset($policyUnits[$current])) {
                return true;
            }
            $current = $parents[$current] ?? null;
        }

        return false;
    }
}
