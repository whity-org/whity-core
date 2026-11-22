<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\RoleChecker;
use Whity\Core\Identity\ProfileEmailRepository;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\WorkspaceCreator;
use Whity\Core\Tenant\WorkspaceOwners;
use Whity\Database\Database;

/**
 * Owners and "Create workspace" while signed in (WHIT-1169), on the real
 * schema from migrations (migration 300 included).
 */
final class WorkspaceOwnersRealEngineTest extends TestCase
{
    private PDO $pdo;
    private SettingsService $settings;
    private WorkspaceCreator $creator;
    private WorkspaceOwners $owners;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->settings = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo)
        );
        $this->settings->setGlobal(SettingsRegistry::WORKSPACES_SELF_CREATE_ENABLED, 'true');
        $this->creator = new WorkspaceCreator($this->pdo, $this->settings);
        $this->owners = new WorkspaceOwners($this->pdo);
        RoleChecker::clearCache();
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
    }

    public function testCreatingIsOffUntilTheOperatorTurnsItOn(): void
    {
        $this->settings->setGlobal(SettingsRegistry::WORKSPACES_SELF_CREATE_ENABLED, 'false');
        $pid = $this->profile('Amro Saleh', 'amro@example.test');

        $result = $this->creator->createFor($pid, 'Example Bakery');

        self::assertSame(WorkspaceCreator::DISABLED, $result['status']);
        self::assertSame(WorkspaceCreator::DISABLED, $this->creator->stateFor($pid)['reason']);
        self::assertSame(0, $this->owners->countOwnedBy($pid));
    }

    public function testAPersonWhoAlreadyBelongsSomewhereCreatesAnotherAndOwnsIt(): void
    {
        $pid = $this->profile('Amro Saleh', 'amro@example.test');
        $first = $this->creator->createFor($pid, 'Example Bakery');
        $second = $this->creator->createFor($pid, 'Example Cafe');

        self::assertSame(WorkspaceCreator::CREATED, $second['status']);
        $tenantId = (int) $second['tenant_id'];
        self::assertNotSame($first['tenant_id'], $tenantId);
        self::assertSame('Example Cafe', $second['name']);
        self::assertTrue($this->owners->isOwner($tenantId, $pid));
        self::assertSame(2, $this->owners->countOwnedBy($pid));

        $stmt = $this->pdo->prepare(
            "SELECT r.name, m.status, m.is_primary FROM memberships m JOIN roles r ON r.id = m.role_id
              WHERE m.tenant_id = :t AND m.profile_id = :p"
        );
        $stmt->execute([':t' => $tenantId, ':p' => $pid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame('admin', $row['name']);
        self::assertSame('active', $row['status']);
        self::assertTrue((bool) $row['is_primary']);
    }

    public function testTheLimitCountsOnlyWorkspacesThePersonOwns(): void
    {
        $this->settings->setGlobal(SettingsRegistry::WORKSPACES_MAX_OWNED_PER_PERSON, '2');
        $pid = $this->profile('Awss Haddad', 'awss@example.test');
        $other = $this->profile('Ahmad Khalil', 'ahmad@example.test');

        // A membership somewhere they don't own doesn't count.
        $theirs = (int) $this->creator->createFor($other, 'Ahmad Studio')['tenant_id'];
        $this->member($pid, $theirs);

        self::assertSame(WorkspaceCreator::CREATED, $this->creator->createFor($pid, 'One')['status']);
        self::assertSame(WorkspaceCreator::CREATED, $this->creator->createFor($pid, 'Two')['status']);
        $third = $this->creator->createFor($pid, 'Three');

        self::assertSame(WorkspaceCreator::LIMIT_REACHED, $third['status']);
        self::assertNull($third['tenant_id']);
        self::assertSame(
            ['allowed' => false, 'reason' => WorkspaceCreator::LIMIT_REACHED, 'owned' => 2, 'limit' => 2, 'next_plan' => null],
            $this->creator->stateFor($pid)
        );
    }

    public function testZeroMeansNoLimit(): void
    {
        $pid = $this->profile('Emad Nasser', 'emad@example.test');
        for ($i = 1; $i <= 4; $i++) {
            self::assertSame(WorkspaceCreator::CREATED, $this->creator->createFor($pid, 'Shop ' . $i)['status']);
        }
        self::assertTrue($this->creator->stateFor($pid)['allowed']);
    }

    public function testADeactivatedPersonCreatesNothing(): void
    {
        $pid = $this->profile('Mallory', 'mallory@example.test', 'inactive');

        self::assertSame(WorkspaceCreator::REFUSED, $this->creator->createFor($pid, 'Nope')['status']);
    }

    public function testTheNewWorkspaceStartsBehindThePaymentGateWhenPaymentIsRequired(): void
    {
        $this->settings->setGlobal(SettingsRegistry::REGISTRATION_PAYMENT_REQUIRED, 'true');
        $pid = $this->profile('Ibrahim Mansour', 'ibrahim@example.test');

        $tenantId = (int) $this->creator->createFor($pid, 'Example Bakery')['tenant_id'];

        $stmt = $this->pdo->prepare('SELECT status, enforcement_mode FROM tenant_plan WHERE tenant_id = :t');
        $stmt->execute([':t' => $tenantId]);
        self::assertSame(['status' => 'expired', 'enforcement_mode' => 'block_writes'], $stmt->fetch(PDO::FETCH_ASSOC));
    }

    public function testOnlyAnOwnerKeepsBillingPay(): void
    {
        $owner = $this->profile('Amro Saleh', 'amro@example.test');
        $admin = $this->profile('Awss Haddad', 'awss@example.test');
        $tenantId = (int) $this->creator->createFor($owner, 'Example Bakery')['tenant_id'];
        $this->member($admin, $tenantId);
        $this->grantToAdmin('billing:pay');
        $this->grantToAdmin('settings:write');

        $roles = $this->roleChecker();
        self::assertTrue($roles->hasPermissionForProfile($owner, 'billing:pay', $tenantId));
        self::assertFalse($roles->hasPermissionForProfile($admin, 'billing:pay', $tenantId));
        // Everything else an admin has, they keep.
        self::assertTrue($roles->hasPermissionForProfile($admin, 'settings:write', $tenantId));

        // A co-owner is a full owner.
        $this->owners->add($tenantId, $admin, $owner);
        RoleChecker::clearCache();
        self::assertTrue($this->roleChecker()->hasPermissionForProfile($admin, 'billing:pay', $tenantId));
    }

    public function testAWorkspaceWithNoOwnerRecordedCapsNothing(): void
    {
        $admin = $this->profile('Awss Haddad', 'awss@example.test');
        $this->pdo->exec("INSERT INTO tenants (name, slug, created_at) VALUES ('Old Shop', 'old-shop', NOW())");
        $tenantId = (int) $this->pdo->lastInsertId();
        $this->member($admin, $tenantId);
        $this->grantToAdmin('billing:pay');

        self::assertTrue($this->roleChecker()->hasPermissionForProfile($admin, 'billing:pay', $tenantId));
    }

    public function testOwnersAreScopedToTheirWorkspace(): void
    {
        $a = $this->profile('Amro Saleh', 'amro@example.test');
        $b = $this->profile('Ahmad Khalil', 'ahmad@example.test');
        $tenantA = (int) $this->creator->createFor($a, 'A')['tenant_id'];
        $tenantB = (int) $this->creator->createFor($b, 'B')['tenant_id'];

        self::assertFalse($this->owners->isOwner($tenantB, $a));
        self::assertSame([$a], array_column($this->owners->list($tenantA), 'profile_id'));
        self::assertSame([$b], array_column($this->owners->list($tenantB), 'profile_id'));
        self::assertFalse($this->owners->remove($tenantB, $a));
        self::assertTrue($this->owners->isOwner($tenantA, $a));
    }

    public function testTheBackfillNamesTheOldestAdminAndIsSafeToRunTwice(): void
    {
        $first = $this->profile('Amro Saleh', 'amro@example.test');
        $second = $this->profile('Awss Haddad', 'awss@example.test');
        $this->pdo->exec("INSERT INTO tenants (name, slug, created_at) VALUES ('Legacy', 'legacy', NOW())");
        $tenantId = (int) $this->pdo->lastInsertId();
        $this->member($first, $tenantId, '2026-01-01 10:00:00');
        $this->member($second, $tenantId, '2026-02-01 10:00:00');

        require_once dirname(__DIR__, 2) . '/database/migrations/300_create_workspace_owners.php';
        $db = $this->database();
        \Database\Migrations\CreateWorkspaceOwners::up($db);
        \Database\Migrations\CreateWorkspaceOwners::up($db);

        self::assertSame([$first], array_column($this->owners->list($tenantId), 'profile_id'));
    }

    private function profile(string $displayName, string $email, string $status = 'active'): int
    {
        $this->pdo->prepare(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled, two_factor_secret,
                 two_factor_backup_codes_version, token_epoch, status, created_at, updated_at)
             VALUES (:n, '', false, NULL, 0, 0, :s, NOW(), NOW())"
        )->execute([':n' => $displayName, ':s' => $status]);
        $id = (int) $this->pdo->lastInsertId();
        (new ProfileEmailRepository($this->pdo))->insert($id, $email, true, true);
        return $id;
    }

    /** An active admin membership. */
    private function member(int $profileId, int $tenantId, ?string $createdAt = null): void
    {
        $this->pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, is_primary, status, created_at)
             VALUES (:p, :t, :r, NULL, true, 'active', :c)"
        )->execute([
            ':p' => $profileId,
            ':t' => $tenantId,
            ':r' => $this->adminRoleId(),
            ':c' => $createdAt ?? date('Y-m-d H:i:s'),
        ]);
    }

    private function adminRoleId(): int
    {
        $stmt = $this->pdo->prepare("SELECT id FROM roles WHERE name = 'admin' AND tenant_id IS NULL");
        $stmt->execute();
        $id = $stmt->fetchColumn();
        self::assertNotFalse($id);
        return (int) $id;
    }

    private function grantToAdmin(string $permission): void
    {
        $find = $this->pdo->prepare('SELECT id FROM permissions WHERE name = ?');
        $find->execute([$permission]);
        $permissionId = $find->fetchColumn();
        if ($permissionId === false) {
            $this->pdo->prepare('INSERT INTO permissions (name, description, created_at) VALUES (?, NULL, NOW())')->execute([$permission]);
            $permissionId = $this->pdo->lastInsertId();
        }
        $roleId = $this->adminRoleId();
        $has = $this->pdo->prepare('SELECT 1 FROM role_permissions WHERE role_id = ? AND permission_id = ?');
        $has->execute([$roleId, (int) $permissionId]);
        if ($has->fetchColumn() === false) {
            $this->pdo->prepare('INSERT INTO role_permissions (role_id, permission_id, created_at) VALUES (?, ?, NOW())')
                ->execute([$roleId, (int) $permissionId]);
        }
        RoleChecker::clearCache();
    }

    private function database(): Database
    {
        $pdo = $this->pdo;
        $db = Database::withFactory(static fn (): PDO => $pdo, 86400, 86400);
        $db->forceConnect();
        return $db;
    }

    private function roleChecker(): RoleChecker
    {
        return new RoleChecker($this->database(), new PermissionRegistry());
    }
}
