<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\RoleChecker;
use Whity\Core\Identity\HostLinkedPeople;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Database\Database;
use Whity\Sdk\Http\ServiceCall;

/**
 * A plugin acting for a person at the trusted issuer sees only the workspaces
 * that person may act in for the permission asked (WHIT-1011). That is the
 * rule that keeps one workspace's billing from another's.
 */
final class LinkedPeopleRealEngineTest extends TestCase
{
    private const ISSUER = 'https://id.example.test';

    private PDO $pdo;
    private HostLinkedPeople $people;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name, slug) VALUES (0, 'system', 'system')");
        foreach ([1 => 'Example Bakery', 2 => 'Example Florist', 3 => 'Example Garage', 4 => 'Example Cafe'] as $id => $name) {
            $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES ({$id}, '{$name}', 'w{$id}')");
        }
        // Role 901 holds billing:view; role 902 holds nothing.
        $this->pdo->exec("INSERT OR IGNORE INTO roles (id, name, description, tenant_id, created_at) VALUES (901, 'billing-test', '', NULL, datetime('now'))");
        $this->pdo->exec("INSERT OR IGNORE INTO roles (id, name, description, tenant_id, created_at) VALUES (902, 'viewer-test', '', NULL, datetime('now'))");
        $this->pdo->exec("INSERT OR IGNORE INTO permissions (name, description, created_at) VALUES ('billing:view', '', NOW())");
        $this->pdo->exec("INSERT OR IGNORE INTO role_permissions (role_id, permission_id, created_at) SELECT 901, id, NOW() FROM permissions WHERE name = 'billing:view'");

        foreach ([10, 11] as $profile) {
            $this->pdo->exec("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                VALUES ({$profile}, 'p{$profile}', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        }
        // Profile 10: billing in 1, viewer in 2, billing in 0 (system), billing but suspended in 3.
        foreach ([[1, 901, 'active'], [2, 902, 'active'], [0, 901, 'active'], [3, 901, 'suspended']] as [$tenant, $role, $status]) {
            $this->pdo->exec("INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at) VALUES (10, {$tenant}, {$role}, '{$status}', CURRENT_TIMESTAMP)");
        }
        // Profile 11: billing in 4.
        $this->pdo->exec("INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at) VALUES (11, 4, 901, 'active', CURRENT_TIMESTAMP)");

        // Global links: sub-a -> 10. A TENANT-scoped link (provider_id set) for sub-b -> 11, which must not count.
        $this->pdo->exec("INSERT INTO external_identities (profile_id, provider_id, provider_key, issuer, subject) VALUES (10, NULL, 'whity-id', '" . self::ISSUER . "', 'sub-a')");
        $this->pdo->exec("INSERT INTO external_identities (profile_id, provider_id, provider_key, issuer, subject) VALUES (11, 7, 'whity-id', '" . self::ISSUER . "', 'sub-b')");
        SchemaFromMigrations::syncSequences($this->pdo);

        $database = Database::withFactory(fn (): PDO => $this->pdo);
        $database->setMaxLifetimeSeconds(86400);
        $database->setPingIntervalSeconds(86400);
        $database->forceConnect();
        $this->people = new HostLinkedPeople($this->pdo, new RoleChecker($database, new PermissionRegistry()));
    }

    public function testOnlyActiveNonSystemWorkspacesWithThePermission(): void
    {
        $workspaces = $this->people->workspacesFor($this->call('sub-a'), 'billing:view');

        self::assertSame([[1, 'Example Bakery']], array_map(static fn ($w): array => [$w->id, $w->name], $workspaces));
    }

    public function testATenantScopedLinkDoesNotCount(): void
    {
        self::assertSame([], $this->people->workspacesFor($this->call('sub-b'), 'billing:view'));
    }

    public function testAnotherIssuersSubjectIsNobody(): void
    {
        self::assertSame([], $this->people->workspacesFor(new ServiceCall('https://evil.example.test', 'c', [], 'sub-a'), 'billing:view'));
    }

    public function testAnUnknownSubjectIsNobody(): void
    {
        self::assertSame([], $this->people->workspacesFor($this->call('nobody'), 'billing:view'));
    }

    private function call(?string $person): ServiceCall
    {
        return new ServiceCall(self::ISSUER, 'whity-id', ['billing:read'], $person);
    }

    public function testACallActingForNobodyHasNoWorkspaces(): void
    {
        self::assertSame([], $this->people->workspacesFor($this->call(null), 'billing:view'));
    }
}
