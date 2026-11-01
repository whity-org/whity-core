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
 *   GET  /api/me/agent-actions             the caller's agent actions, newest first
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

        return Response::json(['data' => array_map(self::present(...), $this->actions->listFor($tenantId, $profileId))], 200);
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
        if (strtotime($action['undoable_until'] . ' UTC') <= time()) {
            return Response::error('This can no longer be undone: its undo window has closed', 409);
        }

        // As the person, not the agent: their own RBAC, no grant, no proposal.
        $person = new McpPrincipal($profileId, $profileId, $tenantId, 'session', McpScope::CAPABILITIES, 'undo:' . $id);
        try {
            $result = $this->toolsCall->undo($person, $action);
        } catch (UndoConflictException $e) {
            return Response::error($e->getMessage(), 409);
        } catch (McpException $e) {
            return Response::error('Undo failed: ' . $e->getMessage(), 422);
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
        $this->audit?->record('agent.action.undone', [
            'tenant_id'   => $tenantId,
            'target_type' => 'agent_action',
            'target_id'   => $id,
            'metadata'    => ['tool' => $action['tool'], 'proposal_id' => $action['proposal_id']],
        ]);

        $fresh = $this->actions->find($id, $tenantId);
        return Response::json(['data' => $fresh !== null ? self::present($fresh) : null], 200);
    }

    /**
     * What the activity item and the UndoBar show. Times are UTC (ISO 8601,
     * `Z`); the inverse stays server-side.
     *
     * @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    public static function present(array $action): array
    {
        return [
            'id'                => $action['id'],
            'status'            => $action['status'],
            'tool'              => $action['tool'],
            'summary'           => $action['summary'],
            'arguments'         => $action['arguments'] === [] ? new \stdClass() : $action['arguments'],
            'proposalId'        => $action['proposal_id'],
            'undoWindowSeconds' => $action['undo_window_seconds'],
            'undoableUntil'     => self::utc($action['undoable_until']),
            'undoneBy'          => $action['undone_by_profile_id'] !== null
                ? ['id' => $action['undone_by_profile_id'], 'name' => $action['undone_by_name']]
                : null,
            'undoneAt'          => $action['undone_at'] !== null ? self::utc((string) $action['undone_at']) : null,
            'createdAt'         => self::utc($action['created_at']),
        ];
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
