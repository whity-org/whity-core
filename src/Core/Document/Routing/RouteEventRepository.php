<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

use PDO;

/**
 * Data-access for `document_route_events` (#947 item 3) — THE TRAIL. The
 * system of record for what happened to a routed document.
 *
 * APPEND-ONLY, BY OMISSION
 * ------------------------
 * There is `append()` and there are reads. There is no `update()`, no
 * `delete()`, no `markCorrected()`, no soft-delete flag — and that absence is
 * the enforcement, not a note about intent. #947 puts it plainly: "the moment
 * somebody most wants to tidy history is exactly when it must be immutable."
 * A store that offers an UPDATE is a store where somebody eventually calls it,
 * at which point the guarantee is a comment in a docblock.
 *
 * The same construction {@see \Whity\Core\Document\DocumentArtifactRepository}
 * uses one table over, deliberately: the two halves of a document's history —
 * what was issued, and what happened to it — should not be immutable to
 * different degrees, because a reader reconciling them cannot tell which one to
 * believe.
 *
 * A CORRECTION IS A NEW EVENT
 * ---------------------------
 * {@see RouteAction::NOTED} is how the record is put right: the mistaken note,
 * the wrong unit, the misspelled name stay where they are and the correction is
 * appended beside them. That is more useful than an edit as well as safer —
 * "this was corrected on the 14th" is a fact somebody may need, and an edit
 * destroys it.
 *
 * Rows are removed only by cascade from `documents` and `tenants` (migration
 * 112). There is no path from this class.
 *
 * WHY ITS OWN TABLE AND NOT `domain_events`
 * -----------------------------------------
 * Migration 112's docblock carries the full argument. In short: a routing event
 * has a FIXED shape core knows (actor, action, from unit, to unit, note), three
 * of whose facts name other tables, and a generic JSONB payload can constrain
 * none of it — while `aggregate_id VARCHAR(191)` can carry no foreign key at
 * all. The spine still sees every event: {@see DocumentRouter} dispatches each
 * appended row through `HookManager::dispatchAsync()`, so the outbox relay
 * carries routing exactly as it carries everything else. One trail, one
 * broadcast, derived from it in one direction.
 *
 * ORDERED BY `id`, NOT BY `occurred_at`
 * -------------------------------------
 * Several events are appended inside one transaction — a forward closes one row
 * and opens several — and they share `occurred_at` to whatever resolution the
 * engine's clock has. `id` is monotonic, so it is the only ordering that is
 * stable across engines and across a restore.
 *
 * TENANT-OWNED. Every statement binds a literal `tenant_id` predicate, spelled
 * out in SQL so scripts/ci-tenant-predicate-guard.php can verify it by reading
 * this file.
 */
final class RouteEventRepository
{
    /** The first key of the advisory lock that serialises one document's chain. */
    private const CHAIN_LOCK_SPACE = 922001;

    /** The same, for the chain of a route with no document (WHIT-920). */
    private const ROUTE_CHAIN_LOCK_SPACE = 922002;

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Append one event and return its id. The only write this class has.
     *
     * The action is CHECK-constrained by the schema (migrations 112 and 226), so a verb
     * outside {@see RouteAction::all()} is refused by the database rather than
     * stored as a row nothing renders.
     *
     * `verdict` (#1014, migration 119) is NULL for every act that said nothing
     * about approval — which is every act on a circulation step, every `noted`,
     * and every row written before that migration. It is never a way of saying
     * "not approved": absence and refusal are different facts, and a reader that
     * conflated them would invent a rejection for every document ever
     * circulated. {@see RouteVerdict}.
     *
     * @param array{route_id: int, step_id?: ?int, actor_profile_id?: ?int, action: string,
     *              from_ou_id?: ?int, to_ou_id?: ?int, note?: ?string, verdict?: ?string,
     *              subject_version?: ?int, content_hash?: ?string, to_author?: bool,
     *              resume_from?: ?string} $event
     */
    public function append(int $tenantId, ?int $documentId, array $event): int
    {
        return $this->insertLinked($tenantId, $documentId, [
            'route_id' => $event['route_id'],
            'step_id' => $event['step_id'] ?? null,
            'actor_profile_id' => $event['actor_profile_id'] ?? null,
            'action' => $event['action'],
            'from_ou_id' => $event['from_ou_id'] ?? null,
            'to_ou_id' => $event['to_ou_id'] ?? null,
            'note' => $event['note'] ?? null,
            'verdict' => $event['verdict'] ?? null,
            'withdrawal' => false,
            // WHIT-919: the subject version this act was made on, and its hash,
            // so the trail shows which version each approval was given on.
            'subject_version' => $event['subject_version'] ?? null,
            'content_hash' => $event['content_hash'] ?? null,
            'to_author' => ($event['to_author'] ?? false) === true,
            'resume_from' => $event['resume_from'] ?? null,
        ]);
    }

    /**
     * The row that closes a route's open items when its document is withdrawn
     * (S5). `noted`, flagged: see migration 201 for why it is not a new action.
     */
    public function appendWithdrawal(int $tenantId, ?int $documentId, int $routeId, int $actorId): int
    {
        return $this->insertLinked($tenantId, $documentId, [
            'route_id' => $routeId,
            'step_id' => null,
            'actor_profile_id' => $actorId,
            'action' => RouteAction::NOTED,
            'from_ou_id' => null,
            'to_ou_id' => null,
            'note' => null,
            'verdict' => null,
            'withdrawal' => true,
            'subject_version' => null,
            'content_hash' => null,
            'to_author' => false,
            'resume_from' => null,
        ]);
    }

    /**
     * Insert one entry linked to the one before it on the same document
     * (WHIT-922, migration 232; {@see RouteTrailChain}).
     *
     * The time is set here, to the second, rather than by the database, so the
     * hashed body and the stored row say the same instant. On PostgreSQL the
     * document's chain is held by a transaction-scoped advisory lock, so two
     * acts on one document link one after the other; the unique index on
     * `prev_hash` refuses a fork on either engine.
     *
     * A route with no document (a form submission routed on itself, WHIT-920)
     * has no document's chain to join, so its entries link along the route.
     *
     * @param array{route_id: int, step_id: ?int, actor_profile_id: ?int, action: string,
     *              from_ou_id: ?int, to_ou_id: ?int, note: ?string, verdict: ?string, withdrawal: bool,
     *              subject_version: ?int, content_hash: ?string, to_author: bool,
     *              resume_from: ?string} $fields
     */
    private function insertLinked(int $tenantId, ?int $documentId, array $fields): int
    {
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }

        try {
            if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
                $lock = $this->db->prepare('SELECT pg_advisory_xact_lock(:space, :chain_id)');
                $lock->execute($documentId !== null
                    ? [':space' => self::CHAIN_LOCK_SPACE, ':chain_id' => $documentId]
                    : [':space' => self::ROUTE_CHAIN_LOCK_SPACE, ':chain_id' => $fields['route_id']]);
                $lock->closeCursor();
            }

            $row = $fields + [
                'tenant_id' => $tenantId,
                'document_id' => $documentId,
                'occurred_at' => gmdate('Y-m-d H:i:s'),
            ];
            $prevHash = $documentId !== null
                ? $this->chainHead($tenantId, $documentId)
                : $this->routeChainHead($tenantId, $fields['route_id']);
            $body = RouteTrailChain::body($row);
            $entryHash = RouteTrailChain::entryHash($prevHash, $body);

            $stmt = $this->db->prepare(
                'INSERT INTO document_route_events
                     (tenant_id, document_id, route_id, step_id, actor_profile_id,
                      action, from_ou_id, to_ou_id, note, verdict, withdrawal,
                      subject_version, content_hash, to_author, resume_from, occurred_at,
                      prev_hash, entry_hash, entry_body)
                 VALUES (:tenant_id, :document_id, :route_id, :step_id, :actor_profile_id,
                         :action, :from_ou_id, :to_ou_id, :note, :verdict, :withdrawal,
                         :subject_version, :content_hash, :to_author, :resume_from, :occurred_at,
                         :prev_hash, :entry_hash, :entry_body)'
            );
            $stmt->execute([
                ':tenant_id' => $tenantId,
                ':document_id' => $documentId,
                ':route_id' => $row['route_id'],
                ':step_id' => $row['step_id'],
                ':actor_profile_id' => $row['actor_profile_id'],
                ':action' => $row['action'],
                ':from_ou_id' => $row['from_ou_id'],
                ':to_ou_id' => $row['to_ou_id'],
                ':note' => $row['note'],
                ':verdict' => $row['verdict'],
                // 1/0, never a PHP bool: PDO binds `false` as an empty string,
                // which PostgreSQL refuses for a BOOLEAN.
                ':withdrawal' => $row['withdrawal'] ? 1 : 0,
                ':subject_version' => $row['subject_version'],
                ':content_hash' => $row['content_hash'],
                ':to_author' => $row['to_author'] ? 1 : 0,
                ':resume_from' => $row['resume_from'],
                ':occurred_at' => $row['occurred_at'],
                ':prev_hash' => $prevHash,
                ':entry_hash' => $entryHash,
                ':entry_body' => $body,
            ]);
            $id = (int) $this->db->lastInsertId();

            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return $id;
    }

    /**
     * What the next entry on a route with no document links to. Such a route
     * began after migration 232, so it has no earlier, unlinked entries.
     */
    private function routeChainHead(int $tenantId, int $routeId): string
    {
        $stmt = $this->db->prepare(
            'SELECT entry_hash FROM document_route_events
              WHERE tenant_id = :tenant_id AND route_id = :route_id AND document_id IS NULL
              ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':route_id' => $routeId]);
        $head = $stmt->fetchColumn();
        $stmt->closeCursor();

        return is_string($head) && $head !== '' ? $head : RouteTrailChain::GENESIS;
    }

    /**
     * What the next entry on a document links to: the newest entry's hash; the
     * anchor over the earlier entries when the newest was written before
     * migration 232; or {@see RouteTrailChain::GENESIS} for a first entry.
     */
    private function chainHead(int $tenantId, int $documentId): string
    {
        $stmt = $this->db->prepare(
            'SELECT entry_hash FROM document_route_events
              WHERE tenant_id = :tenant_id AND document_id = :document_id
              ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':document_id' => $documentId]);
        $head = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        if (!is_array($head)) {
            return RouteTrailChain::GENESIS;
        }
        if (isset($head['entry_hash']) && (string) $head['entry_hash'] !== '') {
            return (string) $head['entry_hash'];
        }

        $earlier = $this->db->prepare(
            'SELECT id, tenant_id, document_id, route_id, step_id, actor_profile_id, action,
                    from_ou_id, to_ou_id, note, verdict, withdrawal, subject_version, content_hash,
                    to_author, resume_from, occurred_at
               FROM document_route_events
              WHERE tenant_id = :tenant_id AND document_id = :document_id AND entry_hash IS NULL
              ORDER BY id ASC'
        );
        $earlier->execute([':tenant_id' => $tenantId, ':document_id' => $documentId]);
        /** @var list<array<string, mixed>> $rows */
        $rows = $earlier->fetchAll(PDO::FETCH_ASSOC);

        return RouteTrailChain::legacyAnchor($rows);
    }

    /**
     * One event, tenant-scoped.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id, int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, document_id, route_id, step_id, actor_profile_id,
                    action, from_ou_id, to_ou_id, note, verdict, withdrawal,
                    subject_version, content_hash, to_author, resume_from, occurred_at,
                    prev_hash, entry_hash
               FROM document_route_events
              WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? self::normalize($row) : null;
    }

    /**
     * A document's whole trail, oldest first — across every route on it.
     *
     * Across routes, deliberately: a document circulated twice has one history,
     * and splitting it per route would make the second circulation look like a
     * fresh start for a document that plainly has a past. The route id is on
     * every row for a reader who wants to separate them.
     *
     * @return list<array<string, mixed>>
     */
    public function listForDocument(int $documentId, int $tenantId, int $limit, int $offset): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, document_id, route_id, step_id, actor_profile_id,
                    action, from_ou_id, to_ou_id, note, verdict, withdrawal,
                    subject_version, content_hash, to_author, resume_from, occurred_at,
                    prev_hash, entry_hash
               FROM document_route_events
              WHERE tenant_id = :tenant_id AND document_id = :document_id
              ORDER BY id ASC
              LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':document_id', $documentId, PDO::PARAM_INT);
        // Bound as INT explicitly. PDO's default is PARAM_STR, which emulated
        // prepares quote — `LIMIT '25'` is a syntax error on PostgreSQL and
        // silently accepted on SQLite, so the SQLite unit run would pass and the
        // real engine would not.
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(self::normalize(...), $rows);
    }

    /**
     * One route's whole trail, oldest first (WHIT-920). The trail of a route on a
     * subject without a document is read this way rather than by document.
     *
     * @return list<array<string, mixed>>
     */
    public function listForRoute(int $routeId, int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, document_id, route_id, step_id, actor_profile_id,
                    action, from_ou_id, to_ou_id, note, verdict, withdrawal,
                    subject_version, content_hash, to_author, resume_from, occurred_at
               FROM document_route_events
              WHERE tenant_id = :tenant_id AND route_id = :route_id
              ORDER BY id ASC'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':route_id' => $routeId]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(self::normalize(...), $rows);
    }

    /**
     * How many events a document's trail holds, so the pagination envelope
     * reports a total the caller can actually reach.
     */
    public function countForDocument(int $documentId, int $tenantId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM document_route_events
              WHERE tenant_id = :tenant_id AND document_id = :document_id'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':document_id' => $documentId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * How many times each step has been REJECTED on this route (#1037).
     *
     * WHAT THIS MAKES VISIBLE. A rejection is what sends a document backwards —
     * "to the author, to fix" is the most common approval design there is, and
     * it produces a cycle the engine fully supports. Nothing counted the laps,
     * so a document on its ninth rejection looked, in every surface, exactly
     * like one on its first: one open inbox item, a long trail nobody reads to
     * the end, and no number anywhere saying it had been round nine times. The
     * failure is not a runaway — `act()` performs one traversal per human act —
     * it is a document quietly ping-ponging for six weeks with nobody able to
     * see why.
     *
     * DERIVED, NOT STORED. The trail already holds this: #1014's `verdict`
     * column records every settled answer, so the count is a GROUP BY over rows
     * that exist. A stored counter would be a second source of truth that can
     * disagree with the trail, and the trail is the auditable one.
     *
     * ONLY REJECTIONS COUNT. An approval moves the document ON; a rejection is
     * the verdict that can send it back, so a step's rejection count IS the
     * number of times it has been round from there. Steps with no rejection are
     * absent from the map rather than present as zero — the caller defaults, and
     * a route that has never been rejected produces an empty array instead of a
     * row per step.
     *
     * @return array<int, int> step id => rejections recorded at it
     */
    public function rejectionCountsByStep(int $routeId, int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT step_id, COUNT(*) AS rejections
               FROM document_route_events
              WHERE tenant_id = :tenant_id
                AND route_id = :route_id
                AND verdict = :verdict
                AND step_id IS NOT NULL
              GROUP BY step_id'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':route_id' => $routeId,
            ':verdict' => RouteVerdict::REJECTED,
        ]);

        $counts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[(int) $row['step_id']] = (int) $row['rejections'];
        }

        return $counts;
    }

    /** The newest verdict on a route, or null when nobody has decided anything on it. */
    public function latestVerdictOnRoute(int $routeId, int $tenantId): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT verdict FROM document_route_events
              WHERE tenant_id = :tenant_id AND route_id = :route_id AND verdict IS NOT NULL
              ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':route_id' => $routeId]);
        $verdict = $stmt->fetchColumn();

        return is_string($verdict) ? $verdict : null;
    }

    /**
     * Map a raw row to the typed shape the presenter serialises.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalize(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'tenant_id' => (int) $row['tenant_id'],
            // WHIT-920: null on a route whose subject has no document yet.
            'document_id' => isset($row['document_id']) ? (int) $row['document_id'] : null,
            'route_id' => (int) $row['route_id'],
            'step_id' => $row['step_id'] !== null ? (int) $row['step_id'] : null,
            'actor_profile_id' => $row['actor_profile_id'] !== null ? (int) $row['actor_profile_id'] : null,
            'action' => (string) $row['action'],
            'from_ou_id' => $row['from_ou_id'] !== null ? (int) $row['from_ou_id'] : null,
            'to_ou_id' => $row['to_ou_id'] !== null ? (int) $row['to_ou_id'] : null,
            'note' => $row['note'] !== null ? (string) $row['note'] : null,
            // NULL means "this act said nothing about approval", never "not
            // approved". See append().
            'verdict' => isset($row['verdict']) ? (string) $row['verdict'] : null,
            'withdrawal' => $row['withdrawal'] ?? false,
            'subject_version' => isset($row['subject_version']) ? (int) $row['subject_version'] : null,
            'content_hash' => isset($row['content_hash']) ? (string) $row['content_hash'] : null,
            'to_author' => $row['to_author'] ?? false,
            'resume_from' => isset($row['resume_from']) ? (string) $row['resume_from'] : null,
            'occurred_at' => (string) $row['occurred_at'],
            // WHIT-922: the hash of the entry before this one, and this entry's
            // own. NULL on entries written before migration 232.
            'prev_hash' => isset($row['prev_hash']) ? (string) $row['prev_hash'] : null,
            'entry_hash' => isset($row['entry_hash']) ? (string) $row['entry_hash'] : null,
        ];
    }
}
