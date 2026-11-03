<?php

declare(strict_types=1);

namespace Tests\Integration;

use Database\Migrations\RestrictStatsReadToOperators;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * Migration 199 (WHIT-872): `stats:read` leaves the roles workspaces own and stays
 * on the global roles, whatever they are called, because a global role may be held
 * in the system tenant. Operators keep it; the holder guard keeps a holder.
 */
final class RestrictStatsReadMigrationRealEngineTest extends TestCase
{
    private const GLOBAL_ROLE = 900;
    private const WORKSPACE_ROLE = 901;
    private const SYSTEM_TENANT_ROLE = 902;

    private PDO $pdo;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/199_restrict_stats_read_to_operators.php';

        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'bakery')");
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) SELECT 0, 'System', 'system-test' WHERE NOT EXISTS (SELECT 1 FROM tenants WHERE id = 0)");
        $this->pdo->exec("INSERT INTO permissions (name, description, created_at) VALUES ('" . CorePermissions::STATS_READ . "', 'x', CURRENT_TIMESTAMP) ON CONFLICT (name) DO NOTHING");
        $this->pdo->exec("INSERT INTO permissions (name, description, created_at) VALUES ('" . CorePermissions::USERS_READ . "', 'x', CURRENT_TIMESTAMP) ON CONFLICT (name) DO NOTHING");

        // Global, workspace-owned and system-tenant-owned: all can list users and
        // read statistics, which is what migration 157 left behind.
        foreach ([[self::GLOBAL_ROLE, 'renamed-global', null], [self::WORKSPACE_ROLE, 'bakery-manager', 1], [self::SYSTEM_TENANT_ROLE, 'operator', 0]] as [$id, $name, $tenant]) {
            $this->pdo->prepare('INSERT INTO roles (id, name, tenant_id, created_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)')->execute([$id, $name, $tenant]);
            foreach ([CorePermissions::STATS_READ, CorePermissions::USERS_READ] as $slug) {
                $this->pdo->prepare('INSERT INTO role_permissions (role_id, permission_id, created_at) SELECT ?, id, CURRENT_TIMESTAMP FROM permissions WHERE name = ?')->execute([$id, $slug]);
            }
        }
    }

    public function testUpLeavesOnlyRolesAWorkspaceDoesNotOwn(): void
    {
        RestrictStatsReadToOperators::up($this->db());

        self::assertTrue($this->holdsStats(self::GLOBAL_ROLE), 'A global role keeps it, whatever its name.');
        self::assertTrue($this->holdsStats(self::SYSTEM_TENANT_ROLE), 'The system tenant\'s own role keeps it.');
        self::assertFalse($this->holdsStats(self::WORKSPACE_ROLE), 'A workspace\'s own role loses it.');
        self::assertTrue($this->holds(self::WORKSPACE_ROLE, CorePermissions::USERS_READ), 'Nothing else is taken.');
    }

    public function testUpIsIdempotent(): void
    {
        RestrictStatsReadToOperators::up($this->db());
        RestrictStatsReadToOperators::up($this->db());

        self::assertTrue($this->holdsStats(self::GLOBAL_ROLE));
        self::assertFalse($this->holdsStats(self::WORKSPACE_ROLE));
    }

    public function testDownGivesTheWorkspaceRoleItBack(): void
    {
        RestrictStatsReadToOperators::up($this->db());
        RestrictStatsReadToOperators::down($this->db());

        self::assertTrue($this->holdsStats(self::WORKSPACE_ROLE));
        self::assertTrue($this->holdsStats(self::GLOBAL_ROLE));
        self::assertTrue($this->holdsStats(self::SYSTEM_TENANT_ROLE));
    }

    private function db(): Database
    {
        $pdo = $this->pdo;

        return Database::withFactory(static fn (): PDO => $pdo);
    }

    private function holdsStats(int $roleId): bool
    {
        return $this->holds($roleId, CorePermissions::STATS_READ);
    }

    private function holds(int $roleId, string $slug): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ? AND p.name = ?'
        );
        $stmt->execute([$roleId, $slug]);

        return (int) $stmt->fetchColumn() === 1;
    }
}
