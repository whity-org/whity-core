<?php

declare(strict_types=1);

namespace Whity\Core\Tenant;

use PDO;

/**
 * Who owns a workspace (WHIT-1169, migration 300).
 *
 * Co-owners are equal (owner, 2026-11-16): every row is a full owner. Only
 * owners add or remove owners, hand ownership over, change the plan and pay
 * invoices. An owner also holds an admin membership; this table never grants
 * a permission by itself, it only lets RoleChecker cap the owner-only ones
 * ({@see self::OWNER_ONLY_PERMISSIONS}) for everyone else.
 *
 * Every read and write names its tenant, except {@see self::countOwnedBy()},
 * which counts the person's OWN rows across workspaces for the limit.
 */
final class WorkspaceOwners
{
    /**
     * Permissions only an owner keeps. Changing the plan goes through
     * `billing:pay` (checkout and plan changes on the external billing routes),
     * so this one slug covers both.
     */
    public const OWNER_ONLY_PERMISSIONS = ['billing:pay'];

    public function __construct(private readonly PDO $db)
    {
    }

    public function isOwner(int $tenantId, int $profileId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM workspace_owners WHERE tenant_id = :t AND profile_id = :p');
        $stmt->execute([':t' => $tenantId, ':p' => $profileId]);
        return $stmt->fetchColumn() !== false;
    }

    public function hasOwners(int $tenantId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM workspace_owners WHERE tenant_id = :t LIMIT 1');
        $stmt->execute([':t' => $tenantId]);
        return $stmt->fetchColumn() !== false;
    }

    public function count(int $tenantId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM workspace_owners WHERE tenant_id = :t');
        $stmt->execute([':t' => $tenantId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * The workspace's owners, oldest first, with their names.
     *
     * @return list<array{profile_id: int, display_name: string, email: string, since: string, added_by_profile_id: ?int}>
     */
    public function list(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT o.profile_id, p.display_name, pe.email, o.created_at, o.added_by_profile_id
               FROM workspace_owners o
               JOIN profiles p ON p.id = o.profile_id
               LEFT JOIN profile_emails pe ON pe.profile_id = o.profile_id AND pe.is_primary = TRUE
              WHERE o.tenant_id = :t
              ORDER BY o.created_at ASC, o.id ASC'
        );
        $stmt->execute([':t' => $tenantId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'profile_id'          => (int) $row['profile_id'],
                'display_name'        => (string) ($row['display_name'] ?? ''),
                'email'               => (string) ($row['email'] ?? ''),
                'since'               => (string) $row['created_at'],
                'added_by_profile_id' => $row['added_by_profile_id'] !== null ? (int) $row['added_by_profile_id'] : null,
            ];
        }
        return $out;
    }

    /**
     * How many workspaces this person owns, for `workspaces.max_owned_per_person`.
     */
    public function countOwnedBy(int $profileId): int
    {
        // The person's OWN rows across every workspace, counted for the per-person limit.
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM workspace_owners WHERE profile_id = :p AND tenant_id <> 0');
        $stmt->execute([':p' => $profileId]);
        return (int) $stmt->fetchColumn();
    }

    /** Make them an owner. True when a row was added, false when they already were one. */
    public function add(int $tenantId, int $profileId, ?int $addedBy): bool
    {
        if ($this->isOwner($tenantId, $profileId)) {
            return false;
        }
        $this->db->prepare(
            'INSERT INTO workspace_owners (tenant_id, profile_id, added_by_profile_id, created_at)
             VALUES (:t, :p, :by, NOW())'
        )->execute([':t' => $tenantId, ':p' => $profileId, ':by' => $addedBy]);
        return true;
    }

    /** Stop them being an owner. True when a row was removed. */
    public function remove(int $tenantId, int $profileId): bool
    {
        $stmt = $this->db->prepare('DELETE FROM workspace_owners WHERE tenant_id = :t AND profile_id = :p');
        $stmt->execute([':t' => $tenantId, ':p' => $profileId]);
        return $stmt->rowCount() > 0;
    }
}
