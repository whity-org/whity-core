<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Agent\AgentActionRepository;
use Whity\Core\Agent\AgentProposalRepository;
use Whity\Core\Audit\AuditLoggerInterface;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\Auth\McpPrincipal;
use Whity\Mcp\Auth\McpScope;
use Whity\Mcp\JsonRpc\McpException;
use Whity\Mcp\Tools\ToolsCallHandler;
use Whity\Mcp\Tools\Undo\UndoConflictException;

/**
 * What agents did for you, and undoing it (WHIT-812; register 7.5c Undo).
 *
 *   GET  /api/me/agent-actions             what agents did for the caller, newest first
 *                                          (?since=<UTC time>&status=done|undone|expired)
 *   POST /api/me/agent-actions/{id}/undo   undo one, inside its window
 *
 * Only actions an agent took on the caller's behalf are visible or undoable.
 * Undo runs the operation's inverse as the caller (actor "person"), after
 * every check runs again, then records who undid it; an approved proposal it
 * ran from becomes `undone`.
 *
 * Answers: 200 with the action; 404 for no such action of yours; 409 when it
 * was already undone, its window has closed, or what it changed has changed
 * since; 422 when the inverse itself failed (the message says why), and the
 * action stays done.
 *
 * NOT agent-callable: no route gate, so the routes are never derived into MCP
 * tools. An agent must not undo, or hide, what it did.
 */
final class AgentActionsApiHandler
{
    public function __construct(
        private readonly AgentActionRepository $actions,
        private readonly ToolsCallHandler $toolsCall,
        private readonly ?AgentProposalRepository $proposals = null,
        private readonly ?AuditLoggerInterface $audit = null,
    ) {
    }

    /** @param array<string, string> $params */
    public function list(Request $request, array $params = []): Response
    {
        $caller = $this->caller($request);
        if ($caller instanceof Response) {
            return $caller;
        }
        [$tenantId, $profileId] = $caller;

        parse_str((string) parse_url($request->getPath(), PHP_URL_QUERY), $query);
        $query += $_GET;
        $status = is_string($query['status'] ?? null) && in_array($query['status'], [
            AgentActionRepository::DONE, AgentActionRepository::UNDONE, AgentActionRepository::EXPIRED,
        ], true) ? $query['status'] : null;
        $since = is_string($query['since'] ?? null) && ($time = strtotime($query['since'])) !== false ? gmdate('Y-m-d H:i:s', $time) : null;

        $rows  = $this->actions->listFor($tenantId, $profileId, 100, $since, $status);
        $names = $this->names($tenantId, $rows);

        return Response::json(['data' => array_map(static fn (array $row): array => self::present($row, $names), $rows)], 200);
    }

    /** @param array<string, string> $params */
    public function undo(Request $request, array $params = []): Response
    {
        $caller = $this->caller($request);
        if ($caller instanceof Response) {
            return $caller;
        }
        [$tenantId, $profileId] = $caller;
        $id = (int) ($params['id'] ?? 0);

        $action = $this->actions->find($id, $tenantId);
        if ($action === null || $action['on_behalf_of_profile_id'] !== $profileId) {
            return Response::error('Action not found', 404);
        }
        if ($action['status'] !== AgentActionRepository::DONE) {
            return Response::error('This was already undone', 409);
        }
        if ($action['undoable_until'] === null || $action['inverse'] === null) {
            return Response::error('This can\'t be undone', 409);
        }
        if (strtotime($action['undoable_until'] . ' UTC') <= time()) {
            return Response::error('This can no longer be undone: its undo window has closed', 409);
        }

        // As the person, not the agent: their own RBAC, no grant, no proposal.
        $person = new McpPrincipal($profileId, $profileId, $tenantId, 'session', McpScope::CAPABILITIES, 'undo:' . $id);
        try {
            $result = $this->toolsCall->undo($person, $action);
        } catch (UndoConflictException $e) {
            return Response::error($e->clientMessage, 409);
        } catch (McpException) {
            // A check refused it: the person may no longer make this change.
            return Response::error('Undo failed: you can no longer make this change.', 422);
        }
        if (!is_array($result) || ($result['isError'] ?? false) === true) {
            $text = is_array($result) ? ($result['content'][0]['text'] ?? '') : '';
            return Response::error('Undo failed: ' . (is_string($text) && $text !== '' ? $text : 'the inverse operation refused'), 422);
        }

        // Atomic: only the caller that moves it from done records the undo.
        if (!$this->actions->markUndone($id, $tenantId, $profileId, $result)) {
            return Response::error('This was already undone', 409);
        }
        if ($action['proposal_id'] !== null) {
            $this->proposals?->markUndone((int) $action['proposal_id'], $tenantId);
        }
        // Actor: the person who undid it ("Undone by Rana").
        $this->audit?->record('agent.action.undone', [
            'tenant_id'     => $tenantId,
            'actor_user_id' => $profileId,
            'target_type'   => 'agent_action',
            'target_id'     => $id,
            'metadata'      => ['action_id' => $id, 'tool' => $action['tool'], 'proposal_id' => $action['proposal_id'], 'agent_principal_id' => $action['agent_principal_id']],
        ]);

        $fresh = $this->actions->find($id, $tenantId);
        return Response::json(['data' => $fresh !== null ? self::present($fresh, $this->names($tenantId, [$fresh])) : null], 200);
    }

    /**
     * What the activity item and the UndoBar show. Times are UTC (ISO 8601,
     * `Z`); the inverse stays server-side.
     *
     * @param array<string, mixed>                                $action
     * @param array<string, array{name: string, nameAr: ?string}> $agentNames by principal id
     * @return array<string, mixed>
     */
    public static function present(array $action, array $agentNames = []): array
    {
        $until  = $action['undoable_until'];
        $status = $action['status'] === AgentActionRepository::DONE && $until !== null && strtotime($until . ' UTC') <= time()
            ? AgentActionRepository::EXPIRED
            : $action['status'];

        return [
            'id'                => $action['id'],
            // done · undone · expired (done, and its undo window has closed)
            'status'            => $status,
            'tool'              => $action['tool'],
            'summary'           => $action['summary'],
            // Named as proposals name it: see AgentProposalRepository::agentNames().
            'agent'             => $agentNames[(string) $action['agent_principal_id']] ?? ['name' => null, 'nameAr' => null],
            'permissions'       => $action['permissions'],
            'arguments'         => $action['arguments'] === [] ? new \stdClass() : $action['arguments'],
            'proposalId'        => $action['proposal_id'],
            // Null when it can't be undone.
            'undoWindowSeconds' => $action['undo_window_seconds'],
            'undoableUntil'     => $until !== null ? self::utc($until) : null,
            'undoneBy'          => $action['undone_by_profile_id'] !== null
                ? ['id' => $action['undone_by_profile_id'], 'name' => $action['undone_by_name']]
                : null,
            'undoneAt'          => $action['undone_at'] !== null ? self::utc((string) $action['undone_at']) : null,
            'createdAt'         => self::utc($action['created_at']),
        ];
    }

    /**
     * @param list<array<string, mixed>> $actions
     * @return array<string, array{name: string, nameAr: ?string}>
     */
    private function names(int $tenantId, array $actions): array
    {
        return $this->proposals?->agentNames($tenantId, array_map(
            static fn (array $action): string => (string) $action['agent_principal_id'],
            $actions,
        )) ?? [];
    }

    /** A stored UTC timestamp as ISO 8601 with `Z`. */
    private static function utc(string $stored): string
    {
        $time = strtotime($stored . ' UTC');
        return $time === false ? $stored : gmdate('Y-m-d\TH:i:s\Z', $time);
    }

    /** @return array{0: int, 1: int}|Response */
    private function caller(Request $request): array|Response
    {
        $tenantId = TenantContext::getTenantId();
        $actor    = $request->user;
        $profile  = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id) ? $actor->profile_id : null;
        if ($tenantId === null || $profile === null) {
            return Response::error('Unauthenticated', 401);
        }
        return [$tenantId, $profile];
    }
}
