<?php

declare(strict_types=1);

namespace Tests\Core\Document\Numbering;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Document\Numbering\DocumentNumbering;
use Whity\Sdk\Schema\SchemaInspector;

/**
 * A document is numbered exactly once (WHIT-684), proved with two real connections
 * to one database and the interleaving driven by hand, as
 * {@see \Tests\Integration\SequenceAllocatorConcurrencyTest} does: connection A
 * claims and holds the row, connection B is shown to block rather than proceed on a
 * stale read, and once A commits B gets A's number and the counter has moved once.
 *
 * Runs on both engines. CI covers PostgreSQL in the merge queue's dialect shards.
 */
final class DocumentNumberingConcurrencyTest extends TestCase
{
    private const TENANT = 0;

    private const PATTERN = 'CRT-{YYYY}-{SEQ:4}';

    /** @var array{PDO, PDO}|null */
    private static ?array $connections = null;

    private static ?string $sqliteFile = null;

    private PDO $a;
    private PDO $b;

    public static function tearDownAfterClass(): void
    {
        self::$connections = null;
        if (self::$sqliteFile !== null && file_exists(self::$sqliteFile)) {
            @unlink(self::$sqliteFile);
        }
        self::$sqliteFile = null;
    }

    protected function setUp(): void
    {
        self::$connections ??= $this->twoConnections();
        [$this->a, $this->b] = self::$connections;
        foreach ([$this->a, $this->b] as $pdo) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
        $this->clearTimeout($this->b);
        $this->a->exec('DELETE FROM documents');
        $this->a->exec('DELETE FROM sequence_counters');
    }

    /** @return array{PDO, PDO} */
    private function twoConnections(): array
    {
        $dsn = $_ENV['PHPUNIT_PG_DSN'] ?? getenv('PHPUNIT_PG_DSN') ?: null;
        if ($dsn !== null) {
            $a = SchemaFromMigrations::make();
            // B follows A rather than the env DSN: the harness may have cloned a database.
            $database = (string) $this->scalar($a, 'SELECT current_database()');
            $schema = (string) $this->scalar($a, 'SELECT current_schema()');
            $target = preg_match('/\bdbname=/i', (string) $dsn) === 1
                ? (string) preg_replace('/\bdbname=[^;]*/i', 'dbname=' . $database, (string) $dsn)
                : rtrim((string) $dsn, ';') . ';dbname=' . $database;
            $b = new PDO(
                $target,
                (string) ($_ENV['PHPUNIT_PG_USER'] ?? getenv('PHPUNIT_PG_USER') ?: 'whity'),
                (string) ($_ENV['PHPUNIT_PG_PASSWORD'] ?? getenv('PHPUNIT_PG_PASSWORD') ?: 'whity_dev'),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
            );
            $b->exec('SET search_path TO "' . $schema . '", public');

            return [$a, $b];
        }

        // SQLite's `:memory:` is one connection's, so two connections need a file,
        // holding the schema the migrations built.
        $file = tempnam(sys_get_temp_dir(), 'whity_num_') ?: sys_get_temp_dir() . '/whity_num_' . uniqid();
        self::$sqliteFile = $file;
        $a = $this->openSqlite($file);
        $b = $this->openSqlite($file);
        $template = SchemaFromMigrations::make();
        $objects = $template->query("SELECT sql FROM sqlite_master WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%'");
        self::assertNotFalse($objects);
        $a->beginTransaction();
        foreach ($objects->fetchAll(PDO::FETCH_COLUMN) as $ddl) {
            $a->exec((string) $ddl);
        }
        $tenants = $template->query('SELECT id, name FROM tenants');
        self::assertNotFalse($tenants);
        $insert = $a->prepare('INSERT INTO tenants (id, name) VALUES (:id, :name)');
        foreach ($tenants->fetchAll(PDO::FETCH_ASSOC) as $tenant) {
            $insert->execute([':id' => $tenant['id'], ':name' => $tenant['name']]);
        }
        $a->commit();

        return [$a, $b];
    }

    private function scalar(PDO $pdo, string $sql): mixed
    {
        $stmt = $pdo->query($sql);
        self::assertNotFalse($stmt);

        return $stmt->fetchColumn();
    }

    private function openSqlite(string $file): PDO
    {
        $pdo = new PDO('sqlite:' . $file);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'), 0);

        return $pdo;
    }

    private function armTimeout(PDO $pdo): void
    {
        if (SchemaInspector::driver($pdo) === 'pgsql') {
            $pdo->exec("SET statement_timeout = '300ms'");

            return;
        }
        $pdo->exec('PRAGMA busy_timeout = 100');
    }

    private function clearTimeout(PDO $pdo): void
    {
        if (SchemaInspector::driver($pdo) === 'pgsql') {
            $pdo->exec('SET statement_timeout = 0');

            return;
        }
        $pdo->exec('PRAGMA busy_timeout = 5000');
    }

    private function document(PDO $pdo, string $title = 'Handover', ?string $pattern = self::PATTERN): int
    {
        $stmt = $pdo->prepare(
            "INSERT INTO documents (tenant_id, template_name, title, number_pattern, created_at)
             VALUES (:tenant, 'T', :title, :pattern, '2026-01-01 00:00:00')"
        );
        $stmt->execute([':tenant' => self::TENANT, ':title' => $title, ':pattern' => $pattern]);

        return (int) $pdo->lastInsertId();
    }

    private function counterTotal(): int
    {
        return (int) $this->scalar($this->a, 'SELECT COALESCE(SUM(value), 0) FROM sequence_counters');
    }

    public function testTwoApproversFinishingTogetherGetOneNumberAndTheCounterMovesOnce(): void
    {
        $id = $this->document($this->a);
        $numberingA = new DocumentNumbering($this->a);
        $numberingB = new DocumentNumbering($this->b);

        // A has the approval's transaction open, holding the claim.
        $this->a->beginTransaction();
        $first = $numberingA->assign(self::TENANT, $id);
        self::assertTrue($first['minted']);
        self::assertNotNull($first['number']);

        // B must wait. If it went ahead on what it could read, it would mint a second.
        $this->armTimeout($this->b);
        try {
            $numberingB->assign(self::TENANT, $id);
            self::fail('The second approver went ahead while the first held the claim');
        } catch (PDOException) {
            // Blocked, which is the proof. assign() rolled its own transaction back.
        }
        self::assertFalse($this->b->inTransaction());
        $this->clearTimeout($this->b);

        $this->a->commit();

        $second = $numberingB->assign(self::TENANT, $id);
        self::assertFalse($second['minted'], 'the second approver must not mint');
        self::assertSame($first['number'], $second['number']);
        self::assertSame(1, $this->counterTotal(), 'the lost race must burn no number');
    }

    public function testAReplayChangesNothing(): void
    {
        $id = $this->document($this->a);
        $numbering = new DocumentNumbering($this->a);

        $first = $numbering->assign(self::TENANT, $id, new \DateTimeImmutable('2026-12-31 23:00:00 UTC'));
        $replay = $numbering->assign(self::TENANT, $id, new \DateTimeImmutable('2027-01-01 01:00:00 UTC'));

        self::assertSame('CRT-2026-0001', $first['number']);
        self::assertSame('CRT-2026-0001', $replay['number'], 'the year is the assignment instant, and it is never recomputed');
        self::assertFalse($replay['minted']);
        self::assertSame(1, $this->counterTotal());
    }

    public function testARolledBackAssignmentTakesItsNumberBack(): void
    {
        $id = $this->document($this->a);
        $numbering = new DocumentNumbering($this->a);

        $this->a->beginTransaction();
        $numbering->assign(self::TENANT, $id, new \DateTimeImmutable('2026-06-01 UTC'));
        $this->a->rollBack();

        self::assertTrue($numbering->isPending(self::TENANT, $id));
        self::assertSame('CRT-2026-0001', $numbering->assign(self::TENANT, $id, new \DateTimeImmutable('2026-06-01 UTC'))['number']);
    }

    public function testDifferentDocumentsGetDifferentNumbersAndAYearRestartsTheSeries(): void
    {
        $numbering = new DocumentNumbering($this->a);
        $june = new \DateTimeImmutable('2026-06-01 UTC');

        self::assertSame('CRT-2026-0001', $numbering->assign(self::TENANT, $this->document($this->a, 'One'), $june)['number']);
        self::assertSame('CRT-2026-0002', $numbering->assign(self::TENANT, $this->document($this->a, 'Two'), $june)['number']);
        self::assertSame(
            'CRT-2027-0001',
            $numbering->assign(self::TENANT, $this->document($this->a, 'Three'), new \DateTimeImmutable('2027-01-01 UTC'))['number'],
        );
        self::assertSame(
            'PKL-2026-0001',
            $numbering->assign(self::TENANT, $this->document($this->a, 'Other kind', 'PKL-{YYYY}-{SEQ:4}'), $june)['number'],
            'each kind has its own series',
        );
    }

    public function testADocumentWithNoRuleIsNeverNumbered(): void
    {
        $id = $this->document($this->a, 'Old', null);
        $numbering = new DocumentNumbering($this->a);

        self::assertFalse($numbering->isPending(self::TENANT, $id));
        self::assertSame(['number' => null, 'minted' => false], $numbering->assign(self::TENANT, $id));
        self::assertSame(0, $this->counterTotal());
    }
}
