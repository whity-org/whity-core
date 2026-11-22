<?php

declare(strict_types=1);

namespace Whity\Core\Audit;

use PDO;

/**
 * The audit trail's hash chain (WHIT-1339, readiness gap CO-5).
 *
 * Each `audit_log` row gets one row in `audit_log_seals`: the previous seal's
 * hash and this row's hash, which covers the previous hash and the row's
 * columns. Changing, removing or reordering a sealed row therefore breaks its
 * own seal and every seal after it, and {@see verify()} says where.
 *
 * Sealing happens after the fact, in id order, by the `audit.seal` job and
 * `whity-cli audit:seal`, not when the row is written: hashing at insert would
 * put every audit write in the install behind one lock. A row is sealed once it
 * is older than the settle time, so a transaction still open when its id was
 * handed out has committed by then. A row that commits later still (an id below
 * the head with no seal) is reported by verify() as unsealed, never skipped
 * silently.
 *
 * The chain is install-wide, not per workspace: it is evidence for the
 * operator and the auditor. The database refuses UPDATE and DELETE on both
 * tables (migration 348), so the seal is the second line: it shows tampering by
 * someone who could get past that refusal. Keeping the head the job logs off
 * the host is what makes the chain hold against the database owner too.
 */
final class AuditSeals
{
    /** The hash of nothing: the first seal's previous hash. */
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /** The column set a version-1 seal covers, in hashing order. Never change it; add a version. */
    public const COLUMNS_V1 = [
        'id',
        'tenant_id',
        'actor_user_id',
        'action',
        'target_type',
        'target_id',
        'metadata',
        'ip_address',
        'created_at',
        'actor_kind',
        'agent_principal_id',
        'on_behalf_of_profile_id',
    ];

    public const VERSION = 1;

    /** Rows newer than this are left for the next run (seconds). */
    public const SETTLE_SECONDS = 300;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Seal up to $max settled rows after the head, in id order.
     *
     * @return array{sealed: int, last_audit_id: int, head_hash: string, sealed_count: int}
     */
    public function seal(int $max = 5000, int $settleSeconds = self::SETTLE_SECONDS): array
    {
        $max = max(1, $max);
        $settleSeconds = max(0, $settleSeconds);
        $pgsql = $this->isPgsql();

        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $head = $this->pdo->query(
                'SELECT last_audit_id, head_hash, sealed_count FROM audit_log_chain WHERE id = 1' . ($pgsql ? ' FOR UPDATE' : '')
            );
            $chain = $head === false ? false : $head->fetch(PDO::FETCH_ASSOC);
            if ($chain === false) {
                throw new \RuntimeException('audit_log_chain has no head row; run migrations');
            }
            $last = (int) $chain['last_audit_id'];
            $prev = (string) $chain['head_hash'];
            $count = (int) $chain['sealed_count'];

            // An int, so it is written into the SQL rather than bound.
            $settled = $pgsql
                ? 'created_at <= NOW() - make_interval(secs => ' . $settleSeconds . ')'
                : "created_at <= datetime('now', '-" . $settleSeconds . " seconds')";
            // @tenant-guard-ignore: the seal chain is install-wide by design (WHIT-1339); it reads every workspace's rows to hash them and returns nothing to any workspace
            $rows = $this->pdo->prepare(
                'SELECT ' . implode(', ', self::COLUMNS_V1) . ' FROM audit_log
                  WHERE id > :last AND ' . $settled . '
                  ORDER BY id ASC
                  LIMIT ' . $max
            );
            $rows->execute(['last' => $last]);

            $insert = $this->pdo->prepare(
                'INSERT INTO audit_log_seals (audit_id, version, prev_hash, row_hash) VALUES (:audit_id, :version, :prev, :hash)'
            );
            $sealed = 0;
            while (($row = $rows->fetch(PDO::FETCH_ASSOC)) !== false) {
                $hash = self::rowHash($prev, $row);
                $insert->execute([
                    'audit_id' => (int) $row['id'],
                    'version' => self::VERSION,
                    'prev' => $prev,
                    'hash' => $hash,
                ]);
                $prev = $hash;
                $last = (int) $row['id'];
                $sealed++;
            }

            if ($sealed > 0) {
                $count += $sealed;
                $update = $this->pdo->prepare(
                    'UPDATE audit_log_chain
                        SET last_audit_id = :last, head_hash = :head, sealed_count = :count, updated_at = ' . ($pgsql ? 'NOW()' : "datetime('now')") . '
                      WHERE id = 1'
                );
                $update->execute(['last' => $last, 'head' => $prev, 'count' => $count]);
            }

            if ($ownTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return ['sealed' => $sealed, 'last_audit_id' => $last, 'head_hash' => $prev, 'sealed_count' => $count];
    }

    /**
     * Recompute the chain from the start.
     *
     * `ok` is false when a sealed row was changed or removed, a seal is out of
     * order, or the head doesn't match the seals. Rows below the head with no
     * seal (committed after the head passed them) are counted in
     * `unsealed_below_head`; they are not covered by the chain, so they are a
     * finding of their own but don't break it.
     *
     * @return array{ok: bool, checked: int, broken_at: ?int, reason: ?string, unsealed_below_head: int, head_hash: string, last_audit_id: int}
     */
    public function verify(int $batch = 5000): array
    {
        $head = $this->pdo->query('SELECT last_audit_id, head_hash, sealed_count FROM audit_log_chain WHERE id = 1');
        $chain = $head === false ? false : $head->fetch(PDO::FETCH_ASSOC);
        if ($chain === false) {
            return self::result(false, 0, null, 'the chain head is missing', 0, '', 0);
        }
        $headId = (int) $chain['last_audit_id'];
        $headHash = (string) $chain['head_hash'];

        // A seal whose row is gone: the row was deleted.
        // @tenant-guard-ignore: the seal chain is install-wide by design (WHIT-1339); verification reads every workspace's rows and returns only a verdict
        $orphan = $this->pdo->query(
            'SELECT MIN(s.audit_id) FROM audit_log_seals s LEFT JOIN audit_log a ON a.id = s.audit_id WHERE a.id IS NULL'
        );
        $orphanId = $orphan === false ? null : $orphan->fetchColumn();
        if ($orphanId !== null && $orphanId !== false) {
            return self::result(false, 0, (int) $orphanId, 'a sealed audit row was deleted', 0, $headHash, $headId);
        }

        $columns = implode(', ', array_map(static fn (string $c): string => 'a.' . $c, self::COLUMNS_V1));
        // @tenant-guard-ignore: the seal chain is install-wide by design (WHIT-1339); verification reads every workspace's rows and returns only a verdict
        $page = $this->pdo->prepare(
            'SELECT ' . $columns . ', s.version AS seal_version, s.prev_hash AS seal_prev, s.row_hash AS seal_hash
               FROM audit_log a
               LEFT JOIN audit_log_seals s ON s.audit_id = a.id
              WHERE a.id > :after AND a.id <= :head
              ORDER BY a.id ASC
              LIMIT ' . max(1, $batch)
        );

        $prev = self::GENESIS;
        $checked = 0;
        $unsealed = 0;
        $after = 0;
        while (true) {
            $page->execute(['after' => $after, 'head' => $headId]);
            $rows = $page->fetchAll(PDO::FETCH_ASSOC);
            if ($rows === []) {
                break;
            }
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $after = $id;
                if ($row['seal_hash'] === null) {
                    $unsealed++;
                    continue;
                }
                if ((int) $row['seal_version'] !== self::VERSION) {
                    return self::result(false, $checked, $id, 'unknown seal version', $unsealed, $headHash, $headId);
                }
                if ((string) $row['seal_prev'] !== $prev) {
                    return self::result(false, $checked, $id, 'the seal does not follow the one before it', $unsealed, $headHash, $headId);
                }
                $expected = self::rowHash($prev, $row);
                if (!hash_equals($expected, (string) $row['seal_hash'])) {
                    return self::result(false, $checked, $id, 'the row no longer matches its seal', $unsealed, $headHash, $headId);
                }
                $prev = $expected;
                $checked++;
            }
        }

        if (!hash_equals($headHash, $prev)) {
            return self::result(false, $checked, $headId, 'the chain head does not match the last seal', $unsealed, $headHash, $headId);
        }
        if ($checked !== (int) $chain['sealed_count']) {
            return self::result(false, $checked, null, 'the number of seals does not match the chain head', $unsealed, $headHash, $headId);
        }

        return self::result(true, $checked, null, null, $unsealed, $headHash, $headId);
    }

    /**
     * The hash of one row, chained to the previous one.
     *
     * The row is written as a JSON list of the version and the
     * {@see COLUMNS_V1} values in order, every value as a string or null.
     * `metadata` is decoded and re-encoded with sorted keys, so the hash doesn't
     * depend on how the engine spells the same JSON.
     *
     * @param array<string, mixed> $row
     */
    public static function rowHash(string $prev, array $row): string
    {
        $values = [self::VERSION];
        foreach (self::COLUMNS_V1 as $column) {
            $value = $row[$column] ?? null;
            if ($column === 'metadata') {
                $value = self::canonicalJson($value);
            } elseif ($value !== null) {
                $value = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
            }
            $values[] = $value;
        }

        return hash('sha256', $prev . "\n" . json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private static function canonicalJson(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $decoded = json_decode((string) $value, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return (string) $value;
        }

        return json_encode(self::sortKeys($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::sortKeys($item);
        }

        return $value;
    }

    private function isPgsql(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
    }

    /** @return array{ok: bool, checked: int, broken_at: ?int, reason: ?string, unsealed_below_head: int, head_hash: string, last_audit_id: int} */
    private static function result(bool $ok, int $checked, ?int $brokenAt, ?string $reason, int $unsealed, string $head, int $last): array
    {
        return [
            'ok' => $ok,
            'checked' => $checked,
            'broken_at' => $brokenAt,
            'reason' => $reason,
            'unsealed_below_head' => $unsealed,
            'head_hash' => $head,
            'last_audit_id' => $last,
        ];
    }
}
