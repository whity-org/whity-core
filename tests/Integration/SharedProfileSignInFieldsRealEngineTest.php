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
 * WHIT-909 (#1144): a person's sign-in fields are global, so a workspace may
 * change them only for people who belong to it alone.
 *
 * `PATCH /api/users/{id}` writes the primary email, the password and the
 * account status onto the PROFILE, which every workspace the person belongs to
 * shares. Display name already refused the change for a person who also
 * belongs to another workspace; these three did not. This proves, on the real
 * engine (SQLite here, PostgreSQL in the Integration shards), that each one is
 * now refused with the same `shared_profile` 409 and that the profile row is
 * left exactly as it was, while a workspace's own people and the system tenant
 * keep working.
 */
final class SharedProfileSignInFieldsRealEngineTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;
    private const SYSTEM_TENANT = 0;

    /** Belongs to tenant A only. */
    private const OWN_PERSON = 201;
    /** Belongs to tenant B, and also to tenant A. */
    private const SHARED_PERSON = 202;

    private const NEW_PASSWORD = 'a-new-password-for-this-test';

    private PDO $pdo;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
        $this->pdo = $this->makeSchema();
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
    }

    public function testTenantCannotChangeTheEmailOfAPersonSharedWithAnotherTenant(): void
    {
        $before = $this->profileState(self::SHARED_PERSON);

        $response = $this->patch(self::TENANT_A, self::SHARED_PERSON, ['email' => 'someone-else@a.example']);

        $this->assertSharedProfileRefusal($response);
        $this->assertSame($before, $this->profileState(self::SHARED_PERSON), 'The shared profile must be untouched');
    }

    public function testTenantCannotSetThePasswordOfAPersonSharedWithAnotherTenant(): void
    {
        $before = $this->profileState(self::SHARED_PERSON);

        $response = $this->patch(self::TENANT_A, self::SHARED_PERSON, ['password' => self::NEW_PASSWORD]);

        $this->assertSharedProfileRefusal($response);
        $this->assertSame($before, $this->profileState(self::SHARED_PERSON), 'The shared profile must be untouched');
    }

    public function testTenantCannotDeactivateAPersonSharedWithAnotherTenant(): void
    {
        $before = $this->profileState(self::SHARED_PERSON);

        $response = $this->patch(self::TENANT_A, self::SHARED_PERSON, ['accountStatus' => 'inactive']);

        $this->assertSharedProfileRefusal($response);
        $this->assertSame($before, $this->profileState(self::SHARED_PERSON), 'The shared profile must be untouched');
    }

    /**
     * The whole path, from the tenant's side: adding a person by an email that
     * already has a profile elsewhere attaches that profile, and must not make
     * its sign-in fields the tenant's to change.
     */
    public function testAttachingAnExistingPersonByEmailDoesNotHandOverTheirSignIn(): void
    {
        $this->pdo->exec('DELETE FROM memberships WHERE profile_id = ' . self::SHARED_PERSON . ' AND tenant_id = ' . self::TENANT_A);
        $before = $this->profileState(self::SHARED_PERSON);

        TenantContext::setTenantId(self::TENANT_A);
        $created = $this->handler()->create($this->req('POST', '/api/users', [
            'email' => 'shared@b.example',
            'password' => self::NEW_PASSWORD,
        ], self::TENANT_A));
        $this->assertSame(201, $created->getStatusCode(), $created->getBody());
        $this->assertSame($before, $this->profileState(self::SHARED_PERSON), 'Attaching must not rewrite the existing profile');

        TenantContext::reset();
        $response = $this->patch(self::TENANT_A, self::SHARED_PERSON, ['password' => self::NEW_PASSWORD]);

        $this->assertSharedProfileRefusal($response);
        $this->assertSame($before, $this->profileState(self::SHARED_PERSON));
    }

    /**
     * A refused field must refuse the whole request: nothing else in the same
     * body may be applied around it.
     */
    public function testARefusedFieldLeavesTheRestOfTheRequestUnapplied(): void
    {
        $roleBefore = $this->membershipRole(self::SHARED_PERSON, self::TENANT_A);

        $response = $this->patch(self::TENANT_A, self::SHARED_PERSON, [
            'role' => 'admin',
            'password' => self::NEW_PASSWORD,
        ]);

        $this->assertSharedProfileRefusal($response);
        $this->assertSame($roleBefore, $this->membershipRole(self::SHARED_PERSON, self::TENANT_A));
    }

    public function testTenantStillChangesTheSignInFieldsOfItsOwnPeople(): void
    {
        $before = $this->profileState(self::OWN_PERSON);

        $response = $this->patch(self::TENANT_A, self::OWN_PERSON, [
            'email' => 'renamed@a.example',
            'password' => self::NEW_PASSWORD,
            'accountStatus' => 'inactive',
        ]);

        $this->assertSame(200, $response->getStatusCode(), $response->getBody());
        $after = $this->profileState(self::OWN_PERSON);
        $this->assertSame('renamed@a.example', $after['email']);
        $this->assertSame('inactive', $after['status']);
        $this->assertNotSame($before['password_hash'], $after['password_hash']);
        $this->assertTrue(password_verify(self::NEW_PASSWORD, $after['password_hash']));
    }

    public function testSystemTenantStillChangesTheSignInFieldsOfASharedPerson(): void
    {
        $response = $this->patch(self::SYSTEM_TENANT, self::SHARED_PERSON, ['password' => self::NEW_PASSWORD]);

        $this->assertSame(200, $response->getStatusCode(), $response->getBody());
        $this->assertTrue(password_verify(self::NEW_PASSWORD, $this->profileState(self::SHARED_PERSON)['password_hash']));
    }

    /**
     * The other tenant is asked too: tenant B is just as unable to change the
     * person through ITS membership, because tenant A relies on them as well.
     */
    public function testTheRefusalHoldsFromEitherSide(): void
    {
        $before = $this->profileState(self::SHARED_PERSON);

        $response = $this->patch(self::TENANT_B, self::SHARED_PERSON, ['email' => 'moved@b.example']);

        $this->assertSharedProfileRefusal($response);
        $this->assertSame($before, $this->profileState(self::SHARED_PERSON));
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param array<string, mixed> $body
     */
    private function patch(int $tenantId, int $profileId, array $body): \Whity\Sdk\Http\Response
    {
        TenantContext::reset();
        TenantContext::setTenantId($tenantId);

        return $this->handler()->update(
            $this->req('PATCH', '/api/users/' . $profileId, $body, $tenantId),
            ['id' => (string) $profileId]
        );
    }

    private function assertSharedProfileRefusal(\Whity\Sdk\Http\Response $response): void
    {
        $this->assertSame(409, $response->getStatusCode(), $response->getBody());
        $decoded = json_decode($response->getBody(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('shared_profile', $decoded['details']['reason'] ?? null, $response->getBody());
    }

    /**
     * @return array{email: string, password_hash: string, status: string, token_epoch: int}
     */
    private function profileState(int $profileId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT pe.email, p.password_hash, p.status, p.token_epoch
               FROM profiles p
               JOIN profile_emails pe ON pe.profile_id = p.id AND pe.is_primary = true
              WHERE p.id = ?'
        );
        $stmt->execute([$profileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return [
            'email' => (string) $row['email'],
            'password_hash' => (string) $row['password_hash'],
            'status' => (string) $row['status'],
            'token_epoch' => (int) $row['token_epoch'],
        ];
    }

    private function membershipRole(int $profileId, int $tenantId): int
    {
        $stmt = $this->pdo->prepare('SELECT role_id FROM memberships WHERE profile_id = ? AND tenant_id = ?');
        $stmt->execute([$profileId, $tenantId]);

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
    private function req(string $method, string $path, ?array $body, int $tenantId): Request
    {
        $request = new Request($method, $path, [], $body !== null ? (string) json_encode($body) : '');
        $request->user = (object) ['profile_id' => 99, 'active_tenant_id' => $tenantId];

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

        $hash = password_hash('the original password', PASSWORD_BCRYPT);
        $insert = $pdo->prepare(
            "INSERT INTO profiles (id, display_name, password_hash, status, two_factor_enabled,
                 two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (?, ?, ?, 'active', false, 0, 0, datetime('now'), datetime('now'))"
        );
        $insert->execute([self::OWN_PERSON, 'Own Person', $hash]);
        $insert->execute([self::SHARED_PERSON, 'Shared Person', $hash]);

        $pdo->exec("
            INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at) VALUES
                (201, 'own@a.example',    true, true, datetime('now')),
                (202, 'shared@b.example', true, true, datetime('now'))
        ");

        // The shared person is tenant B's administrator, and an ordinary user in tenant A.
        $pdo->exec("
            INSERT INTO memberships (id, profile_id, tenant_id, role_id, ou_id, status, created_at) VALUES
                (201, 201, 1, 2, NULL, 'active', datetime('now')),
                (202, 202, 2, 1, NULL, 'active', datetime('now')),
                (203, 202, 1, 2, NULL, 'active', datetime('now'))
        ");

        return $pdo;
    }
}
