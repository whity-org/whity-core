<?php

declare(strict_types=1);

namespace Whity\Core\Document\Agent;

use PDO;

/**
 * Data access for `document_agent_requests` (editor M6, migration 260): a
 * person's "Ask an agent" from inside the editor, waiting for the person's own
 * agent to pick it up over MCP (owner's answer Q3).
 *
 * TENANT-OWNED: every statement binds an explicit `tenant_id` predicate
 * (literal SQL, so the CI tenant-predicate guard verifies it).
 *
 * Claiming is `UPDATE … WHERE status = 'queued'` on one row, so two agents
 * asking at once can't both take it.
 */
final class DocumentAgentRequestRepository
{
    public const QUEUED = 'queued';
    public const RUNNING = 'running';
    public const DONE = 'done';
    public const FAILED = 'failed';
    public const STOPPED = 'stopped';

    /** What an agent may report. */
    public const REPORTABLE = [self::RUNNING, self::DONE, self::FAILED, self::STOPPED];
    public const FINISHED = [self::DONE, self::FAILED, self::STOPPED];

    /** A request nobody picked up within this long is not handed out any more. */
    public const QUEUE_MINUTES = 60;

    private const COLUMNS = 'id, tenant_id, source_id, requested_by_profile_id, agent_principal_id, claimed_by_principal_id,
        request, anchor_block_id, quote, status, title, progress_done, progress_total, detail,
        stop_requested_at, claimed_at, finished_at, created_at, updated_at';

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param array{agent_principal_id: ?string, request: string, anchor_block_id: ?string, quote: ?string} $rec
     */
    public function create(int $tenantId, int $sourceId, int $profileId, array $rec): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO document_agent_requests
                (tenant_id, source_id, requested_by_profile_id, agent_principal_id, request, anchor_block_id, quote, status, created_at, updated_at)
             VALUES (:tenant_id, :source_id, :profile, :agent, :request, :anchor, :quote, 'queued', NOW(), NOW())"
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':source_id' => $sourceId,
            ':profile'   => $profileId,
            ':agent'     => $rec['agent_principal_id'],
            ':request'   => $rec['request'],
            ':anchor'    => $rec['anchor_block_id'],
            ':quote'     => $rec['quote'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id, int $tenantId): ?array
    {
        $stmt = $this->db->prepare('SELECT ' . self::COLUMNS . ' FROM document_agent_requests WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::row($row) : null;
    }

    /**
     * A person's latest requests on one document, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function forSource(int $tenantId, int $sourceId, int $profileId, int $limit = 20): array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNS . ' FROM document_agent_requests
              WHERE tenant_id = :tenant_id AND source_id = :source_id AND requested_by_profile_id = :profile
              ORDER BY id DESC LIMIT ' . max(1, min(100, $limit))
        );
        $stmt->execute([':tenant_id' => $tenantId, ':source_id' => $sourceId, ':profile' => $profileId]);

        return self::rows($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Hands the oldest waiting request of this person to this agent: one asked
     * of it by name, or of any of the person's agents. Null when none waits.
     *
     * @return array<string, mixed>|null
     */
    public function claimNext(int $tenantId, int $profileId, string $principalId, ?\DateTimeImmutable $now = null): ?array
    {
        $now ??= new \DateTimeImmutable('now');
        $since = $now->modify('-' . self::QUEUE_MINUTES . ' minutes')->format('Y-m-d H:i:s');
        $pick = $this->db->prepare(
            "SELECT id FROM document_agent_requests
              WHERE tenant_id = :tenant_id AND requested_by_profile_id = :profile AND status = 'queued'
                AND (agent_principal_id IS NULL OR agent_principal_id = :principal)
                AND created_at >= :since
              ORDER BY id ASC LIMIT 5"
        );
        $pick->execute([':tenant_id' => $tenantId, ':profile' => $profileId, ':principal' => $principalId, ':since' => $since]);
        foreach ($pick->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $claim = $this->db->prepare(
                "UPDATE document_agent_requests
                    SET status = 'running', claimed_by_principal_id = :principal, claimed_at = NOW(), updated_at = NOW()
                  WHERE id = :id AND tenant_id = :tenant_id AND status = 'queued'"
            );
            $claim->execute([':principal' => $principalId, ':id' => (int) $id, ':tenant_id' => $tenantId]);
            if ($claim->rowCount() === 1) {
                return $this->find((int) $id, $tenantId);
            }
        }

        return null;
    }

    /**
     * The claiming agent's report. Only a running request takes one; a
     * finished status also stamps finished_at.
     *
     * @param array{status: string, title: ?string, progress_done: ?int, progress_total: ?int, detail: ?string} $report
     */
    public function report(int $id, int $tenantId, string $principalId, array $report): bool
    {
        $finished = in_array($report['status'], self::FINISHED, true);
        $stmt = $this->db->prepare(
            "UPDATE document_agent_requests
                SET status = :status,
                    title = COALESCE(:title, title),
                    progress_done = COALESCE(:done, progress_done),
                    progress_total = COALESCE(:total, progress_total),
                    detail = COALESCE(:detail, detail),
                    finished_at = " . ($finished ? 'NOW()' : 'NULL') . ",
                    updated_at = NOW()
              WHERE id = :id AND tenant_id = :tenant_id AND claimed_by_principal_id = :principal AND status = 'running'"
        );
        $stmt->execute([
            ':status'    => $report['status'],
            ':title'     => $report['title'],
            ':done'      => $report['progress_done'],
            ':total'     => $report['progress_total'],
            ':detail'    => $report['detail'],
            ':id'        => $id,
            ':tenant_id' => $tenantId,
            ':principal' => $principalId,
        ]);

        return $stmt->rowCount() === 1;
    }

    /**
     * The person pressed Stop. A request nobody picked up stops at once; a
     * running one is told on its agent's next update.
     */
    public function requestStop(int $id, int $tenantId, int $profileId): bool
    {
        $queued = $this->db->prepare(
            "UPDATE document_agent_requests
                SET status = 'stopped', stop_requested_at = NOW(), finished_at = NOW(), updated_at = NOW()
              WHERE id = :id AND tenant_id = :tenant_id AND requested_by_profile_id = :profile AND status = 'queued'"
        );
        $queued->execute([':id' => $id, ':tenant_id' => $tenantId, ':profile' => $profileId]);
        if ($queued->rowCount() === 1) {
            return true;
        }
        $running = $this->db->prepare(
            "UPDATE document_agent_requests
                SET stop_requested_at = COALESCE(stop_requested_at, NOW()), updated_at = NOW()
              WHERE id = :id AND tenant_id = :tenant_id AND requested_by_profile_id = :profile AND status = 'running'"
        );
        $running->execute([':id' => $id, ':tenant_id' => $tenantId, ':profile' => $profileId]);

        return $running->rowCount() === 1;
    }

    /**
     * @param array<int, mixed> $rows
     * @return list<array<string, mixed>>
     */
    private static function rows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = self::row($row);
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function row(array $row): array
    {
        $int = static fn (mixed $v): ?int => $v !== null ? (int) $v : null;
        $str = static fn (mixed $v): ?string => $v !== null ? (string) $v : null;

        return [
            'id'                      => (int) $row['id'],
            'tenant_id'               => (int) $row['tenant_id'],
            'source_id'               => (int) $row['source_id'],
            'requested_by_profile_id' => (int) $row['requested_by_profile_id'],
            'agent_principal_id'      => $str($row['agent_principal_id']),
            'claimed_by_principal_id' => $str($row['claimed_by_principal_id']),
            'request'                 => (string) $row['request'],
            'anchor_block_id'         => $str($row['anchor_block_id']),
            'quote'                   => $str($row['quote']),
            'status'                  => (string) $row['status'],
            'title'                   => $str($row['title']),
            'progress_done'           => $int($row['progress_done']),
            'progress_total'          => $int($row['progress_total']),
            'detail'                  => $str($row['detail']),
            'stop_requested_at'       => $str($row['stop_requested_at']),
            'claimed_at'              => $str($row['claimed_at']),
            'finished_at'             => $str($row['finished_at']),
            'created_at'              => (string) $row['created_at'],
            'updated_at'              => (string) $row['updated_at'],
        ];
    }
}
