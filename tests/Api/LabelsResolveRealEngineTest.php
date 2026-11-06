<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\LabelsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Labels\LabelResolver;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;

/**
 * #1019: `POST /api/labels/resolve` names a few units and roles for a caller
 * holding the asking surface's permission, and nothing else. Runs on SQLite, and
 * on real PostgreSQL when `PHPUNIT_PG_DSN` is set, as CI does.
 *
 * Every refusal test is paired with a test of the ordinary state, so a handler
 * that refused everything could not pass the suite.
 */
final class LabelsResolveRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const OTHER_TENANT = 2;

    /** documents:read only. No directory permission at all. */
    private const READER = 10;
    /** documents:route only. */
    private const ROUTER = 11;
    /** A member with no permission. */
    private const MEMBER = 12;
    /** ous:read and roles:read, but no document permission. */
    private const DIRECTORY_READER = 13;
    /** documents:read in the other tenant. */
    private const STRANGER = 20;

    private const OU_FACULTY = 500;
    private const OU_DEPT = 501;
    private const OU_FOREIGN = 502;

    private const ROLE_OWN = 900;
    private const ROLE_GLOBAL = 905;
    private const ROLE_FOREIGN = 903;

    private PDO $pdo;
    private SettingsService $settings;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = SchemaFromMigrations::make();
        $this->settings = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo)
        );
        $this->seed();
        TenantContext::setTenantId(self::TENANT);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    // ── what it answers ─────────────────────────────────────────────────────

    public function testADocumentReaderGetsUnitAndRoleNamesWithoutTheDirectory(): void
    {
        $response = $this->post(self::READER, [
            'surface' => 'documents',
            'ous' => [self::OU_FACULTY, self::OU_DEPT],
            'roles' => [self::ROLE_OWN, self::ROLE_GLOBAL],
        ]);

        self::assertSame(200, $response->getStatusCode(), $response->getBody());
        self::assertSame([
            'ous' => [(string) self::OU_FACULTY => 'Faculty of Engineering', (string) self::OU_DEPT => 'Department of Civil Engineering'],
            'roles' => [(string) self::ROLE_OWN => 'department-head', (string) self::ROLE_GLOBAL => 'base-reviewer'],
        ], $this->data($response));
    }

    public function testTheBodyCarriesNothingButLabels(): void
    {
        $response = $this->post(self::READER, ['surface' => 'documents', 'ous' => [self::OU_DEPT]]);

        $decoded = json_decode($response->getBody(), true);
        self::assertSame(['data'], array_keys($decoded));
        self::assertSame(['ous', 'roles'], array_keys($decoded['data']));
        // A label is a string, never a record: no parent, slug or description.
        self::assertSame('Department of Civil Engineering', $decoded['data']['ous'][(string) self::OU_DEPT]);
        self::assertStringNotContainsString('demo-dept', $response->getBody());
        self::assertStringNotContainsString('parent', $response->getBody());
    }

    public function testEmptyKindsAreObjectsSoTheClientIndexesThemTheSameWay(): void
    {
        $response = $this->post(self::READER, ['surface' => 'documents']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"data":{"ous":{},"roles":{}}}', $response->getBody());
    }

    public function testTheRoutingSurfaceIsGatedOnDocumentsRoute(): void
    {
        $ok = $this->post(self::ROUTER, ['surface' => 'documents.route', 'roles' => [self::ROLE_OWN]]);
        self::assertSame(200, $ok->getStatusCode(), $ok->getBody());
        self::assertSame([(string) self::ROLE_OWN => 'department-head'], $this->data($ok)['roles']);

        // The same caller is not a document reader, so the `documents` surface is
        // not theirs to name.
        $refused = $this->post(self::ROUTER, ['surface' => 'documents', 'roles' => [self::ROLE_OWN]]);
        self::assertSame(403, $refused->getStatusCode());
    }

    // ── tenant isolation ────────────────────────────────────────────────────

    public function testAnotherTenantsIdsComeBackAbsentLikeIdsThatDoNotExist(): void
    {
        $response = $this->post(self::READER, [
            'surface' => 'documents',
            'ous' => [self::OU_DEPT, self::OU_FOREIGN, 999999],
            'roles' => [self::ROLE_OWN, self::ROLE_FOREIGN, 999999],
        ]);

        self::assertSame(200, $response->getStatusCode(), $response->getBody());
        self::assertSame([
            'ous' => [(string) self::OU_DEPT => 'Department of Civil Engineering'],
            'roles' => [(string) self::ROLE_OWN => 'department-head'],
        ], $this->data($response));
        self::assertStringNotContainsString('Other Faculty', $response->getBody());
        self::assertStringNotContainsString('other-secret-role', $response->getBody());
    }

    public function testTheOtherTenantSeesItsOwnNamesAndNotThese(): void
    {
        TenantContext::reset();
        TenantContext::setTenantId(self::OTHER_TENANT);

        $response = $this->post(self::STRANGER, [
            'surface' => 'documents',
            'ous' => [self::OU_FACULTY, self::OU_FOREIGN],
            'roles' => [self::ROLE_OWN, self::ROLE_FOREIGN],
        ]);

        self::assertSame(200, $response->getStatusCode(), $response->getBody());
        self::assertSame([
            'ous' => [(string) self::OU_FOREIGN => 'Other Faculty'],
            'roles' => [(string) self::ROLE_FOREIGN => 'other-secret-role'],
        ], $this->data($response));
    }

    public function testAPermissionHeldInAnotherTenantDoesNotCount(): void
    {
        // STRANGER holds documents:read, but only in the other tenant.
        $response = $this->post(self::STRANGER, ['surface' => 'documents', 'ous' => [self::OU_FACULTY]]);

        self::assertSame(403, $response->getStatusCode());
        self::assertStringNotContainsString('Faculty', $response->getBody());
    }

    // ── who may ask ─────────────────────────────────────────────────────────

    public function testAMemberWithoutTheSurfacePermissionIsRefusedAndToldWhich(): void
    {
        $response = $this->post(self::MEMBER, ['surface' => 'documents', 'ous' => [self::OU_FACULTY]]);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(CorePermissions::DOCUMENTS_READ, json_decode($response->getBody(), true)['required']);
        self::assertStringNotContainsString('Faculty', $response->getBody());
    }

    public function testDirectoryPermissionsDoNotStandInForTheSurfacePermission(): void
    {
        $response = $this->post(self::DIRECTORY_READER, ['surface' => 'documents', 'ous' => [self::OU_FACULTY]]);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testAnUnauthenticatedCallerIsRefused(): void
    {
        $request = new Request('POST', '/api/v1/labels/resolve', [], '{"surface":"documents","ous":[500]}');

        self::assertSame(401, $this->handler()->resolve($request)->getStatusCode());
    }

    // ── what it refuses to be asked ─────────────────────────────────────────

    public function testPeopleAreNotResolvedHere(): void
    {
        $response = $this->post(self::READER, ['surface' => 'documents', 'profiles' => [self::MEMBER]]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringNotContainsString('member@', $response->getBody());
    }

    public function testAnUnknownSurfaceIsRefused(): void
    {
        self::assertSame(422, $this->post(self::READER, ['surface' => 'users', 'ous' => [1]])->getStatusCode());
        self::assertSame(422, $this->post(self::READER, ['ous' => [1]])->getStatusCode());
    }

    public function testMalformedIdListsAreRefused(): void
    {
        foreach ([['ous' => 'x'], ['ous' => [0]], ['ous' => ['500']], ['ous' => [1.5]], ['roles' => ['a' => 1]]] as $bad) {
            $response = $this->post(self::READER, ['surface' => 'documents'] + $bad);
            self::assertSame(422, $response->getStatusCode(), json_encode($bad) ?: '');
        }
    }

    // ── the cap ─────────────────────────────────────────────────────────────

    public function testTheDefaultCapIsAHundredIdsAcrossKinds(): void
    {
        $atCap = $this->post(self::READER, [
            'surface' => 'documents',
            'ous' => range(1, 60),
            'roles' => range(1, 40),
        ]);
        self::assertSame(200, $atCap->getStatusCode(), $atCap->getBody());

        $overCap = $this->post(self::READER, [
            'surface' => 'documents',
            'ous' => range(1, 60),
            'roles' => range(1, 41),
        ]);
        self::assertSame(422, $overCap->getStatusCode());
    }

    public function testRepeatedIdsCountOnce(): void
    {
        $this->settings->setTenant(self::TENANT, SettingsRegistry::LABELS_RESOLVE_MAX_IDS, '2');

        $response = $this->post(self::READER, [
            'surface' => 'documents',
            'ous' => [self::OU_FACULTY, self::OU_FACULTY, self::OU_DEPT],
        ]);

        self::assertSame(200, $response->getStatusCode(), $response->getBody());
    }

    public function testTheCapResolvesPerTenantThenGlobal(): void
    {
        $this->settings->setGlobal(SettingsRegistry::LABELS_RESOLVE_MAX_IDS, '3');
        $body = ['surface' => 'documents', 'ous' => [1, 2, 3, 4]];

        self::assertSame(422, $this->post(self::READER, $body)->getStatusCode(), 'the global cap applies');

        $this->settings->setTenant(self::TENANT, SettingsRegistry::LABELS_RESOLVE_MAX_IDS, '4');
        self::assertSame(200, $this->post(self::READER, $body)->getStatusCode(), 'the tenant override wins');

        TenantContext::reset();
        TenantContext::setTenantId(self::OTHER_TENANT);
        self::assertSame(
            422,
            $this->post(self::STRANGER, $body)->getStatusCode(),
            'one tenant raising its cap does not raise another\'s'
        );
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $body
     */
    private function post(int $profileId, array $body): \Whity\Sdk\Http\Response
    {
        $request = new Request('POST', '/api/v1/labels/resolve', [], (string) json_encode($body));
        $request->user = (object) ['profile_id' => $profileId];

        return $this->handler()->resolve($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function data(\Whity\Sdk\Http\Response $response): array
    {
        $decoded = json_decode($response->getBody(), true);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['data'] ?? null);

        return $decoded['data'];
    }

    private function handler(): LabelsApiHandler
    {
        $pdo = $this->pdo;
        $db = Database::withFactory(static fn (): PDO => $pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();

        return new LabelsApiHandler(
            new LabelResolver($this->pdo),
            new RoleChecker($db, new PermissionRegistry()),
            $this->settings,
        );
    }

    private function seed(): void
    {
        $this->pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 'Ministry of Records') ON CONFLICT DO NOTHING");
        $this->pdo->exec("INSERT INTO tenants (id, name) VALUES (2, 'Other') ON CONFLICT DO NOTHING");
        $this->pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES
            (900, 'department-head', '', 1, NOW()),
            (901, 'reader', '', 1, NOW()),
            (902, 'router', '', 1, NOW()),
            (904, 'directory', '', 1, NOW()),
            (906, 'plain', '', 1, NOW())");
        $this->pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES (903, 'other-secret-role', '', 2, NOW())");
        $this->pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES (905, 'base-reviewer', '', NULL, NOW())");

        $this->pdo->exec("INSERT INTO organizational_units (id, tenant_id, parent_id, name, slug, description) VALUES
            (500, 1, NULL, 'Faculty of Engineering', 'demo-faculty', 'A faculty'),
            (501, 1, 500, 'Department of Civil Engineering', 'demo-dept-civil', 'A department'),
            (502, 2, NULL, 'Other Faculty', 'other-faculty', 'Another tenant')");

        foreach ([
            self::READER => [1, 901],
            self::ROUTER => [1, 902],
            self::MEMBER => [1, 906],
            self::DIRECTORY_READER => [1, 904],
            self::STRANGER => [2, 903],
        ] as $id => [$tenant, $role]) {
            $stmt = $this->pdo->prepare("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at) VALUES (:id, '', 'x', false, 0, 0, NOW(), NOW())");
            $stmt->execute([':id' => $id]);
            $stmt = $this->pdo->prepare('INSERT INTO profile_emails (profile_id, email, is_primary, created_at) VALUES (:id, :email, true, NOW())');
            $stmt->execute([':id' => $id, ':email' => "member{$id}@example.test"]);
            $stmt = $this->pdo->prepare("INSERT INTO memberships (profile_id, tenant_id, role_id, is_primary, status, created_at) VALUES (:id, :tenant, :role, true, 'active', NOW())");
            $stmt->execute([':id' => $id, ':tenant' => $tenant, ':role' => $role]);
        }

        $this->grant(901, CorePermissions::DOCUMENTS_READ);
        $this->grant(902, CorePermissions::DOCUMENTS_ROUTE);
        $this->grant(904, CorePermissions::OUS_READ);
        $this->grant(904, CorePermissions::ROLES_READ);
        $this->grant(903, CorePermissions::DOCUMENTS_READ);
    }

    private function grant(int $roleId, string $permission): void
    {
        $this->pdo->prepare('INSERT INTO permissions (name, description, created_at) VALUES (:name, \'\', NOW()) ON CONFLICT (name) DO NOTHING')
            ->execute([':name' => $permission]);
        $stmt = $this->pdo->prepare(
            'INSERT INTO role_permissions (role_id, permission_id, created_at)
             SELECT :role, id, NOW() FROM permissions WHERE name = :name
             ON CONFLICT DO NOTHING'
        );
        $stmt->execute([':role' => $roleId, ':name' => $permission]);
    }
}
