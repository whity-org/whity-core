<?php

declare(strict_types=1);

namespace Whity\Tests\Unit\Database;

use PDO;
use PHPUnit\Framework\TestCase;
use Whity\Database\Database;
use Whity\Database\Migration\Backfill;
use Whity\Database\Migration\Baseline;
use Whity\Database\Migration\SafeIndex;

/**
 * The helpers migrations use to change big tables safely (WHIT-1240), on
 * SQLite. SafeIndex's PostgreSQL branch (CONCURRENTLY, invalid-index cleanup)
 * is exercised wherever a migration uses it on the PostgreSQL shards.
 */
final class BackfillAndSafeIndexTest extends TestCase
{
    private PDO $pdo;
    private Database $db;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo = $this->pdo;
        $this->db = Database::withFactory(static fn(): PDO => $pdo, 86400, 86400);

        $this->pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, tenant_id INTEGER, state TEXT, v2 TEXT)');
        $insert = $this->pdo->prepare('INSERT INTO items (id, tenant_id, state) VALUES (?, ?, ?)');
        // Ids with gaps, so batches by key range are not batches by count.
        foreach ([3, 4, 9, 10, 11, 12, 40, 41, 42, 100, 101] as $i => $id) {
            $insert->execute([$id, $i % 2 + 1, $i % 3 === 0 ? 'old' : 'new']);
        }
    }

    public function testBackfillTouchesEveryMatchingRowInBatches(): void
    {
        $changed = Backfill::inBatches($this->db, 'items', "v2 = UPPER(state) || :suffix", 'v2 IS NULL', [':suffix' => '!'], batchSize: 3);

        $this->assertSame(11, $changed);
        $this->assertSame(0, (int) Baseline::scalar($this->pdo, 'SELECT COUNT(*) FROM items WHERE v2 IS NULL'));
        $this->assertSame('OLD!', Baseline::scalar($this->pdo, 'SELECT v2 FROM items WHERE id = 3'));

        // Idempotent: a second run finds nothing left to do.
        $this->assertSame(0, Backfill::inBatches($this->db, 'items', "v2 = 'again'", 'v2 IS NULL', batchSize: 3));
    }

    public function testBackfillHonoursItsWhere(): void
    {
        $changed = Backfill::inBatches($this->db, 'items', "v2 = 'x'", "state = 'old'", batchSize: 2);

        $this->assertSame(4, $changed);
        $this->assertSame(4, (int) Baseline::scalar($this->pdo, "SELECT COUNT(*) FROM items WHERE v2 = 'x'"));
    }

    public function testDeleteInBatches(): void
    {
        $this->assertSame(4, Backfill::deleteInBatches($this->db, 'items', "state = 'old'", batchSize: 2));
        $this->assertSame(7, (int) Baseline::scalar($this->pdo, 'SELECT COUNT(*) FROM items'));
    }

    public function testBackfillOnAnEmptyTable(): void
    {
        $this->pdo->exec('DELETE FROM items');

        $this->assertSame(0, Backfill::inBatches($this->db, 'items', "v2 = 'x'"));
    }

    public function testBackfillRefusesToRunInsideATransaction(): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->expectException(\LogicException::class);
            Backfill::inBatches($this->db, 'items', "v2 = 'x'");
        } finally {
            $this->pdo->rollBack();
        }
    }

    public function testSafeIndexIsAPlainIdempotentIndexOnSqlite(): void
    {
        SafeIndex::create($this->db, 'idx_items_tenant_state', 'items', 'tenant_id, state', where: "state = 'new'");
        SafeIndex::create($this->db, 'idx_items_tenant_state', 'items', 'tenant_id, state', where: "state = 'new'");
        SafeIndex::create($this->db, 'uq_items_v2', 'items', 'v2', unique: true);

        $indexes = Baseline::column($this->pdo, "SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'items' ORDER BY name");
        $this->assertSame(['idx_items_tenant_state', 'uq_items_v2'], $indexes);

        SafeIndex::drop($this->db, 'uq_items_v2');
        SafeIndex::drop($this->db, 'uq_items_v2');
        $this->assertSame(['idx_items_tenant_state'], Baseline::column($this->pdo, "SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'items'"));
    }
}
