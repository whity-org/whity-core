<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use DateTimeImmutable;
use PDO;

/**
 * External calendars that could not be read (WHIT-1440, design section 39
 * §5.4, AP.17). Booking stays open when one fails (ADR 0017 section 4); this
 * keeps who and when, so the desk and the person see "Couldn't check Ahmad
 * Khalil's Google calendar at 09:14", and the health page counts them.
 *
 * Short-lived: one row per person and source at most every
 * {@see self::QUIET_MINUTES}, and {@see self::prune()} (a daily job) deletes
 * rows older than {@see self::KEEP_DAYS}. Tenant first everywhere.
 */
final class BusyCheckFailures
{
    /** A failure for the same person and source inside this is not written again. */
    public const QUIET_MINUTES = 5;
    public const KEEP_DAYS = 7;
    /** What a source without a name is called. */
    public const UNNAMED = 'external';

    public function __construct(private readonly PDO $db)
    {
    }

    public function record(int $tenantId, int $profileId, string $source, DateTimeImmutable $at): void
    {
        $source = mb_substr(trim($source) !== '' ? trim($source) : self::UNNAMED, 0, 120);
        $stmt = $this->db->prepare(
            'SELECT 1 FROM calendar_busy_check_failures
              WHERE tenant_id = :tenant_id AND profile_id = :profile_id AND source = :source AND failed_at > :since'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':profile_id' => $profileId,
            ':source' => $source,
            ':since' => Interval::toDb($at->modify('-' . self::QUIET_MINUTES . ' minutes')),
        ]);
        if ($stmt->fetchColumn() !== false) {
            return;
        }
        $this->db->prepare(
            'INSERT INTO calendar_busy_check_failures (tenant_id, profile_id, source, failed_at)
             VALUES (:tenant_id, :profile_id, :source, :at)'
        )->execute([':tenant_id' => $tenantId, ':profile_id' => $profileId, ':source' => $source, ':at' => Interval::toDb($at)]);
    }

    /**
     * The latest failure for each person and source since `$since`, newest
     * first, with the person's name. `$profileId` limits it to one person.
     *
     * @return list<array{profile_id: int, name: string, source: string, failed_at: string}>
     */
    public function recent(int $tenantId, DateTimeImmutable $since, ?int $profileId = null): array
    {
        $sql = 'SELECT f.profile_id, p.display_name, f.source, MAX(f.failed_at) AS failed_at
                  FROM calendar_busy_check_failures f
                  JOIN profiles p ON p.id = f.profile_id
                 WHERE f.tenant_id = :tenant_id AND f.failed_at >= :since';
        if ($profileId !== null) {
            $sql .= ' AND f.profile_id = :profile_id';
        }
        $stmt = $this->db->prepare($sql . ' GROUP BY f.profile_id, p.display_name, f.source ORDER BY MAX(f.failed_at) DESC, f.profile_id');
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':since', Interval::toDb($since));
        if ($profileId !== null) {
            $stmt->bindValue(':profile_id', $profileId, PDO::PARAM_INT);
        }
        $stmt->execute();

        return array_values(array_map(static fn (array $r): array => [
            'profile_id' => (int) $r['profile_id'],
            'name' => (string) $r['display_name'],
            'source' => (string) $r['source'],
            'failed_at' => Interval::toApi(Interval::fromDb((string) $r['failed_at'])),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /** How many failures were written since `$since`, across every workspace (the health page). */
    public function countSince(DateTimeImmutable $since): int
    {
        // @tenant-guard-ignore: the health page counts failures across every workspace; no row is returned
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM calendar_busy_check_failures WHERE failed_at >= :since');
        $stmt->execute([':since' => Interval::toDb($since)]);

        return (int) $stmt->fetchColumn();
    }

    /** Delete rows older than {@see self::KEEP_DAYS}; returns how many. */
    public function prune(DateTimeImmutable $now): int
    {
        // @tenant-guard-ignore: the daily prune clears old rows for every workspace
        $stmt = $this->db->prepare('DELETE FROM calendar_busy_check_failures WHERE failed_at < :before');
        $stmt->execute([':before' => Interval::toDb($now->modify('-' . self::KEEP_DAYS . ' days'))]);

        return $stmt->rowCount();
    }
}
