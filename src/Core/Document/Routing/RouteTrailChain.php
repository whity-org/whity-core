<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

use PDO;
use Whity\Core\Db\DbBool;

/**
 * The hash chain over a document's trail (WHIT-922, #1726, migration 228).
 *
 * Every trail entry written since migration 228 stores:
 *  - `entry_body`: the entry's own facts as canonical JSON (keys sorted);
 *  - `prev_hash`: the `entry_hash` of the entry before it on the same document;
 *  - `entry_hash`: SHA-256 of `prev_hash`, a newline and `entry_body`.
 *
 * The first entry of a document links to {@see GENESIS}. The first new entry of
 * a document that already had entries before migration 228 links to an ANCHOR
 * computed over those earlier entries ({@see legacyAnchor()}), so the chain
 * commits to the history before it without rewriting a single old row.
 *
 * WHAT A LATER CASCADE MAY CHANGE
 * -------------------------------
 * Four columns of a trail row are foreign keys that the database sets to NULL
 * when the person, unit or step they name is removed: `actor_profile_id`,
 * `from_ou_id`, `to_ou_id` and `step_id`. The body keeps the value as it was,
 * so {@see verify()} accepts a NULL in one of those columns where the body has
 * a value, and counts it as `detached` rather than as a break. Any other
 * difference is a break.
 *
 * Stateless — worker-safe.
 */
final class RouteTrailChain
{
    /** The `prev_hash` of the first entry on a document with no earlier entries. */
    public const GENESIS = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    /** The facts an entry's body records, in no particular order (the encoding sorts them). */
    public const FIELDS = [
        'tenant_id', 'document_id', 'route_id', 'step_id', 'actor_profile_id', 'action',
        'from_ou_id', 'to_ou_id', 'note', 'verdict', 'withdrawal', 'subject_version',
        'content_hash', 'to_author', 'resume_from', 'occurred_at',
    ];

    /** The columns a removal elsewhere may set to NULL after the entry was written. */
    public const DETACHABLE = ['step_id', 'actor_profile_id', 'from_ou_id', 'to_ou_id'];

    /** The integer columns, so the body records them as numbers on both engines. */
    private const INTS = [
        'tenant_id', 'document_id', 'route_id', 'step_id', 'actor_profile_id',
        'from_ou_id', 'to_ou_id', 'subject_version',
    ];

    private const BOOLS = ['withdrawal', 'to_author'];

    /**
     * The canonical facts of a row, as the body records them.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function facts(array $row): array
    {
        $facts = [];
        foreach (self::FIELDS as $field) {
            $value = $row[$field] ?? null;
            if (in_array($field, self::INTS, true)) {
                $facts[$field] = $value === null ? null : (int) $value;
            } elseif (in_array($field, self::BOOLS, true)) {
                $facts[$field] = DbBool::of($value ?? false);
            } elseif ($field === 'occurred_at') {
                $facts[$field] = self::second((string) $value);
            } else {
                $facts[$field] = $value === null ? null : (string) $value;
            }
        }
        ksort($facts);

        return $facts;
    }

    /** @param array<string, mixed> $row */
    public static function body(array $row): string
    {
        return (string) json_encode(self::facts($row), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function entryHash(string $prevHash, string $body): string
    {
        return hash('sha256', $prevHash . "\n" . $body);
    }

    /**
     * The anchor the first chained entry links to when a document already had
     * entries written before migration 228: a running SHA-256 over each earlier
     * entry's id and its facts, leaving out the four detachable columns so a
     * later removal does not move it.
     *
     * @param list<array<string, mixed>> $rows Earlier entries, oldest first.
     */
    public static function legacyAnchor(array $rows): string
    {
        $anchor = self::GENESIS;
        foreach ($rows as $row) {
            $facts = self::facts($row);
            foreach (self::DETACHABLE as $column) {
                unset($facts[$column]);
            }
            $facts['id'] = (int) $row['id'];
            ksort($facts);
            $anchor = hash(
                'sha256',
                $anchor . "\n" . (string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
        }

        return $anchor;
    }

    /** A timestamp to the second, in the one spelling both engines read back. */
    public static function second(string $value): string
    {
        return substr(str_replace('T', ' ', $value), 0, 19);
    }

    /**
     * Check a document's whole trail. Reads the database, so each call can
     * answer differently.
     *
     * @phpstan-impure
     *
     * @return array{intact: bool, entries: int, linked: int, earlier: int, detached: int,
     *               broken_at_event_id: ?int, last_hash: ?string}
     */
    public static function verify(PDO $db, int $tenantId, int $documentId): array
    {
        $stmt = $db->prepare(
            'SELECT id, tenant_id, document_id, route_id, step_id, actor_profile_id, action,
                    from_ou_id, to_ou_id, note, verdict, withdrawal, subject_version, content_hash,
                    to_author, resume_from, occurred_at, prev_hash, entry_hash, entry_body
               FROM document_route_events
              WHERE tenant_id = :tenant_id AND document_id = :document_id
              ORDER BY id ASC'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':document_id' => $documentId]);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $earlier = [];
        $previous = null;
        $linked = 0;
        $detached = 0;
        $brokenAt = null;

        foreach ($rows as $row) {
            $entryHash = isset($row['entry_hash']) ? (string) $row['entry_hash'] : null;

            if ($entryHash === null) {
                if ($previous !== null) {
                    // An unlinked entry after the chain began was not written by
                    // the engine.
                    $brokenAt = (int) $row['id'];
                    break;
                }
                $earlier[] = $row;
                continue;
            }

            $expectedPrev = $previous ?? ($earlier === [] ? self::GENESIS : self::legacyAnchor($earlier));
            $body = (string) ($row['entry_body'] ?? '');
            $stored = json_decode($body, true);

            if ((string) ($row['prev_hash'] ?? '') !== $expectedPrev
                || !hash_equals(self::entryHash($expectedPrev, $body), $entryHash)
                || !is_array($stored)
            ) {
                $brokenAt = (int) $row['id'];
                break;
            }

            $live = self::facts($row);
            $matches = true;
            foreach ($live as $field => $value) {
                $was = $stored[$field] ?? null;
                if ($value === $was) {
                    continue;
                }
                if ($value === null && in_array($field, self::DETACHABLE, true)) {
                    $detached++;
                    continue;
                }
                $matches = false;
                break;
            }
            if (!$matches) {
                $brokenAt = (int) $row['id'];
                break;
            }

            $previous = $entryHash;
            $linked++;
        }

        return [
            'intact' => $brokenAt === null,
            'entries' => count($rows),
            'linked' => $linked,
            'earlier' => count($earlier),
            'detached' => $detached,
            'broken_at_event_id' => $brokenAt,
            'last_hash' => $previous,
        ];
    }
}
