<?php

declare(strict_types=1);

namespace Tests\Integration\Migrations;

use Database\Migrations\CreatePluginInstalls;
use Database\Migrations\CreatePluginRemovals;
use Database\Migrations\GrantPluginsInstallPermission;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * Migrations 203 to 205 (WHIT-870): `plugins:install` reaches roles by what they
 * can already do, never by name, and every down() reverses its up().
 */
final class PluginsInstallPermissionMigrationTest extends TestCase
{
    private PDO $pdo;
    private Database $db;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $pdo = $this->pdo;
        $this->db = Database::withFactory(static fn (): PDO => $pdo);
    }

    public function testARenamedRoleHoldingUploadGetsInstallAndOneWithoutDoesNot(): void
    {
        GrantPluginsInstallPermission::down($this->db);

        $holder = $this->role('Platform keeper');
        $other = $this->role('Viewer');
        $this->grant($holder, CorePermissions::PLUGINS_UPLOAD);
        $this->grant($other, CorePermissions::PLUGINS_READ);

        GrantPluginsInstallPermission::up($this->db);

        self::assertTrue($this->holds($holder, CorePermissions::PLUGINS_INSTALL));
        self::assertFalse($this->holds($other, CorePermissions::PLUGINS_INSTALL));
    }

    public function testDownRemovesTheSlugAndItsGrantsAndLeavesUploadAlone(): void
    {
        $holder = $this->role('Platform keeper');
        $this->grant($holder, CorePermissions::PLUGINS_UPLOAD);
        GrantPluginsInstallPermission::up($this->db);

        GrantPluginsInstallPermission::down($this->db);

        $slug = $this->pdo->prepare('SELECT COUNT(*) FROM permissions WHERE name = ?');
        $slug->execute([CorePermissions::PLUGINS_INSTALL]);
        self::assertSame(0, (int) $slug->fetchColumn());
        self::assertFalse($this->holds($holder, CorePermissions::PLUGINS_INSTALL));
        self::assertTrue($this->holds($holder, CorePermissions::PLUGINS_UPLOAD));
    }

    public function testTheTableMigrationsRoundTrip(): void
    {
        foreach ([[CreatePluginRemovals::class, 'plugin_removals'], [CreatePluginInstalls::class, 'plugin_installs']] as [$migration, $table]) {
            $migration::down($this->db);
            self::assertFalse($this->tableExists($table), "{$table} dropped");
            $migration::up($this->db);
            self::assertTrue($this->tableExists($table), "{$table} back");
        }
    }

    private function tableExists(string $table): bool
    {
        try {
            $this->pdo->query("SELECT 1 FROM {$table}");

            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    private function role(string $name): int
    {
        $this->pdo->prepare('INSERT INTO roles (name) VALUES (?)')->execute([$name]);

        return (int) $this->pdo->lastInsertId();
    }

    private function grant(int $role, string $permission): void
    {
        $this->pdo->prepare(
            'INSERT INTO role_permissions (role_id, permission_id, created_at)
             SELECT ?, id, NOW() FROM permissions WHERE name = ?'
        )->execute([$role, $permission]);
    }

    private function holds(int $role, string $permission): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
              WHERE rp.role_id = ? AND p.name = ?'
        );
        $stmt->execute([$role, $permission]);

        return (int) $stmt->fetchColumn() === 1;
    }
}
