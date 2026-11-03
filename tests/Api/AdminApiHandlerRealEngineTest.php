<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\MockRequestFactory;
use Whity\Api\AdminApiHandler;
use Whity\Core\Tenant\TenantContext;
use Whity\Core\Request;
use Whity\Database\Database;

/**
 * Real-engine (in-memory SQLite) tests for {@see AdminApiHandler::stats()} (WC-50).
 *
 * These tests drive the handler against a genuine SQL engine seeded with two
 * tenants' data and assert:
 *
 *  - a workspace is refused, however its role is named (H.3, WHIT-872), and
 *  - the system user (tenant 0) sees the platform-wide totals.
 *
 * The handler issues a few PostgreSQL-only statements for the database-health and
 * growth sections (`NOW()`, `pg_size_pretty(...)`, `SHOW server_version`). A thin
 * PDO subclass rewrites the two health statements to SQLite equivalents and a
 * `NOW()` UDF is registered, so the whole `stats()` body runs unmodified end to
 * end against the real engine.
 */
final class AdminApiHandlerRealEngineTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = self::makeSqliteSchema();
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    /**
     * Server statistics are for the system tenant's operators (H.3, WHIT-872). The
     * seeded `admin` is one global role, so a workspace owner holds `stats:read`
     * too: the tenant is what refuses them, and nothing of theirs comes back.
     */
    public function testAWorkspaceIsRefused(): void
    {
        $this->seedUser(1, 1, 1);

        MockRequestFactory::setTestTenant(1);
        $response = $this->respond();

        $this->assertSame(403, $response->getStatusCode());
        $this->assertArrayNotHasKey('stats', json_decode($response->getBody(), true));
    }

    /**
     * The system user (tenant 0) retains the platform-wide totals.
     */
    public function testSystemUserSeesGlobalTotals(): void
    {
        $this->seedUser(1, 1, 1);
        $this->seedUser(2, 2, 1);
        $this->seedUser(3, 2, 1);

        MockRequestFactory::setTestTenant(0);
        $stats = $this->stats();

        $this->assertSame(3, $stats['totals']['users'], 'The system user sees every tenant\'s users.');
        $this->assertSame(3, $stats['totals']['tenants'], 'The system user sees every tenant (incl. system).');
        $this->assertSame(3, $stats['totals']['roles'], 'The system user sees all roles.');

        $roleNames = array_column($stats['breakdown']['users_per_role'], 'name');
        $this->assertContains('tenant-b-role', $roleNames, 'The system user sees every tenant\'s roles.');
    }

    /**
     * A null/unresolved tenant context is not the system tenant, so it is refused
     * rather than falling through to the global counts.
     */
    public function testUnresolvedContextIsRefused(): void
    {
        $this->seedUser(1, 1, 1);

        TenantContext::reset();

        $this->assertSame(403, $this->respond()->getStatusCode());
    }

    // ==================== Helpers ====================

    /**
     * Run stats() and return the decoded `stats` payload.
     *
     * @return array<string, mixed>
     */
    private function stats(): array
    {
        $response = $this->respond();

        $this->assertSame(200, $response->getStatusCode(), $response->getBody());

        return json_decode($response->getBody(), true)['stats'];
    }

    private function respond(): \Whity\Core\Response
    {
        $pdo = $this->pdo;
        $database = Database::withFactory(static fn (): PDO => $pdo);

        return (new AdminApiHandler($database, sys_get_temp_dir()))->stats(new Request('GET', '/api/admin/stats', []));
    }

    private function seedUser(int $id, int $tenantId, int $roleId): void
    {
        // WC-d88de9fa: stats() counts memberships (ADR 0005 §3). Identity is on the
        // profile model; the legacy `users` table was retired by migration 042.
        // Seed a membership so the aggregate counts match the seeded members.
        $mStmt = $this->pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at)
             VALUES (?, ?, ?, 'active', datetime('now'))"
        );
        $mStmt->execute([$id, $tenantId, $roleId]);
    }

    private static function makeSqliteSchema(): PDO
    {
        $pdo = new class ('sqlite::memory:') extends PDO {
            /**
             * Translate the handler's unchanged PostgreSQL SQL fragments to
             * SQLite-safe equivalents so the whole stats() body runs against the
             * real engine. Only dialect — never the tenant-scoping logic under
             * test — is rewritten:
             *  - `NOW() - INTERVAL '7 days'` (PG interval) → SQLite datetime().
             *  - `pg_size_pretty(...)` / `SHOW server_version` (PG health probes).
             */
            private static function translate(string $sql): string
            {
                if (str_contains($sql, 'pg_size_pretty')) {
                    return "SELECT '1 MB'";
                }
                if (str_contains($sql, 'server_version')) {
                    return "SELECT '16.0'";
                }

                return (string) preg_replace(
                    "/NOW\(\)\s*-\s*INTERVAL\s*'7 days'/i",
                    "datetime('now', '-7 days')",
                    $sql
                );
            }

            public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
            {
                $query = self::translate($query);

                return $fetchMode === null
                    ? parent::query($query)
                    : parent::query($query, $fetchMode, ...$fetchModeArgs);
            }

            /**
             * @param array<int|string, mixed> $options
             */
            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                return parent::prepare(self::translate($query), $options);
            }
        };

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // The growth queries use PostgreSQL's NOW(); register a UDF for the
        // remaining bare NOW() calls. SQLite's native DATE() covers DATE(created_at).
        $pdo->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'), 0);

        $pdo->exec('CREATE TABLE tenants (id INTEGER PRIMARY KEY, name TEXT, created_at TEXT)');
        $pdo->exec("
            INSERT INTO tenants (id, name, created_at) VALUES
                (0, 'system',   datetime('now')),
                (1, 'tenant-a', datetime('now')),
                (2, 'tenant-b', datetime('now'))
        ");

        $pdo->exec('
            CREATE TABLE roles (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL UNIQUE,
                tenant_id INTEGER,
                created_at TEXT
            )
        ');
        $pdo->exec("
            INSERT INTO roles (id, name, tenant_id, created_at) VALUES
                (1,   'admin',         NULL, datetime('now')),
                (100, 'tenant-a-role', 1,    datetime('now')),
                (200, 'tenant-b-role', 2,    datetime('now'))
        ");

        $pdo->exec('
            CREATE TABLE permissions (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL UNIQUE
            )
        ');
        $pdo->exec("INSERT INTO permissions (id, name) VALUES (1, 'users:read'), (2, 'roles:read')");

        // WC-d88de9fa: stats() now counts memberships (ADR 0005 §3).
        // role_id is NOT NULL to match production migration 030 (avoid masking a
        // real constraint the handler SQL relies on).
        $pdo->exec('
            CREATE TABLE memberships (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                profile_id INTEGER NOT NULL,
                tenant_id INTEGER NOT NULL,
                role_id INTEGER NOT NULL,
                ou_id INTEGER,
                -- mirrors migration 094; the counts here are per TENANT
                -- OCCUPANCY and filter on it. This fixture is hand-built,
                -- so it does not follow migrations automatically.
                is_primary INTEGER NOT NULL DEFAULT 1,
                status TEXT NOT NULL DEFAULT \'active\',
                created_at TEXT
            )
        ');

        // The handler also queries the migrations table (unscoped, infra metadata).
        $pdo->exec('CREATE TABLE core_schema_migrations (id INTEGER PRIMARY KEY, name TEXT)');

        return $pdo;
    }
}
