<?php

declare(strict_types=1);

namespace Whity\Core\Document\Collab;

use PDO;
use Whity\Core\Document\Comment\DocumentCommentRepository;
use Whity\Core\Document\DocumentSourceRepository;
use Whity\Core\Document\Suggestion\DocumentSuggestionRepository;
use Whity\Core\Document\Suggestion\SuggestionPatch;
use Whity\Core\Text\SearchText;

/**
 * Comments and suggestions on an editor document (editor M5): how they are
 * shown, and how a decision reaches the draft.
 *
 * A DECISION applies accepted patches to the draft in one transaction with
 * one `draft_version` bump, in suggestion id order. A patch that no longer
 * applies (its block or anchor text has gone) marks its suggestion stale and
 * is skipped. The draft is saved optimistically against the version the
 * patches were applied to, so a save landing in between makes the decision
 * run again on the newer draft instead of overwriting it.
 *
 * An ACTOR is shown as the contract's Actor, with ids as numbers for people:
 *   {kind: 'person', id, name}
 *   {kind: 'agent', id: <agent principal id>, name, for: {id, name}}
 *   {kind: 'system', name: 'Whity'}
 */
final class DocumentCollab
{
    /** How far behind "now" a /changes cursor starts, so a slow commit is never missed. */
    private const CURSOR_OVERLAP_SECONDS = 5;

    private const ATTEMPTS = 3;

    public function __construct(
        private readonly PDO $db,
        private readonly DocumentSourceRepository $sources,
        private readonly DocumentSuggestionRepository $suggestions,
        private readonly DocumentCommentRepository $comments,
    ) {
    }

    public function suggestionRepository(): DocumentSuggestionRepository
    {
        return $this->suggestions;
    }

    public function commentRepository(): DocumentCommentRepository
    {
        return $this->comments;
    }

    /**
     * What GET /api/document-sources/{id} adds: open suggestions and every thread.
     *
     * @return array{suggestions: list<array<string, mixed>>, comments: list<array<string, mixed>>}
     */
    public function projection(int $tenantId, int $sourceId): array
    {
        return [
            'suggestions' => $this->presentSuggestions($tenantId, $this->suggestions->listForSource($tenantId, $sourceId, DocumentSuggestionRepository::OPEN)),
            'comments'    => $this->presentThreads($tenantId, $this->comments->threadsForSource($tenantId, $sourceId)),
        ];
    }

    public function openCount(int $tenantId, int $sourceId): int
    {
        return $this->suggestions->countOpen($tenantId, $sourceId);
    }

    // ── decisions ────────────────────────────────────────────────────────

    /**
     * Accepts or rejects the open suggestions of a document that match: all,
     * those ids, and/or those one agent principal made.
     *
     * @param list<int>|null $ids
     * @return array{decided: list<int>, stale: list<int>, draft_version: int}|null
     *         Null when the document is gone, or kept changing under the decision.
     */
    public function decide(int $tenantId, int $sourceId, string $decision, int $deciderId, ?array $ids = null, ?string $agentPrincipalId = null): ?array
    {
        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $row = $this->sources->findById($sourceId, $tenantId);
            if ($row === null) {
                return null;
            }
            $version = (int) $row['draft_version'];
            $candidates = $this->suggestions->openFor($tenantId, $sourceId, $ids, $agentPrincipalId);

            if ($decision === DocumentSuggestionRepository::REJECTED) {
                $decided = [];
                foreach ($candidates as $s) {
                    if ($this->suggestions->decide((int) $s['id'], $tenantId, DocumentSuggestionRepository::REJECTED, $deciderId)) {
                        $decided[] = (int) $s['id'];
                    }
                }

                return ['decided' => $decided, 'stale' => [], 'draft_version' => $version];
            }

            /** @var list<array<string, mixed>> $blocks */
            $blocks = is_array($row['blocks'] ?? null) ? array_values($row['blocks']) : [];
            $accepted = [];
            $stale = [];
            foreach ($candidates as $s) {
                $next = SuggestionPatch::apply($blocks, $s['patch']);
                if ($next === null) {
                    $stale[] = (int) $s['id'];
                    continue;
                }
                $blocks = $next;
                $accepted[] = (int) $s['id'];
            }

            $outcome = $this->commitAccepted($tenantId, $sourceId, $version, $blocks, $accepted, $stale, $deciderId);
            if ($outcome !== null) {
                return ['decided' => $accepted, 'stale' => $stale, 'draft_version' => $outcome];
            }
        }

        return null;
    }

    /**
     * The new draft version, or null when something changed in between and the
     * decision must be worked out again.
     *
     * @param list<array<string, mixed>> $blocks
     * @param list<int>                  $accepted
     * @param list<int>                  $stale
     */
    private function commitAccepted(int $tenantId, int $sourceId, int $version, array $blocks, array $accepted, array $stale, int $deciderId): ?int
    {
        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            $newVersion = $version;
            if ($accepted !== []) {
                $saved = $this->sources->saveDraft($sourceId, $tenantId, $version, ['blocks' => $blocks], $deciderId);
                if ($saved === null) {
                    $this->rollBack($own);

                    return null;
                }
                $newVersion = $saved;
                foreach ($accepted as $id) {
                    if (!$this->suggestions->decide($id, $tenantId, DocumentSuggestionRepository::ACCEPTED, $deciderId)) {
                        $this->rollBack($own);

                        return null;
                    }
                }
            }
            foreach ($stale as $id) {
                $this->suggestions->markStale($id, $tenantId);
            }
            if ($own) {
                $this->db->commit();
            }

            return $newVersion;
        } catch (\Throwable $e) {
            $this->rollBack($own);
            throw $e;
        }
    }

    private function rollBack(bool $own): void
    {
        if ($own && $this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }

    /**
     * After a draft save: every open suggestion that no longer applies to the
     * new blocks becomes stale, so it stops blocking publish (E.37). Returns
     * the ids marked.
     *
     * @param list<array<string, mixed>> $blocks
     * @return list<int>
     */
    public function markStaleAgainst(int $tenantId, int $sourceId, array $blocks): array
    {
        $marked = [];
        foreach ($this->suggestions->listForSource($tenantId, $sourceId, DocumentSuggestionRepository::OPEN) as $s) {
            if (SuggestionPatch::isStale($blocks, $s['patch']) && $this->suggestions->markStale((int) $s['id'], $tenantId)) {
                $marked[] = (int) $s['id'];
            }
        }

        return $marked;
    }

    // ── people to @mention ───────────────────────────────────────────────

    /**
     * Active members of the workspace whose display name or primary email
     * contains $query (case- and accent-insensitive), newest first, at most
     * $limit. The caller narrows these to who can read the document.
     *
     * @return list<array{id: int, name: string, email: ?string}>
     */
    public function searchMembers(int $tenantId, string $query, int $limit): array
    {
        $pattern = SearchText::containsPattern(SearchText::fold($query));
        $stmt = $this->db->prepare(
            "SELECT DISTINCT p.id, p.display_name, pe.email
               FROM memberships m
               JOIN profiles p ON p.id = m.profile_id
               LEFT JOIN profile_emails pe ON pe.profile_id = p.id AND pe.is_primary = true
              WHERE m.tenant_id = :tenant_id AND m.status = 'active'
                AND (" . SearchText::sqlFold('p.display_name') . " LIKE :pattern ESCAPE '\\'
                     OR LOWER(pe.email) LIKE :pattern ESCAPE '\\')
              ORDER BY p.id DESC LIMIT " . max(1, min(500, $limit))
        );
        $stmt->execute([':tenant_id' => $tenantId, ':pattern' => $pattern]);
        $out = [];
        $seen = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row) || isset($seen[(int) $row['id']])) {
                continue;
            }
            $seen[(int) $row['id']] = true;
            $out[] = [
                'id'    => (int) $row['id'],
                'name'  => (string) ($row['display_name'] ?? ''),
                'email' => isset($row['email']) && $row['email'] !== '' ? (string) $row['email'] : null,
            ];
        }

        return $out;
    }

    // ── the /changes cursor ──────────────────────────────────────────────

    /**
     * A cursor for "now", a few seconds early so a transaction committing
     * late is never missed (an item may come back twice; the client keeps
     * the newest by id). Opaque to the client.
     */
    public function cursorNow(): string
    {
        $raw = $this->db->query('SELECT NOW()');
        $now = $raw !== false ? $raw->fetchColumn() : false;
        try {
            $at = new \DateTimeImmutable(is_string($now) ? $now : 'now');
        } catch (\Exception) {
            $at = new \DateTimeImmutable('now');
        }
        $at = $at->modify('-' . self::CURSOR_OVERLAP_SECONDS . ' seconds');

        return rtrim(strtr(base64_encode('v1|' . $at->format('Y-m-d H:i:s')), '+/', '-_'), '=');
    }

    /** The database timestamp a cursor stands for, or null when it isn't one. */
    public static function cursorTime(string $cursor): ?string
    {
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($decoded === false || preg_match('/^v1\|(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})$/', $decoded, $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    // ── presentation ─────────────────────────────────────────────────────

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function presentSuggestions(int $tenantId, array $rows): array
    {
        $people = [];
        $agents = [];
        foreach ($rows as $r) {
            foreach (['created_by_profile_id', 'on_behalf_of_profile_id', 'decided_by_profile_id'] as $k) {
                if (is_int($r[$k] ?? null)) {
                    $people[] = $r[$k];
                }
            }
            if (is_string($r['agent_principal_id'] ?? null)) {
                $agents[] = $r['agent_principal_id'];
            }
        }
        $names = $this->personNames($people);
        $agentNames = $this->agentNames($tenantId, $agents);

        return array_map(fn (array $r): array => [
            'id'           => $r['id'],
            'kind'         => $r['kind'],
            'summary'      => $r['summary'],
            'request'      => $r['request'],
            'permission'   => $r['permission'],
            'by'           => self::actor($r, $names, $agentNames),
            'at'           => self::iso($r['created_at']),
            'status'       => $r['status'],
            'patch'        => $r['patch'],
            'base_version' => $r['base_version'],
            'decided_by'   => is_int($r['decided_by_profile_id']) ? self::person($r['decided_by_profile_id'], $names) : null,
            'decided_at'   => $r['decided_at'] !== null ? self::iso($r['decided_at']) : null,
        ], $rows);
    }

    /**
     * Threads with their messages.
     *
     * @param list<array<string, mixed>> $threads
     * @return list<array<string, mixed>>
     */
    public function presentThreads(int $tenantId, array $threads): array
    {
        $messages = $this->comments->commentsFor($tenantId, array_map(static fn (array $t): int => (int) $t['id'], $threads));
        $people = [];
        $agents = [];
        foreach ($threads as $t) {
            if (is_int($t['resolved_by_profile_id'] ?? null)) {
                $people[] = $t['resolved_by_profile_id'];
            }
        }
        foreach ($messages as $list) {
            foreach ($list as $c) {
                if (is_int($c['created_by_profile_id'])) {
                    $people[] = $c['created_by_profile_id'];
                }
                if (is_int($c['on_behalf_of_profile_id'])) {
                    $people[] = $c['on_behalf_of_profile_id'];
                }
                if (is_string($c['agent_principal_id'])) {
                    $agents[] = $c['agent_principal_id'];
                }
                array_push($people, ...$c['mentions']);
            }
        }
        $names = $this->personNames($people);
        $agentNames = $this->agentNames($tenantId, $agents);

        return array_map(fn (array $t): array => [
            'id'              => $t['id'],
            'anchor_block_id' => $t['anchor_block_id'],
            'quote'           => $t['quote'],
            'messages'        => array_map(
                fn (array $c): array => $this->message($c, $names, $agentNames),
                $messages[(int) $t['id']] ?? []
            ),
            'resolved'        => $t['resolved_at'] !== null
                ? ['by' => is_int($t['resolved_by_profile_id']) ? self::person($t['resolved_by_profile_id'], $names) : self::system(), 'at' => self::iso((string) $t['resolved_at'])]
                : null,
        ], $threads);
    }

    /**
     * One message, as the thread lists it.
     *
     * @param array<string, mixed> $comment
     * @return array<string, mixed>
     */
    public function presentMessage(int $tenantId, array $comment): array
    {
        $people = array_merge(
            array_filter([$comment['created_by_profile_id'] ?? null, $comment['on_behalf_of_profile_id'] ?? null], 'is_int'),
            $comment['mentions'] ?? []
        );
        $agents = is_string($comment['agent_principal_id'] ?? null) ? [$comment['agent_principal_id']] : [];

        return $this->message($comment, $this->personNames(array_values($people)), $this->agentNames($tenantId, $agents));
    }

    /**
     * Which of these profiles are members of the workspace, with their names.
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    public function memberNames(int $tenantId, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        [$in, $params] = self::inList($ids);
        $stmt = $this->db->prepare(
            'SELECT p.id, p.display_name FROM profiles p
               JOIN memberships m ON m.profile_id = p.id AND m.tenant_id = :tenant_id
              WHERE p.id IN (' . $in . ')'
        );
        $stmt->execute($params + [':tenant_id' => $tenantId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row)) {
                $out[(int) $row['id']] = (string) ($row['display_name'] ?? '');
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed>  $c
     * @param array<int, string>    $names
     * @param array<string, string> $agentNames
     * @return array<string, mixed>
     */
    private function message(array $c, array $names, array $agentNames): array
    {
        return [
            'id'       => $c['id'],
            'by'       => self::actor($c, $names, $agentNames),
            'at'       => self::iso((string) $c['created_at']),
            'body'     => $c['body'],
            'mentions' => array_map(static fn (int $id): array => self::person($id, $names), $c['mentions']),
        ];
    }

    /**
     * Display names of profiles, by id. Actors recorded here acted in this
     * workspace, so their names are shown even after they leave it.
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    private function personNames(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }
        [$in, $params] = self::inList($ids);
        $stmt = $this->db->prepare('SELECT id, display_name FROM profiles WHERE id IN (' . $in . ')');
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row)) {
                $out[(int) $row['id']] = (string) ($row['display_name'] ?? '');
            }
        }

        return $out;
    }

    /**
     * Agent principals' names (their credential's name), by principal id.
     *
     * @param list<string> $ids
     * @return array<string, string>
     */
    private function agentNames(int $tenantId, array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }
        [$in, $params] = self::inList($ids);
        $stmt = $this->db->prepare(
            'SELECT jti, name FROM mcp_tokens WHERE tenant_id = :tenant_id AND jti IN (' . $in . ')'
        );
        $stmt->execute($params + [':tenant_id' => $tenantId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row)) {
                $out[(string) $row['jti']] = (string) $row['name'];
            }
        }

        return $out;
    }

    /**
     * @param list<int|string> $values
     * @return array{0: string, 1: array<string, int|string>}
     */
    private static function inList(array $values): array
    {
        $names = [];
        $params = [];
        foreach ($values as $i => $v) {
            $names[] = ':v' . $i;
            $params[':v' . $i] = $v;
        }

        return [implode(', ', $names), $params];
    }

    /**
     * @param array<string, mixed>  $row     Actor columns.
     * @param array<int, string>    $names
     * @param array<string, string> $agentNames
     * @return array<string, mixed>
     */
    private static function actor(array $row, array $names, array $agentNames): array
    {
        if (($row['actor_kind'] ?? null) === 'agent' && is_string($row['agent_principal_id'] ?? null)) {
            $for = is_int($row['on_behalf_of_profile_id'] ?? null) ? $row['on_behalf_of_profile_id'] : (int) ($row['created_by_profile_id'] ?? 0);

            return [
                'kind' => 'agent',
                'id'   => $row['agent_principal_id'],
                'name' => $agentNames[$row['agent_principal_id']] ?? 'Agent',
                'for'  => ['id' => $for, 'name' => $names[$for] ?? ''],
            ];
        }
        if (($row['actor_kind'] ?? null) === 'system' || !is_int($row['created_by_profile_id'] ?? null)) {
            return self::system();
        }

        return self::person($row['created_by_profile_id'], $names);
    }

    /**
     * @param array<int, string> $names
     * @return array{kind: string, id: int, name: string}
     */
    private static function person(int $id, array $names): array
    {
        return ['kind' => 'person', 'id' => $id, 'name' => $names[$id] ?? ''];
    }

    /** @return array{kind: string, name: string} */
    private static function system(): array
    {
        return ['kind' => 'system', 'name' => 'Whity'];
    }

    /** A database timestamp as ISO 8601 UTC (`2026-10-29T14:02:00Z`). */
    private static function iso(string $value): string
    {
        try {
            return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z');
        } catch (\Exception) {
            return $value;
        }
    }
}
