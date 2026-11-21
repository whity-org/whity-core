<?php

declare(strict_types=1);

namespace Whity\Core\Document\Signing;

use PDO;

/**
 * Data access for a workspace's company stamps and their holders (#1729,
 * migration 331).
 *
 * A stamp is archived, never deleted: route steps and applied stamps name it.
 * A holder is a named person or a role.
 *
 * TENANT-OWNED: every statement binds a literal `tenant_id` predicate.
 */
final class CompanyStampRepository
{
    private const COLUMNS = 'id, tenant_id, name, legal_name, image, image_hash, created_by_profile_id,
        created_at, updated_at, archived_at';

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @return list<array<string, mixed>> Oldest first, with their holders.
     */
    public function list(int $tenantId, bool $includeArchived = false): array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNS . ' FROM company_stamps
              WHERE tenant_id = :tenant_id' . ($includeArchived ? '' : ' AND archived_at IS NULL') . '
              ORDER BY id ASC'
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn (array $row): array => $this->withHolders($tenantId, self::normalize($row)), $rows);
    }

    /** @return array<string, mixed>|null */
    public function find(int $tenantId, int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNS . ' FROM company_stamps WHERE tenant_id = :tenant_id AND id = :id'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        return is_array($row) ? $this->withHolders($tenantId, self::normalize($row)) : null;
    }

    public function create(int $tenantId, string $name, string $legalName, string $image, ?int $createdBy): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->db->prepare(
            'INSERT INTO company_stamps
                 (tenant_id, name, legal_name, image, image_hash, created_by_profile_id, created_at, updated_at)
             VALUES (:tenant_id, :name, :legal_name, :image, :image_hash, :created_by, :now, :now2)'
        )->execute([
            ':tenant_id' => $tenantId,
            ':name' => $name,
            ':legal_name' => $legalName,
            ':image' => $image,
            ':image_hash' => StampImage::hash($image),
            ':created_by' => $createdBy,
            ':now' => $now,
            ':now2' => $now,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Change a stamp's name, legal name or image. Applied stamps keep their
     * own copy, so this never changes a record already made.
     *
     * @param array{name?: string, legal_name?: string, image?: string} $changes
     */
    public function update(int $tenantId, int $id, array $changes): void
    {
        $sets = [];
        $params = [':tenant_id' => $tenantId, ':id' => $id, ':now' => gmdate('Y-m-d H:i:s')];
        foreach (['name', 'legal_name', 'image'] as $field) {
            if (array_key_exists($field, $changes)) {
                $sets[] = "{$field} = :{$field}";
                $params[':' . $field] = $changes[$field];
            }
        }
        if (array_key_exists('image', $changes)) {
            $sets[] = 'image_hash = :image_hash';
            $params[':image_hash'] = StampImage::hash($changes['image']);
        }
        if ($sets === []) {
            return;
        }
        $this->db->prepare(
            'UPDATE company_stamps SET ' . implode(', ', $sets) . ', updated_at = :now
              WHERE tenant_id = :tenant_id AND id = :id'
        )->execute($params);
    }

    public function setArchived(int $tenantId, int $id, bool $archived): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->db->prepare(
            'UPDATE company_stamps SET archived_at = :archived_at, updated_at = :now
              WHERE tenant_id = :tenant_id AND id = :id'
        )->execute([
            ':archived_at' => $archived ? $now : null,
            ':now' => $now,
            ':tenant_id' => $tenantId,
            ':id' => $id,
        ]);
    }

    /**
     * Replace a stamp's holders.
     *
     * @param list<int> $profileIds
     * @param list<int> $roleIds
     */
    public function setHolders(int $tenantId, int $stampId, array $profileIds, array $roleIds): void
    {
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $this->db->prepare(
                'DELETE FROM company_stamp_holders WHERE tenant_id = :tenant_id AND stamp_id = :stamp_id'
            )->execute([':tenant_id' => $tenantId, ':stamp_id' => $stampId]);
            $insert = $this->db->prepare(
                'INSERT INTO company_stamp_holders (tenant_id, stamp_id, profile_id, role_id, created_at)
                 VALUES (:tenant_id, :stamp_id, :profile_id, :role_id, :now)'
            );
            $now = gmdate('Y-m-d H:i:s');
            foreach (array_unique($profileIds) as $profileId) {
                $insert->execute([':tenant_id' => $tenantId, ':stamp_id' => $stampId, ':profile_id' => $profileId, ':role_id' => null, ':now' => $now]);
            }
            foreach (array_unique($roleIds) as $roleId) {
                $insert->execute([':tenant_id' => $tenantId, ':stamp_id' => $stampId, ':profile_id' => null, ':role_id' => $roleId, ':now' => $now]);
            }
            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Whether a person holds a stamp: named on it, or holding one of its roles
     * in this workspace (an active membership with that role).
     */
    public function isHolder(int $tenantId, int $stampId, int $profileId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM company_stamp_holders h
              WHERE h.tenant_id = :tenant_id AND h.stamp_id = :stamp_id
                AND (h.profile_id = :profile_id
                     OR h.role_id IN (
                         SELECT m.role_id FROM memberships m
                          WHERE m.tenant_id = :tenant_id2 AND m.profile_id = :profile_id2 AND m.status = 'active'
                     ))
              LIMIT 1"
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':stamp_id' => $stampId,
            ':profile_id' => $profileId,
            ':tenant_id2' => $tenantId,
            ':profile_id2' => $profileId,
        ]);
        $found = $stmt->fetchColumn() !== false;
        $stmt->closeCursor();

        return $found;
    }

    /**
     * Whether every id is an active member of this workspace.
     *
     * @param list<int> $profileIds
     */
    public function allMembers(int $tenantId, array $profileIds): bool
    {
        foreach (array_unique($profileIds) as $profileId) {
            $stmt = $this->db->prepare(
                "SELECT 1 FROM memberships WHERE tenant_id = :tenant_id AND profile_id = :profile_id AND status = 'active' LIMIT 1"
            );
            $stmt->execute([':tenant_id' => $tenantId, ':profile_id' => $profileId]);
            $found = $stmt->fetchColumn() !== false;
            $stmt->closeCursor();
            if (!$found) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether every id is a role this workspace can use: its own, or a shared one.
     *
     * @param list<int> $roleIds
     */
    public function allRoles(int $tenantId, array $roleIds): bool
    {
        foreach (array_unique($roleIds) as $roleId) {
            // roles: tenant_id NULL is a shared role every workspace uses.
            $stmt = $this->db->prepare(
                'SELECT 1 FROM roles WHERE id = :id AND (tenant_id = :tenant_id OR tenant_id IS NULL) LIMIT 1'
            );
            $stmt->execute([':id' => $roleId, ':tenant_id' => $tenantId]);
            $found = $stmt->fetchColumn() !== false;
            $stmt->closeCursor();
            if (!$found) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $stamp
     * @return array<string, mixed>
     */
    private function withHolders(int $tenantId, array $stamp): array
    {
        // profiles is a sanctioned GLOBAL identity table (ADR 0005 §1); roles may be shared (tenant_id NULL).
        $stmt = $this->db->prepare(
            'SELECT h.profile_id, h.role_id, p.display_name, r.name AS role_name
               FROM company_stamp_holders h
               LEFT JOIN profiles p ON p.id = h.profile_id
               LEFT JOIN roles r ON r.id = h.role_id
              WHERE h.tenant_id = :tenant_id AND h.stamp_id = :stamp_id
              ORDER BY h.id ASC'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':stamp_id' => (int) $stamp['id']]);
        $people = [];
        $roles = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['profile_id'] !== null) {
                $people[] = ['profile_id' => (int) $row['profile_id'], 'name' => (string) ($row['display_name'] ?? '')];
            } elseif ($row['role_id'] !== null) {
                $roles[] = ['role_id' => (int) $row['role_id'], 'name' => (string) ($row['role_name'] ?? '')];
            }
        }
        $stamp['holders'] = ['people' => $people, 'roles' => $roles];

        return $stamp;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalize(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'tenant_id' => (int) $row['tenant_id'],
            'name' => (string) $row['name'],
            'legal_name' => (string) $row['legal_name'],
            'image' => (string) $row['image'],
            'image_hash' => (string) $row['image_hash'],
            'created_by_profile_id' => isset($row['created_by_profile_id']) ? (int) $row['created_by_profile_id'] : null,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
            'archived_at' => isset($row['archived_at']) ? (string) $row['archived_at'] : null,
        ];
    }
}
