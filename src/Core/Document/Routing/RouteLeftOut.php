<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

use PDO;

/**
 * The people a route's steps left out because the subject names them
 * (WHIT-1018's `exclude_named_in`), as recorded on each step when it was
 * resolved (migration 271). The printout lists them as "Not counted" (FP.6).
 *
 * Stateless apart from the injected handle — worker-safe.
 */
final class RouteLeftOut
{
    private const MAX = 50;

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Record who a step left out. Called when the step is resolved; a step
     * resolved again (a send-back that resumes there) records afresh.
     *
     * @param list<array{profile_id: int, key: string}> $people
     */
    public function record(int $tenantId, int $stepId, array $people): void
    {
        $people = array_slice($people, 0, self::MAX);
        $this->db->prepare('UPDATE document_route_steps SET left_out = :left_out WHERE id = :id AND tenant_id = :tenant_id')
            ->execute([
                ':left_out' => $people === [] ? null : json_encode($people, JSON_THROW_ON_ERROR),
                ':id' => $stepId,
                ':tenant_id' => $tenantId,
            ]);
    }

    /**
     * Everyone the route's steps left out, once each, in step order.
     *
     * @return list<array{profile_id: int, name: string, key: string}>
     */
    public function forRoute(int $tenantId, int $routeId): array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT left_out FROM document_route_steps
                  WHERE tenant_id = :tenant_id AND route_id = :route_id AND left_out IS NOT NULL
                  ORDER BY position ASC'
            );
            $stmt->execute([':tenant_id' => $tenantId, ':route_id' => $routeId]);
            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (\PDOException $e) {
            // Before migration 271 there is nothing recorded to print.
            error_log('[RouteLeftOut] reading the people left out failed: ' . $e->getMessage());

            return [];
        }

        $seen = [];
        foreach ($rows as $json) {
            $list = json_decode((string) $json, true);
            foreach (is_array($list) ? $list : [] as $entry) {
                if (is_array($entry) && is_int($entry['profile_id'] ?? null) && is_string($entry['key'] ?? null) && !isset($seen[$entry['profile_id']])) {
                    $seen[$entry['profile_id']] = $entry['key'];
                }
            }
        }
        if ($seen === []) {
            return [];
        }

        $ids = array_keys($seen);
        $marks = implode(', ', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("SELECT id, display_name FROM profiles WHERE id IN ({$marks})");
        $stmt->execute($ids);
        $names = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $names[(int) $row['id']] = (string) ($row['display_name'] ?? '');
        }

        $out = [];
        foreach ($seen as $id => $key) {
            $out[] = ['profile_id' => $id, 'name' => ($names[$id] ?? '') !== '' ? $names[$id] : '—', 'key' => $key];
        }

        return $out;
    }
}
