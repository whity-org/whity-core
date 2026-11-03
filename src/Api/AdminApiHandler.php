<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use PDO;

/**
 * Admin API Handler
 *
 * Provides system-wide statistics and health information for administrators.
 *
 * Tenant isolation (WC-50): the aggregate counts are scoped to the caller's
 * tenant. System users (tenant_id=0) retain the platform-wide totals; a regular
 * tenant's admin sees only its own tenant's figures (its user count and growth,
 * the roles it can see, and a tenant total fixed at 1 — its own). Without this
 * scoping a regular tenant's admin could read platform-wide totals such as
 * other tenants' user counts and the total number of tenants.
 */
class AdminApiHandler
{
    /**
     * The reserved identifier for the system tenant.
     *
     * System users (tenant_id=0) act with cross-tenant authority and so see the
     * unfiltered, platform-wide statistics.
     */
    private const SYSTEM_TENANT_ID = 0;

    /**
     * Bound on the eight-odd COUNT/GROUP BY queries below, in milliseconds.
     * These are read-only aggregates that are normally fast; if the DB is
     * unhealthy (lock contention, connection exhaustion) they must fail loudly
     * rather than hang the worker's PDO connection indefinitely — this
     * connection is PERSISTENT across requests in FrankenPHP worker mode
     * (Database::getPdo() docblock), so a genuinely unbounded query here would
     * starve every later request this worker serves, not just this one.
     * Overridable via env for environments with a legitimately slower DB.
     */
    private const DEFAULT_STATS_QUERY_TIMEOUT_MS = 5000;

    private Database $db;
    private string $migrationDir;

    public function __construct(Database $db, string $migrationDir)
    {
        $this->db = $db;
        $this->migrationDir = $migrationDir;
    }

    /**
     * GET /api/admin/stats - Comprehensive system statistics
     *
     * For the platform's operators only (H.3, WHIT-872). `stats:read` can't say
     * that alone, because the seeded `admin` is one global role held in every
     * workspace, so the tenant is checked here. An unresolved context is not the
     * system tenant, so it is refused too.
     */
    public function stats(Request $request): Response
    {
        if (TenantContext::getTenantId() !== self::SYSTEM_TENANT_ID) {
            return Response::error('Server statistics are for the system tenant', 403);
        }

        try {
            $pdo = $this->db->getPdo();

            // SET LOCAL is transaction-scoped: it reverts automatically at
            // commit/rollback, so it can never leak onto this shared, worker-
            // persistent connection's later requests even if something below
            // throws. All queries in this method are read-only, so wrapping
            // them in a transaction purely to scope this timeout is safe.
            $timeoutMs = (int) (getenv('STATS_QUERY_TIMEOUT_MS') ?: self::DEFAULT_STATS_QUERY_TIMEOUT_MS);
            $pdo->beginTransaction();
            try {
                // statement_timeout is Postgres-only syntax (SQLite -- used by
                // the unit/RealEngine test suite -- has no equivalent and has
                // no unbounded-query risk to guard against anyway, being
                // in-memory).
                if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
                    $pdo->exec('SET LOCAL statement_timeout = ' . max(1, $timeoutMs));
                }
                $response = $this->collectStats($pdo);
                $pdo->commit();
                return $response;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        } catch (\Exception $e) {
            error_log('[AdminApiHandler] stats failed: ' . $e->getMessage());
            return Response::error('Failed to fetch system stats', 500);
        }
    }

    /**
     * The actual stats-gathering body, run inside the timeout-scoped
     * transaction {@see stats()} opens.
     */
    private function collectStats(PDO $pdo): Response
    {
            // 1. Basic Totals (platform-wide)
            // ROLE/TENANT data: this is an ACTIVE-MEMBERSHIP count, not a profile
            // count — a person with active memberships in N tenants counts N times
            // (each tenant occupancy is one active membership, ADR 0005 §3).
            // @tenant-guard-ignore: system-tenant only, stats() refuses every other tenant; aggregates across all tenants
            $totalUsers = $pdo->query('SELECT COUNT(*) FROM memberships WHERE is_primary AND status = \'active\'')->fetchColumn();
            $totalTenants = $pdo->query('SELECT COUNT(*) FROM tenants')->fetchColumn();
            // @tenant-guard-ignore: system-tenant only, stats() refuses every other tenant; aggregates across all tenants
            $totalRoles = $pdo->query('SELECT COUNT(*) FROM roles')->fetchColumn();

            // 2. Role Breakdown (all roles, all memberships)
            // ROLE data: role_id now lives on memberships (ADR 0005 §3).
            // @tenant-guard-ignore: system-tenant only, stats() refuses every other tenant; aggregates across all tenants
            $usersPerRole = $pdo->query('
                SELECT r.name, COUNT(DISTINCT m.profile_id) as count
                FROM roles r
                LEFT JOIN memberships m ON m.role_id = r.id AND m.status = \'active\'
                GROUP BY r.name
            ')->fetchAll(PDO::FETCH_ASSOC);

            // 3. Growth Trends (Last 7 Days, platform-wide)
            // ROLE/TENANT data: membership creation date tracks when a person joined a tenant.
            // @tenant-guard-ignore: system-tenant only, stats() refuses every other tenant; aggregates across all tenants
            $userGrowth = $pdo->query("
                SELECT DATE(created_at) as date, COUNT(*) as count
                FROM memberships
                WHERE status = 'active' AND is_primary
                  AND created_at >= NOW() - INTERVAL '7 days'
                GROUP BY DATE(created_at)
                ORDER BY DATE(created_at)
            ")->fetchAll(PDO::FETCH_ASSOC);

            $tenantGrowth = $pdo->query("
                SELECT DATE(created_at) as date, COUNT(*) as count
                FROM tenants
                WHERE created_at >= NOW() - INTERVAL '7 days'
                GROUP BY DATE(created_at)
                ORDER BY DATE(created_at)
            ")->fetchAll(PDO::FETCH_ASSOC);

            // Permissions are a global catalogue (not tenant-owned), so the count
            // is the same for every caller.
            $totalPermissions = $pdo->query('SELECT COUNT(*) FROM permissions')->fetchColumn();

            // 4. Migration Status
            $executedMigrations = $pdo->query('SELECT COUNT(*) FROM core_schema_migrations')->fetchColumn();
            $allMigrationFiles = count(glob($this->migrationDir . '/*.php'));

            // 5. Database Health
            $dbSize = $pdo->query("SELECT pg_size_pretty(pg_database_size(current_database()))")->fetchColumn();
            $pgVersion = $pdo->query("SHOW server_version")->fetchColumn();

            // 6. System Info
            $systemInfo = [
                'php_version' => PHP_VERSION,
                'memory_usage' => $this->formatBytes(memory_get_usage()),
                'peak_memory' => $this->formatBytes(memory_get_peak_usage()),
                'os' => PHP_OS,
                'server' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            ];

            return Response::json([
                'status' => 'success',
                'timestamp' => date('Y-m-d H:i:s'),
                'stats' => [
                    'totals' => [
                        'users' => (int)$totalUsers,
                        'tenants' => (int)$totalTenants,
                        'roles' => (int)$totalRoles,
                        'permissions' => (int)$totalPermissions,
                    ],
                    'breakdown' => [
                        'users_per_role' => $usersPerRole,
                    ],
                    'growth' => [
                        'users' => $this->fillMissingDates($userGrowth),
                        'tenants' => $this->fillMissingDates($tenantGrowth),
                    ],
                    'maintenance' => [
                        'migrations_executed' => (int)$executedMigrations,
                        'migrations_total' => $allMigrationFiles,
                        'pending_migrations' => max(0, $allMigrationFiles - $executedMigrations),
                    ],
                    'database' => [
                        'size' => $dbSize,
                        'version' => $pgVersion,
                    ],
                    'system' => $systemInfo
                ]
            ]);
    }

    /**
     * Helper to format bytes to human readable format
     */
    private function formatBytes($bytes, $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * Helper to ensure growth charts have entries for all last 7 days
     */
    private function fillMissingDates(array $data): array
    {
        $filled = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $count = 0;
            foreach ($data as $row) {
                if ($row['date'] === $date) {
                    $count = (int)$row['count'];
                    break;
                }
            }
            $filled[] = ['date' => $date, 'count' => $count];
        }
        return $filled;
    }
}
