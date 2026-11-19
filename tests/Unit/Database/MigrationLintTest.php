<?php

declare(strict_types=1);

namespace Whity\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Whity\Database\Migration\MigrationLint;

/**
 * The migration safety lint (WHIT-1240): each rule fires on the pattern it
 * names and stays quiet on the safe form of the same change.
 */
final class MigrationLintTest extends TestCase
{
    private static function migration(string $up, string $extra = ''): string
    {
        return <<<PHP
<?php
namespace Database\\Migrations;

use Whity\\Database\\Database;

{$extra}
final class Example
{
    public static function up(Database \$db): void
    {
        {$up}
    }

    public static function down(Database \$db): void
    {
        \$db->exec('DROP TABLE bookings');
        \$db->exec('CREATE INDEX idx_down ON bookings (x)');
    }
}
PHP;
    }

    /** @return list<array{rule: string, severity: string}> */
    private function lint(string $up, string $extra = ''): array
    {
        $lint = new MigrationLint(['bookings']);

        return array_map(
            static fn(array $f): array => ['rule' => $f['rule'], 'severity' => $f['severity']],
            $lint->lintSource('999_example', self::migration($up, $extra))
        );
    }

    /** @return array<string, array{0: string, 1: list<array{rule: string, severity: string}>}> */
    public static function cases(): array
    {
        $error = static fn(string $rule): array => ['rule' => $rule, 'severity' => 'error'];
        $warning = static fn(string $rule): array => ['rule' => $rule, 'severity' => 'warning'];

        return [
            'index on a large table' => ["\$db->exec('CREATE INDEX IF NOT EXISTS idx_b ON bookings (tenant_id)');", [$error('index-not-concurrent')]],
            'index on a small table' => ["\$db->exec('CREATE INDEX IF NOT EXISTS idx_s ON app_settings (k)');", [$warning('index-not-concurrent')]],
            'concurrent index' => ["\$db->exec('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_b ON bookings (tenant_id)');", []],
            'index on a table made here' => ["\$db->exec('CREATE TABLE IF NOT EXISTS fresh (id INT)'); \$db->exec('CREATE INDEX IF NOT EXISTS idx_f ON fresh (id)');", []],
            'index without IF NOT EXISTS' => ["\$db->exec('CREATE INDEX CONCURRENTLY idx_b ON bookings (tenant_id)');", [$error('not-idempotent')]],
            'table without IF NOT EXISTS' => ["\$db->exec('CREATE TABLE fresh (id INT)');", [$error('not-idempotent')]],
            'serial column' => ["\$db->exec('ALTER TABLE bookings ADD COLUMN IF NOT EXISTS seq BIGSERIAL');", [$error('add-column-rewrite')]],
            'volatile default' => ["\$db->exec('ALTER TABLE bookings ADD COLUMN IF NOT EXISTS ref UUID DEFAULT gen_random_uuid()');", [$error('add-column-rewrite')]],
            'constant default is fine' => ["\$db->exec(\"ALTER TABLE bookings ADD COLUMN IF NOT EXISTS state TEXT NOT NULL DEFAULT 'open'\");", []],
            'now() default is fine' => ["\$db->exec('ALTER TABLE bookings ADD COLUMN IF NOT EXISTS seen_at TIMESTAMP DEFAULT NOW()');", []],
            'not null without default' => ["\$db->exec('ALTER TABLE app_settings ADD COLUMN IF NOT EXISTS k2 TEXT NOT NULL');", [$error('add-column-not-null')]],
            'add column without guard' => ["\$db->exec('ALTER TABLE bookings ADD COLUMN note TEXT');", [$error('not-idempotent')]],
            'type change' => ["\$db->exec('ALTER TABLE bookings ALTER COLUMN amount TYPE BIGINT');", [$error('alter-column-type')]],
            'varchar widening' => ["\$db->exec('ALTER TABLE bookings ALTER COLUMN ref TYPE VARCHAR(160)');", [$warning('alter-column-type')]],
            'set not null' => ["\$db->exec('ALTER TABLE bookings ALTER COLUMN ref SET NOT NULL');", [$error('set-not-null')]],
            'foreign key validated at once' => ["\$db->exec('ALTER TABLE bookings ADD CONSTRAINT fk_b FOREIGN KEY (p) REFERENCES profiles(id)'); // pg_constraint", [$error('constraint-not-valid')]],
            'foreign key NOT VALID' => ["\$db->exec('ALTER TABLE bookings ADD CONSTRAINT fk_b FOREIGN KEY (p) REFERENCES profiles(id) NOT VALID'); // pg_constraint", []],
            'unguarded constraint' => ["\$db->exec('ALTER TABLE bookings ADD CONSTRAINT fk_b FOREIGN KEY (p) REFERENCES profiles(id) NOT VALID');", [$warning('not-idempotent')]],
            'whole-table update' => ["\$db->exec(\"UPDATE bookings SET state = 'open' WHERE state IS NULL\");", [$error('unbatched-backfill')]],
            'update of one row by id' => ["\$db->query('UPDATE bookings SET state = :s WHERE id = :id AND tenant_id = :t', []);", []],
            'small table update with WHERE' => ["\$db->exec(\"UPDATE app_settings SET v = 'x' WHERE k = 'y'\");", []],
            'small table update without WHERE' => ["\$db->exec(\"UPDATE app_settings SET v = 'x'\");", [$warning('unbatched-backfill')]],
            'delete on a large table' => ["\$db->exec(\"DELETE FROM bookings WHERE state = 'gone'\");", [$error('unbatched-backfill')]],
            'lock table' => ["\$db->exec('LOCK TABLE bookings IN ACCESS EXCLUSIVE MODE');", [$error('long-lock')]],
            'vacuum full' => ["\$db->exec('VACUUM FULL bookings');", [$error('long-lock')]],
            'concatenated pieces' => ["\$db->exec('CREATE INDEX IF NOT EXISTS ' . 'idx_b ON bookings (x)');", [$error('index-not-concurrent')]],
            'variable inside a string' => ["\$t = 'bookings'; \$db->exec(\"UPDATE {\$t} SET x = 1\");", [$warning('unbatched-backfill')]],
            'comment is not SQL' => ["// CREATE INDEX idx ON bookings (x)\n        \$x = 1;", []],
        ];
    }

    /** @param list<array{rule: string, severity: string}> $expected */
    #[DataProvider('cases')]
    public function testRule(string $up, array $expected): void
    {
        $this->assertSame($expected, $this->lint($up));
    }

    public function testDownIsNotLinted(): void
    {
        $this->assertSame([], $this->lint('$x = 1;'));
    }

    public function testAllowCommentTurnsARuleOffForTheFile(): void
    {
        $up = "\$db->exec('CREATE INDEX IF NOT EXISTS idx_b ON bookings (tenant_id)');";

        $this->assertSame([], $this->lint($up, '// migration-lint: allow index-not-concurrent (table is new this release and empty)'));
    }

    public function testTablesWithTenantIdAreLarge(): void
    {
        $root = sys_get_temp_dir() . '/whity_lint_' . uniqid();
        mkdir($root . '/database/migrations', 0o755, true);
        file_put_contents($root . '/database/migrations/001_x.php', "<?php\n\$db->exec('CREATE TABLE IF NOT EXISTS orders (\n id INT,\n tenant_id INT NOT NULL\n)');\n\$db->exec('CREATE TABLE IF NOT EXISTS lookups (\n id INT\n)');\n");
        file_put_contents($root . '/database/migration-lint.json', json_encode(['large_tables' => ['audit_trail']]));

        try {
            $lint = MigrationLint::forRepository($root);
            $this->assertTrue($lint->isLarge('orders'));
            $this->assertTrue($lint->isLarge('audit_trail'));
            $this->assertFalse($lint->isLarge('lookups'));
        } finally {
            unlink($root . '/database/migrations/001_x.php');
            unlink($root . '/database/migration-lint.json');
            rmdir($root . '/database/migrations');
            rmdir($root . '/database');
            rmdir($root);
        }
    }

    public function testMigrationsNewerThanTheBaselineHaveNoErrors(): void
    {
        $root = dirname(__DIR__, 3);
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/ci-migration-lint.php') . ' 2>&1', $output, $exit);

        $this->assertSame(0, $exit, "The migration lint fails on a new migration:\n" . implode("\n", $output));
    }
}
