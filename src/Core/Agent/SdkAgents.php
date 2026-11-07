<?php

declare(strict_types=1);

namespace Whity\Core\Agent;

use Whity\Auth\RoleChecker;
use Whity\Core\Audit\AuditContext;
use Whity\Core\Container\HostWiredService;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Agents\Agents;

/**
 * The SDK's {@see Agents} (WHIT-990): the workspace's agents and their activity,
 * for plugins, read the way the host's own screens read them.
 *
 * The workspace comes from {@see TenantContext} and the person from
 * {@see AuditContext}, never from the plugin. A person with `agents:manage` reads
 * what `GET /api/agents` and `GET /api/agent-activity` show; anybody else reads
 * only the agents they use and the activity on their own behalf. No person, or
 * no workspace, reads nothing.
 *
 * Worker-safe: no request state is kept on the instance.
 */
final class SdkAgents implements Agents, HostWiredService
{
    private const MAX_LIMIT = 200;

    public function __construct(
        private readonly AgentRepository $agents,
        private readonly AgentAssignmentRepository $assignments,
        private readonly AgentActivityRepository $activity,
        private readonly AgentProposalRepository $proposals,
        private readonly RoleChecker $roleChecker,
    ) {
    }

    public function list(bool $includeArchived = false): array
    {
        $caller = $this->caller();
        if ($caller === null) {
            return [];
        }
        [$tenantId, $profileId, $manages] = $caller;

        if ($manages) {
            $rows = $this->agents->list($tenantId, $includeArchived);
        } else {
            $used = $this->assignments->activeForPerson($profileId, $tenantId);
            $rows = array_values(array_filter(
                $this->agents->list($tenantId),
                static fn (array $agent): bool => isset($used[$agent['id']]),
            ));
        }

        return array_map(self::present(...), $rows);
    }

    public function find(int $agentId): ?array
    {
        $caller = $this->caller();
        if ($caller === null) {
            return null;
        }
        [$tenantId, $profileId, $manages] = $caller;

        $agent = $this->agents->find($agentId, $tenantId);
        if ($agent === null) {
            return null;
        }
        if (!$manages) {
            $used = $this->assignments->activeForPerson($profileId, $tenantId);
            if (!isset($used[$agent['id']]) || $agent['status'] !== AgentRepository::ACTIVE) {
                return null;
            }
        }

        return self::present($agent);
    }

    public function activity(?int $agentId = null, ?string $status = null, ?\DateTimeInterface $since = null, int $limit = 50, int $offset = 0): array
    {
        $caller = $this->caller();
        if ($caller === null) {
            return [];
        }
        [$tenantId, $profileId, $manages] = $caller;

        if ($status !== null && !in_array($status, AgentActivityRepository::STATUSES, true)) {
            return [];
        }

        // Waiting proposals past their expiry read as expired, as on the screens.
        $this->proposals->expireDue($tenantId);

        $rows = $this->activity->forWorkspace(
            $tenantId,
            $agentId,
            $status,
            $since === null ? null : gmdate('Y-m-d H:i:s', $since->getTimestamp()),
            max(1, min($limit, self::MAX_LIMIT)),
            max(0, $offset),
            $manages ? null : $profileId,
        );

        return array_map(static fn (array $row): array => [
            'id'            => $row['kind'] . '-' . $row['id'],
            'kind'          => $row['kind'] === AgentActivityRepository::KIND_ACTION ? 'action' : 'proposal',
            'status'        => (string) $row['status'],
            'agentId'       => $row['agent_id'] === null ? null : (int) $row['agent_id'],
            'agentName'     => $row['agent_name'] === null ? null : (string) $row['agent_name'],
            'personId'      => (int) $row['on_behalf_of_profile_id'],
            'tool'          => (string) $row['tool'],
            'summary'       => (string) $row['summary'],
            'permissions'   => array_values(array_map('strval', (array) $row['permissions'])),
            'undoableUntil' => $row['undoable_until'] !== null ? self::utc((string) $row['undoable_until']) : null,
            'createdAt'     => self::utc((string) $row['created_at']),
        ], $rows);
    }

    /** @return array{0: int, 1: int, 2: bool}|null the tenant, the person, and whether they manage agents */
    private function caller(): ?array
    {
        $tenantId  = TenantContext::getTenantId();
        $profileId = AuditContext::getActorUserId();
        if ($tenantId === null || $tenantId === 0 || $profileId === null) {
            return null;
        }

        return [$tenantId, $profileId, $this->roleChecker->hasPermissionForProfile($profileId, CorePermissions::AGENTS_MANAGE, $tenantId)];
    }

    /**
     * @param array<string, mixed> $agent
     * @return array{id: int, slug: string, name: array{en: string, ar: string}, role: array{en: string, ar: string}, toolsets: list<string>, status: 'active'|'archived', paused: bool, presetKey: string|null, createdAt: string, updatedAt: string}
     */
    private static function present(array $agent): array
    {
        return [
            'id'        => (int) $agent['id'],
            'slug'      => (string) $agent['slug'],
            'name'      => ['en' => (string) $agent['name'], 'ar' => (string) $agent['name_ar']],
            'role'      => ['en' => (string) $agent['role'], 'ar' => (string) $agent['role_ar']],
            'toolsets'  => array_values(array_map('strval', (array) $agent['toolsets'])),
            'status'    => $agent['status'] === AgentRepository::ARCHIVED ? 'archived' : 'active',
            'paused'    => $agent['paused_at'] !== null,
            'presetKey' => $agent['preset_key'] === null ? null : (string) $agent['preset_key'],
            'createdAt' => self::utc((string) $agent['created_at']),
            'updatedAt' => self::utc((string) $agent['updated_at']),
        ];
    }

    private static function utc(string $time): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($time . ' UTC'));
    }
}
