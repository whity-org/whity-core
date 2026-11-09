<?php

declare(strict_types=1);

namespace Whity\Core\Agent;

use PDO;

/**
 * Everything the workspace's agents did and proposed, for administrators
 * (WHIT-867, design 2.9 S6; docs/design/components/AgentActivity).
 *
 * One timeline from two tables: `agent_actions` for what ran, and
 * `agent_proposals` for what didn't run: waiting, declined, expired, or approved
 * and then refused by a check. A proposal that ran appears once, as its action.
 *
 * Every table in the query is bound to the one tenant, and the filter by agent
 * goes through the tenant's own assignments, so another workspace's id finds
 * nothing. Worker-safe: holds only the PDO.
 */
final class AgentActivityRepository
{
    public const KIND_ACTION   = 'action';
    public const KIND_PROPOSAL = 'proposal';

    public const DONE     = 'done';
    public const UNDONE   = 'undone';
    public const WAITING  = 'waiting';
    public const DECLINED = 'declined';
    public const EXPIRED  = 'expired';
    /** Approved, and a check refused it when it ran: nothing happened. */
    public const FAILED = 'failed';

    public const STATUSES = [self::DONE, self::UNDONE, self::WAITING, self::DECLINED, self::EXPIRED, self::FAILED];

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Newest first. With $onBehalfOf, only rows on that person's behalf (WHIT-990).
     *
     * @return list<array<string, mixed>>
     */
    public function forWorkspace(int $tenantId, ?int $agentId = null, ?string $status = null, ?string $since = null, int $limit = 50, int $offset = 0, ?int $onBehalfOf = null): array
    {
        $sql = "SELECT u.kind, u.id, u.agent_principal_id, u.on_behalf_of_profile_id, u.tool, u.summary, u.permissions,
                       u.undo_window_seconds, u.undoable_until, u.status, u.created_at,
                       asg.agent_id, COALESCE(g.name, t.name) AS agent_name, g.name_ar AS agent_name_ar,
                       p.display_name AS person_name
                  FROM (
                        SELECT 'action' AS kind, a.id, a.agent_principal_id, a.on_behalf_of_profile_id, a.tool, a.summary, a.permissions,
                               a.undo_window_seconds, a.undoable_until, a.status, a.created_at
                          FROM agent_actions a
                         WHERE a.tenant_id = :tenant_actions
                        UNION ALL
                        SELECT 'proposal' AS kind, pr.id, pr.agent_principal_id, pr.on_behalf_of_profile_id, pr.tool, pr.summary, pr.permissions,
                               pr.undo_window_seconds, CAST(NULL AS TIMESTAMP) AS undoable_until,
                               CASE WHEN pr.status IN (:approved, :undone_proposal) THEN :failed ELSE pr.status END AS status,
                               pr.created_at
                          FROM agent_proposals pr
                         WHERE pr.tenant_id = :tenant_proposals
                           AND (pr.status NOT IN (:approved_again, :undone_again)
                                OR NOT EXISTS (SELECT 1 FROM agent_actions ran WHERE ran.proposal_id = pr.id AND ran.tenant_id = :tenant_ran))
                       ) u
                  LEFT JOIN agent_assignments asg ON u.agent_principal_id = 'agent:' || asg.id AND asg.tenant_id = :tenant_assignments
                  LEFT JOIN agents g ON g.id = asg.agent_id AND g.tenant_id = :tenant_agents
                  LEFT JOIN mcp_tokens t ON t.jti = u.agent_principal_id AND t.tenant_id = :tenant_tokens
                  LEFT JOIN profiles p ON p.id = u.on_behalf_of_profile_id
                 WHERE 1 = 1";
        $params = [
            ':tenant_actions'     => $tenantId,
            ':approved'           => AgentProposalRepository::APPROVED,
            ':undone_proposal'    => AgentProposalRepository::UNDONE,
            ':failed'             => self::FAILED,
            ':tenant_proposals'   => $tenantId,
            ':approved_again'     => AgentProposalRepository::APPROVED,
            ':undone_again'       => AgentProposalRepository::UNDONE,
            ':tenant_ran'         => $tenantId,
            ':tenant_assignments' => $tenantId,
            ':tenant_agents'      => $tenantId,
            ':tenant_tokens'      => $tenantId,
        ];
        if ($agentId !== null) {
            $sql .= ' AND asg.agent_id = :agent';
            $params[':agent'] = $agentId;
        }
        if ($onBehalfOf !== null) {
            $sql .= ' AND u.on_behalf_of_profile_id = :on_behalf_of';
            $params[':on_behalf_of'] = $onBehalfOf;
        }
        if ($status !== null) {
            $sql .= ' AND u.status = :status';
            $params[':status'] = $status;
        }
        if ($since !== null) {
            $sql .= ' AND u.created_at >= :since';
            $params[':since'] = $since;
        }

        $stmt = $this->db->prepare($sql . ' ORDER BY u.created_at DESC, u.kind, u.id DESC LIMIT :limit OFFSET :offset');
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', max(1, min($limit, 201)), PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(self::normalize(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalize(array $row): array
    {
        $permissions = is_string($row['permissions']) ? json_decode($row['permissions'], true) : null;
        $nameAr      = (string) ($row['agent_name_ar'] ?? '');

        return [
            'kind'                    => (string) $row['kind'],
            'id'                      => (int) $row['id'],
            'agent_id'                => $row['agent_id'] === null ? null : (int) $row['agent_id'],
            'agent_name'              => $row['agent_name'] === null ? null : (string) $row['agent_name'],
            'agent_name_ar'           => $nameAr !== '' ? $nameAr : null,
            'on_behalf_of_profile_id' => (int) $row['on_behalf_of_profile_id'],
            'person_name'             => $row['person_name'] === null ? null : (string) $row['person_name'],
            'tool'                    => (string) $row['tool'],
            'summary'                 => (string) $row['summary'],
            'permissions'             => is_array($permissions) ? array_values(array_filter($permissions, 'is_string')) : [],
            'undo_window_seconds'     => $row['undo_window_seconds'] === null ? null : (int) $row['undo_window_seconds'],
            'undoable_until'          => $row['undoable_until'] === null ? null : (string) $row['undoable_until'],
            'status'                  => (string) $row['status'],
            'created_at'              => (string) $row['created_at'],
        ];
    }
}
