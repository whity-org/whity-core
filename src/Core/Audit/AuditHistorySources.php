<?php

declare(strict_types=1);

namespace Whity\Core\Audit;

use PDO;

/**
 * The history the audit page reads besides `audit_log` (merge map plumbing):
 * what agents did with their tools (`agent_actions`) and the decisions people
 * made on routes (`document_route_events` that carry a verdict, a signature or
 * a send-back). Those records stay where they are, because each serves its own
 * job; this reads them as audit rows, in `audit_log`'s own shape, so the page,
 * its sentences and its filters treat them like any other entry.
 *
 * - An agent's action reads `agent.action.ran`, by the agent for its person,
 *   on what it did (its summary).
 * - A route decision reads `route.approved`, `route.rejected`, `route.signed`,
 *   `route.declined` or `route.sent_back`, by the person who decided (and whom
 *   they decided for, while someone was away), on the route's title.
 *
 * Read only for the audit page's list; the CSV export stays the audit log.
 */
final class AuditHistorySources
{
    private const SYSTEM_TENANT_ID = 0;

    private const ROUTE_ACTIONS = ['approved', 'rejected', 'signed', 'declined', 'sent_back'];

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Whether these sources can answer this query at all. Filters that only
     * the audit log has (a target, an action outside these) leave them out.
     *
     * @param array<string, mixed> $query
     * @return array{agents: bool, routes: bool}
     */
    public function scope(array $query): array
    {
        if (($query['source'] ?? '') === 'audit'
            || (isset($query['target_type']) && $query['target_type'] !== '')
            || (isset($query['target_id']) && $query['target_id'] !== '')
        ) {
            return ['agents' => false, 'routes' => false];
        }
        $agents = true;
        $routes = true;
        $action = (string) ($query['action'] ?? '');
        if ($action !== '') {
            $agents = $action === 'agent.action.ran' || $action === 'agent.' || $action === 'agent.action.';
            $routes = str_starts_with($action, 'route.') && ($action === 'route.' || in_array(substr($action, 6), self::ROUTE_ACTIONS, true));
        }
        $area = (string) ($query['area'] ?? '');
        if ($area !== '') {
            $agents = $agents && $area === 'agents';
            $routes = $routes && $area === 'documents';
        }
        if (isset($query['agent']) && $query['agent'] !== '') {
            $routes = false;
        }

        return ['agents' => $agents, 'routes' => $routes];
    }

    /**
     * Newest first, up to `$limit` from each source asked for.
     *
     * @param array<string, mixed> $query
     * @param array{agents: bool, routes: bool} $scope
     * @return list<array<string, mixed>> rows in `audit_log`'s shape, plus `source`
     */
    public function rows(int $tenantId, array $query, array $scope, int $limit): array
    {
        $out = [];
        if ($scope['agents']) {
            [$where, $params] = $this->where($tenantId, $query, 'a', 'a.on_behalf_of_profile_id', 'a.agent_principal_id');
            // @tenant-guard-ignore: tenant_id predicate is in $where for every workspace; the system tenant (id 0) reads every workspace's history by design, as the audit log does
            $stmt = $this->db->prepare(
                "SELECT a.id, a.tenant_id, a.agent_principal_id, a.on_behalf_of_profile_id, a.tool, a.summary, a.status, a.created_at
                   FROM agent_actions a{$where}
                  ORDER BY a.created_at DESC, a.id DESC LIMIT " . max(1, $limit)
            );
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[] = [
                    'id' => (int) $r['id'],
                    'tenant_id' => (int) $r['tenant_id'],
                    'actor_user_id' => null,
                    'actor_kind' => 'agent',
                    'agent_principal_id' => (string) $r['agent_principal_id'],
                    'on_behalf_of_profile_id' => $r['on_behalf_of_profile_id'] !== null ? (int) $r['on_behalf_of_profile_id'] : null,
                    'action' => 'agent.action.ran',
                    'target_type' => 'agent_action',
                    'target_id' => (int) $r['id'],
                    'metadata' => (string) json_encode([
                        'title' => (string) ($r['summary'] ?? '') !== '' ? (string) $r['summary'] : (string) $r['tool'],
                        'tool' => (string) $r['tool'],
                        'status' => (string) $r['status'],
                    ]),
                    'ip_address' => null,
                    'created_at' => (string) $r['created_at'],
                    'source' => 'agents',
                ];
            }
        }
        if ($scope['routes']) {
            [$where, $params] = $this->where($tenantId, $query, 'e', 'e.actor_profile_id', null);
            $kinds = "(e.verdict IS NOT NULL OR e.action IN ('signed', 'declined', 'sent_back'))";
            $where .= ($where === '' ? ' WHERE ' : ' AND ') . $kinds;
            $stmt = $this->db->prepare(
                "SELECT e.id, e.tenant_id, e.actor_profile_id, e.on_behalf_of_profile_id, e.action, e.verdict, e.note,
                        e.document_id, r.subject_type, r.subject_id, r.title, e.occurred_at
                   FROM document_route_events e
                   JOIN document_routes r ON r.id = e.route_id AND r.tenant_id = e.tenant_id{$where}
                  ORDER BY e.occurred_at DESC, e.id DESC LIMIT " . max(1, $limit)
            );
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $what = in_array((string) $r['action'], ['signed', 'declined', 'sent_back'], true)
                    ? (string) $r['action']
                    : ((string) $r['verdict'] === 'rejected' ? 'rejected' : 'approved');
                $out[] = [
                    'id' => (int) $r['id'],
                    'tenant_id' => (int) $r['tenant_id'],
                    'actor_user_id' => $r['actor_profile_id'] !== null ? (int) $r['actor_profile_id'] : null,
                    'actor_kind' => 'person',
                    'agent_principal_id' => null,
                    'on_behalf_of_profile_id' => $r['on_behalf_of_profile_id'] !== null ? (int) $r['on_behalf_of_profile_id'] : null,
                    'action' => 'route.' . $what,
                    'target_type' => $r['document_id'] !== null ? 'document' : (string) ($r['subject_type'] ?? 'route'),
                    'target_id' => $r['document_id'] !== null ? (int) $r['document_id'] : (int) ($r['subject_id'] ?? 0),
                    'metadata' => (string) json_encode(array_filter([
                        'title' => (string) ($r['title'] ?? ''),
                        'note' => $r['note'] !== null ? (string) $r['note'] : null,
                    ], static fn ($v): bool => $v !== null && $v !== '')),
                    'ip_address' => null,
                    'created_at' => (string) $r['occurred_at'],
                    'source' => 'routes',
                ];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $query
     * @param array{agents: bool, routes: bool} $scope
     */
    public function count(int $tenantId, array $query, array $scope): int
    {
        $total = 0;
        if ($scope['agents']) {
            [$where, $params] = $this->where($tenantId, $query, 'a', 'a.on_behalf_of_profile_id', 'a.agent_principal_id');
            // @tenant-guard-ignore: tenant_id predicate is in $where for every workspace; the system tenant (id 0) reads every workspace's history by design, as the audit log does
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM agent_actions a{$where}");
            $stmt->execute($params);
            $total += (int) $stmt->fetchColumn();
        }
        if ($scope['routes']) {
            [$where, $params] = $this->where($tenantId, $query, 'e', 'e.actor_profile_id', null);
            $where .= ($where === '' ? ' WHERE ' : ' AND ') . "(e.verdict IS NOT NULL OR e.action IN ('signed', 'declined', 'sent_back'))";
            // @tenant-guard-ignore: tenant_id predicate is in $where for every workspace; the system tenant (id 0) reads every workspace's history by design, as the audit log does
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM document_route_events e{$where}");
            $stmt->execute($params);
            $total += (int) $stmt->fetchColumn();
        }

        return $total;
    }

    /**
     * The workspace (all of them for the system tenant), the person, the agent
     * and the dates, as the audit log filters them.
     *
     * @param array<string, mixed> $query
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function where(int $tenantId, array $query, string $alias, string $personColumn, ?string $agentColumn): array
    {
        $at = $alias === 'e' ? 'e.occurred_at' : 'a.created_at';
        $conditions = [];
        $params = [];
        if ($tenantId !== self::SYSTEM_TENANT_ID) {
            $conditions[] = "{$alias}.tenant_id = :tenant_id";
            $params[':tenant_id'] = $tenantId;
        }
        if (isset($query['actor']) && ctype_digit((string) $query['actor'])) {
            $conditions[] = "{$personColumn} = :actor";
            $params[':actor'] = (int) $query['actor'];
        }
        if ($agentColumn !== null && isset($query['agent']) && $query['agent'] !== '') {
            $conditions[] = "{$agentColumn} = :agent";
            $params[':agent'] = (string) $query['agent'];
        }
        if (isset($query['from']) && $query['from'] !== '') {
            $conditions[] = "{$at} >= :from";
            $params[':from'] = (string) $query['from'];
        }
        if (isset($query['to']) && $query['to'] !== '') {
            $conditions[] = "{$at} <= :to";
            $params[':to'] = (string) $query['to'];
        }

        return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $params];
    }
}
