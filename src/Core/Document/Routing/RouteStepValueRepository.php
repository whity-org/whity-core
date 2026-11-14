<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

use PDO;

/**
 * The values people entered at a route's steps (WHIT-1016, migration 267).
 *
 * Insert-only, like the trail: a value entered again (after a send-back, or by
 * a second approver on the same step) is a new row, and the route's value for
 * a key is its latest row. Every query binds tenant_id.
 */
final class RouteStepValueRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Keep the values one act carried. Called inside the act's transaction,
     * after its trail event is appended.
     *
     * @param array<string, string> $values
     */
    public function record(int $tenantId, int $routeId, int $stepId, int $eventId, int $profileId, array $values): void
    {
        if ($values === []) {
            return;
        }
        $stmt = $this->db->prepare(
            'INSERT INTO route_step_values
                 (tenant_id, route_id, step_id, event_id, value_key, value, entered_by, created_at)
             VALUES (:tenant_id, :route_id, :step_id, :event_id, :value_key, :value, :entered_by, NOW())'
        );
        foreach ($values as $key => $value) {
            $stmt->execute([
                ':tenant_id' => $tenantId,
                ':route_id' => $routeId,
                ':step_id' => $stepId,
                ':event_id' => $eventId,
                ':value_key' => $key,
                ':value' => $value,
                ':entered_by' => $profileId,
            ]);
        }
    }

    /**
     * The route's current value for each key: the latest one entered.
     *
     * @return array<string, array{value: string, step_id: int, event_id: int, entered_by: ?int, entered_at: string}>
     */
    public function currentForRoute(int $tenantId, int $routeId): array
    {
        $stmt = $this->db->prepare(
            'SELECT value_key, value, step_id, event_id, entered_by, created_at
               FROM route_step_values
              WHERE tenant_id = :tenant_id AND route_id = :route_id
              ORDER BY id ASC'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':route_id' => $routeId]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            // Later rows overwrite earlier ones: the latest entry is the value.
            $out[(string) $row['value_key']] = [
                'value' => (string) $row['value'],
                'step_id' => (int) $row['step_id'],
                'event_id' => (int) $row['event_id'],
                'entered_by' => $row['entered_by'] !== null ? (int) $row['entered_by'] : null,
                'entered_at' => (string) $row['created_at'],
            ];
        }

        return $out;
    }

    /**
     * Just the text of each current value, for printing and for references.
     *
     * @return array<string, string>
     */
    public function valuesForRoute(int $tenantId, int $routeId): array
    {
        return array_map(
            static fn (array $row): string => $row['value'],
            $this->currentForRoute($tenantId, $routeId),
        );
    }
}
