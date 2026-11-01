<?php

declare(strict_types=1);

namespace Whity\Core\Agent;

use PDO;

/**
 * Storage for the undo log (WHIT-812, migration 182): what an agent did that
 * its person can still undo, and who undid it.
 *
 * Every query is bound to a tenant; listing and undoing are further bound to
 * the person the agent acted for. Worker-safe: holds only the PDO.
 */
final class AgentActionRepository
{
    public const DONE    = 'done';
    public const UNDONE  = 'undone';
    /** Not stored: a done action whose undo window has closed reads as expired. */
    public const EXPIRED = 'expired';

    private const COLUMNS = 'a.id, a.tenant_id, a.agent_principal_id, a.on_behalf_of_profile_id, a.proposal_id, a.tool,
                             a.arguments, a.summary, a.permissions, a.inverse, a.undo_window_seconds, a.undoable_until, a.status,
                             a.undone_by_profile_id, a.undone_at, a.undo_result, a.created_at, p.display_name AS undone_by_name,
                             t.name AS agent_name';

    /** Who undid it, and the agent credential's name (null once it is gone, or for a signed-in agent). */
    private const JOINS = 'LEFT JOIN profiles p ON p.id = a.undone_by_profile_id
                           LEFT JOIN mcp_tokens t ON t.jti = a.agent_principal_id';

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param array<string, mixed>      $arguments
     * @param list<string>              $permissions
     * @param array<string, mixed>|null $inverse       null: it can't be undone
     */
    public function record(
        int $tenantId,
        string $agentPrincipalId,
        int $onBehalfOfProfileId,
        ?int $proposalId,
        string $tool,
        array $arguments,
        string $summary,
        array $permissions,
        ?array $inverse,
        ?int $windowSeconds,
    ): int {
        $undoable = $inverse !== null && $windowSeconds !== null;
        $stmt     = $this->db->prepare(
            'INSERT INTO agent_actions
                 (tenant_id, agent_principal_id, on_behalf_of_profile_id, proposal_id, tool, arguments, summary,
                  permissions, inverse, undo_window_seconds, undoable_until, status, created_at)
             VALUES (:tenant_id, :agent, :profile, :proposal, :tool, :arguments, :summary,
                  :permissions, :inverse, :window, :until, :status, NOW())'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':agent'     => $agentPrincipalId,
            ':profile'   => $onBehalfOfProfileId,
            ':proposal'  => $proposalId,
            ':tool'      => $tool,
            ':arguments' => self::encode($arguments === [] ? new \stdClass() : $arguments),
            ':summary'     => $summary,
            ':permissions' => self::encode($permissions),
            ':inverse'     => $undoable ? self::encode($inverse) : null,
            ':window'      => $undoable ? $windowSeconds : null,
            ':until'       => $undoable ? gmdate('Y-m-d H:i:s', time() + (int) $windowSeconds) : null,
            ':status'    => self::DONE,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id, int $tenantId): ?array
    {
        $stmt = $this->db->prepare('SELECT ' . self::COLUMNS . ' FROM agent_actions a ' . self::JOINS . '
            WHERE a.id = :id AND a.tenant_id = :tenant_id');
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::normalize($row) : null;
    }

    /**
     * A person's agent actions, newest first: since a UTC time, and with a
     * status (done, undone, or expired: done with its window closed).
     *
     * @return list<array<string, mixed>>
     */
    public function listFor(int $tenantId, int $profileId, int $limit = 50, ?string $since = null, ?string $status = null): array
    {
        $now    = gmdate('Y-m-d H:i:s');
        $sql    = 'SELECT ' . self::COLUMNS . ' FROM agent_actions a ' . self::JOINS . '
            WHERE a.tenant_id = :tenant_id AND a.on_behalf_of_profile_id = :profile';
        $params = [':tenant_id' => $tenantId, ':profile' => $profileId];
        if ($since !== null) {
            $sql .= ' AND a.created_at >= :since';
            $params[':since'] = $since;
        }
        if ($status === self::UNDONE) {
            $sql .= ' AND a.status = :undone';
            $params[':undone'] = self::UNDONE;
        } elseif ($status === self::DONE) {
            $sql .= ' AND a.status = :done AND (a.undoable_until IS NULL OR a.undoable_until > :now)';
            $params[':done'] = self::DONE;
            $params[':now']  = $now;
        } elseif ($status === self::EXPIRED) {
            $sql .= ' AND a.status = :done AND a.undoable_until <= :now';
            $params[':done'] = self::DONE;
            $params[':now']  = $now;
        }
        $stmt = $this->db->prepare($sql . ' ORDER BY a.id DESC LIMIT :limit');
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', max(1, min($limit, 200)), PDO::PARAM_INT);
        $stmt->execute();

        return array_map(self::normalize(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * The actions approved proposals ran, by proposal id.
     *
     * @param list<int> $proposalIds
     * @return array<int, array<string, mixed>>
     */
    public function forProposals(int $tenantId, array $proposalIds): array
    {
        if ($proposalIds === []) {
            return [];
        }
        $marks = implode(', ', array_fill(0, count($proposalIds), '?'));
        $stmt  = $this->db->prepare('SELECT ' . self::COLUMNS . ' FROM agent_actions a ' . self::JOINS . "
            WHERE a.tenant_id = ? AND a.proposal_id IN ({$marks})");
        $stmt->execute([$tenantId, ...$proposalIds]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $action = self::normalize($row);
            $out[(int) $action['proposal_id']] = $action;
        }
        return $out;
    }

    /**
     * Mark a done action undone, inside its window. True only for the one
     * caller that made the move.
     */
    public function markUndone(int $id, int $tenantId, int $profileId, mixed $result): bool
    {
        $now  = gmdate('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'UPDATE agent_actions
                SET status = :undone, undone_by_profile_id = :profile, undone_at = :now, undo_result = :result
              WHERE id = :id AND tenant_id = :tenant_id AND status = :done AND undoable_until > :now2'
        );
        $stmt->execute([
            ':undone' => self::UNDONE, ':profile' => $profileId, ':now' => $now, ':result' => self::encode($result),
            ':id' => $id, ':tenant_id' => $tenantId, ':done' => self::DONE, ':now2' => $now,
        ]);
        return $stmt->rowCount() === 1;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalize(array $row): array
    {
        $json = static function (mixed $value, mixed $default): mixed {
            if (!is_string($value) || $value === '') {
                return $default;
            }
            return json_decode($value, true) ?? $default;
        };

        return [
            'id'                      => (int) $row['id'],
            'tenant_id'               => (int) $row['tenant_id'],
            'agent_principal_id'      => (string) $row['agent_principal_id'],
            'on_behalf_of_profile_id' => (int) $row['on_behalf_of_profile_id'],
            'proposal_id'             => $row['proposal_id'] === null ? null : (int) $row['proposal_id'],
            'tool'                    => (string) $row['tool'],
            'arguments'               => (array) $json($row['arguments'], []),
            'summary'                 => (string) $row['summary'],
            'permissions'             => array_values(array_filter((array) $json($row['permissions'], []), 'is_string')),
            'inverse'                 => $json($row['inverse'], null),
            'undo_window_seconds'     => $row['undo_window_seconds'] === null ? null : (int) $row['undo_window_seconds'],
            'undoable_until'          => $row['undoable_until'] === null ? null : (string) $row['undoable_until'],
            'status'                  => (string) $row['status'],
            'undone_by_profile_id'    => $row['undone_by_profile_id'] === null ? null : (int) $row['undone_by_profile_id'],
            'undone_by_name'          => $row['undone_by_name'] === null ? null : (string) $row['undone_by_name'],
            'undone_at'               => $row['undone_at'] === null ? null : (string) $row['undone_at'],
            'undo_result'             => $json($row['undo_result'], null),
            'created_at'              => (string) $row['created_at'],
            'agent_name'              => $row['agent_name'] === null ? null : (string) $row['agent_name'],
        ];
    }

    private static function encode(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
