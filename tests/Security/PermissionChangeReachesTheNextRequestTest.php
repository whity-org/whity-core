<?php

declare(strict_types=1);

namespace Tests\Security;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;
use Whity\Core\Delegation\DelegationRepository;
use Whity\Core\Delegation\DelegationService;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Http\HttpKernel;
use Whity\Http\Middleware\EnforceTenantIsolation;
use Whity\Http\RbacMiddleware;

/**
 * A permission taken away is gone on the very next request, on the same worker
 * (#1144, Phase 4).
 *
 * Effective permissions are cached in process statics. A worker serves many
 * requests, and a change can be made by a request on ANOTHER worker, which
 * cannot reach this one's memory. So the only thing that keeps a revoke from
 * lingering is the cache being dropped after every request. PR #701 found a
 * revoke reaching one worker in eight.
 *
 * This drives one real {@see HttpKernel}, with the real RBAC middleware and the
 * real delegation-aware {@see RoleChecker}, through a sequence of requests on
 * a migrated schema. Between requests the database is changed directly, as
 * another worker would change it, and nothing here clears any cache: only the
 * kernel's own per-request reset runs.
 */
final class PermissionChangeReachesTheNextRequestTest extends TestCase
{
    private const SECRET = 'permission-change-test-secret-padded-for-hs256-key';
    private const PERMISSION = 'users:read';
    private const TENANT = 1;

    private PDO $pdo;
    private HttpKernel $kernel;

    protected function setUp(): void
    {
        TenantContext::reset();
        RoleChecker::clearCache();
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (1, 'tenant-a')");
        $this->kernel = $this->kernel();
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        RoleChecker::clearCache();
    }

    public function testARoleLosingThePermissionIsRefusedOnTheNextRequest(): void
    {
        $this->grant('user', self::PERMISSION);
        $member = $this->person('user');

        self::assertSame(200, $this->call($member), 'Fixture: the role grants it.');

        $this->pdo->exec('DELETE FROM role_permissions WHERE role_id = ' . $this->role('user'));

        self::assertSame(403, $this->call($member), 'The next request on this worker must see the role without it.');
    }

    public function testAPersonMovedToASmallerRoleIsRefusedOnTheNextRequest(): void
    {
        $this->grant('admin', self::PERMISSION);
        $person = $this->person('admin');

        self::assertSame(200, $this->call($person));

        $this->pdo->exec('UPDATE memberships SET role_id = ' . $this->role('user') . ' WHERE profile_id = ' . $person);

        self::assertSame(403, $this->call($person));
    }

    /**
     * A delegation is bounded by its grantor at every use: when the grantor
     * loses the permission, the grantee loses it on their next request.
     */
    public function testADelegationEndsOnTheNextRequestWhenItsGrantorLosesThePermission(): void
    {
        $this->grant('admin', self::PERMISSION);
        $grantor = $this->person('admin');
        $grantee = $this->person('user');

        self::assertSame(403, $this->call($grantee), 'Fixture: the grantee does not hold it on their own.');

        $this->delegations()->delegate(self::TENANT, $grantor, DelegationRepository::GRANTEE_PROFILE, $grantee, [self::PERMISSION], null);
        self::assertSame(200, $this->call($grantee), 'The delegation grants it.');

        $this->pdo->exec("UPDATE memberships SET status = 'suspended' WHERE profile_id = " . $grantor);

        self::assertSame(403, $this->call($grantee), 'The grant must end with its grantor holding the permission.');
    }

    // ─── plumbing ────────────────────────────────────────────────────────────

    private function kernel(): HttpKernel
    {
        $db = Database::withFactory(fn (): PDO => $this->pdo, 86400, 86400);
        $jwt = new JwtParser(self::SECRET);
        $checker = new RoleChecker($db, new PermissionRegistry(), null, $this->delegations());

        $router = new Router('');
        $router->register('GET', '/api/probe', static fn (): Response => Response::json(['ok' => true]), null, null, self::PERMISSION);

        $kernel = new HttpKernel($router, new RbacMiddleware($jwt, $checker));
        $kernel->use(new EnforceTenantIsolation($jwt));

        return $kernel;
    }

    private function delegations(): DelegationService
    {
        $db = Database::withFactory(fn (): PDO => $this->pdo, 86400, 86400);

        return new DelegationService(
            new DelegationRepository($this->pdo),
            new RoleChecker($db, new PermissionRegistry()),
            new PermissionRegistry()
        );
    }

    private function call(int $profile): int
    {
        $token = (new JwtParser(self::SECRET))->create([
            'profile_id' => $profile,
            'active_tenant_id' => self::TENANT,
            'email' => "person{$profile}@example.com",
            'role' => '',
            'token_epoch' => 0,
        ]);

        return $this->kernel->handle(new Request('GET', '/api/probe', ['Authorization' => "Bearer {$token}"]))->getStatusCode();
    }

    private function role(string $name): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM roles WHERE name = ? AND tenant_id IS NULL');
        $stmt->execute([$name]);

        return (int) $stmt->fetchColumn();
    }

    private function grant(string $role, string $permission): void
    {
        $this->pdo->prepare('INSERT OR IGNORE INTO permissions (name, description, created_at) VALUES (?, NULL, NOW())')->execute([$permission]);
        $stmt = $this->pdo->prepare('SELECT id FROM permissions WHERE name = ?');
        $stmt->execute([$permission]);
        $this->pdo->prepare('INSERT OR IGNORE INTO role_permissions (role_id, permission_id, created_at) VALUES (?, ?, NOW())')
            ->execute([$this->role($role), (int) $stmt->fetchColumn()]);
    }

    private function person(string $role): int
    {
        $this->pdo->exec(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES ('', '', false, 0, 0, NOW(), NOW())"
        );
        $profile = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at) VALUES (?, ?, ?, 'active', NOW())")
            ->execute([$profile, self::TENANT, $this->role($role)]);

        return $profile;
    }
}
