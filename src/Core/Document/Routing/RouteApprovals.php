<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

use PDO;

/**
 * Who approved a route, for printing on its final document (WHIT-1018).
 *
 * Read from the trail: every act carrying a verdict. A person's LATEST verdict
 * at a step is the one that stands, so an approval given again after a
 * send-back is listed once, and one who approved and later rejected at the
 * same step is not listed. People a step left out
 * ({@see RouteStepExclusions}) never acted, so they never appear. Listed in
 * the order the approvals were given.
 *
 * As merge fields ({@see \Whity\Core\Document\Fields\RouteApprovalFieldProvider}):
 * `route.approvals.<n>.name|date|step|mark` for every approval on the route,
 * `route.step.<position>.approvals.<n>.…` for one step's, each with a
 * `….count`. A table row holding `route.approvals.*.name` prints once per
 * approval ({@see \Whity\Core\Document\Print\WhityDocumentPrinter}).
 *
 * Stateless apart from the injected handle — worker-safe.
 */
final class RouteApprovals
{
    public const PREFIX = 'route.approvals';

    /** The parts of one approval a template can print. */
    public const PARTS = ['name', 'date', 'step', 'mark'];

    public function __construct(private readonly PDO $db)
    {
    }

    /** `route.approvals` or `route.step.<position>.approvals`. */
    public static function prefix(?int $position = null): string
    {
        return $position === null ? self::PREFIX : 'route.step.' . $position . '.approvals';
    }

    /**
     * The approvals that stand on a route, oldest first.
     *
     * @return list<array{profile_id: ?int, name: string, date: string, position: int, step: ?string}>
     */
    public function forRoute(int $tenantId, int $routeId): array
    {
        $stmt = $this->db->prepare(
            'SELECT e.id, e.actor_profile_id, e.verdict, e.occurred_at, s.position, s.label, p.display_name
               FROM document_route_events e
               JOIN document_route_steps s ON s.id = e.step_id AND s.tenant_id = e.tenant_id
               LEFT JOIN profiles p ON p.id = e.actor_profile_id
              WHERE e.tenant_id = :tenant_id AND e.route_id = :route_id AND e.verdict IS NOT NULL
              ORDER BY e.id ASC'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':route_id' => $routeId]);

        $latest = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = (int) $row['position'] . ':' . ($row['actor_profile_id'] ?? 'gone-' . $row['id']);
            // Removed first, so a later verdict moves the person to where it was given.
            unset($latest[$key]);
            $latest[$key] = $row;
        }

        $out = [];
        foreach ($latest as $row) {
            if ($row['verdict'] !== RouteVerdict::APPROVED) {
                continue;
            }
            $out[] = [
                'profile_id' => $row['actor_profile_id'] !== null ? (int) $row['actor_profile_id'] : null,
                'name' => (string) ($row['display_name'] ?? ''),
                'date' => substr((string) $row['occurred_at'], 0, 10),
                'position' => (int) $row['position'],
                'step' => $row['label'] !== null ? (string) $row['label'] : null,
            ];
        }

        return $out;
    }

    /**
     * The approvals as merge values, for every approval and for each step's.
     *
     * @param string $approvedMark What `mark` prints, in the template's language.
     * @return array<string, string|null>
     */
    public function values(int $tenantId, int $routeId, string $approvedMark): array
    {
        $all = $this->forRoute($tenantId, $routeId);
        $byStep = [];
        foreach ($all as $approval) {
            $byStep[$approval['position']][] = $approval;
        }

        $values = self::list(self::prefix(), $all, $approvedMark);
        foreach ($byStep as $position => $approvals) {
            $values += self::list(self::prefix($position), $approvals, $approvedMark);
        }

        return $values;
    }

    /**
     * @param list<array{profile_id: ?int, name: string, date: string, position: int, step: ?string}> $approvals
     * @return array<string, string|null>
     */
    private static function list(string $prefix, array $approvals, string $approvedMark): array
    {
        $values = [$prefix . '.count' => (string) count($approvals)];
        foreach ($approvals as $i => $approval) {
            $n = $prefix . '.' . ($i + 1) . '.';
            $values[$n . 'name'] = $approval['name'] !== '' ? $approval['name'] : null;
            $values[$n . 'date'] = $approval['date'];
            $values[$n . 'step'] = $approval['step'];
            $values[$n . 'mark'] = $approvedMark . ' ' . $approval['date'];
        }

        return $values;
    }
}
