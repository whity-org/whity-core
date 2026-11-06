<?php

declare(strict_types=1);

namespace Whity\Core\Labels;

use PDO;

/**
 * Display labels for a small batch of unit and role ids (#1019).
 *
 * A screen that stores units and roles as foreign keys needs their names to be
 * readable, and until now the only way to get a name was to list the whole
 * directory (`ous:read`, `roles:read`). This answers the narrower question:
 * "what are these few ids called". It returns the label and nothing else: no
 * parent, no path, no type, no members, no permissions. The moment it returned
 * more it would be the directory listing again under another name.
 *
 * TENANT-SCOPED IN THE STATEMENT. A unit is this tenant's when
 * `organizational_units.tenant_id` is this tenant. A role is this tenant's own
 * or a global base role (`tenant_id IS NULL`), the same visibility
 * `GET /api/roles` applies. Another tenant's id matches no row, so it comes back
 * absent, exactly like an id that does not exist: the caller cannot tell the two
 * apart. There is no system-tenant branch; tenant 0 is bound like any other.
 *
 * PEOPLE ARE NOT HERE, ON PURPOSE. A person's name is personal data, and
 * resolving caller-chosen profile ids would let anybody holding the surface's
 * permission walk the integers and read the workspace's people out, which is
 * what `users:read` guards. People are named from the records the caller may
 * already see ({@see \Whity\Core\Document\DocumentPeople}).
 */
final class LabelResolver
{
    public const KIND_OUS = 'ous';
    public const KIND_ROLES = 'roles';

    /** @var list<string> */
    public const KINDS = [self::KIND_OUS, self::KIND_ROLES];

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Labels for the requested ids, keyed by kind then id. An id with no label
     * in this tenant is absent.
     *
     * @param array<string, list<int>> $ids Kind => ids. Unknown kinds are ignored.
     * @return array<string, array<int, string>>
     */
    public function resolve(int $tenantId, array $ids): array
    {
        $labels = [];
        foreach (self::KINDS as $kind) {
            $labels[$kind] = $this->forKind($tenantId, $kind, $ids[$kind] ?? []);
        }

        return $labels;
    }

    /**
     * @param list<int> $ids
     * @return array<int, string>
     */
    private function forKind(int $tenantId, string $kind, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));

        $stmt = $kind === self::KIND_OUS
            ? $this->db->prepare(
                "SELECT id, name FROM organizational_units
                  WHERE tenant_id = ? AND id IN ({$placeholders})"
            )
            : $this->db->prepare(
                "SELECT id, name FROM roles
                  WHERE (tenant_id = ? OR tenant_id IS NULL) AND id IN ({$placeholders})"
            );
        $stmt->execute([$tenantId, ...$ids]);

        $labels = [];
        /** @var array<string, mixed> $row */
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = is_string($row['name'] ?? null) ? trim($row['name']) : '';
            if ($name !== '') {
                $labels[(int) $row['id']] = $name;
            }
        }

        return $labels;
    }
}
