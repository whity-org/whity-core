<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\Audit\Actor;
use Whity\Core\Audit\AuditContext;
use Whity\Core\Audit\AuditLoggerInterface;
use Whity\Core\Document\Collab\DocumentCollab;
use Whity\Core\Document\Suggestion\DocumentSuggestionRepository;
use Whity\Core\Document\Suggestion\SuggestionPatch;
use Whity\Core\Notification\NotificationDispatcher;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\ScopedPermissionSet;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * Comments and suggestions on an editor document (editor M5; E.4, E.6, E.10,
 * E.29, E.37; IMPLEMENTATION-NOTES 3.2, 3.3):
 *
 *   GET  /api/document-sources/{id}/suggestions                    documents:read
 *   POST /api/document-sources/{id}/suggestions                    documents:suggest
 *   POST /api/document-sources/{id}/suggestions/{sid}/decision     documents:write (people only)
 *   POST /api/document-sources/{id}/suggestions/decisions          documents:write (people only)
 *   GET  /api/document-sources/{id}/comments                       documents:read
 *   POST /api/document-sources/{id}/comments                       documents:comment
 *   POST /api/document-sources/{id}/comments/{tid}/messages        documents:comment
 *   POST /api/document-sources/{id}/comments/{tid}/resolve|reopen  documents:comment
 *   GET  /api/document-sources/{id}/changes?since=<cursor>         documents:read
 *   GET  /api/document-sources/{id}/mentionable?q=                 documents:comment
 *
 * Every route first finds the document the way the editor's own routes do
 * ({@see DocumentSourcesApiHandler::visibleRow()}), so a document the caller
 * can't see answers 404 here too. Each also checks its permission itself, as
 * well as the route gate.
 *
 * AGENTS never decide (E.4, E.29): the decision routes are refused for an
 * agent principal here and declared `agentPolicy: refuse` on the MCP surface.
 * A suggestion or comment an agent makes records the agent principal and the
 * person it acts for (the actor {@see AuditContext} holds for the MCP call).
 */
final class DocumentCollabApiHandler
{
    private const MAX_SUMMARY = 500;
    private const MAX_REQUEST = 4000;
    private const MAX_QUOTE = 2000;
    private const MAX_BODY_TEXT = 10000;
    private const MAX_MENTIONS = 50;
    private const MAX_MENTIONABLE = 20;
    /** Members read before the visibility filter, so 20 visible ones are usually found. */
    private const MENTIONABLE_CANDIDATES = 200;

    public const MENTION_NOTIFICATION = 'document.comment.mention';

    public function __construct(
        private readonly DocumentSourcesApiHandler $sources,
        private readonly DocumentCollab $collab,
        private readonly RoleChecker $roleChecker,
        private readonly ?AuditLoggerInterface $audit = null,
        private readonly ?NotificationDispatcher $notifications = null,
    ) {
    }

    // ── suggestions ──────────────────────────────────────────────────────

    /** @param array<string, string> $params */
    public function listSuggestions(Request $request, array $params): Response
    {
        $found = $this->document($request, $params, CorePermissions::DOCUMENTS_READ);
        if ($found instanceof Response) {
            return $found;
        }
        [$tenantId, , $row] = $found;

        $status = self::queryParam($request, 'status') ?? 'open';
        if (!in_array($status, ['open', 'all'], true)) {
            return Response::error('status must be open or all', 422);
        }
        $rows = $this->collab->suggestionRepository()->listForSource($tenantId, (int) $row['id'], $status === 'all' ? null : DocumentSuggestionRepository::OPEN);

        return Response::json(['data' => $this->collab->presentSuggestions($tenantId, $rows)]);
    }

    /** @param array<string, string> $params */
    public function createSuggestion(Request $request, array $params): Response
    {
        $found = $this->document($request, $params, CorePermissions::DOCUMENTS_SUGGEST);
        if ($found instanceof Response) {
            return $found;
        }
        [$tenantId, $callerId, $row] = $found;

        $body = JsonBody::parsed($request);
        $patch = $body['patch'] ?? null;
        $problem = SuggestionPatch::problem($patch);
        if ($problem !== null) {
            return Response::error($problem, 422);
        }
        /** @var array<string, mixed> $patch */
        $kind = $body['kind'] ?? null;
        $expected = SuggestionPatch::KIND_OF[$patch['op']];
        if ($kind !== $expected) {
            return Response::error("kind must be {$expected} for a {$patch['op']} patch", 422);
        }
        $summary = is_string($body['summary'] ?? null) ? trim($body['summary']) : '';
        if ($summary === '' || mb_strlen($summary) > self::MAX_SUMMARY) {
            return Response::error('summary must be a non-empty string of at most ' . self::MAX_SUMMARY . ' characters', 422);
        }
        $req = $body['request'] ?? null;
        if ($req !== null && (!is_string($req) || mb_strlen($req) > self::MAX_REQUEST)) {
            return Response::error('request must be a string of at most ' . self::MAX_REQUEST . ' characters, or null', 422);
        }
        /** @var list<array<string, mixed>> $blocks */
        $blocks = is_array($row['blocks'] ?? null) ? array_values($row['blocks']) : [];
        if (SuggestionPatch::isStale($blocks, $patch)) {
            return Response::error('The suggestion does not apply to the current draft', 422, ['code' => 'patch_does_not_apply']);
        }

        $actor = self::actorColumns($callerId);
        $id = $this->collab->suggestionRepository()->create($tenantId, (int) $row['id'], [
            'kind'                    => $kind,
            'summary'                 => $summary,
            'request'                 => is_string($req) && trim($req) !== '' ? trim($req) : null,
            'permission'              => CorePermissions::DOCUMENTS_SUGGEST,
            'created_by_profile_id'   => $callerId,
            'actor_kind'              => $actor['actor_kind'],
            'agent_principal_id'      => $actor['agent_principal_id'],
            'on_behalf_of_profile_id' => $actor['on_behalf_of_profile_id'],
            'base_version'            => (int) $row['draft_version'],
            'patch'                   => $patch,
        ]);
        $this->audit?->record('documents.suggestion.created', [
            'tenant_id'     => $tenantId,
            'actor_user_id' => $callerId,
            'target_type'   => 'document_suggestion',
            'target_id'     => $id,
            'metadata'      => ['document_id' => (int) $row['id'], 'kind' => $kind, 'op' => $patch['op'], 'summary' => $summary, 'permission' => CorePermissions::DOCUMENTS_SUGGEST],
        ]);

        return Response::json(['data' => $this->presentSuggestion($tenantId, (int) $row['id'], $id)], 201);
    }

    /**
     * Accept or reject one suggestion (E.29). Accepting applies its patch to
     * the draft and bumps draft_version.
     *
     * @param array<string, string> $params
     */
    public function decide(Request $request, array $params): Response
    {
        $found = $this->document($request, $params, CorePermissions::DOCUMENTS_WRITE);
        if ($found instanceof Response) {
            return $found;
        }
        [$tenantId, $callerId, $row] = $found;
        if (self::isAgent($callerId)) {
            return Response::error('Agents never accept or reject suggestions; a person decides', 403);
        }
        $decision = self::decision(JsonBody::parsed($request));
        if ($decision instanceof Response) {
            return $decision;
        }

        $sourceId = (int) $row['id'];
        $sid = (int) ($params['sid'] ?? 0);
        $suggestion = $this->collab->suggestionRepository()->find($sid, $tenantId, $sourceId);
        if ($suggestion === null) {
            return Response::error('Suggestion not found', 404);
        }
        if ($suggestion['status'] === DocumentSuggestionRepository::STALE) {
            return Response::error('The text or block this suggestion changes has gone', 409, ['code' => 'suggestion_stale']);
        }
        if ($suggestion['status'] !== DocumentSuggestionRepository::OPEN) {
            return Response::error('This suggestion was already decided', 409, ['code' => 'already_decided', 'status' => $suggestion['status']]);
        }

        $outcome = $this->collab->decide($tenantId, $sourceId, $decision, $callerId, [$sid]);
        if ($outcome === null) {
            return Response::error('The document kept changing; try again', 409, ['code' => 'draft_changed']);
        }
        if (in_array($sid, $outcome['stale'], true)) {
            return Response::error('The text or block this suggestion changes has gone', 409, ['code' => 'suggestion_stale', 'draft_version' => $outcome['draft_version']]);
        }
        if (!in_array($sid, $outcome['decided'], true)) {
            return Response::error('This suggestion was already decided', 409, ['code' => 'already_decided']);
        }
        $this->auditDecision($tenantId, $callerId, $sourceId, $suggestion, $decision, false);

        return Response::json(['data' => [
            'suggestion'    => $this->presentSuggestion($tenantId, $sourceId, $sid),
            'draft_version' => $outcome['draft_version'],
        ]])->withHeaders(['ETag' => '"' . $outcome['draft_version'] . '"']);
    }

    /**
     * The rail's All menu (E.29): accept or reject every open suggestion,
     * those ids, or all from one agent. Applied in id order; stale ones are
     * skipped and reported.
     *
     * @param array<string, string> $params
     */
    public function decideMany(Request $request, array $params): Response
    {
        $found = $this->document($request, $params, CorePermissions::DOCUMENTS_WRITE);
        if ($found instanceof Response) {
            return $found;
        }
        [$tenantId, $callerId, $row] = $found;
        if (self::isAgent($callerId)) {
            return Response::error('Agents never accept or reject suggestions; a person decides', 403);
        }
        $body = JsonBody::parsed($request);
        $decision = self::decision($body);
        if ($decision instanceof Response) {
            return $decision;
        }
        $ids = null;
        if (array_key_exists('suggestion_ids', $body) && $body['suggestion_ids'] !== null) {
            $list = $body['suggestion_ids'];
            if (!is_array($list) || !array_is_list($list) || count($list) > 1000) {
                return Response::error('suggestion_ids must be a list of suggestion ids', 422);
            }
            $ids = [];
            foreach ($list as $id) {
                if (!is_int($id) || $id <= 0) {
                    return Response::error('suggestion_ids must be a list of suggestion ids', 422);
                }
                $ids[] = $id;
            }
        }
        $agent = $body['agent'] ?? null;
        if ($agent !== null && (!is_string($agent) || $agent === '' || strlen($agent) > 64)) {
            return Response::error('agent must be an agent principal id', 422);
        }

        $sourceId = (int) $row['id'];
        $before = [];
        foreach ($this->collab->suggestionRepository()->openFor($tenantId, $sourceId, $ids, $agent) as $s) {
            $before[(int) $s['id']] = $s;
        }
        $outcome = $this->collab->decide($tenantId, $sourceId, $decision, $callerId, $ids, $agent);
        if ($outcome === null) {
            return Response::error('The document kept changing; try again', 409, ['code' => 'draft_changed']);
        }
        foreach ($outcome['decided'] as $id) {
            if (isset($before[$id])) {
                $this->auditDecision($tenantId, $callerId, $sourceId, $before[$id], $decision, true);
            }
        }

        return Response::json(['data' => $outcome])->withHeaders(['ETag' => '"' . $outcome['draft_version'] . '"']);
    }

    // ── comments ─────────────────────────────────────────────────────────

    /** @param array<string, string> $params */
    public function listComments(Request $request, array $params): Response
    {
        $found = $this->document($request, $params, CorePermissions::DOCUMENTS_READ);
        if ($found instanceof Response) {
            return $found;
        }
        [$tenantId, , $row] = $found;

        $resolved = self::queryParam($request, 'resolved') ?? 'all';
        if (!in_array($resolved, ['0', '1', 'all'], true)) {
            return Response::error('resolved must be 0, 1 or all', 422);
        }
        $threads = $this->collab->commentRepository()->threadsForSource(
            $tenantId,
            (int) $row['id'],
            $resolved === 'all' ? null : $resolved === '1',
        );

        return Response::json(['data' => $this->collab->presentThreads($tenantId, $threads)]);
    }

    /** @param array<string, string> $params */
    public function createThread(Request $request, array $params): Response
    {
        $found = $this->document($request, $params, CorePermissions::DOCUMENTS_COMMENT);
        if ($found instanceof Response) {
            return $found;
        }
        [$tenantId, $callerId, $row] = $found;

        $body = JsonBody::parsed($request);
        $anchor = $body['anchor_block_id'] ?? null;
        /** @var list<array<string, mixed>> $blocks */
        $blocks = is_array($row['blocks'] ?? null) ? array_values($row['blocks']) : [];
        if (!is_string($anchor) || $anchor === '' || strlen($anchor) > 64 || !SuggestionPatch::hasBlock($blocks, $anchor)) {
            return Response::error('anchor_block_id must be the id of a block in the draft', 422);
        }
        $quote = $body['quote'] ?? null;
        if (!is_string($quote) || mb_strlen($quote) > self::MAX_QUOTE) {
            return Response::error('quote must be the commented text, at most ' . self::MAX_QUOTE . ' characters', 422);
        }
        $message = $this->messageFields($tenantId, $body);
        if ($message instanceof Response) {
            return $message;
        }

        $sourceId = (int) $row['id'];
        $comments = $this->collab->commentRepository();
        $threadId = $comments->createThread($tenantId, $sourceId, $anchor, $quote, $callerId);
        $commentId = $comments->addComment($tenantId, $threadId, $message + self::actorColumns($callerId) + ['created_by_profile_id' => $callerId]);
        $this->notifyMentions($tenantId, $callerId, $row, $threadId, $commentId, $message);

        $thread = $comments->findThread($threadId, $tenantId, $sourceId);

        return Response::json(['data' => $thread !== null ? $this->collab->presentThreads($tenantId, [$thread])[0] : null], 201);
    }

    /** @param array<string, string> $params */
    public function addMessage(Request $request, array $params): Response
    {
        $found = $this->thread($request, $params);
        if ($found instanceof Response) {
            return $found;
        }
        [$tenantId, $callerId, $row, $thread] = $found;

        $body = JsonBody::parsed($request);
        $message = $this->messageFields($tenantId, $body);
        if ($message instanceof Response) {
            return $message;
        }
        $comments = $this->collab->commentRepository();
        $commentId = $comments->addComment($tenantId, (int) $thread['id'], $message + self::actorColumns($callerId) + ['created_by_profile_id' => $callerId]);
        $this->notifyMentions($tenantId, $callerId, $row, (int) $thread['id'], $commentId, $message);

        $comment = $comments->findComment($commentId, $tenantId, (int) $thread['id']);

        return Response::json(['data' => $comment !== null ? $this->collab->presentMessage($tenantId, $comment) : null], 201);
    }

    /** @param array<string, string> $params */
    public function resolve(Request $request, array $params): Response
    {
        return $this->setResolved($request, $params, true);
    }

    /** @param array<string, string> $params */
    public function reopen(Request $request, array $params): Response
    {
        return $this->setResolved($request, $params, false);
    }

    // ── changes ──────────────────────────────────────────────────────────

    /**
     * What changed since a cursor: suggestions (every status) and threads,
     * and the draft version. Without `since`, everything. Polled by the
     * editor while it is open.
     *
     * @param array<string, string> $params
     */
    public function changes(Request $request, array $params): Response
    {
        $found = $this->document($request, $params, CorePermissions::DOCUMENTS_READ);
        if ($found instanceof Response) {
            return $found;
        }
        [$tenantId, , $row] = $found;
        $sourceId = (int) $row['id'];

        $since = null;
        $cursor = self::queryParam($request, 'since');
        if ($cursor !== null && $cursor !== '') {
            $since = DocumentCollab::cursorTime($cursor);
            if ($since === null) {
                return Response::error('since must be a cursor from an earlier changes response', 422);
            }
        }
        // Taken before reading, so nothing written while reading is skipped next time.
        $next = $this->collab->cursorNow();
        $suggestions = $since !== null
            ? $this->collab->suggestionRepository()->changedSince($tenantId, $sourceId, $since)
            : $this->collab->suggestionRepository()->listForSource($tenantId, $sourceId, null);
        $threads = $this->collab->commentRepository()->threadsForSource($tenantId, $sourceId, null, $since);

        return Response::json(['data' => [
            'cursor'        => $next,
            'draft_version' => (int) $row['draft_version'],
            'suggestions'   => $this->collab->presentSuggestions($tenantId, $suggestions),
            'comments'      => $this->collab->presentThreads($tenantId, $threads),
        ]])->withHeaders(['ETag' => '"' . (int) $row['draft_version'] . '"']);
    }

    // ── people to @mention ───────────────────────────────────────────────

    /**
     * Up to 20 members of the workspace who can read this document, matching
     * `q` on display name or email: the people a comment may @mention. Uses
     * the rule mention notifications use (the document's visibility for that
     * person), so nobody outside the workspace or the document's audience is
     * ever listed.
     *
     * @param array<string, string> $params
     */
    public function mentionable(Request $request, array $params): Response
    {
        $found = $this->document($request, $params, CorePermissions::DOCUMENTS_COMMENT);
        if ($found instanceof Response) {
            return $found;
        }
        [$tenantId, , $row] = $found;

        $q = trim((string) (self::queryParam($request, 'q') ?? ''));
        if ($q === '' || mb_strlen($q) > 100) {
            return Response::error('q must be 1 to 100 characters of a name or email', 422);
        }

        $people = [];
        foreach ($this->collab->searchMembers($tenantId, $q, self::MENTIONABLE_CANDIDATES) as $member) {
            if ($this->sources->visibleRow((int) $row['id'], $tenantId, $member['id']) === null) {
                continue;
            }
            $person = ['kind' => 'person', 'id' => $member['id'], 'name' => $member['name']];
            if ($member['email'] !== null) {
                $person['email'] = $member['email'];
            }
            $people[] = $person;
            if (count($people) >= self::MAX_MENTIONABLE) {
                break;
            }
        }

        return Response::json(['data' => $people]);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** @param array<string, string> $params */
    private function setResolved(Request $request, array $params, bool $resolve): Response
    {
        $found = $this->thread($request, $params);
        if ($found instanceof Response) {
            return $found;
        }
        [$tenantId, $callerId, $row, $thread] = $found;
        $comments = $this->collab->commentRepository();
        $isResolved = $thread['resolved_at'] !== null;
        if ($isResolved !== $resolve) {
            $comments->setResolved((int) $thread['id'], $tenantId, $resolve ? $callerId : null);
            $this->audit?->record($resolve ? 'documents.comment.resolved' : 'documents.comment.reopened', [
                'tenant_id'     => $tenantId,
                'actor_user_id' => $callerId,
                'target_type'   => 'document_comment_thread',
                'target_id'     => (int) $thread['id'],
                'metadata'      => ['document_id' => (int) $row['id']],
            ]);
        }
        $fresh = $comments->findThread((int) $thread['id'], $tenantId, (int) $row['id']);

        return Response::json(['data' => $fresh !== null ? $this->collab->presentThreads($tenantId, [$fresh])[0] : null]);
    }

    /**
     * The document, when the caller holds the permission and can see it.
     *
     * @param array<string, string> $params
     * @return array{0: int, 1: int, 2: array<string, mixed>}|Response
     */
    private function document(Request $request, array $params, string $permission): array|Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 400);
        }
        $user = $request->user;
        $callerId = is_object($user) && isset($user->profile_id) && is_int($user->profile_id) ? $user->profile_id : null;
        if ($callerId === null) {
            return Response::error('Authentication required', 401);
        }
        if (!ScopedPermissionSet::forProfile($this->roleChecker, $callerId, $tenantId)($permission)) {
            return Response::error('Insufficient permissions', 403, ['required' => $permission]);
        }
        $row = $this->sources->visibleRow((int) ($params['id'] ?? 0), $tenantId, $callerId);
        if ($row === null) {
            return Response::error('Document not found', 404);
        }

        return [$tenantId, $callerId, $row];
    }

    /**
     * @param array<string, string> $params
     * @return array{0: int, 1: int, 2: array<string, mixed>, 3: array<string, mixed>}|Response
     */
    private function thread(Request $request, array $params): array|Response
    {
        $found = $this->document($request, $params, CorePermissions::DOCUMENTS_COMMENT);
        if ($found instanceof Response) {
            return $found;
        }
        [$tenantId, $callerId, $row] = $found;
        $thread = $this->collab->commentRepository()->findThread((int) ($params['tid'] ?? 0), $tenantId, (int) $row['id']);
        if ($thread === null) {
            return Response::error('Comment thread not found', 404);
        }

        return [$tenantId, $callerId, $row, $thread];
    }

    /**
     * A message's body and mentions, validated. Mentions keep only members of
     * the workspace.
     *
     * @param array<string, mixed> $body
     * @return array{body: list<array<string, mixed>>, mentions: list<int>}|Response
     */
    private function messageFields(int $tenantId, array $body): array|Response
    {
        $inlines = $body['body'] ?? null;
        $problem = SuggestionPatch::inlinesProblem($inlines, 'body', false);
        if ($problem !== null) {
            return Response::error($problem, 422);
        }
        /** @var list<array<string, mixed>> $inlines */
        if (trim(SuggestionPatch::plainText($inlines)) === '' || mb_strlen(SuggestionPatch::plainText($inlines)) > self::MAX_BODY_TEXT) {
            return Response::error('body must say something, in at most ' . self::MAX_BODY_TEXT . ' characters', 422);
        }
        $mentions = [];
        if (array_key_exists('mentions', $body) && $body['mentions'] !== null) {
            $list = $body['mentions'];
            if (!is_array($list) || !array_is_list($list) || count($list) > self::MAX_MENTIONS) {
                return Response::error('mentions must be a list of at most ' . self::MAX_MENTIONS . ' profile ids', 422);
            }
            foreach ($list as $id) {
                if (!is_int($id) || $id <= 0) {
                    return Response::error('mentions must be a list of profile ids', 422);
                }
            }
            $members = $this->collab->memberNames($tenantId, $list);
            $mentions = array_values(array_filter(array_values(array_unique($list)), static fn (int $id): bool => isset($members[$id])));
        }

        return ['body' => $inlines, 'mentions' => $mentions];
    }

    /**
     * Tells each person @mentioned, in-app, when they can read the document.
     * Fail-soft: a notification that can't be sent never fails the comment.
     *
     * @param array<string, mixed>                                            $row
     * @param array{body: list<array<string, mixed>>, mentions: list<int>} $message
     */
    private function notifyMentions(int $tenantId, int $callerId, array $row, int $threadId, int $commentId, array $message): void
    {
        if ($this->notifications === null || $message['mentions'] === []) {
            return;
        }
        $title = trim((string) ($row['title'] ?? '')) !== '' ? trim((string) $row['title']) : 'a document';
        $author = $this->collab->memberNames($tenantId, [$callerId])[$callerId] ?? 'Someone';
        $text = str_replace(SuggestionPatch::ATOM, '', SuggestionPatch::plainText($message['body']));
        foreach ($message['mentions'] as $profileId) {
            if ($profileId === $callerId || $this->sources->visibleRow((int) $row['id'], $tenantId, $profileId) === null) {
                continue;
            }
            try {
                $this->notifications->dispatch($tenantId, $profileId, self::MENTION_NOTIFICATION, [
                    'channels' => ['in_app'],
                    'subject'  => $author . ' mentioned you in ' . $title,
                    'body'     => mb_substr($text, 0, 500),
                    'data'     => [
                        'document_id' => (int) $row['id'],
                        'thread_id'   => $threadId,
                        'comment_id'  => $commentId,
                    ],
                ]);
            } catch (\Throwable $e) {
                error_log('[DocumentCollabApiHandler] mention notification failed: ' . $e->getMessage());
            }
        }
    }

    /** @return array<string, mixed> */
    private function presentSuggestion(int $tenantId, int $sourceId, int $id): array
    {
        $row = $this->collab->suggestionRepository()->find($id, $tenantId, $sourceId);

        return $row !== null ? $this->collab->presentSuggestions($tenantId, [$row])[0] : [];
    }

    /** @param array<string, mixed> $suggestion */
    private function auditDecision(int $tenantId, int $callerId, int $sourceId, array $suggestion, string $decision, bool $bulk): void
    {
        $this->audit?->record('documents.suggestion.' . $decision, [
            'tenant_id'     => $tenantId,
            'actor_user_id' => $callerId,
            'target_type'   => 'document_suggestion',
            'target_id'     => (int) $suggestion['id'],
            'metadata'      => [
                'document_id' => $sourceId,
                'kind'        => $suggestion['kind'],
                'summary'     => $suggestion['summary'],
                'suggested_by_kind' => $suggestion['actor_kind'],
                'suggested_by_agent' => $suggestion['agent_principal_id'],
                'suggested_by_profile' => $suggestion['created_by_profile_id'],
                'bulk'        => $bulk,
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function decision(array $body): string|Response
    {
        $decision = $body['decision'] ?? null;
        if ($decision !== DocumentSuggestionRepository::ACCEPTED && $decision !== DocumentSuggestionRepository::REJECTED) {
            return Response::error('decision must be accepted or rejected', 422);
        }

        return $decision;
    }

    /** Whether the caller is an agent acting for this person (an MCP agent principal). */
    private static function isAgent(int $callerId): bool
    {
        $actor = AuditContext::getActor();

        return $actor->kind === Actor::AGENT && $actor->onBehalfOfProfileId === $callerId;
    }

    /**
     * Who is acting, as the actor columns: the agent and the person it acts
     * for, or the person.
     *
     * @return array{actor_kind: string, agent_principal_id: ?string, on_behalf_of_profile_id: ?int}
     */
    private static function actorColumns(int $callerId): array
    {
        $actor = AuditContext::getActor();
        if ($actor->kind === Actor::AGENT && $actor->onBehalfOfProfileId === $callerId && $actor->agentPrincipalId !== null) {
            return ['actor_kind' => Actor::AGENT, 'agent_principal_id' => $actor->agentPrincipalId, 'on_behalf_of_profile_id' => $callerId];
        }

        return ['actor_kind' => Actor::PERSON, 'agent_principal_id' => null, 'on_behalf_of_profile_id' => null];
    }

    /** A query-string parameter, read the way DocumentSourcesApiHandler reads one. */
    private static function queryParam(Request $request, string $name): ?string
    {
        $value = isset($_GET[$name]) && is_string($_GET[$name]) ? $_GET[$name] : null;
        $query = parse_url($request->getPath(), PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            $params = [];
            parse_str($query, $params);
            if (isset($params[$name]) && is_string($params[$name])) {
                $value = $params[$name];
            }
        }

        return $value;
    }
}
