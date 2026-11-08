<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

/**
 * What changed in a subject since a reviewer last saw it (WHIT-920, #1724).
 *
 * "Last saw" is the version named on the reviewer's newest act on the route:
 * every act records the version it was made on (WHIT-919). A reviewer who has
 * not acted on the route yet has seen no earlier version, so there is nothing
 * to compare and the answer says so.
 *
 * The comparison is over the snapshot's `values` when it has them (a form
 * submission's answers), and over its top-level keys otherwise. Each changed key
 * comes back with what it was and what it is now.
 *
 * Stateless apart from the injected repositories — worker-safe.
 */
final class RouteSubjectChanges
{
    public function __construct(
        private readonly RouteSubjectVersionRepository $versions,
        private readonly RouteEventRepository $events,
    ) {
    }

    /**
     * @param array<string, mixed> $route A normalized route row.
     *
     * @return array{seen_version: ?int, current_version: ?int,
     *               changes: list<array{key: string, before: mixed, after: mixed}>}
     */
    public function since(int $tenantId, array $route, int $profileId): array
    {
        $subjectType = (string) ($route['subject_type'] ?? DocumentRouteSubject::TYPE);
        $subjectId = (int) ($route['subject_id'] ?? $route['document_id'] ?? 0);

        $all = $this->versions->listForSubject($tenantId, $subjectType, $subjectId);
        $current = $all === [] ? null : $all[count($all) - 1];

        $seen = null;
        foreach ($this->events->listForRoute((int) $route['id'], $tenantId) as $event) {
            if ($event['actor_profile_id'] === $profileId
                && $event['subject_version'] !== null
                && $event['action'] !== RouteAction::ISSUED
                && $event['action'] !== RouteAction::RESUBMITTED) {
                $seen = (int) $event['subject_version'];
            }
        }

        $result = [
            'seen_version' => $seen,
            'current_version' => $current === null ? null : $current['version'],
            'changes' => [],
        ];
        if ($seen === null || $current === null || $seen >= $current['version']) {
            return $result;
        }

        $before = null;
        foreach ($all as $version) {
            if ($version['version'] === $seen) {
                $before = $version['data'];
            }
        }

        $result['changes'] = self::diff(self::comparable($before), self::comparable($current['data']));

        return $result;
    }

    /**
     * Every key whose value differs between two maps, in the order the keys
     * first appear.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return list<array{key: string, before: mixed, after: mixed}>
     */
    public static function diff(array $before, array $after): array
    {
        $changes = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
            $was = $before[$key] ?? null;
            $now = $after[$key] ?? null;
            if (self::canonical($was) !== self::canonical($now)) {
                $changes[] = ['key' => (string) $key, 'before' => $was, 'after' => $now];
            }
        }

        return $changes;
    }

    /** @return array<string, mixed> */
    private static function comparable(mixed $snapshot): array
    {
        if (!is_array($snapshot)) {
            return [];
        }
        $values = array_key_exists('values', $snapshot) ? $snapshot['values'] : $snapshot;
        if (!is_array($values)) {
            return [];
        }

        $out = [];
        foreach ($values as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    private static function canonical(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
