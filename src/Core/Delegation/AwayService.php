<?php

declare(strict_types=1);

namespace Whity\Core\Delegation;

use PDO;

/**
 * "I'm away" (OR.1, `TplAway`): while a person is away, someone they trust
 * decides for them.
 *
 * A person sets it from their own account: the day it starts, the day they are
 * back, who decides for them, and what goes to that person:
 *  - `flows`: approvals in flows (purchases, leave, expense claims);
 *  - `proposals`: their agents' proposals;
 *  - `documents`: documents to sign.
 * A message tells people who write to them. Coming back early ends it at once.
 *
 * It is in force from the start day up to (not including) the day they are
 * back. Whatever the delegate decides is recorded as theirs, on the away
 * person's behalf; anything above what the delegate may approve still waits
 * for the person (the callers check the delegate's own permissions).
 *
 * One open period per person: setting a new one replaces the one before.
 */
final class AwayService
{
    public const FLOWS = 'flows';
    public const PROPOSALS = 'proposals';
    public const DOCUMENTS = 'documents';
    public const COVERS = [self::FLOWS, self::PROPOSALS, self::DOCUMENTS];

    public const STARTING = 'starting';
    public const IN_FORCE = 'in_force';
    public const ENDED = 'ended';

    /** Longest an away period may run, in days. */
    public const MAX_DAYS = 366;

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * The person's open period (not ended, not yet back), or null.
     *
     * @return array<string, mixed>|null
     */
    public function current(int $tenantId, int $profileId, ?string $today = null): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNS . '
               FROM away_periods a
               JOIN profiles d ON d.id = a.delegate_profile_id
              WHERE a.tenant_id = :tenant_id AND a.profile_id = :profile_id
                AND a.ended_at IS NULL AND a.back_on > :today
              ORDER BY a.id DESC LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':profile_id' => $profileId, ':today' => $today ?? self::today()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        return is_array($row) ? $this->present($row, $today) : null;
    }

    /**
     * Hand over: replaces any open period of the person's.
     *
     * @param list<string> $covers
     * @return array<string, mixed>|string the period, or why it was refused:
     *   'self', 'delegate', 'dates', 'covers'
     */
    public function start(
        int $tenantId,
        int $profileId,
        int $delegateProfileId,
        string $startsOn,
        string $backOn,
        array $covers,
        ?string $message,
        ?string $today = null,
    ): array|string {
        $today ??= self::today();
        if ($delegateProfileId === $profileId) {
            return 'self';
        }
        if (!$this->isActiveMember($tenantId, $delegateProfileId)) {
            return 'delegate';
        }
        if (!self::isDate($startsOn) || !self::isDate($backOn) || $backOn <= $startsOn || $backOn <= $today
            || (strtotime($backOn) - strtotime($startsOn)) / 86400 > self::MAX_DAYS
        ) {
            return 'dates';
        }
        $covers = array_values(array_intersect(self::COVERS, $covers));
        if ($covers === []) {
            return 'covers';
        }
        $message = $message !== null && trim($message) !== '' ? mb_substr(trim($message), 0, 500) : null;

        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }
        try {
            $this->endOpen($tenantId, $profileId);
            $this->db->prepare(
                'INSERT INTO away_periods (tenant_id, profile_id, delegate_profile_id, starts_on, back_on, covers, message, created_at, updated_at)
                 VALUES (:tenant_id, :profile_id, :delegate, :starts_on, :back_on, :covers, :message, NOW(), NOW())'
            )->execute([
                ':tenant_id'  => $tenantId,
                ':profile_id' => $profileId,
                ':delegate'   => $delegateProfileId,
                ':starts_on'  => $startsOn,
                ':back_on'    => $backOn,
                ':covers'     => json_encode($covers, JSON_THROW_ON_ERROR),
                ':message'    => $message,
            ]);
            if ($ownTx) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return $this->current($tenantId, $profileId, $today) ?? 'dates';
    }

    /** Back early: ends the person's open period at once. True when there was one. */
    public function end(int $tenantId, int $profileId): bool
    {
        return $this->endOpen($tenantId, $profileId) > 0;
    }

    /**
     * Who the delegate decides for today, for one kind of decision.
     *
     * @return list<int> profile ids of the people away
     */
    public function coveredBy(int $tenantId, int $delegateProfileId, string $cover, ?string $today = null): array
    {
        $today ??= self::today();
        $stmt = $this->db->prepare(
            'SELECT profile_id, covers FROM away_periods
              WHERE tenant_id = :tenant_id AND delegate_profile_id = :delegate
                AND ended_at IS NULL AND starts_on <= :today AND back_on > :today'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':delegate' => $delegateProfileId, ':today' => $today]);
        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (in_array($cover, self::decodeCovers($row['covers']), true)) {
                $ids[] = (int) $row['profile_id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Whether $delegateProfileId decides $profileId's $cover today.
     */
    public function decidesFor(int $tenantId, int $delegateProfileId, int $profileId, string $cover, ?string $today = null): bool
    {
        return in_array($profileId, $this->coveredBy($tenantId, $delegateProfileId, $cover, $today), true);
    }

    /**
     * Every away period in the workspace, newest first, for the Delegations list.
     *
     * @return list<array<string, mixed>>
     */
    public function listForTenant(int $tenantId, int $limit = 100, ?string $today = null): array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNS . ', p.display_name AS profile_name
               FROM away_periods a
               JOIN profiles d ON d.id = a.delegate_profile_id
               JOIN profiles p ON p.id = a.profile_id
              WHERE a.tenant_id = :tenant_id
              ORDER BY a.id DESC LIMIT :limit'
        );
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(1, min($limit, 200)), PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn (array $row): array => $this->present($row, $today), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private const COLUMNS = 'a.id, a.profile_id, a.delegate_profile_id, a.starts_on, a.back_on, a.covers, a.message,
                             a.ended_at, a.created_at, d.display_name AS delegate_name';

    private function endOpen(int $tenantId, int $profileId): int
    {
        $stmt = $this->db->prepare(
            'UPDATE away_periods SET ended_at = NOW(), updated_at = NOW()
              WHERE tenant_id = :tenant_id AND profile_id = :profile_id AND ended_at IS NULL'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':profile_id' => $profileId]);

        return $stmt->rowCount();
    }

    private function isActiveMember(int $tenantId, int $profileId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM memberships WHERE tenant_id = :tenant_id AND profile_id = :profile_id AND status = 'active' LIMIT 1"
        );
        $stmt->execute([':tenant_id' => $tenantId, ':profile_id' => $profileId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row, ?string $today): array
    {
        $today ??= self::today();
        $startsOn = substr((string) $row['starts_on'], 0, 10);
        $backOn = substr((string) $row['back_on'], 0, 10);
        $status = match (true) {
            $row['ended_at'] !== null || $backOn <= $today => self::ENDED,
            $startsOn > $today => self::STARTING,
            default => self::IN_FORCE,
        };

        return [
            'id'          => (int) $row['id'],
            'profile'     => ['id' => (int) $row['profile_id'], 'name' => isset($row['profile_name']) ? (string) $row['profile_name'] : null],
            'delegate'    => ['id' => (int) $row['delegate_profile_id'], 'name' => (string) $row['delegate_name']],
            'startsOn'    => $startsOn,
            'backOn'      => $backOn,
            'covers'      => self::decodeCovers($row['covers']),
            'message'     => $row['message'] !== null ? (string) $row['message'] : null,
            'status'      => $status,
            'endedAt'     => $row['ended_at'] !== null ? (string) $row['ended_at'] : null,
            'createdAt'   => (string) $row['created_at'],
        ];
    }

    /** @return list<string> */
    private static function decodeCovers(mixed $json): array
    {
        $decoded = is_string($json) ? json_decode($json, true) : null;

        return is_array($decoded) ? array_values(array_intersect(self::COVERS, $decoded)) : [];
    }

    private static function isDate(string $value): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $d !== false && $d->format('Y-m-d') === $value;
    }

    private static function today(): string
    {
        return gmdate('Y-m-d');
    }
}
