<?php

declare(strict_types=1);

namespace Tests\Auth;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\RoleChecker;
use Whity\Core\RBAC\GrantResourceScope;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\RBAC\ResourceRoleAssignmentRepository;
use Whity\Core\RBAC\ResourceTypeRegistry;
use Whity\Core\RBAC\RoleCheckerPermissionResolver;
use Whity\Database\Database;

/**
 * #1074: a plugin can narrow access to its own resource type.
 *
 * Runs against the real migrations. What these pin:
 *  - two people holding the SAME permission tenant-wide see DIFFERENT record
 *    sets, which `hasPermission()` with a resource cannot express;
 *  - the reach never contains a record where `hasPermission()` says no;
 *  - everyone-grants, other tenants, unknown permissions, unregistered types
 *    and a missing membership all give nothing;
 *  - unrestricted needs both the permission and the plugin's "every record"
 *    permission.
 */
final class ResourceScopeRealEngineTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;
    private const TYPE = 'testplugin:dossier';

    private PDO $pdo;
    private Database $db;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (1, 'tenant-a'), (2, 'tenant-b')");
        $this->pdo->exec("INSERT OR IGNORE INTO roles (id, name, created_at) VALUES
            (3, 'editor', NOW()), (4, 'viewer', NOW()), (5, 'nobody', NOW())");
        SchemaFromMigrations::syncSequences($this->pdo);

        $this->db = Database::withFactory(fn (): PDO => $this->pdo);
        $this->db->setMaxLifetimeSeconds(86400);
        $this->db->setPingIntervalSeconds(86400);
        $this->db->forceConnect();

        $this->grantPermissionToRole('editor', 'dossiers:read');
        $this->grantPermissionToRole('viewer', 'dossiers:read');
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
    }

    public function testSamePermissionDifferentRecordSets(): void
    {
        $alice = $this->seedProfile('alice@example.com', 'editor', self::TENANT_A);
        $bob = $this->seedProfile('bob@example.com', 'editor', self::TENANT_A);
        $this->repository()->grant(self::TENANT_A, self::TYPE, 10, $this->roleId('viewer'), $alice);
        $this->repository()->grant(self::TENANT_A, self::TYPE, 11, $this->roleId('viewer'), $alice);
        $this->repository()->grant(self::TENANT_A, self::TYPE, 12, $this->roleId('viewer'), $bob);

        // The additive check cannot tell them apart: both pass for every id.
        foreach ([10, 11, 12, 99] as $id) {
            self::assertTrue($this->resolver()->hasPermission($alice, self::TENANT_A, 'dossiers:read', self::TYPE, $id));
            self::assertTrue($this->resolver()->hasPermission($bob, self::TENANT_A, 'dossiers:read', self::TYPE, $id));
        }

        $aliceReach = $this->scope()->reach($alice, self::TENANT_A, 'dossiers:read', self::TYPE);
        $bobReach = $this->scope()->reach($bob, self::TENANT_A, 'dossiers:read', self::TYPE);

        self::assertSame([10, 11], $aliceReach->ids());
        self::assertSame([12], $bobReach->ids());
        self::assertSame([11, 10], $aliceReach->filter([11, 12, 99, 10]));
        self::assertFalse($bobReach->contains(10));
    }

    public function testAGrantWhoseRoleCarriesThePermissionIsEnoughWithoutItTenantWide(): void
    {
        $carol = $this->seedProfile('carol@example.com', 'nobody', self::TENANT_A);
        $this->repository()->grant(self::TENANT_A, self::TYPE, 20, $this->roleId('viewer'), $carol);

        self::assertSame([20], $this->scope()->reach($carol, self::TENANT_A, 'dossiers:read', self::TYPE)->ids());
    }

    public function testReachNeverHoldsARecordWherePermissionIsRefused(): void
    {
        // Standing at the record through a role that does NOT carry the
        // permission, and no permission tenant-wide: standing alone is not access.
        $dave = $this->seedProfile('dave@example.com', 'nobody', self::TENANT_A);
        $this->repository()->grant(self::TENANT_A, self::TYPE, 30, $this->roleId('nobody'), $dave);

        self::assertFalse($this->resolver()->hasPermission($dave, self::TENANT_A, 'dossiers:read', self::TYPE, 30));
        self::assertTrue($this->scope()->reach($dave, self::TENANT_A, 'dossiers:read', self::TYPE)->isEmpty());
    }

    public function testEveryoneGrantsAreNotStanding(): void
    {
        $erin = $this->seedProfile('erin@example.com', 'editor', self::TENANT_A);
        $this->repository()->grant(self::TENANT_A, self::TYPE, 40, $this->roleId('viewer'), null);

        self::assertTrue($this->scope()->reach($erin, self::TENANT_A, 'dossiers:read', self::TYPE)->isEmpty());
    }

    public function testGrantsInAnotherTenantDoNotCount(): void
    {
        $frank = $this->seedProfile('frank@example.com', 'editor', self::TENANT_A);
        $this->repository()->grant(self::TENANT_B, self::TYPE, 50, $this->roleId('viewer'), $frank);

        self::assertTrue($this->scope()->reach($frank, self::TENANT_A, 'dossiers:read', self::TYPE)->isEmpty());
        // And in tenant B he has no membership, so nothing there either.
        self::assertTrue($this->scope()->reach($frank, self::TENANT_B, 'dossiers:read', self::TYPE)->isEmpty());
    }

    public function testNoActiveMembershipReachesNothing(): void
    {
        $gina = $this->seedProfile('gina@example.com', 'editor', self::TENANT_A);
        $this->repository()->grant(self::TENANT_A, self::TYPE, 60, $this->roleId('viewer'), $gina);
        $this->pdo->prepare("UPDATE memberships SET status = 'suspended' WHERE profile_id = ?")->execute([$gina]);
        RoleChecker::clearCache();

        self::assertTrue($this->scope()->reach($gina, self::TENANT_A, 'dossiers:read', self::TYPE)->isEmpty());
        self::assertTrue(
            $this->scope()->reach($gina, self::TENANT_A, 'dossiers:read', self::TYPE, 'dossiers:all')->isEmpty()
        );
    }

    public function testUnknownPermissionOrTypeReachesNothing(): void
    {
        $hank = $this->seedProfile('hank@example.com', 'editor', self::TENANT_A);
        $this->repository()->grant(self::TENANT_A, self::TYPE, 70, $this->roleId('viewer'), $hank);

        self::assertTrue($this->scope()->reach($hank, self::TENANT_A, 'dossiers:nope', self::TYPE)->isEmpty());
        self::assertTrue($this->scope()->reach($hank, self::TENANT_A, 'dossiers:read', 'testplugin:other')->isEmpty());
    }

    public function testUnrestrictedNeedsBothPermissionsTenantWide(): void
    {
        $this->grantPermissionToRole('editor', 'dossiers:all');
        $ivy = $this->seedProfile('ivy@example.com', 'editor', self::TENANT_A);
        $jack = $this->seedProfile('jack@example.com', 'viewer', self::TENANT_A);
        $this->repository()->grant(self::TENANT_A, self::TYPE, 80, $this->roleId('viewer'), $jack);

        self::assertTrue($this->scope()->reach($ivy, self::TENANT_A, 'dossiers:read', self::TYPE, 'dossiers:all')->isUnrestricted());
        // Without naming it, never unrestricted.
        self::assertFalse($this->scope()->reach($ivy, self::TENANT_A, 'dossiers:read', self::TYPE)->isUnrestricted());
        // Jack lacks dossiers:all, so he stays narrowed.
        self::assertSame([80], $this->scope()->reach($jack, self::TENANT_A, 'dossiers:read', self::TYPE, 'dossiers:all')->ids());
    }

    public function testHoldingOnlyTheUnrestrictedPermissionIsNotEnough(): void
    {
        // A role with the "every record" permission but NOT the capability.
        $this->grantPermissionToRole('nobody', 'dossiers:all');
        $kim = $this->seedProfile('kim@example.com', 'nobody', self::TENANT_A);

        $reach = $this->scope()->reach($kim, self::TENANT_A, 'dossiers:read', self::TYPE, 'dossiers:all');
        self::assertFalse($reach->isUnrestricted());
        self::assertTrue($reach->isEmpty());
    }

    public function testUnknownUnrestrictedPermissionNeverWidens(): void
    {
        $lee = $this->seedProfile('lee@example.com', 'editor', self::TENANT_A);

        self::assertFalse(
            $this->scope()->reach($lee, self::TENANT_A, 'dossiers:read', self::TYPE, 'dossiers:unknown')->isUnrestricted()
        );
    }

    // ==================== Helpers ====================

    private function scope(): GrantResourceScope
    {
        return new GrantResourceScope(
            $this->resolver(),
            $this->repository(),
            $this->resourceTypes(),
            $this->permissionRegistry()
        );
    }

    private function resolver(): RoleCheckerPermissionResolver
    {
        return new RoleCheckerPermissionResolver(
            new RoleChecker($this->db, $this->permissionRegistry()),
            $this->permissionRegistry()
        );
    }

    private function permissionRegistry(): PermissionRegistry
    {
        $registry = new PermissionRegistry();
        $registry->register('test', ['dossiers:read', 'dossiers:all']);

        return $registry;
    }

    private function repository(): ResourceRoleAssignmentRepository
    {
        return new ResourceRoleAssignmentRepository($this->pdo, $this->resourceTypes());
    }

    private function resourceTypes(): ResourceTypeRegistry
    {
        $types = new ResourceTypeRegistry();
        $types->register('TestPlugin', ['dossier', 'other']);

        return $types;
    }

    private function grantPermissionToRole(string $roleName, string $permission): void
    {
        $this->pdo->prepare('INSERT OR IGNORE INTO permissions (name, created_at) VALUES (?, NOW())')
            ->execute([$permission]);
        $stmt = $this->pdo->prepare('SELECT id FROM permissions WHERE name = ?');
        $stmt->execute([$permission]);
        $permissionId = (int) $stmt->fetchColumn();

        $this->pdo->prepare(
            'INSERT OR IGNORE INTO role_permissions (role_id, permission_id, created_at) VALUES (?, ?, NOW())'
        )->execute([$this->roleId($roleName), $permissionId]);
        RoleChecker::clearCache();
    }

    private function seedProfile(string $email, string $roleName, int $tenantId): int
    {
        $this->pdo->prepare(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled,
                 two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (?, 'x', false, 0, 0, NOW(), NOW())"
        )->execute([explode('@', $email)[0]]);
        $profileId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES (?, ?, true, true, NOW())'
        )->execute([$profileId, $email]);

        $this->pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, status, created_at)
             VALUES (?, ?, ?, NULL, 'active', NOW())"
        )->execute([$profileId, $tenantId, $this->roleId($roleName)]);

        return $profileId;
    }

    private function roleId(string $name): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM roles WHERE name = ? ORDER BY id LIMIT 1');
        $stmt->execute([$name]);

        return (int) $stmt->fetchColumn();
    }
}
