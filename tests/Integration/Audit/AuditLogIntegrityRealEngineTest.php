<?php

declare(strict_types=1);

namespace Tests\Integration\Audit;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Audit\AuditSeals;

/**
 * The audit trail can't be changed, survives its workspace, and shows
 * tampering (WHIT-1339; readiness gaps CO-5 and CO-6; migrations 347 and 348).
 *
 * Runs on SQLite in the unit job and on PostgreSQL in the integration shards,
 * so the triggers are proven on both engines.
 */
final class AuditLogIntegrityRealEngineTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Example Cafe', 'example-cafe')");
    }

    // ── Refusal ───────────────────────────────────────────────────────────────

    public function testAnAuditRowCannotBeChanged(): void
    {
        $id = $this->row(1, 'role.updated');

        $this->expectException(PDOException::class);
        $this->pdo->exec("UPDATE audit_log SET action = 'nothing.happened' WHERE id = {$id}");
    }

    public function testAnAuditRowCannotBeDeleted(): void
    {
        $id = $this->row(1, 'role.updated');

        $this->expectException(PDOException::class);
        $this->pdo->exec("DELETE FROM audit_log WHERE id = {$id}");
    }

    public function testASealCannotBeChangedOrDeleted(): void
    {
        $this->row(1, 'role.updated');
        (new AuditSeals($this->pdo))->seal(100, 0);

        foreach (["UPDATE audit_log_seals SET row_hash = prev_hash", 'DELETE FROM audit_log_seals'] as $sql) {
            try {
                $this->pdo->exec($sql);
                self::fail('the seals table accepted: ' . $sql);
            } catch (PDOException) {
                self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM audit_log_seals'));
            }
        }
    }

    public function testDeletingAWorkspaceKeepsItsAuditTrail(): void
    {
        $this->row(2, 'tenant.updated');
        $this->row(2, 'role.created');

        $this->pdo->exec('DELETE FROM tenants WHERE id = 2');

        self::assertSame(
            2,
            $this->scalar('SELECT COUNT(*) FROM audit_log WHERE tenant_id = 2'),
            'deleting the workspace deleted its audit trail'
        );
    }

    // ── The chain ─────────────────────────────────────────────────────────────

    public function testSettledRowsAreSealedOnceAndTheChainHolds(): void
    {
        $this->row(1, 'role.created');
        $this->row(2, 'user.created', ['email_domain' => 'example.com', 'roles' => ['b', 'a']]);
        $this->row(1, 'auth.login.success');

        $seals = new AuditSeals($this->pdo);
        $first = $seals->seal(100, 0);
        self::assertSame(3, $first['sealed']);
        self::assertSame(0, $seals->seal(100, 0)['sealed'], 'a row was sealed twice');

        $verdict = $seals->verify();
        self::assertTrue($verdict['ok'], (string) $verdict['reason']);
        self::assertSame(3, $verdict['checked']);
        self::assertSame($first['head_hash'], $verdict['head_hash']);
    }

    public function testRowsAreSealedInBatchesThatChainOnFromEachOther(): void
    {
        for ($i = 0; $i < 7; $i++) {
            $this->row(1, 'thing.done');
        }

        $seals = new AuditSeals($this->pdo);
        $runs = [];
        for ($i = 0; $i < 3; $i++) {
            $runs[] = $seals->seal(3, 0)['sealed'];
        }
        self::assertSame([3, 3, 1], $runs);

        self::assertTrue($seals->verify(2)['ok']);
    }

    public function testARowStillSettlingWaitsForTheNextRun(): void
    {
        $this->row(1, 'role.created', [], gmdate('Y-m-d H:i:s'));

        self::assertSame(0, (new AuditSeals($this->pdo))->seal(100, 300)['sealed']);
    }

    public function testVerifyFindsAChangedRow(): void
    {
        $this->row(1, 'role.created');
        $changed = $this->row(1, 'role.updated');
        $this->row(1, 'role.deleted');
        $seals = new AuditSeals($this->pdo);
        $seals->seal(100, 0);

        $this->withoutRefusal(function () use ($changed): void {
            $this->pdo->exec("UPDATE audit_log SET action = 'role.viewed' WHERE id = {$changed}");
        });

        $verdict = $seals->verify();
        self::assertFalse($verdict['ok']);
        self::assertSame($changed, $verdict['broken_at']);
        self::assertSame(1, $verdict['checked'], 'rows before the change still verify');
    }

    public function testVerifyFindsAChangeInsideTheMetadata(): void
    {
        $changed = $this->row(1, 'role.updated', ['permissions' => ['users:read']]);
        $seals = new AuditSeals($this->pdo);
        $seals->seal(100, 0);

        $this->withoutRefusal(function () use ($changed): void {
            $this->pdo->exec("UPDATE audit_log SET metadata = '{\"permissions\":[\"users:write\"]}' WHERE id = {$changed}");
        });

        self::assertSame($changed, $seals->verify()['broken_at']);
    }

    public function testVerifyFindsADeletedRow(): void
    {
        $this->row(1, 'role.created');
        $deleted = $this->row(1, 'role.updated');
        $seals = new AuditSeals($this->pdo);
        $seals->seal(100, 0);

        $this->withoutRefusal(function () use ($deleted): void {
            $this->pdo->exec("DELETE FROM audit_log WHERE id = {$deleted}");
        });

        $verdict = $seals->verify();
        self::assertFalse($verdict['ok']);
        self::assertSame($deleted, $verdict['broken_at']);
    }

    public function testARowThatCommittedAfterSealingPassedItIsReportedNotHidden(): void
    {
        $this->row(1, 'role.created', [], null, 10);
        $this->row(1, 'role.updated', [], null, 12);
        $seals = new AuditSeals($this->pdo);
        $seals->seal(100, 0);

        // Id 11 was handed out first but committed after the run sealed 12.
        $this->row(1, 'role.deleted', [], null, 11);

        $verdict = $seals->verify();
        self::assertTrue($verdict['ok'], (string) $verdict['reason']);
        self::assertSame(1, $verdict['unsealed_below_head']);
        self::assertSame(0, $seals->seal(100, 0)['sealed'], 'a row below the head was sealed out of order');
    }

    public function testTheHashDoesNotDependOnHowTheEngineSpellsTheJson(): void
    {
        $row = ['id' => 1, 'tenant_id' => 1, 'action' => 'x', 'metadata' => '{"b": 1, "a": [2, 1]}', 'created_at' => '2026-01-01 00:00:00'];
        $same = ['metadata' => '{"a":[2,1],"b":1}'] + $row;

        self::assertSame(AuditSeals::rowHash(AuditSeals::GENESIS, $row), AuditSeals::rowHash(AuditSeals::GENESIS, $same));
        self::assertNotSame(
            AuditSeals::rowHash(AuditSeals::GENESIS, $row),
            AuditSeals::rowHash(AuditSeals::GENESIS, ['metadata' => '{"a":[1,2],"b":1}'] + $row),
            'list order is meaning, and must change the hash'
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $metadata */
    private function row(int $tenantId, string $action, array $metadata = [], ?string $createdAt = '2026-01-01 00:00:00', ?int $id = null): int
    {
        $createdAt ??= '2026-01-01 00:00:00';
        $columns = 'tenant_id, action, metadata, created_at' . ($id !== null ? ', id' : '');
        $values = ':tenant, :action, :metadata, :created' . ($id !== null ? ', :id' : '');
        $params = [
            'tenant' => $tenantId,
            'action' => $action,
            'metadata' => json_encode($metadata === [] ? new \stdClass() : $metadata),
            'created' => $createdAt,
        ];
        if ($id !== null) {
            $params['id'] = $id;
        }
        $this->pdo->prepare("INSERT INTO audit_log ({$columns}) VALUES ({$values})")->execute($params);

        return $id ?? $this->scalar('SELECT MAX(id) FROM audit_log');
    }

    private function scalar(string $sql): int
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt);

        return (int) $stmt->fetchColumn();
    }

    /** Tamper the way only the database owner could: without the triggers. */
    private function withoutRefusal(callable $tamper): void
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
            $this->pdo->exec('DROP TRIGGER IF EXISTS audit_log_append_only ON audit_log');
        } else {
            $this->pdo->exec('DROP TRIGGER IF EXISTS audit_log_no_update');
            $this->pdo->exec('DROP TRIGGER IF EXISTS audit_log_no_delete');
        }
        $tamper();
    }
}
