<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\Agent\AgentAssignmentRepository;
use Whity\Core\Audit\Actor;
use Whity\Core\Audit\AuditContext;
use Whity\Core\Audit\AuditLoggerInterface;
use Whity\Core\Document\Agent\DocumentAgentRequestRepository;
use Whity\Core\Document\Collab\DocumentCollab;
use Whity\Core\Document\DocumentSourceVersionRepository;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\ScopedPermissionSet;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * Agents in the editor (editor M6; E.4, E.30, E.31, E.32; IMPLEMENTATION-NOTES
 * 3.4 and the owner's answers Q3, Q4, Q9).
 *
 * WHITY HOSTS NO MODEL (Q3). A person asks from the editor; the request waits
 * in `document_agent_requests` until the person's own agent, connected over
 * MCP, picks it up and works on it with the tools below. The editor polls the
 * request and shows it as an AgentRunStatus with Stop.
 *
 * The person (session; `agentPolicy: refuse`, an agent never asks an agent):
 *
 *   POST /api/document-sources/{id}/agent-requests      documents:suggest  ask an agent
 *   GET  /api/document-sources/{id}/agent-requests      documents:read     my requests on this document
 *   POST /api/document-agent-requests/{rid}/stop        documents:read     Stop
 *
 * The agent (MCP tools, each named by its operationId):
 *
 *   document_agent_requests_next   POST /api/document-agent-requests/next
 *   document_agent_request_update  POST /api/document-agent-requests/{rid}/progress
 *   document_read                  GET  /api/document-sources/{id}/agent-view
 *   document_suggest_blocks        POST /api/document-sources/{id}/agent-suggestions/blocks
 *   document_suggest_rewrite       POST /api/document-sources/{id}/agent-suggestions/rewrite
 *   document_comment_reply         POST /api/document-sources/{id}/comments/{tid}/reply
 *
 * NOTHING HERE WRITES A DRAFT. The suggest tools create suggestions and the
 * reply tool a comment, through {@see DocumentCollabApiHandler}, which records
 * the agent and the person it acts for on the row and in the audit log, with
 * the permission used. A person decides each suggestion (Q9: the suggestion is
 * the approval, so no proposal is wrapped around it).
 *
 * WHICH VERSION AN AGENT READS (Q4): the draft when it is working on a request
 * the person made from inside the editor (`request_id`), the published
 * version otherwise. A person calling document_read gets the draft.
 */
final class DocumentAgentApiHandler
{
    private const MAX_REQUEST = 4000;
    private const MAX_QUOTE = 2000;
    private const MAX_TITLE = 300;
    private const MAX_DETAIL = 4000;
    private const MAX_REPLY = 10000;
    private const MAX_BLOCKS = 200;

    public function __construct(
        private readonly DocumentSourcesApiHandler $sources,
        private readonly DocumentCollabApiHandler $collabApi,
        private readonly DocumentCollab $collab,
        private readonly DocumentAgentRequestRepository $requests,
        private readonly DocumentSourceVersionRepository $versions,
        private readonly AgentAssignmentRepository $assignments,
        private readonly RoleChecker $roleChecker,
        private readonly ?AuditLoggerInterface $audit = null,
    ) {
    }

    // ── the person ───────────────────────────────────────────────────────

    /**
     * Ask an agent. `agent_id` is one of the caller's workspace agents (GET
     * /api/me/agents), or null for any agent the caller has connected.
     *
     * @param array<string, string> $params
     */
    public function ask(Request $request, array $params): Response
    {
        $found = $this->document($request, $params, CorePermissions::DOCUMENTS_SUGGEST);
        if ($found instanceof Response) {
            return $found;
        }
        [$tenantId, $callerId, $row] = $found;
        if (self::agentActor($callerId) !== null) {
            return Response::error('An agent can not ask another agent; a person asks', 403);
        }

        $body = JsonBody::parsed($request);
        $text = is_string($body['request'] ?? null) ? trim($body['request']) : '';
        if ($text === '' || mb_strlen($text) > self::MAX_REQUEST) {
            return Response::error('request must say what to do, in at most ' . self::MAX_REQUEST . ' characters', 422);
        }
        $principal = null;
        $agentId = $body['agent_id'] ?? null;
        if ($agentId !== null) {
            if (!is_int($agentId) || $agentId <= 0) {
                return Response::error('agent_id must be the id of one of your agents, or null', 422);
            }
            $assignment = $this->assignments->activeForPerson($callerId, $tenantId)[$agentId] ?? null;
            if ($assignment === null) {
                return Response::error('You are not using that agent', 422, ['code' => 'agent_not_connected']);
            }
            $principal = AgentAssignmentRepository::principalId((int) $assignment['id']);
        }
        $anchor = $body['anchor_block_id'] ?? null;
        if ($anchor !== null && (!is_string($anchor) || $anchor === '' || strlen($anchor) > 64)) {
            return Response::error('anchor_block_id must be a block id, or null', 422);
        }
        $quote = $body['quote'] ?? null;
        if ($quote !== null && (!is_string($quote) || mb_strlen($quote) > self::MAX_QUOTE)) {
            return Response::error('quote must be at most ' . self::MAX_QUOTE . ' characters, or null', 422);
        }

        $id = $this->requests->create($tenantId, (int) $row['id'], $callerId, [
            'agent_principal_id' => $principal,
            'request'            => $text,
            'anchor_block_id'    => $anchor,
            'quote'              => is_string($quote) && $quote !== '' ? $quote : null,
        ]);
        $this->audit?->record('documents.agent_request.created', [
            'tenant_id'     => $tenantId,
            'actor_user_id' => $callerId,
            'target_type'   => 'document_agent_request',
            'target_id'     => $id,
            'metadata'      => ['document_id' => (int) $row['id'], 'agent' => $principal],
        ]);
        $made = $this->requests->find($id, $tenantId);

        return Response::json(['data' => $made !== null ? $this->present($tenantId, $made) : null], 201);
    }

    /**
     * The caller's latest requests on this document, newest first. The editor
     * polls this while one is waiting or running.
     *
     * @param array<string, string> $params
     */
    public function listRequests(Request $request, array $params): Response
    {
        $found = $this->document($request, $params, CorePermissions::DOCUMENTS_READ);
        if ($found instanceof Response) {
            return $found;
        }
        [$tenantId, $callerId, $row] = $found;
        $rows = $this->requests->forSource($tenantId, (int) $row['id'], $callerId);

        return Response::json(['data' => $this->presentMany($tenantId, $rows)]);
    }

    /** @param array<string, string> $params */
    public function stop(Request $request, array $params): Response
    {
        $caller = $this->caller($request);
        if ($caller instanceof Response) {
            return $caller;
        }
        [$tenantId, $callerId] = $caller;
        $req = $this->requests->find((int) ($params['rid'] ?? 0), $tenantId);
        if ($req === null || $req['requested_by_profile_id'] !== $callerId) {
            return Response::error('Request not found', 404);
        }
        if (in_array($req['status'], DocumentAgentRequestRepository::FINISHED, true)) {
            return Response::json(['data' => $this->present($tenantId, $req)]);
        }
        $this->requests->requestStop((int) $req['id'], $tenantId, $callerId);
        $this->audit?->record('documents.agent_request.stopped', [
            'tenant_id'     => $tenantId,
            'actor_user_id' => $callerId,
            'target_type'   => 'document_agent_request',
            'target_id'     => (int) $req['id'],
            'metadata'      => ['document_id' => $req['source_id']],
        ]);
        $now = $this->requests->find((int) $req['id'], $tenantId);

        return Response::json(['data' => $now !== null ? $this->present($tenantId, $now) : null]);
    }

    // ── the agent ────────────────────────────────────────────────────────

    /**
     * document_agent_requests_next: the oldest request waiting for this agent
     * from the person it acts for, now marked running. `data` is null when
     * none waits.
     *
     * @param array<string, string> $params
     */
    public function next(Request $request, array $params = []): Response
    {
        $caller = $this->caller($request);
        if ($caller instanceof Response) {
            return $caller;
        }
        [$tenantId, $callerId] = $caller;
        $principal = self::agentActor($callerId);
        if ($principal === null) {
            return Response::error('Only an agent picks up requests', 403, ['code' => 'agents_only']);
        }
        $req = $this->requests->claimNext($tenantId, $callerId, $principal);
        if ($req === null) {
            return Response::json(['data' => null]);
        }
        // The person may have lost sight of the document since asking.
        if ($this->sources->visibleRow($req['source_id'], $tenantId, $callerId) === null) {
            $this->requests->report((int) $req['id'], $tenantId, $principal, [
                'status' => DocumentAgentRequestRepository::FAILED, 'title' => null,
                'progress_done' => null, 'progress_total' => null, 'detail' => 'The document is no longer available to you.',
            ]);
            return Response::json(['data' => null]);
        }

        return Response::json(['data' => $this->present($tenantId, $req) + [
            'document_id' => $req['source_id'],
            'next'        => 'Read the document with document_read (pass request_id), then suggest with document_suggest_blocks or document_suggest_rewrite. Report with document_agent_request_update; stop when it says stop.',
        ]]);
    }

    /**
     * document_agent_request_update: how the agent's work is going. Answers
     * `stop: true` once the person pressed Stop; the agent then reports
     * `stopped` and ends.
     *
     * @param array<string, string> $params
     */
    public function progress(Request $request, array $params): Response
    {
        $caller = $this->caller($request);
        if ($caller instanceof Response) {
            return $caller;
        }
        [$tenantId, $callerId] = $caller;
        $principal = self::agentActor($callerId);
        if ($principal === null) {
            return Response::error('Only an agent reports on a request', 403, ['code' => 'agents_only']);
        }
        $req = $this->requests->find((int) ($params['rid'] ?? 0), $tenantId);
        if ($req === null || $req['requested_by_profile_id'] !== $callerId || $req['claimed_by_principal_id'] !== $principal) {
            return Response::error('Request not found', 404);
        }

        $body = JsonBody::parsed($request);
        $status = $body['status'] ?? DocumentAgentRequestRepository::RUNNING;
        if (!is_string($status) || !in_array($status, DocumentAgentRequestRepository::REPORTABLE, true)) {
            return Response::error('status must be one of ' . implode(', ', DocumentAgentRequestRepository::REPORTABLE), 422);
        }
        $title = self::optionalText($body, 'title', self::MAX_TITLE);
        $detail = self::optionalText($body, 'detail', self::MAX_DETAIL);
        if ($title instanceof Response) {
            return $title;
        }
        if ($detail instanceof Response) {
            return $detail;
        }
        $done = $body['done'] ?? null;
        $total = $body['total'] ?? null;
        if (($done !== null && (!is_int($done) || $done < 0)) || ($total !== null && (!is_int($total) || $total < 0))) {
            return Response::error('done and total must be counts, or null', 422);
        }

        if ($req['status'] !== DocumentAgentRequestRepository::RUNNING) {
            return Response::json(['data' => ['stop' => true, 'request' => $this->present($tenantId, $req)]]);
        }
        $this->requests->report((int) $req['id'], $tenantId, $principal, [
            'status'         => $status,
            'title'          => $title,
            'progress_done'  => $done,
            'progress_total' => $total,
            'detail'         => $detail,
        ]);
        if (in_array($status, DocumentAgentRequestRepository::FINISHED, true)) {
            $this->audit?->record('documents.agent_request.' . $status, [
                'tenant_id'     => $tenantId,
                'actor_user_id' => $callerId,
                'target_type'   => 'document_agent_request',
                'target_id'     => (int) $req['id'],
                'metadata'      => ['document_id' => $req['source_id']],
            ]);
        }
        $now = $this->requests->find((int) $req['id'], $tenantId) ?? $req;

        return Response::json(['data' => [
            'stop'    => $now['stop_requested_at'] !== null && $now['status'] === DocumentAgentRequestRepository::RUNNING,
            'request' => $this->present($tenantId, $now),
        ]]);
    }

    /**
     * document_read: the document as blocks with ids, with its open comment
     * threads and suggestions. The draft for a person, or for an agent working
     * on a request from the editor; the published version otherwise (Q4).
     *
     * @param array<string, string> $params
     */
    public function read(Request $request, array $params): Response
    {
        $found = $this->document($request, $params, CorePermissions::DOCUMENTS_READ);
        if ($found instanceof Response) {
            return $found;
        }
        [$tenantId, $callerId, $row] = $found;
        $sourceId = (int) $row['id'];

        $principal = self::agentActor($callerId);
        $draft = $principal === null;
        $rid = self::queryParam($request, 'request_id');
        if ($principal !== null && $rid !== null && $rid !== '') {
            $req = ctype_digit($rid) ? $this->requests->find((int) $rid, $tenantId) : null;
            if ($req === null || $req['source_id'] !== $sourceId || $req['requested_by_profile_id'] !== $callerId || $req['claimed_by_principal_id'] !== $principal) {
                return Response::error('request_id is not a request you are working on for this document', 422);
            }
            $draft = true;
        }

        $published = $row['published_version'] !== null ? $this->versions->find($sourceId, $tenantId, (int) $row['published_version']) : null;
        if (!$draft && $published === null) {
            return Response::error('This document has not been published; an agent reads a draft only when asked from the editor', 409, ['code' => 'not_published']);
        }
        $projection = $this->collab->projection($tenantId, $sourceId);

        return Response::json(['data' => [
            'id'                => $sourceId,
            'kind'              => (string) $row['kind'],
            'title'             => $draft ? (string) $row['title'] : (string) ($published['title'] ?? $row['title']),
            'lang'              => (string) ($row['lang'] ?? 'en'),
            'reading'           => $draft ? 'draft' : 'published',
            'draft_version'     => (int) $row['draft_version'],
            'published_version' => $row['published_version'] !== null ? (int) $row['published_version'] : null,
            'blocks'            => $draft ? array_values(is_array($row['blocks'] ?? null) ? $row['blocks'] : []) : ($published['blocks'] ?? []),
            'suggestions'       => $projection['suggestions'],
            'comments'          => array_values(array_filter($projection['comments'], static fn (array $t): bool => $t['resolved'] === null)),
        ]]);
    }

    /**
     * document_suggest_blocks: new blocks after a block, as a suggestion.
     *
     * @param array<string, string> $params
     */
    public function suggestBlocks(Request $request, array $params): Response
    {
        $body = JsonBody::parsed($request);
        $blocks = $body['blocks'] ?? null;
        if (!is_array($blocks) || !array_is_list($blocks) || $blocks === [] || count($blocks) > self::MAX_BLOCKS) {
            return Response::error('blocks must be a list of 1 to ' . self::MAX_BLOCKS . ' blocks', 422);
        }
        $request2 = $this->asSuggestion($request, $body, 'block', [
            'op'           => 'insert-blocks',
            'afterBlockId' => $body['after_block_id'] ?? null,
            'blocks'       => $blocks,
        ]);
        if ($request2 instanceof Response) {
            return $request2;
        }

        return $this->collabApi->createSuggestion($request2, $params);
    }

    /**
     * document_suggest_rewrite: replace text in a block, as a suggestion. The
     * text is found by what it says, not by offset.
     *
     * @param array<string, string> $params
     */
    public function suggestRewrite(Request $request, array $params): Response
    {
        $body = JsonBody::parsed($request);
        $insert = $body['insert'] ?? null;
        if (!is_string($insert)) {
            return Response::error('insert must be the new text', 422);
        }
        $occurrence = $body['occurrence'] ?? 0;
        $request2 = $this->asSuggestion($request, $body, 'text', [
            'op'         => 'replace-text',
            'blockId'    => $body['block_id'] ?? null,
            'deleteText' => $body['delete_text'] ?? null,
            'occurrence' => $occurrence,
            'insert'     => $insert === '' ? [] : [['type' => 'text', 'text' => $insert]],
        ]);
        if ($request2 instanceof Response) {
            return $request2;
        }

        return $this->collabApi->createSuggestion($request2, $params);
    }

    /**
     * document_comment_reply: a reply in a thread (E.30), in plain text,
     * optionally naming the suggestions it made.
     *
     * @param array<string, string> $params
     */
    public function reply(Request $request, array $params): Response
    {
        $body = JsonBody::parsed($request);
        $text = is_string($body['text'] ?? null) ? trim($body['text']) : '';
        if ($text === '' || mb_strlen($text) > self::MAX_REPLY) {
            return Response::error('text must say something, in at most ' . self::MAX_REPLY . ' characters', 422);
        }
        $ids = $body['suggestion_ids'] ?? [];
        if (!is_array($ids) || !array_is_list($ids) || array_filter($ids, static fn (mixed $v): bool => !is_int($v) || $v <= 0) !== []) {
            return Response::error('suggestion_ids must be a list of suggestion ids', 422);
        }
        if ($ids !== []) {
            $text .= "\n" . 'Suggested: ' . implode(', ', array_map(static fn (int $id): string => '#' . $id, $ids));
        }
        $forward = new Request('POST', $request->getPath(), [], (string) json_encode(['body' => [['type' => 'text', 'text' => $text]]]));
        $forward->user = $request->user;

        return $this->collabApi->addMessage($forward, $params);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /**
     * The suggestion request DocumentCollabApiHandler::createSuggestion takes,
     * with the person's request text when the agent names the request it is
     * working on.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $patch
     */
    private function asSuggestion(Request $request, array $body, string $kind, array $patch): Request|Response
    {
        $summary = is_string($body['summary'] ?? null) ? trim($body['summary']) : '';
        if ($summary === '') {
            return Response::error('summary must say in a few words what the change does', 422);
        }
        $asked = null;
        $rid = $body['request_id'] ?? null;
        if ($rid !== null) {
            $tenantId = TenantContext::getTenantId();
            $req = is_int($rid) && $tenantId !== null ? $this->requests->find($rid, $tenantId) : null;
            $callerId = self::callerId($request);
            if ($req === null || $req['requested_by_profile_id'] !== $callerId) {
                return Response::error('request_id is not a request you are working on', 422);
            }
            $asked = $req['request'];
        }
        $forward = new Request('POST', $request->getPath(), [], (string) json_encode([
            'kind'    => $kind,
            'summary' => $summary,
            'request' => $asked,
            'patch'   => $patch,
        ]));
        $forward->user = $request->user;

        return $forward;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function presentMany(int $tenantId, array $rows): array
    {
        $agents = [];
        foreach ($rows as $r) {
            foreach (['agent_principal_id', 'claimed_by_principal_id'] as $k) {
                if (is_string($r[$k])) {
                    $agents[] = $r[$k];
                }
            }
        }
        $names = $this->collab->agentNames($tenantId, $agents);
        $people = $this->collab->namesOf(array_values(array_unique(array_map(static fn (array $r): int => (int) $r['requested_by_profile_id'], $rows))));

        return array_map(static function (array $r) use ($names, $people): array {
            $agent = $r['claimed_by_principal_id'] ?? $r['agent_principal_id'];

            return [
                'id'        => $r['id'],
                'document_id' => $r['source_id'],
                'request'   => $r['request'],
                'anchor_block_id' => $r['anchor_block_id'],
                'quote'     => $r['quote'],
                'status'    => $r['status'],
                'agent'     => is_string($agent) ? ['id' => $agent, 'name' => $names[$agent] ?? 'Agent'] : null,
                'for'       => ['id' => $r['requested_by_profile_id'], 'name' => $people[$r['requested_by_profile_id']] ?? ''],
                'title'     => $r['title'],
                'progress'  => $r['progress_total'] !== null ? ['done' => (int) ($r['progress_done'] ?? 0), 'total' => $r['progress_total']] : null,
                'detail'    => $r['detail'],
                'stopping'  => $r['stop_requested_at'] !== null && $r['status'] === DocumentAgentRequestRepository::RUNNING,
                'at'        => self::iso($r['created_at']),
                'updated_at' => self::iso($r['updated_at']),
            ];
        }, $rows);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(int $tenantId, array $row): array
    {
        return $this->presentMany($tenantId, [$row])[0];
    }

    /**
     * @param array<string, string> $params
     * @return array{0: int, 1: int, 2: array<string, mixed>}|Response
     */
    private function document(Request $request, array $params, string $permission): array|Response
    {
        $caller = $this->caller($request);
        if ($caller instanceof Response) {
            return $caller;
        }
        [$tenantId, $callerId] = $caller;
        if (!ScopedPermissionSet::forProfile($this->roleChecker, $callerId, $tenantId)($permission)) {
            return Response::error('Insufficient permissions', 403, ['required' => $permission]);
        }
        $row = $this->sources->visibleRow((int) ($params['id'] ?? 0), $tenantId, $callerId);
        if ($row === null) {
            return Response::error('Document not found', 404);
        }

        return [$tenantId, $callerId, $row];
    }

    /** @return array{0: int, 1: int}|Response */
    private function caller(Request $request): array|Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 400);
        }
        $callerId = self::callerId($request);
        if ($callerId === null) {
            return Response::error('Authentication required', 401);
        }

        return [$tenantId, $callerId];
    }

    private static function callerId(Request $request): ?int
    {
        $user = $request->user;

        return is_object($user) && isset($user->profile_id) && is_int($user->profile_id) ? $user->profile_id : null;
    }

    /** The agent principal acting for this person, or null when the person is acting. */
    private static function agentActor(int $callerId): ?string
    {
        $actor = AuditContext::getActor();

        return $actor->kind === Actor::AGENT && $actor->onBehalfOfProfileId === $callerId ? $actor->agentPrincipalId : null;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function optionalText(array $body, string $key, int $max): string|null|Response
    {
        $value = $body[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || mb_strlen($value) > $max) {
            return Response::error("{$key} must be at most {$max} characters, or null", 422);
        }
        $value = trim($value);

        return $value !== '' ? $value : null;
    }

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

    private static function iso(string $value): string
    {
        try {
            return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        } catch (\Exception) {
            return $value;
        }
    }
}
