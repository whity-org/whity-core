<?php

declare(strict_types=1);

namespace Whity\Core\Taskaat;

use PDO;
use Whity\Core\Agent\AgentRepository;

/**
 * Who a Taskaat task is with, in Whity's words (A10).
 *
 * Taskaat stores an assignee as the calling app's own reference plus a name (`assignee` on a
 * task: kind, ref, name, for, by). Whity's references name the workspace too, because one
 * Taskaat account can be connected to more than one workspace:
 * `whity:<tenant>:person:<profile id>` and `whity:<tenant>:agent:<agent id>`.
 *
 * Only members of this workspace and its own agents can be named; anyone else is refused.
 */
final class TaskPeople
{
    public function __construct(
        private readonly PDO $db,
        private readonly AgentRepository $agents,
    ) {
    }

    public static function personRef(int $tenantId, int $profileId): string
    {
        return 'whity:' . $tenantId . ':person:' . $profileId;
    }

    public static function agentRef(int $tenantId, int $agentId): string
    {
        return 'whity:' . $tenantId . ':agent:' . $agentId;
    }

    /**
     * A person of this workspace as Taskaat stores them, or null when they are not a member.
     *
     * @return array{ref: string, name: string}|null
     */
    public function person(int $tenantId, int $profileId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT p.display_name FROM profiles p
               JOIN memberships m ON m.profile_id = p.id AND m.tenant_id = :tenant_id
              WHERE p.id = :id
              LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $profileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $name = is_string($row['display_name'] ?? null) ? trim($row['display_name']) : '';

        return ['ref' => self::personRef($tenantId, $profileId), 'name' => $name];
    }

    /**
     * An agent of this workspace as Taskaat stores it, or null when it is not one.
     *
     * @return array{ref: string, name: string}|null
     */
    public function agent(int $tenantId, int $agentId): ?array
    {
        $agent = $this->agents->find($agentId, $tenantId);
        if ($agent === null) {
            return null;
        }
        $name = is_string($agent['name'] ?? null) ? $agent['name'] : '';

        return ['ref' => self::agentRef($tenantId, $agentId), 'name' => $name];
    }
}
