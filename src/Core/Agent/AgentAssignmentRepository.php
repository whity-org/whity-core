<?php

declare(strict_types=1);

namespace Whity\Core\Agent;

use PDO;
use Whity\Mcp\Auth\AgentScopeGrant;
use Whity\Mcp\Auth\McpScope;

/**
 * Storage for agent assignments (WHIT-816, migration 181): one person's use of
 * one workspace agent, with that person's grant for it.
 *
 * Every read and write is scoped to the tenant, and the person-facing ones to
 * the person too. An assignment is never deleted here: stopping is
 * `revoked_at`, which also revokes the credentials bound to it, so history
 * keeps naming the agent.
 */
final class AgentAssignmentRepository
{
    /** The stable principal id of an assignment's credentials. */
    public const PRINCIPAL_PREFIX = 'agent:';

    private const COLUMNS = 'a.id, a.tenant_id, a.agent_id, a.profile_id, a.grant_modes, a.revoked_at, a.created_at, a.updated_at';

    public function __construct(
        private readonly PDO $db,
        // WHIT-992: tells plugins the agent's connections were disconnected.
        private readonly ?\Whity\Mcp\Auth\McpConnectionEvents $connectionEvents = null,
    ) {
    }

    public static function principalId(int $assignmentId): string
    {
        return self::PRINCIPAL_PREFIX . $assignmentId;
    }

    /** The assignment id in `agent:<id>`, or null for any other principal. */
    public static function idFromPrincipal(string $principalId): ?int
    {
        if (preg_match('/^agent:([1-9][0-9]*)$/', $principalId, $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }

    /**
     * The scope entries an assignment contributes on every call: the agent's
     * toolsets and the person's grant. Anything else in a credential's scope
     * (its capabilities) stays as issued.
     *
     * @param list<string>          $toolsets
     * @param array<string, string> $grant
     * @return list<string>
     */
    public static function scopeEntries(array $toolsets, array $grant): array
    {
        $entries = array_map(static fn (string $t): string => McpScope::TOOLSET_PREFIX . $t, $toolsets);
        ksort($grant);
        foreach ($grant as $permission => $mode) {
            $entries[] = AgentScopeGrant::PREFIX . $permission . '=' . $mode;
        }

        return $entries;
    }

    /**
     * What a credential bound to an assignment may use right now: the agent's
     * toolsets and the person's grant, or null when the assignment is revoked,
     * the agent archived or paused, or either is not the token's person and
     * tenant. Every way an agent runs passes through here or activeScopeBySlug.
     *
     * @return array{principalId: string, agentId: int, slug: string, name: string, entries: list<string>}|null
     */
    public function activeScope(int $assignmentId, int $tenantId, int $profileId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT a.id, a.agent_id, a.grant_modes, g.toolsets, g.slug, g.name
               FROM agent_assignments a
               JOIN agents g ON g.id = a.agent_id AND g.tenant_id = a.tenant_id
              WHERE a.id = :id AND a.tenant_id = :tenant_id AND a.profile_id = :profile
                AND a.revoked_at IS NULL AND g.status = :active AND g.paused_at IS NULL'
        );
        $stmt->execute([':id' => $assignmentId, ':tenant_id' => $tenantId, ':profile' => $profileId, ':active' => AgentRepository::ACTIVE]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::activeFrom($row) : null;
    }

    /**
     * The same, found by the agent's slug: what an OAuth token issued for
     * `/mcp/agents/<slug>` acts as (WHIT-819).
     *
     * @return array{principalId: string, agentId: int, slug: string, name: string, entries: list<string>}|null
     */
    public function activeScopeBySlug(string $slug, int $tenantId, int $profileId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT a.id, a.agent_id, a.grant_modes, g.toolsets, g.slug, g.name
               FROM agents g
               JOIN agent_assignments a ON a.agent_id = g.id AND a.tenant_id = g.tenant_id
              WHERE g.slug = :slug AND g.tenant_id = :tenant_id AND a.profile_id = :profile
                AND a.revoked_at IS NULL AND g.status = :active AND g.paused_at IS NULL'
        );
        $stmt->execute([':slug' => $slug, ':tenant_id' => $tenantId, ':profile' => $profileId, ':active' => AgentRepository::ACTIVE]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::activeFrom($row) : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{principalId: string, agentId: int, slug: string, name: string, entries: list<string>}
     */
    private static function activeFrom(array $row): array
    {
        return [
            'principalId' => self::principalId((int) $row['id']),
            'agentId'     => (int) $row['agent_id'],
            'slug'        => (string) $row['slug'],
            'name'        => (string) $row['name'],
            'entries'     => self::scopeEntries(self::stringList($row['toolsets']), self::grantMap($row['grant_modes'])),
        ];
    }

    /**
     * The workspace agent an `agent:<id>` principal acts as, while its
     * assignment is active, for the session briefing (WHIT-817).
     *
     * @return array<string, mixed>|null in {@see AgentRepository}'s shape
     */
    public function personaFor(string $principalId, int $tenantId, AgentRepository $agents): ?array
    {
        $assignmentId = self::idFromPrincipal($principalId);
        if ($assignmentId === null) {
            return null;
        }
        $stmt = $this->db->prepare(
            'SELECT agent_id FROM agent_assignments WHERE id = :id AND tenant_id = :tenant_id AND revoked_at IS NULL'
        );
        $stmt->execute([':id' => $assignmentId, ':tenant_id' => $tenantId]);
        $agentId = $stmt->fetchColumn();
        if ($agentId === false) {
            return null;
        }
        $agent = $agents->find((int) $agentId, $tenantId);

        return $agent !== null && $agent['status'] === AgentRepository::ACTIVE && $agent['paused_at'] === null ? $agent : null;
    }

    /** Whether the agent behind an assignment is paused, so a refusal can say so (WHIT-867). */
    public function isPaused(int $assignmentId, int $tenantId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT g.paused_at
               FROM agent_assignments a
               JOIN agents g ON g.id = a.agent_id AND g.tenant_id = a.tenant_id
              WHERE a.id = :id AND a.tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $assignmentId, ':tenant_id' => $tenantId]);
        $pausedAt = $stmt->fetchColumn();

        return $pausedAt !== false && $pausedAt !== null;
    }

    /** The same, by the agent's slug. */
    public function isPausedSlug(string $slug, int $tenantId): bool
    {
        $stmt = $this->db->prepare('SELECT paused_at FROM agents WHERE slug = :slug AND tenant_id = :tenant_id');
        $stmt->execute([':slug' => $slug, ':tenant_id' => $tenantId]);
        $pausedAt = $stmt->fetchColumn();

        return $pausedAt !== false && $pausedAt !== null;
    }

    /**
     * Which of these principals belong to a paused agent.
     *
     * @param list<string> $principalIds
     * @return list<string>
     */
    public function pausedPrincipals(array $principalIds, int $tenantId): array
    {
        $ids = [];
        foreach ($principalIds as $principalId) {
            $id = self::idFromPrincipal($principalId);
            if ($id !== null) {
                $ids[$id] = true;
            }
        }
        if ($ids === []) {
            return [];
        }
        $marks = implode(', ', array_fill(0, count($ids), '?'));
        $stmt  = $this->db->prepare(
            "SELECT a.id
               FROM agent_assignments a
               JOIN agents g ON g.id = a.agent_id AND g.tenant_id = a.tenant_id
              WHERE a.tenant_id = ? AND g.paused_at IS NOT NULL AND a.id IN ({$marks})"
        );
        $stmt->execute([$tenantId, ...array_keys($ids)]);

        /** @var list<mixed> $values */
        $values = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return array_map(static fn (mixed $id): string => self::principalId((int) $id), $values);
    }

    /**
     * Note that an agent just made a call, reads included (WHIT-867). Throttled
     * like {@see \Whity\Mcp\Auth\McpTokenService::recordUse()}: a minute's
     * precision answers "last active", and an agent makes dozens of calls a minute.
     */
    public function recordActivity(string $principalId, int $tenantId, ?\DateTimeImmutable $now = null): void
    {
        $assignmentId = self::idFromPrincipal($principalId);
        if ($assignmentId === null) {
            return;
        }
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $stmt = $this->db->prepare(
            'UPDATE agent_assignments SET last_active_at = :now
              WHERE id = :id AND tenant_id = :tenant_id AND (last_active_at IS NULL OR last_active_at < :cutoff)'
        );
        $stmt->execute([
            ':now'       => $now->format('Y-m-d H:i:s'),
            ':id'        => $assignmentId,
            ':tenant_id' => $tenantId,
            ':cutoff'    => $now->modify('-60 seconds')->format('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<string, mixed>|null the person's assignment for an agent, revoked or not */
    public function findFor(int $agentId, int $profileId, int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNS . ' FROM agent_assignments a
              WHERE a.agent_id = :agent AND a.profile_id = :profile AND a.tenant_id = :tenant_id'
        );
        $stmt->execute([':agent' => $agentId, ':profile' => $profileId, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::normalize($row) : null;
    }

    /** @return array<int, array<string, mixed>> the person's active assignments, by agent id */
    public function activeForPerson(int $profileId, int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNS . ' FROM agent_assignments a
              WHERE a.profile_id = :profile AND a.tenant_id = :tenant_id AND a.revoked_at IS NULL'
        );
        $stmt->execute([':profile' => $profileId, ':tenant_id' => $tenantId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $assignment = self::normalize($row);
            $out[$assignment['agent_id']] = $assignment;
        }

        return $out;
    }

    /**
     * Grant an agent for a person, starting from $grant: a new assignment, or
     * the revoked one brought back with the fresh grant. Returns its id.
     *
     * @param array<string, string> $grant already clamped to the person
     */
    public function assign(int $agentId, int $profileId, int $tenantId, array $grant): int
    {
        $existing = $this->findFor($agentId, $profileId, $tenantId);
        if ($existing !== null) {
            $stmt = $this->db->prepare(
                'UPDATE agent_assignments SET grant_modes = :grant, revoked_at = NULL, updated_by_profile_id = :profile, updated_at = NOW()
                  WHERE id = :id AND tenant_id = :tenant_id'
            );
            $stmt->execute([':grant' => self::encodeGrant($grant), ':profile' => $profileId, ':id' => $existing['id'], ':tenant_id' => $tenantId]);

            return (int) $existing['id'];
        }

        $stmt = $this->db->prepare(
            'INSERT INTO agent_assignments (tenant_id, agent_id, profile_id, grant_modes, updated_by_profile_id, created_at, updated_at)
             VALUES (:tenant_id, :agent, :profile, :grant, :profile, NOW(), NOW())'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':agent' => $agentId, ':profile' => $profileId, ':grant' => self::encodeGrant($grant)]);

        return (int) $this->db->lastInsertId();
    }

    /** @param array<string, string> $grant */
    public function setGrant(int $assignmentId, int $tenantId, int $profileId, array $grant): void
    {
        $stmt = $this->db->prepare(
            'UPDATE agent_assignments SET grant_modes = :grant, updated_by_profile_id = :profile, updated_at = NOW()
              WHERE id = :id AND tenant_id = :tenant_id AND profile_id = :profile'
        );
        $stmt->execute([':grant' => self::encodeGrant($grant), ':profile' => $profileId, ':id' => $assignmentId, ':tenant_id' => $tenantId]);
    }

    /**
     * Stop using an agent: mark the assignment revoked and revoke every
     * credential bound to it. Returns how many credentials were revoked.
     */
    public function revoke(int $assignmentId, int $tenantId, int $profileId): int
    {
        $stmt = $this->db->prepare(
            'UPDATE agent_assignments SET revoked_at = NOW(), updated_by_profile_id = :profile, updated_at = NOW()
              WHERE id = :id AND tenant_id = :tenant_id AND profile_id = :profile AND revoked_at IS NULL'
        );
        $stmt->execute([':profile' => $profileId, ':id' => $assignmentId, ':tenant_id' => $tenantId]);

        $tokens = $this->db->prepare(
            'SELECT jti, expires_at FROM mcp_tokens WHERE agent_assignment_id = :id AND tenant_id = :tenant_id'
        );
        $tokens->execute([':id' => $assignmentId, ':tenant_id' => $tenantId]);
        $revoked = 0;
        $insert = $this->db->prepare('INSERT INTO revoked_tokens (jti, expires_at) VALUES (:jti, :expires_at) ON CONFLICT (jti) DO NOTHING');
        foreach ($tokens->fetchAll(PDO::FETCH_ASSOC) as $token) {
            $insert->execute([':jti' => $token['jti'], ':expires_at' => $token['expires_at']]);
            if ($insert->rowCount() === 1) {
                $this->connectionEvents?->revoked((string) $token['jti'], $tenantId, $profileId, \Whity\Mcp\Auth\McpConnectionEvents::BY_AGENT_REMOVED);
            }
            $revoked++;
        }

        return $revoked;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalize(array $row): array
    {
        return [
            'id'         => (int) $row['id'],
            'tenant_id'  => (int) $row['tenant_id'],
            'agent_id'   => (int) $row['agent_id'],
            'profile_id' => (int) $row['profile_id'],
            'grant'      => self::grantMap($row['grant_modes']),
            'revoked_at' => $row['revoked_at'] === null ? null : (string) $row['revoked_at'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /** @return array<string, string> */
    private static function grantMap(mixed $raw): array
    {
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $permission => $mode) {
            if (is_string($permission) && is_string($mode) && in_array($mode, AgentScopeGrant::MODES, true)) {
                $out[$permission] = $mode;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private static function stringList(mixed $raw): array
    {
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    /** @param array<string, string> $grant */
    private static function encodeGrant(array $grant): string
    {
        ksort($grant);

        return (string) json_encode((object) $grant, JSON_UNESCAPED_SLASHES);
    }
}
