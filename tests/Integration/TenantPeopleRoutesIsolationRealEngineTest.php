<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\UsersApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;

/**
 * WHIT-594 (#1144, first slice): the people routes, for a person who belongs to
 * two workspaces.
 *
 * The existing cross-tenant suites prove these routes against a person who
 * belongs to the OTHER workspace only, where the membership lookup returns
 * nothing and every route is a 404. The case that leans on the code is a person
 * who belongs to BOTH: the lookup succeeds, and from there each statement has
 * to stay on the caller's own rows by itself. Tenant A here reaches the shared
 * person through its own membership and tries, route by route, to read or
 * change what tenant B holds about them.
 *
 * Runs on the real engine: SQLite in the unit job, PostgreSQL in the
 * Integration shards.
 */
final class TenantPeopleRoutesIsolationRealEngineTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private const SHARED = 301;

    /** Tenant A's membership of the shared person (primary). */
    private const A_MEMBERSHIP = 311;
    /** Tenant B's memberships of the shared person: primary, and a secondary role. */
    private const B_PRIMARY = 321;
    private const B_SECONDARY = 322;

    private const A_PRIVATE_ROLE = 110;
    private const B_PRIVATE_ROLE = 120;

    private PDO $pdo;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
        $this->pdo = $this->makeSchema();
        TenantContext::setTenantId(self::TENANT_A);
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
    }

    public function testGetShowsOnlyTheCallersOwnMembership(): void
    {
        $response = $this->handler()->get($this->req('GET', '/api/users/' . self::SHARED), ['id' => (string) self::SHARED]);

        $this->assertSame(200, $response->getStatusCode(), $response->getBody());
        $data = $this->data($response);
        $this->assertSame(self::TENANT_A, $data['tenantId']);
        $this->assertSame('user', $data['role'], "Tenant B's administrator role must not show through");
    }

    public function testMembershipListHoldsOnlyTheCallersRows(): void
    {
        $response = $this->handler()->listMemberships(
            $this->req('GET', '/api/users/' . self::SHARED . '/memberships'),
            ['id' => (string) self::SHARED]
        );

        $this->assertSame(200, $response->getStatusCode(), $response->getBody());
        $rows = $this->data($response);
        $this->assertSame([self::A_MEMBERSHIP], array_column($rows, 'id'));
        $this->assertSame([self::TENANT_A], array_unique(array_column($rows, 'tenantId')));
    }

    public function testCannotRemoveTheOtherTenantsMembershipThroughTheSharedPerson(): void
    {
        $response = $this->handler()->removeMembership(
            $this->req('DELETE', '/api/users/' . self::SHARED . '/memberships/' . self::B_SECONDARY),
            ['id' => (string) self::SHARED, 'membershipId' => (string) self::B_SECONDARY]
        );

        $this->assertSame(404, $response->getStatusCode(), $response->getBody());
        $this->assertSame(3, $this->membershipCount(), 'Every membership must survive');
    }

    public function testCannotAddAMembershipWithTheOtherTenantsPrivateRole(): void
    {
        $response = $this->handler()->addMembership(
            $this->req('POST', '/api/users/' . self::SHARED . '/memberships', ['role_id' => self::B_PRIVATE_ROLE]),
            ['id' => (string) self::SHARED]
        );

        $this->assertContains($response->getStatusCode(), [403, 404], $response->getBody());
        $this->assertSame(3, $this->membershipCount(), 'No membership may be created');
        $this->assertSame(0, $this->countRows('SELECT COUNT(*) FROM memberships WHERE role_id = ' . self::B_PRIVATE_ROLE . ' AND tenant_id = ' . self::TENANT_A));
    }

    public function testCannotAddAMembershipInTheOtherTenant(): void
    {
        $response = $this->handler()->addMembership(
            $this->req('POST', '/api/users/' . self::SHARED . '/memberships', [
                'role_id' => self::A_PRIVATE_ROLE,
                'tenant_id' => self::TENANT_B,
            ]),
            ['id' => (string) self::SHARED]
        );

        $this->assertSame(403, $response->getStatusCode(), $response->getBody());
        $this->assertSame(3, $this->countRows('SELECT COUNT(*) FROM memberships WHERE tenant_id = ' . self::TENANT_B));
    }

    public function testAddingARoleLandsOnlyInTheCallersTenant(): void
    {
        $response = $this->handler()->addMembership(
            $this->req('POST', '/api/users/' . self::SHARED . '/memberships', ['role_id' => self::A_PRIVATE_ROLE]),
            ['id' => (string) self::SHARED]
        );

        $this->assertContains($response->getStatusCode(), [200, 201], $response->getBody());
        $this->assertSame(1, $this->countRows('SELECT COUNT(*) FROM memberships WHERE role_id = ' . self::A_PRIVATE_ROLE . ' AND tenant_id = ' . self::TENANT_A));
        $this->assertSame(3, $this->countRows('SELECT COUNT(*) FROM memberships WHERE tenant_id = ' . self::TENANT_B), "Tenant B's rows are unchanged");
    }

    public function testRoleChangeTouchesOnlyTheCallersMembership(): void
    {
        $response = $this->handler()->update(
            $this->req('PATCH', '/api/users/' . self::SHARED, ['role' => 'admin']),
            ['id' => (string) self::SHARED]
        );

        $this->assertSame(200, $response->getStatusCode(), $response->getBody());
        $this->assertSame(1, $this->roleOf(self::A_MEMBERSHIP));
        $this->assertSame(1, $this->roleOf(self::B_PRIMARY), "Tenant B's primary role is unchanged");
        $this->assertSame(self::B_PRIVATE_ROLE, $this->roleOf(self::B_SECONDARY), "Tenant B's secondary role is unchanged");
    }

    public function testCannotGiveTheSharedPersonTheOtherTenantsPrivateRole(): void
    {
        $response = $this->handler()->update(
            $this->req('PATCH', '/api/users/' . self::SHARED, ['role_id' => self::B_PRIVATE_ROLE]),
            ['id' => (string) self::SHARED]
        );

        $this->assertSame(404, $response->getStatusCode(), $response->getBody());
        $this->assertSame(2, $this->roleOf(self::A_MEMBERSHIP));
    }

    public function testRemovingTheSharedPersonRemovesOnlyTheCallersMembership(): void
    {
        $response = $this->handler()->delete(
            $this->req('DELETE', '/api/users/' . self::SHARED),
            ['id' => (string) self::SHARED]
        );

        $this->assertContains($response->getStatusCode(), [200, 204], $response->getBody());
        $this->assertSame(0, $this->countRows('SELECT COUNT(*) FROM memberships WHERE tenant_id = ' . self::TENANT_A . ' AND profile_id = ' . self::SHARED));
        $this->assertSame(2, $this->countRows('SELECT COUNT(*) FROM memberships WHERE tenant_id = ' . self::TENANT_B . ' AND profile_id = ' . self::SHARED), "Tenant B's memberships survive");
        $this->assertSame(1, $this->countRows('SELECT COUNT(*) FROM profiles WHERE id = ' . self::SHARED), 'The global profile survives');
    }

    public function testTheUsersListNeverShowsTheOtherTenantsRows(): void
    {
        $response = $this->handler()->list($this->req('GET', '/api/users'));

        $this->assertSame(200, $response->getStatusCode(), $response->getBody());
        foreach ($this->data($response) as $row) {
            $this->assertSame(self::TENANT_A, (int) $row['tenantId']);
            $this->assertNotSame('b-only@b.example', $row['email']);
        }
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @return array<mixed>
     */
    private function data(\Whity\Sdk\Http\Response $response): array
    {
        $decoded = json_decode($response->getBody(), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('data', $decoded);
        $this->assertIsArray($decoded['data']);

        return $decoded['data'];
    }

    private function membershipCount(): int
    {
        return $this->countRows('SELECT COUNT(*) FROM memberships WHERE profile_id = ' . self::SHARED);
    }

    private function roleOf(int $membershipId): int
    {
        return $this->countRows('SELECT role_id FROM memberships WHERE id = ' . $membershipId);
    }

    private function countRows(string $sql): int
    {
        $stmt = $this->pdo->query($sql);
        self::assertInstanceOf(\PDOStatement::class, $stmt);

        return (int) $stmt->fetchColumn();
    }

    private function handler(): UsersApiHandler
    {
        $hooks = $this->createMock(HookManager::class);
        $hooks->method('dispatch')->willReturnArgument(1);

        return new UsersApiHandler($this->pdo, $hooks);
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function req(string $method, string $path, ?array $body = null): Request
    {
        $request = new Request($method, $path, [], $body !== null ? (string) json_encode($body) : '');
        $request->user = (object) ['profile_id' => 99, 'active_tenant_id' => self::TENANT_A];

        return $request;
    }

    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make(true);

        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (0, 'system')");
        $pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 'tenant-a'), (2, 'tenant-b')");
        $pdo->exec("
            INSERT OR IGNORE INTO roles (id, name, description, tenant_id, created_at) VALUES
                (1, 'admin', '', NULL, datetime('now')),
                (2, 'user',  '', NULL, datetime('now'))
        ");
        $pdo->exec("
            INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES
                (110, 'a-private', '', 1, datetime('now')),
                (120, 'b-private', '', 2, datetime('now'))
        ");

        $pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, status, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at) VALUES
                (301, 'Shared Person', '', 'active', false, 0, 0, datetime('now'), datetime('now')),
                (302, 'B Only',        '', 'active', false, 0, 0, datetime('now'), datetime('now'))
        ");
        $pdo->exec("
            INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at) VALUES
                (301, 'shared@example.com', true, true, datetime('now')),
                (302, 'b-only@b.example',   true, true, datetime('now'))
        ");

        // The shared person: an ordinary user in A; the administrator of B, with a
        // second, B-private role on a non-primary membership.
        $pdo->exec("
            INSERT INTO memberships (id, profile_id, tenant_id, role_id, ou_id, status, is_primary, created_at) VALUES
                (311, 301, 1, 2,   NULL, 'active', true,  datetime('now')),
                (321, 301, 2, 1,   NULL, 'active', true,  datetime('now')),
                (322, 301, 2, 120, NULL, 'active', false, datetime('now')),
                (331, 302, 2, 2,   NULL, 'active', true,  datetime('now'))
        ");

        return $pdo;
    }
}
