<?php

declare(strict_types=1);

namespace Tests\Integration;

use Database\Migrations\GrantMigrationsPermissions;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * Migration 220 (#990): `migrations:read` follows `stats:read`, and
 * `migrations:manage` needs `stats:read` and `plugins:install` together. Both are
 * granted by capability, so a renamed administrative role keeps them.
 */
final class GrantMigrationsPermissionsRealEngineTest extends TestCase
{
    private const OPERATOR = 910;   // stats:read + plugins:install
    private const READER = 911;     // stats:read only
    private const INSTALLER = 912;  // plugins:install only
    private const NOBODY = 913;     // neither

    private PDO $pdo;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/220_grant_migrations_permissions.php';

        $this->pdo = SchemaFromMigrations::make();
        foreach ([CorePermissions::STATS_READ, CorePermissions::PLUGINS_INSTALL] as $slug) {
            $this->pdo->prepare('INSERT INTO permissions (name, description, created_at) VALUES (?, ?, CURRENT_TIMESTAMP) ON CONFLICT (name) DO NOTHING')
                ->execute([$slug, 'x']);
        }

        $roles = [
            self::OPERATOR => [CorePermissions::STATS_READ, CorePermissions::PLUGINS_INSTALL],
            self::READER => [CorePermissions::STATS_READ],
            self::INSTALLER => [CorePermissions::PLUGINS_INSTALL],
            self::NOBODY => [],
        ];
        foreach ($roles as $id => $slugs) {
            $this->pdo->prepare('INSERT INTO roles (id, name, tenant_id, created_at) VALUES (?, ?, NULL, CURRENT_TIMESTAMP)')
                ->execute([$id, 'role-' . $id]);
            foreach ($slugs as $slug) {
                $this->pdo->prepare('INSERT INTO role_permissions (role_id, permission_id, created_at) SELECT ?, id, CURRENT_TIMESTAMP FROM permissions WHERE name = ?')
                    ->execute([$id, $slug]);
            }
        }
    }

    public function testUpGrantsByCapability(): void
    {
        GrantMigrationsPermissions::up($this->db());

        self::assertTrue($this->holds(self::OPERATOR, CorePermissions::MIGRATIONS_READ));
        self::assertTrue($this->holds(self::OPERATOR, CorePermissions::MIGRATIONS_MANAGE));

        self::assertTrue($this->holds(self::READER, CorePermissions::MIGRATIONS_READ));
        self::assertFalse($this->holds(self::READER, CorePermissions::MIGRATIONS_MANAGE), 'Reading statistics is not the authority to change the schema.');

        self::assertFalse($this->holds(self::INSTALLER, CorePermissions::MIGRATIONS_READ));
        self::assertFalse($this->holds(self::INSTALLER, CorePermissions::MIGRATIONS_MANAGE), 'Both anchors are required.');

        self::assertFalse($this->holds(self::NOBODY, CorePermissions::MIGRATIONS_READ));
        self::assertFalse($this->holds(self::NOBODY, CorePermissions::MIGRATIONS_MANAGE));
    }

    public function testUpIsIdempotent(): void
    {
        GrantMigrationsPermissions::up($this->db());
        GrantMigrationsPermissions::up($this->db());

        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM permissions WHERE name = ?', CorePermissions::MIGRATIONS_MANAGE));
        self::assertTrue($this->holds(self::OPERATOR, CorePermissions::MIGRATIONS_MANAGE));
    }

    public function testDownRemovesBothSlugs(): void
    {
        GrantMigrationsPermissions::up($this->db());
        GrantMigrationsPermissions::down($this->db());

        foreach ([CorePermissions::MIGRATIONS_READ, CorePermissions::MIGRATIONS_MANAGE] as $slug) {
            self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM permissions WHERE name = ?', $slug));
        }
        self::assertTrue($this->holds(self::OPERATOR, CorePermissions::STATS_READ), 'The anchors are untouched.');
    }

    private function db(): Database
    {
        $pdo = $this->pdo;

        return Database::withFactory(static fn (): PDO => $pdo);
    }

    private function holds(int $roleId, string $slug): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ? AND p.name = ?'
        );
        $stmt->execute([$roleId, $slug]);

        return (int) $stmt->fetchColumn() === 1;
    }

    private function countRows(string $sql, string $param): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$param]);

        return (int) $stmt->fetchColumn();
    }
}
