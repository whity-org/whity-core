<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\Agent\AgentAssignmentRepository;
use Whity\Core\Agent\AgentDefinition;
use Whity\Core\Agent\AgentRepository;
use Whity\Core\Audit\AuditLoggerInterface;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\Auth\AgentGrantView;
use Whity\Mcp\Auth\AgentScopeGrant;
use Whity\Mcp\Auth\McpScope;
use Whity\Mcp\Auth\McpTokenService;

/**
 * A person's use of the workspace's agents (WHIT-816;
 * docs/design/AGENT-PERSONAS-AND-WHITY-AGENT.md §4.3 and §4.4).
 *
 *   GET    /api/me/agents                          the workspace's agents, with the caller's assignment
 *   POST   /api/me/agents/{id}/assignment          grant the agent for yourself
 *   DELETE /api/me/agents/{id}/assignment          stop using it; its credentials are revoked
 *   GET    /api/me/agents/{id}/assignment/grant    your grant, permission by permission
 *   PATCH  /api/me/agents/{id}/assignment/grant    change one or more modes
 *   POST   /api/me/agents/{id}/credentials         a Whity credential that acts as this agent for you
 *
 * SESSION ONLY, and never on the MCP surface: every signed-in member may use an
 * agent for themselves (decided 2026-11-01, instead of an `agents:use`
 * permission the base member role could never hold), and an agent must never
 * be able to widen its own grant. No route gate, not in
 * CoreApiSchemas::AGENT_CALLABLE_UNGATED, so the deriver never offers them.
 *
 * NEVER ABOVE THE PERSON. A new assignment starts from the agent's template
 * clamped to what the person holds, with reads at most Allowed and writes at
 * most Ask first (owner decision Q6). Changes go through {@see AgentGrantView}
 * exactly as an agent credential's grant does (WHIT-832), so both screens
 * explain a grant the same way, and RBAC is checked again on every call.
 */
final class MyAgentsApiHandler
{
    /** What an agent credential may do as a client; its toolsets and grant come from the assignment. */
    private const CREDENTIAL_SCOPE = [McpScope::TOOLS_CALL, McpScope::RESOURCES_READ, McpScope::PROMPTS_READ];

    /**
     * @param \Closure(): list<array{permission: string, readOnly: bool, agentPolicy: string}> $agentOperations
     *        every operation an agent could be offered, with its gate
     */
    public function __construct(
        private readonly AgentRepository $agents,
        private readonly AgentAssignmentRepository $assignments,
        private readonly McpTokenService $tokens,
        private readonly RoleChecker $roles,
        private readonly \Closure $agentOperations,
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

        $mine = $this->assignments->activeForPerson($profileId, $tenantId);

        return Response::json(['data' => array_map(
            static fn (array $agent): array => self::presentAgent($agent, $mine[$agent['id']] ?? null),
            $this->agents->list($tenantId),
        )], 200);
    }

    /** @param array<string, string> $params */
    public function assign(Request $request, array $params = []): Response
    {
        $found = $this->agent($request, $params);
        if ($found instanceof Response) {
            return $found;
        }
        [$agent, $tenantId, $profileId] = $found;

        $existing = $this->assignments->findFor($agent['id'], $profileId, $tenantId);
        if ($existing !== null && $existing['revoked_at'] === null) {
            return Response::error('You already use this agent', 409);
        }

        $grant = $this->clamp($agent['grant'], $profileId, $tenantId);
        $id    = $this->assignments->assign($agent['id'], $profileId, $tenantId, $grant);
        $this->audit?->record('agent.assignment.created', [
            'tenant_id'     => $tenantId,
            'actor_user_id' => $profileId,
            'target_type'   => 'agent_assignment',
            'target_id'     => $id,
            'metadata'      => ['agent' => $agent['slug'], 'grant' => $grant],
        ]);

        return Response::json(['data' => $this->presentGrant($agent, $id, $grant, $profileId, $tenantId)], 201);
    }

    /** @param array<string, string> $params */
    public function unassign(Request $request, array $params = []): Response
    {
        $found = $this->assignment($request, $params);
        if ($found instanceof Response) {
            return $found;
        }
        [$agent, $assignment, $tenantId, $profileId] = $found;

        $revoked = $this->assignments->revoke($assignment['id'], $tenantId, $profileId);
        $this->audit?->record('agent.assignment.revoked', [
            'tenant_id'     => $tenantId,
            'actor_user_id' => $profileId,
            'target_type'   => 'agent_assignment',
            'target_id'     => $assignment['id'],
            'metadata'      => ['agent' => $agent['slug'], 'credentials_revoked' => $revoked],
        ]);

        return Response::json(['data' => ['revokedCredentials' => $revoked]], 200);
    }

    /** @param array<string, string> $params */
    public function grant(Request $request, array $params = []): Response
    {
        $found = $this->assignment($request, $params);
        if ($found instanceof Response) {
            return $found;
        }
        [$agent, $assignment, $tenantId, $profileId] = $found;

        return Response::json(['data' => $this->presentGrant($agent, $assignment['id'], $assignment['grant'], $profileId, $tenantId)], 200);
    }

    /**
     * Body: {"<permission>": "off" | "ask" | "allow" | "default", ...}. Each mode
     * must be one the grant screen offers for that permission, the same rule
     * as PATCH /api/mcp/tokens/{jti}/grant.
     *
     * @param array<string, string> $params
     */
    public function updateGrant(Request $request, array $params = []): Response
    {
        $found = $this->assignment($request, $params);
        if ($found instanceof Response) {
            return $found;
        }
        [$agent, $assignment, $tenantId, $profileId] = $found;

        $body = json_decode($request->getBody(), true);
        if (!is_array($body) || $body === [] || array_is_list($body)) {
            return Response::error('Send an object of permission => off, ask, allow or default', 422);
        }

        $before = $assignment['grant'];
        $grant  = $before;
        $view   = new AgentGrantView(($this->agentOperations)());
        $errors = [];
        foreach ($body as $permission => $mode) {
            $permission = (string) $permission;
            if (!is_string($mode) || !in_array($mode, [...AgentScopeGrant::MODES, 'default'], true)) {
                $errors[] = "{$permission}: the mode must be off, ask, allow or default";
                continue;
            }
            if ($mode === 'default') {
                unset($grant[$permission]);
                continue;
            }
            $scope   = AgentAssignmentRepository::scopeEntries($agent['toolsets'], $grant);
            $choices = $view->choices($permission, $scope, $this->held([$permission], $profileId, $tenantId));
            if (!in_array($mode, $choices['modes'], true)) {
                $errors[] = "{$permission}: " . ($choices['withheld'][$mode]['message'] ?? 'not possible');
                continue;
            }
            $grant[$permission] = $mode;
        }
        if ($errors !== []) {
            return Response::error(implode('; ', $errors), 422);
        }

        $this->assignments->setGrant($assignment['id'], $tenantId, $profileId, $grant);
        $this->audit?->record('agent.assignment.grant_changed', [
            'tenant_id'     => $tenantId,
            'actor_user_id' => $profileId,
            'target_type'   => 'agent_assignment',
            'target_id'     => $assignment['id'],
            'metadata'      => ['agent' => $agent['slug'], 'before' => $before, 'after' => $grant],
        ]);

        return Response::json(['data' => $this->presentGrant($agent, $assignment['id'], $grant, $profileId, $tenantId)], 200);
    }

    /**
     * A Whity `ai-agent` credential bound to the caller's assignment, shown
     * once. Its toolsets and grant are read from the assignment on every call.
     *
     * @param array<string, string> $params
     */
    public function credential(Request $request, array $params = []): Response
    {
        $found = $this->assignment($request, $params);
        if ($found instanceof Response) {
            return $found;
        }
        [$agent, $assignment, $tenantId, $profileId] = $found;

        $body = json_decode($request->getBody(), true);
        $name = is_array($body) && is_string($body['name'] ?? null) ? trim($body['name']) : '';
        if (mb_strlen($name) > 100) {
            return Response::error('name must be at most 100 characters', 422);
        }
        $name = $name !== '' ? $name : $agent['name'];

        ['token' => $token, 'jti' => $jti] = $this->tokens->issueForAssignment($profileId, $tenantId, $name, self::CREDENTIAL_SCOPE, $assignment['id']);
        $this->audit?->record('agent.credential.issued', [
            'tenant_id'     => $tenantId,
            'actor_user_id' => $profileId,
            'target_type'   => 'agent_assignment',
            'target_id'     => $assignment['id'],
            'metadata'      => ['agent' => $agent['slug'], 'jti' => $jti, 'name' => $name],
        ]);

        return Response::json(['data' => [
            'jti'         => $jti,
            'token'       => $token,
            'name'        => $name,
            'principalId' => AgentAssignmentRepository::principalId($assignment['id']),
        ]], 201);
    }

    /**
     * The template clamped to the person: only permissions they hold, a read
     * at most Allowed and a write at most Ask first (owner decision Q6).
     *
     * @param array<string, string> $template
     * @return array<string, string>
     */
    private function clamp(array $template, int $profileId, int $tenantId): array
    {
        $held  = $this->held(array_keys($template), $profileId, $tenantId);
        $grant = [];
        foreach ($template as $permission => $mode) {
            if (!in_array($permission, $held, true)) {
                continue;
            }
            $grant[$permission] = $mode === AgentScopeGrant::ALLOW && !AgentDefinition::isReadPermission($permission)
                ? AgentScopeGrant::ASK
                : $mode;
        }
        ksort($grant);

        return $grant;
    }

    /**
     * Rows in exactly the shape of GET /api/mcp/tokens/{jti}/grant, so the
     * grant screen reads both the same way.
     *
     * @param array<string, mixed>  $agent
     * @param array<string, string> $grant
     * @return array<string, mixed>
     */
    private function presentGrant(array $agent, int $assignmentId, array $grant, int $profileId, int $tenantId): array
    {
        $operations = ($this->agentOperations)();
        $scope      = AgentAssignmentRepository::scopeEntries($agent['toolsets'], $grant);
        $candidates = array_values(array_unique([...array_column($operations, 'permission'), ...array_keys($grant)]));

        return [
            'assignmentId' => $assignmentId,
            'principalId'  => AgentAssignmentRepository::principalId($assignmentId),
            'agent'        => ['id' => $agent['id'], 'name' => $agent['name'], 'nameAr' => $agent['name_ar']],
            'permissions'  => (new AgentGrantView($operations))->rows($scope, $this->held($candidates, $profileId, $tenantId)),
        ];
    }

    /**
     * @param list<string> $permissions
     * @return list<string>
     */
    private function held(array $permissions, int $profileId, int $tenantId): array
    {
        return array_values(array_filter(
            $permissions,
            fn (string $permission): bool => $this->roles->hasPermissionForProfile($profileId, $permission, $tenantId),
        ));
    }

    /**
     * The active agent the route names, in the caller's workspace.
     *
     * @param array<string, string> $params
     * @return array{0: array<string, mixed>, 1: int, 2: int}|Response
     */
    private function agent(Request $request, array $params): array|Response
    {
        $caller = $this->caller($request);
        if ($caller instanceof Response) {
            return $caller;
        }
        [$tenantId, $profileId] = $caller;
        $agent = $this->agents->find((int) ($params['id'] ?? 0), $tenantId);
        if ($agent === null || $agent['status'] !== AgentRepository::ACTIVE) {
            return Response::error('Agent not found', 404);
        }

        return [$agent, $tenantId, $profileId];
    }

    /**
     * The caller's active assignment for the agent the route names.
     *
     * @param array<string, string> $params
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: int, 3: int}|Response
     */
    private function assignment(Request $request, array $params): array|Response
    {
        $found = $this->agent($request, $params);
        if ($found instanceof Response) {
            return $found;
        }
        [$agent, $tenantId, $profileId] = $found;
        $assignment = $this->assignments->findFor($agent['id'], $profileId, $tenantId);
        if ($assignment === null || $assignment['revoked_at'] !== null) {
            return Response::error('You don\'t use this agent', 404);
        }

        return [$agent, $assignment, $tenantId, $profileId];
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

    /**
     * @param array<string, mixed>      $agent
     * @param array<string, mixed>|null $assignment
     * @return array<string, mixed>
     */
    private static function presentAgent(array $agent, ?array $assignment): array
    {
        return [
            'id'         => $agent['id'],
            'slug'       => $agent['slug'],
            'name'       => $agent['name'],
            'nameAr'     => $agent['name_ar'],
            'role'       => $agent['role'],
            'roleAr'     => $agent['role_ar'],
            'tone'       => $agent['tone'],
            'language'   => $agent['language'],
            'preset'     => $agent['preset_key'] === null ? null : ['key' => $agent['preset_key'], 'version' => $agent['preset_version']],
            'assignment' => $assignment === null ? null : [
                'id'          => $assignment['id'],
                'principalId' => AgentAssignmentRepository::principalId($assignment['id']),
                'createdAt'   => $assignment['created_at'],
            ],
        ];
    }
}
