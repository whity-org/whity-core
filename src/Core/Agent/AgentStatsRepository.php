<?php

declare(strict_types=1);

namespace Whity\Core\Agent;

use PDO;

/**
 * Counts per workspace agent, for the agents list and record (WHIT-867, design
 * 2.9 S6; docs/design/components/TplAgents): who it works for, what it did and
 * proposed today, what waits for a person, and when it was last active.
 *
 * Every query is bound to one tenant. An agent's actions and proposals are found
 * through its assignments' stable principals (`agent:<assignmentId>`), so a
 * revoked assignment's history still counts for the agent. With a person, every
 * figure is that person's alone: a member sees their own use, never a colleague's.
 *
 * Worker-safe: holds only the PDO.
 */
final class AgentStatsRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * The first moment of today in a time zone, in UTC, as `created_at` stores it.
     */
    public static function todayStart(\DateTimeZone $zone, ?\DateTimeImmutable $now = null): string
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $now->setTimezone($zone)->setTime(0, 0)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * @param string   $todayFrom UTC; see {@see self::todayStart()}
     * @param int|null $profileId only this person's use of each agent
     * @return array<int, array{worksFor: list<array{id: int, name: ?string}>, actionsToday: int, proposalsToday: int, waiting: int, lastActiveAt: ?string}>
     *         by agent id; an agent nobody has used is absent
     */
    public function forWorkspace(int $tenantId, string $todayFrom, ?int $profileId = null): array
    {
        $stats = [];
        $entry = static fn (): array => ['worksFor' => [], 'actionsToday' => 0, 'proposalsToday' => 0, 'waiting' => 0, 'lastActiveAt' => null];
        $seen  = static function (array &$row, mixed $time): void {
            if (is_string($time) && $time !== '' && ($row['lastActiveAt'] === null || strtotime($time . ' UTC') > strtotime($row['lastActiveAt'] . ' UTC'))) {
                $row['lastActiveAt'] = $time;
            }
        };

        $sql = 'SELECT asg.agent_id, asg.profile_id, asg.revoked_at, asg.last_active_at, p.display_name
                  FROM agent_assignments asg
                  JOIN profiles p ON p.id = asg.profile_id
                 WHERE asg.tenant_id = :tenant_id';
        $params = [':tenant_id' => $tenantId];
        if ($profileId !== null) {
            $sql .= ' AND asg.profile_id = :profile';
            $params[':profile'] = $profileId;
        }
        $stmt = $this->db->prepare($sql . ' ORDER BY p.display_name, asg.profile_id');
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $agentId = (int) $row['agent_id'];
            $stats[$agentId] ??= $entry();
            $seen($stats[$agentId], $row['last_active_at']);
            if ($row['revoked_at'] === null) {
                $stats[$agentId]['worksFor'][] = ['id' => (int) $row['profile_id'], 'name' => $row['display_name'] === null ? null : (string) $row['display_name']];
            }
        }

        foreach ($this->activity('agent_actions', $tenantId, $todayFrom, $profileId) as $row) {
            $agentId = (int) $row['agent_id'];
            $stats[$agentId] ??= $entry();
            $stats[$agentId]['actionsToday'] = (int) $row['today'];
            $seen($stats[$agentId], $row['last_at']);
        }
        foreach ($this->activity('agent_proposals', $tenantId, $todayFrom, $profileId) as $row) {
            $agentId = (int) $row['agent_id'];
            $stats[$agentId] ??= $entry();
            $stats[$agentId]['proposalsToday'] = (int) $row['today'];
            $stats[$agentId]['waiting']        = (int) $row['waiting'];
            $seen($stats[$agentId], $row['last_at']);
        }

        return $stats;
    }

    /**
     * Display names by profile id, for the actor columns on an agent.
     *
     * @param list<int> $profileIds
     * @return array<int, string>
     */
    public function names(array $profileIds): array
    {
        $profileIds = array_values(array_unique($profileIds));
        if ($profileIds === []) {
            return [];
        }
        $marks = implode(', ', array_fill(0, count($profileIds), '?'));
        $stmt  = $this->db->prepare("SELECT id, display_name FROM profiles WHERE id IN ({$marks})");
        $stmt->execute($profileIds);
        $names = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $names[(int) $row['id']] = (string) $row['display_name'];
        }

        return $names;
    }

    /**
     * @param 'agent_actions'|'agent_proposals' $table
     * @return list<array<string, mixed>>
     */
    private function activity(string $table, int $tenantId, string $todayFrom, ?int $profileId): array
    {
        $waiting = $table === 'agent_proposals'
            ? 'SUM(CASE WHEN x.status = :waiting AND x.expires_at > :now THEN 1 ELSE 0 END)'
            : '0';
        $sql = "SELECT asg.agent_id,
                       SUM(CASE WHEN x.created_at >= :today THEN 1 ELSE 0 END) AS today,
                       {$waiting} AS waiting,
                       MAX(x.created_at) AS last_at
                  FROM {$table} x
                  JOIN agent_assignments asg ON x.agent_principal_id = 'agent:' || asg.id AND asg.tenant_id = x.tenant_id
                 WHERE x.tenant_id = :tenant_id";
        $params = [':today' => $todayFrom, ':tenant_id' => $tenantId];
        if ($table === 'agent_proposals') {
            $params[':waiting'] = AgentProposalRepository::WAITING;
            $params[':now']     = gmdate('Y-m-d H:i:s');
        }
        if ($profileId !== null) {
            $sql .= ' AND x.on_behalf_of_profile_id = :profile';
            $params[':profile'] = $profileId;
        }
        $stmt = $this->db->prepare($sql . ' GROUP BY asg.agent_id');
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
