<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Agent\AgentAssignmentRepository;
use Whity\Core\Agent\AgentDefinition;
use Whity\Core\Agent\AgentPresetRegistry;
use Whity\Core\Agent\AgentRepository;
use Whity\Core\Agent\AgentStatsRepository;
use Whity\Core\Audit\AuditLoggerInterface;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\Auth\AgentGrantView;

/**
 * Workspace agents and the presets they start from (WHIT-815;
 * docs/design/AGENT-PERSONAS-AND-WHITY-AGENT.md §4.2 and §4.5).
 *
 *   GET   /api/agents                  the workspace's agents (?archived=1 adds archived ones)
 *   GET   /api/agents/{id}             one agent
 *   POST  /api/agents                  create one, from scratch or from `presetKey`
 *   PATCH /api/agents/{id}             change its persona, toolsets or starting grant
 *   POST  /api/agents/{id}/archive     stop offering it
 *   POST  /api/agents/{id}/restore     offer it again
 *   POST  /api/agents/{id}/pause       refuse it on every call until it is resumed
 *   POST  /api/agents/{id}/resume      let it work again
 *   GET   /api/agent-presets           the presets an agent can start from
 *
 * Every route is gated on `agents:manage` at the router (owner decision
 * 2026-10-31, Q1: workspace agents only, made by admins). Every query is scoped
 * to the caller's tenant; another tenant's id answers 404.
 *
 * Writes carry `agentPolicy: refuse` (CoreApiSchemas), so no agent can create
 * or edit agents, however its grant is set.
 */
final class AgentsApiHandler
{
    public function __construct(
        private readonly AgentRepository $agents,
        private readonly AgentPresetRegistry $presets,
        private readonly ?AuditLoggerInterface $audit = null,
        // WHIT-867: counts per agent, and what they need.
        private readonly ?AgentStatsRepository $stats = null,
        private readonly ?\Closure $todayFrom = null,
        private readonly ?\Closure $agentOperations = null,
        // The row when this person can read that document, else null.
        private readonly ?\Closure $readableSource = null,
    ) {
    }

    /** @param array<string, string> $params */
    public function list(Request $request, array $params = []): Response
    {
        $caller = $this->caller($request);
        if ($caller instanceof Response) {
            return $caller;
        }
        parse_str((string) parse_url($request->getPath(), PHP_URL_QUERY), $query);
        $archived = $query['archived'] ?? $_GET['archived'] ?? null;

        return Response::json([
            'data' => $this->presentAll($this->agents->list($caller[0], in_array($archived, ['1', 'true'], true)), $caller[0], $caller[1]),
        ], 200);
    }

    /** @param array<string, string> $params */
    public function get(Request $request, array $params = []): Response
    {
        $caller = $this->caller($request);
        if ($caller instanceof Response) {
            return $caller;
        }
        $agent = $this->agents->find((int) ($params['id'] ?? 0), $caller[0]);

        return $agent === null
            ? Response::error('Agent not found', 404)
            : Response::json(['data' => $this->presentAll([$agent], $caller[0], $caller[1])[0]], 200);
    }

    /** @param array<string, string> $params */
    public function create(Request $request, array $params = []): Response
    {
        $caller = $this->caller($request);
        if ($caller instanceof Response) {
            return $caller;
        }
        [$tenantId, $profileId] = $caller;

        $body = json_decode($request->getBody(), true);
        if (!is_array($body)) {
            return Response::error('Send a JSON object', 422);
        }

        $body      = self::input($body);
        $presetKey = $body['presetKey'] ?? null;
        $preset    = null;
        if ($presetKey !== null) {
            $preset = is_string($presetKey) ? $this->presets->find($tenantId, $presetKey) : null;
            if ($preset === null) {
                return Response::error('No such preset', 422);
            }
        }
        unset($body['presetKey']);

        // A preset gives the starting fields; anything sent overrides them.
        $start = $preset === null ? [] : array_intersect_key($preset, array_flip([
            'name', 'name_ar', 'role', 'role_ar', 'instructions', 'instructions_ar', 'tone', 'language', 'toolsets', 'grant',
        ]));
        [$fields, $errors] = AgentDefinition::validate($body + $start);
        if ($errors !== []) {
            return Response::json(['error' => 'Invalid agent', 'errors' => $errors], 422);
        }
        $unreadable = $this->unreadableSources($fields, $tenantId, $profileId);
        if ($unreadable !== []) {
            return Response::json(['error' => 'Invalid agent', 'errors' => $unreadable], 422);
        }

        if (isset($fields['slug'])) {
            if ($this->agents->slugTaken($tenantId, $fields['slug'])) {
                return Response::error('Another agent in this workspace already uses that slug', 409);
            }
        } else {
            $base           = AgentDefinition::slugFrom((string) $fields['name']);
            $fields['slug'] = $this->agents->freeSlug($tenantId, $base !== '' ? $base : (string) ($preset['slug'] ?? 'agent'));
        }

        // Feature flags come only from the preset a plugin declared, never from
        // the request body, so a person cannot switch on a flag by sending it.
        $fields['features'] = $preset === null ? [] : (array) ($preset['features'] ?? []);

        $id = $this->agents->create(
            $tenantId,
            $fields,
            $profileId,
            $preset === null ? null : (string) $preset['key'],
            $preset === null ? null : (int) $preset['version'],
        );
        $this->audit?->record('agent.created', [
            'tenant_id'   => $tenantId,
            'target_type' => 'agent',
            'target_id'   => $id,
            'metadata'    => ['slug' => $fields['slug'], 'preset' => $preset['key'] ?? null],
        ]);

        if (($fields['may_read'] ?? []) !== []) {
            $this->agents->update($id, $tenantId, ['may_read' => $fields['may_read']], $profileId);
        }

        $agent = $this->agents->find($id, $tenantId);
        return Response::json(['data' => $agent !== null ? $this->presentAll([$agent], $tenantId, $profileId)[0] : null], 201);
    }

    /** @param array<string, string> $params */
    public function update(Request $request, array $params = []): Response
    {
        $caller = $this->caller($request);
        if ($caller instanceof Response) {
            return $caller;
        }
        [$tenantId, $profileId] = $caller;
        $id = (int) ($params['id'] ?? 0);

        $existing = $this->agents->find($id, $tenantId);
        if ($existing === null) {
            return Response::error('Agent not found', 404);
        }
        $body = json_decode($request->getBody(), true);
        if (!is_array($body) || $body === []) {
            return Response::error('Send the fields to change', 422);
        }
        [$fields, $errors] = AgentDefinition::validate(self::input($body), partial: true);
        if ($errors !== []) {
            return Response::json(['error' => 'Invalid agent', 'errors' => $errors], 422);
        }
        if ($fields === []) {
            return Response::error('Nothing to change', 422);
        }
        $unreadable = $this->unreadableSources($fields, $tenantId, $profileId, $existing['may_read']);
        if ($unreadable !== []) {
            return Response::json(['error' => 'Invalid agent', 'errors' => $unreadable], 422);
        }
        if (isset($fields['slug']) && $this->agents->slugTaken($tenantId, $fields['slug'], $id)) {
            return Response::error('Another agent in this workspace already uses that slug', 409);
        }

        $this->agents->update($id, $tenantId, $fields, $profileId);
        $this->audit?->record('agent.updated', [
            'tenant_id'   => $tenantId,
            'target_type' => 'agent',
            'target_id'   => $id,
            'metadata'    => ['fields' => array_keys($fields)],
        ]);

        return Response::json(['data' => $this->presentAll([$this->agents->find($id, $tenantId) ?? $existing], $tenantId, $profileId)[0]], 200);
    }

    /** @param array<string, string> $params */
    public function archive(Request $request, array $params = []): Response
    {
        return $this->setStatus($request, $params, AgentRepository::ARCHIVED, 'agent.archived');
    }

    /** @param array<string, string> $params */
    public function restore(Request $request, array $params = []): Response
    {
        return $this->setStatus($request, $params, AgentRepository::ACTIVE, 'agent.restored');
    }

    /**
     * Pause: the agent is refused on every call and its waiting proposals can't
     * run, at once and for every person who uses it. Nothing is revoked, so
     * resuming puts everything back as it was.
     *
     * @param array<string, string> $params
     */
    public function pause(Request $request, array $params = []): Response
    {
        return $this->setPaused($request, $params, true, 'agent.paused');
    }

    /** @param array<string, string> $params */
    public function resume(Request $request, array $params = []): Response
    {
        return $this->setPaused($request, $params, false, 'agent.resumed');
    }

    /** @param array<string, string> $params */
    public function presets(Request $request, array $params = []): Response
    {
        $caller = $this->caller($request);
        if ($caller instanceof Response) {
            return $caller;
        }

        // How many of this workspace's agents came from it. Never across
        // workspaces: one workspace's use of a preset is its own business.
        $usedBy = [];
        foreach ($this->agents->list($caller[0], true) as $agent) {
            if ($agent['preset_key'] !== null) {
                $usedBy[$agent['preset_key']] = ($usedBy[$agent['preset_key']] ?? 0) + 1;
            }
        }

        return Response::json(['data' => array_map(
            static fn (array $preset): array => self::presentPreset($preset) + ['agentsFromIt' => $usedBy[$preset['key']] ?? 0],
            $this->presets->forTenant($caller[0]),
        )], 200);
    }

    /** @param array<string, string> $params */
    private function setPaused(Request $request, array $params, bool $paused, string $event): Response
    {
        $caller = $this->caller($request);
        if ($caller instanceof Response) {
            return $caller;
        }
        [$tenantId, $profileId] = $caller;
        $id = (int) ($params['id'] ?? 0);

        if (!$this->agents->setPaused($id, $tenantId, $paused, $profileId)) {
            return Response::error('Agent not found', 404);
        }
        $this->audit?->record($event, ['tenant_id' => $tenantId, 'target_type' => 'agent', 'target_id' => $id]);

        $agent = $this->agents->find($id, $tenantId);
        return Response::json(['data' => $agent !== null ? $this->presentAll([$agent], $tenantId, $profileId)[0] : null], 200);
    }

    /**
     * An agent keeps for reference only what the person setting it can read, so
     * nobody reaches a document's name through an agent they manage. Ids already
     * stored stay: someone who can't read one can still edit the rest of the agent.
     *
     * @param array<string, mixed> $fields
     * @param list<int>            $kept   already on the agent, so not re-checked
     * @return list<string>
     */
    private function unreadableSources(array $fields, int $tenantId, int $profileId, array $kept = []): array
    {
        $errors = [];
        foreach (array_diff((array) ($fields['may_read'] ?? []), $kept) as $id) {
            $row = $this->readableSource !== null ? ($this->readableSource)((int) $id, $tenantId, $profileId) : null;
            if (!is_array($row)) {
                $errors[] = "mayRead: document {$id} doesn't exist or you can't read it";
            }
        }

        return $errors;
    }

    /**
     * The agents with their counts, read in one batch.
     *
     * @param list<array<string, mixed>> $agents
     * @return list<array<string, mixed>>
     */
    private function presentAll(array $agents, int $tenantId, int $viewerId): array
    {
        $stats = $this->stats?->forWorkspace(
            $tenantId,
            $this->todayFrom !== null ? ($this->todayFrom)($tenantId) : gmdate('Y-m-d 00:00:00'),
        ) ?? [];
        $names = $this->stats?->names(array_values(array_filter(array_merge(
            array_column($agents, 'paused_by_profile_id'),
            array_column($agents, 'updated_by_profile_id'),
        ), 'is_int'))) ?? [];
        $view  = new AgentGrantView($this->agentOperations !== null ? ($this->agentOperations)() : []);

        return array_map(function (array $agent) use ($stats, $names, $view, $tenantId, $viewerId): array {
            $use = $stats[$agent['id']] ?? [];

            return self::present($agent) + [
                'paused'         => $agent['paused_at'] !== null,
                'pausedAt'       => $agent['paused_at'] !== null ? self::utc($agent['paused_at']) : null,
                'pausedBy'       => $agent['paused_by_profile_id'] !== null ? ($names[$agent['paused_by_profile_id']] ?? null) : null,
                'updatedBy'      => $agent['updated_by_profile_id'] !== null ? ($names[$agent['updated_by_profile_id']] ?? null) : null,
                'worksFor'       => $use['worksFor'] ?? [],
                'actionsToday'   => $use['actionsToday'] ?? 0,
                'proposalsToday' => $use['proposalsToday'] ?? 0,
                'waiting'        => $use['waiting'] ?? 0,
                'lastActiveAt'   => isset($use['lastActiveAt']) ? self::utc($use['lastActiveAt']) : null,
                'onItsOwn'       => $view->onItsOwn(AgentAssignmentRepository::scopeEntries($agent['toolsets'], $agent['grant'])),
                'mayRead'        => array_map(function (int $id) use ($tenantId, $viewerId): array {
                    $row = $this->readableSource !== null ? ($this->readableSource)($id, $tenantId, $viewerId) : null;

                    // A document this viewer can't read keeps its place, without its name.
                    return is_array($row)
                        ? ['id' => $id, 'title' => (string) ($row['title'] ?? ''), 'kind' => (string) ($row['kind'] ?? ''), 'readable' => true]
                        : ['id' => $id, 'title' => null, 'kind' => null, 'readable' => false];
                }, $agent['may_read']),
            ];
        }, $agents);
    }

    private static function utc(string $time): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($time . ' UTC'));
    }

    /** @param array<string, string> $params */
    private function setStatus(Request $request, array $params, string $status, string $event): Response
    {
        $caller = $this->caller($request);
        if ($caller instanceof Response) {
            return $caller;
        }
        [$tenantId, $profileId] = $caller;
        $id = (int) ($params['id'] ?? 0);

        if (!$this->agents->setStatus($id, $tenantId, $status, $profileId)) {
            return Response::error('Agent not found', 404);
        }
        $this->audit?->record($event, ['tenant_id' => $tenantId, 'target_type' => 'agent', 'target_id' => $id]);

        $agent = $this->agents->find($id, $tenantId);
        return Response::json(['data' => $agent !== null ? $this->presentAll([$agent], $tenantId, $profileId)[0] : null], 200);
    }

    /**
     * The API speaks camelCase, as its responses do (`nameAr`); the definition
     * uses the column names (`name_ar`).
     *
     * @param array<mixed> $body
     * @return array<mixed>
     */
    private static function input(array $body): array
    {
        foreach (['nameAr' => 'name_ar', 'roleAr' => 'role_ar', 'instructionsAr' => 'instructions_ar', 'mayRead' => 'may_read'] as $camel => $column) {
            unset($body[$column]);
            if (array_key_exists($camel, $body)) {
                $body[$column] = $body[$camel];
                unset($body[$camel]);
            }
        }

        return $body;
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
     * @param array<string, mixed> $agent
     * @return array<string, mixed>
     */
    private static function present(array $agent): array
    {
        return [
            'id'             => $agent['id'],
            'slug'           => $agent['slug'],
            'name'           => $agent['name'],
            'nameAr'         => $agent['name_ar'],
            'role'           => $agent['role'],
            'roleAr'         => $agent['role_ar'],
            'instructions'   => $agent['instructions'],
            'instructionsAr' => $agent['instructions_ar'],
            'tone'           => $agent['tone'],
            'language'       => $agent['language'],
            'toolsets'       => $agent['toolsets'],
            'grant'          => $agent['grant'] === [] ? new \stdClass() : $agent['grant'],
            'preset'         => $agent['preset_key'] === null ? null : ['key' => $agent['preset_key'], 'version' => $agent['preset_version']],
            'status'         => $agent['status'],
            'createdAt'      => $agent['created_at'],
            'updatedAt'      => $agent['updated_at'],
        ];
    }

    /**
     * @param array<string, mixed> $preset
     * @return array<string, mixed>
     */
    private static function presentPreset(array $preset): array
    {
        return [
            'key'            => $preset['key'],
            'version'        => $preset['version'],
            'name'           => $preset['name'],
            'nameAr'         => $preset['name_ar'],
            'role'           => $preset['role'],
            'roleAr'         => $preset['role_ar'],
            'instructions'   => $preset['instructions'],
            'instructionsAr' => $preset['instructions_ar'],
            'tone'           => $preset['tone'],
            'language'       => $preset['language'],
            'toolsets'       => $preset['toolsets'],
            'grant'          => $preset['grant'] === [] ? new \stdClass() : $preset['grant'],
            'does'           => $preset['does'],
            'doesAr'         => $preset['does_ar'],
            'icon'           => $preset['icon'],
            // The plugin it comes with; null when it works with Whity alone.
            'needs'          => $preset['needs'],
        ];
    }
}
