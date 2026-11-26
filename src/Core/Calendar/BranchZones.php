<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use DateTimeZone;
use PDO;

/**
 * A branch's own time zone (WHIT-1440, design section 39 §5.3, AP.16).
 *
 * A branch is an organizational unit; `organizational_units.time_zone` is an
 * IANA name or null. A staff member's hours and bookings use the zone of the
 * branch they work in (`calendar_staff.unit_id`), and the workspace's zone
 * when that branch has none or they have no branch. Tenant first everywhere.
 */
final class BranchZones
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** An IANA zone name Whity can use, or null. */
    public static function valid(mixed $name): ?string
    {
        static $known = null;
        $known ??= array_flip(DateTimeZone::listIdentifiers());

        return is_string($name) && isset($known[$name]) ? $name : null;
    }

    /** The zone a person's times are in: their branch's, or `$workspace`. */
    public function forPerson(int $tenantId, int $profileId, DateTimeZone $workspace): DateTimeZone
    {
        $stmt = $this->db->prepare(
            'SELECT ou.time_zone FROM calendar_staff cs
               JOIN organizational_units ou ON ou.id = cs.unit_id AND ou.tenant_id = cs.tenant_id
              WHERE cs.tenant_id = :tenant_id AND cs.profile_id = :profile_id'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':profile_id' => $profileId]);
        $name = self::valid($stmt->fetchColumn());

        return $name !== null ? new DateTimeZone($name) : $workspace;
    }

    /** The branch a person works in, or null. */
    public function branchOf(int $tenantId, int $profileId): ?int
    {
        $stmt = $this->db->prepare('SELECT unit_id FROM calendar_staff WHERE tenant_id = :tenant_id AND profile_id = :profile_id');
        $stmt->execute([':tenant_id' => $tenantId, ':profile_id' => $profileId]);
        $unit = $stmt->fetchColumn();

        return $unit !== false && $unit !== null ? (int) $unit : null;
    }

    /**
     * Every unit with its zone, for the calendar's branch settings: the
     * units staff or resources are in come first.
     *
     * @return list<array{id: int, name: string, time_zone: ?string, in_use: bool}>
     */
    public function list(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT ou.id, ou.name, ou.time_zone,
                    CASE WHEN EXISTS (SELECT 1 FROM calendar_staff cs WHERE cs.tenant_id = ou.tenant_id AND cs.unit_id = ou.id)
                           OR EXISTS (SELECT 1 FROM calendar_resources r WHERE r.tenant_id = ou.tenant_id AND r.unit_id = ou.id)
                         THEN 1 ELSE 0 END AS in_use
               FROM organizational_units ou
              WHERE ou.tenant_id = :tenant_id
              ORDER BY in_use DESC, ou.name, ou.id'
        );
        $stmt->execute([':tenant_id' => $tenantId]);

        return array_values(array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'time_zone' => self::valid($r['time_zone']),
            'in_use' => (int) $r['in_use'] === 1,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /** Set or clear a branch's zone. False when the unit is not this workspace's. */
    public function set(int $tenantId, int $unitId, ?string $zone): bool
    {
        $stmt = $this->db->prepare('UPDATE organizational_units SET time_zone = :zone WHERE tenant_id = :tenant_id AND id = :id');
        $stmt->bindValue(':zone', $zone, $zone === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':id', $unitId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }
}
