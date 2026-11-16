<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\SsoWorkspaceSetupHandler;
use Whity\Auth\AuthHandler;
use Whity\Auth\JwtParser;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Identity\IdentityProviderRepository;
use Whity\Core\Identity\ProfileEmailRepository;
use Whity\Core\Request;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\FederatedWorkspaceProvisioner;

/**
 * "Create your workspace" after a sign-in that found no workspace (WHIT-994),
 * exercised through GET/POST /api/auth/sso/workspace against the real schema.
 *
 * The cookie the SSO callback sets is planted in $_COOKIE, the way the
 * tenant-selection tests plant theirs: a CLI test cannot read back a
 * Set-Cookie header. Every gate of FederatedWorkspaceProvisioner is checked
 * here through the endpoint, so a person can only be OFFERED the step, and
 * only CREATE a workspace, when the provisioner itself agrees.
 */
final class SsoWorkspaceSetupRealEngineTest extends TestCase
{
    private const SECRET = 'internal_hs256_secret_min_32_chars_xxxx';

    private PDO $pdo;
    private JwtParser $jwt;
    private SettingsService $settings;
    private IdentityProviderRepository $providers;
    private SsoWorkspaceSetupHandler $handler;
    private int $providerId;        // the operator's own provider (tenant 0)
    private int $tenantProviderId;  // a tenant's bring-your-own provider
    private int $acmeId;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->jwt = new JwtParser(self::SECRET);
        $this->settings = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo)
        );
        $this->settings->setGlobal(SettingsRegistry::SSO_SELF_PROVISION_WORKSPACE, 'true');
        $this->providers = new IdentityProviderRepository($this->pdo);

        $auth = new AuthHandler(
            $this->pdo,
            $this->jwt,
            null,
            null,
            null,
            new NullLogger(),
            new AuditLogger($this->pdo, new NullLogger()),
            null
        );
        $this->handler = new SsoWorkspaceSetupHandler(
            $this->jwt,
            $this->providers,
            new FederatedWorkspaceProvisioner($this->pdo, $this->settings),
            $auth,
            $this->settings,
        );

        $this->pdo->exec("INSERT INTO tenants (name, slug, created_at) VALUES ('Acme', 'acme', NOW())");
        $this->acmeId = (int) $this->pdo->lastInsertId();

        $this->providerId = $this->providers->insert(0, self::providerRow('whity_id'));
        $this->tenantProviderId = $this->providers->insert($this->acmeId, self::providerRow('acme_idp'));

        $_COOKIE = [];
        $_GET = [];
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];
        $_GET = [];
    }

    /** @return array<string, mixed> */
    private static function providerRow(string $key): array
    {
        return [
            'provider_key' => $key,
            'display_name' => 'Whity ID',
            'client_id' => 'client',
            'client_secret_encrypted' => null,
            'issuer' => 'https://id.example.test',
            'discovery_url' => null,
            'scopes' => 'openid email profile',
            'enabled' => true,
        ];
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

    private function membership(int $profileId, int $tenantId, string $status): void
    {
        $roleId = (int) $this->scalar('SELECT id FROM roles ORDER BY id ASC LIMIT 1');
        $this->pdo->prepare("INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, status, created_at)
            VALUES (:p, :t, :r, NULL, :s, NOW())")
            ->execute([':p' => $profileId, ':t' => $tenantId, ':r' => $roleId, ':s' => $status]);
    }

    /** Plant the cookie the callback sets for this person. */
    private function signedInWithoutWorkspace(int $profileId, string $email, ?int $providerId = null, string $type = SsoWorkspaceSetupHandler::TOKEN_TYPE): void
    {
        $_COOKIE['workspace_setup_token'] = $this->jwt->create([
            'profile_id' => $profileId,
            'email' => $email,
            'provider_id' => $providerId ?? $this->providerId,
        ], 900, $type);
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     * @phpstan-impure
     */
    private function show(): array
    {
        $res = $this->handler->show(new Request('GET', '/api/v1/auth/sso/workspace', [], ''));
        $body = json_decode($res->getBody(), true);
        return ['status' => $res->getStatusCode(), 'body' => is_array($body) ? $body : []];
    }

    /**
     * @param array<string, mixed> $body
     * @return array{status: int, body: array<string, mixed>}
     * @phpstan-impure
     */
    private function create(array $body): array
    {
        $res = $this->handler->create(new Request('POST', '/api/v1/auth/sso/workspace', [], (string) json_encode($body)));
        $decoded = json_decode($res->getBody(), true);
        return ['status' => $res->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    private function scalar(string $sql): string
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt);
        return (string) $stmt->fetchColumn();
    }

    /** @phpstan-impure */
    private function tenantCount(): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM tenants');
    }

    /** @return list<array<string, mixed>> */
    private function membershipsOf(int $profileId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.tenant_id, m.status, r.name AS role, t.name AS tenant_name, t.slug
               FROM memberships m JOIN roles r ON r.id = m.role_id JOIN tenants t ON t.id = m.tenant_id
              WHERE m.profile_id = :p'
        );
        $stmt->execute([':p' => $profileId]);
        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // ── which screen ─────────────────────────────────────────────────────────

    public function testTheCreateStepIsOfferedWithTheirNameAndAddress(): void
    {
        $pid = $this->profile('Amro Saleh', 'amro@example.test');
        $this->signedInWithoutWorkspace($pid, 'amro@example.test');

        $res = $this->show();

        self::assertSame(200, $res['status']);
        self::assertSame('create', $res['body']['data']['state']);
        self::assertSame('amro@example.test', $res['body']['data']['email']);
        self::assertSame('Amro Saleh', $res['body']['data']['suggested_name']);
        self::assertArrayNotHasKey('workspaces', $res['body']['data']);
    }

    public function testWithTheSettingOffTheyAreToldTheyNeedAnInvitation(): void
    {
        $this->settings->setGlobal(SettingsRegistry::SSO_SELF_PROVISION_WORKSPACE, 'false');
        $pid = $this->profile('Awss Haddad', 'awss@example.test');
        $this->signedInWithoutWorkspace($pid, 'awss@example.test');

        $res = $this->show();

        self::assertSame(200, $res['status']);
        self::assertSame('needs_invite', $res['body']['data']['state']);
        self::assertSame('awss@example.test', $res['body']['data']['email']);
        self::assertArrayNotHasKey('suggested_name', $res['body']['data']);
    }

    public function testAPendingOrInvitedMembershipIsShownAsWaitingNeverAsTheCreateStep(): void
    {
        $pid = $this->profile('Ahmad Khalil', 'ahmad@example.test');
        $this->membership($pid, $this->acmeId, 'invited');
        $this->signedInWithoutWorkspace($pid, 'ahmad@example.test');

        $res = $this->show();

        self::assertSame('waiting', $res['body']['data']['state']);
        self::assertSame([['name' => 'Acme', 'status' => 'invited']], $res['body']['data']['workspaces']);
    }

    public function testATenantsOwnProviderIsNeverOfferedTheStep(): void
    {
        $pid = $this->profile('Emad Nasser', 'emad@example.test');
        $this->signedInWithoutWorkspace($pid, 'emad@example.test', $this->tenantProviderId);
        $before = $this->tenantCount();

        self::assertSame(401, $this->show()['status']);
        self::assertSame(401, $this->create(['name' => 'Emad Co'])['status']);
        self::assertSame($before, $this->tenantCount());
    }

    public function testADeactivatedProfileIsOfferedNothing(): void
    {
        $pid = $this->profile('Banned', 'banned@example.test', 'inactive');
        $this->signedInWithoutWorkspace($pid, 'banned@example.test');
        $before = $this->tenantCount();

        self::assertSame(401, $this->show()['status']);
        self::assertSame(403, $this->create(['name' => 'Banned Co'])['status']);
        self::assertSame($before, $this->tenantCount());
    }

    public function testNoCookieAWrongTokenTypeOrAForgedTokenIsRefused(): void
    {
        $pid = $this->profile('Ibrahim Mansour', 'ibrahim@example.test');

        self::assertSame(401, $this->show()['status']);

        // A tenant-selection token is a real signed token of another kind.
        $this->signedInWithoutWorkspace($pid, 'ibrahim@example.test', null, 'tenant_select');
        self::assertSame(401, $this->show()['status']);

        $_COOKIE['workspace_setup_token'] = (new JwtParser('another_secret_of_at_least_32_chars_xx'))->create([
            'profile_id' => $pid, 'email' => 'ibrahim@example.test', 'provider_id' => $this->providerId,
        ], 900, SsoWorkspaceSetupHandler::TOKEN_TYPE);
        self::assertSame(401, $this->show()['status']);
        self::assertSame(401, $this->create(['name' => 'Forged'])['status']);
    }

    public function testADisabledProviderOrTheSsoSwitchOffEndsThePendingSignIn(): void
    {
        $pid = $this->profile('Amro Saleh', 'amro@example.test');
        $this->signedInWithoutWorkspace($pid, 'amro@example.test');

        $this->providers->update($this->providerId, 0, ['enabled' => false]);
        self::assertSame(401, $this->show()['status']);
        $this->providers->update($this->providerId, 0, ['enabled' => true]);
        self::assertSame(200, $this->show()['status']);

        $this->settings->setGlobal(SettingsRegistry::SSO_ENABLED, 'false');
        $before = $this->tenantCount();
        self::assertSame(401, $this->create(['name' => 'Example Bakery'])['status']);
        self::assertSame($before, $this->tenantCount());
    }

    // ── creating it ──────────────────────────────────────────────────────────

    public function testCreatingMakesThemTheOwnerAndSignsThemIntoIt(): void
    {
        $pid = $this->profile('Amro Saleh', 'amro@example.test');
        $this->signedInWithoutWorkspace($pid, 'amro@example.test');

        $res = $this->create(['name' => 'Example Bakery', 'locale' => 'ar', 'main_color' => '#B74C05']);

        self::assertSame(200, $res['status'], json_encode($res['body']) ?: '');
        $rows = $this->membershipsOf($pid);
        self::assertCount(1, $rows);
        self::assertSame('active', $rows[0]['status']);
        self::assertSame(FederatedWorkspaceProvisioner::OWNER_ROLE, $rows[0]['role']);
        self::assertSame('Example Bakery', $rows[0]['tenant_name']);
        self::assertMatchesRegularExpression('/^ws-[0-9a-f]{12}$/', (string) $rows[0]['slug']);
        // Straight into the new workspace.
        self::assertSame((int) $rows[0]['tenant_id'], (int) $res['body']['user']['tenant_id']);
        // The card's language and colour are the workspace's own settings.
        $tenantSettings = $this->settings->effective((int) $rows[0]['tenant_id']);
        self::assertSame('ar', $tenantSettings[SettingsRegistry::LOCALE]);
        self::assertSame('#b74c05', $tenantSettings[SettingsRegistry::BRANDING_MAIN_COLOR]);
    }

    public function testTheChosenNameIsCleanedAndNumberedWhenTaken(): void
    {
        $this->pdo->exec("INSERT INTO tenants (name, slug, created_at) VALUES ('Example Bakery', 'taken', NOW())");
        $pid = $this->profile('Amro Saleh', 'amro@example.test');
        $this->signedInWithoutWorkspace($pid, 'amro@example.test');

        $res = $this->create(['name' => "  Example\u{202E} Bakery\u{200F} "]);

        self::assertSame(200, $res['status']);
        self::assertSame('Example Bakery 2', $this->membershipsOf($pid)[0]['tenant_name']);
    }

    public function testBadInputCreatesNothing(): void
    {
        $pid = $this->profile('Amro Saleh', 'amro@example.test');
        $this->signedInWithoutWorkspace($pid, 'amro@example.test');
        $before = $this->tenantCount();

        foreach ([
            [[], 'name_required'],
            [['name' => '   '], 'name_required'],
            [['name' => "\u{202E}\u{200F}"], 'name_required'],
            [['name' => ['Example Bakery']], 'name_required'],
            [['name' => str_repeat('x', SsoWorkspaceSetupHandler::NAME_MAX + 1)], 'name_too_long'],
            [['name' => 'Example Bakery', 'locale' => 'english'], 'invalid_locale'],
            [['name' => 'Example Bakery', 'main_color' => 'red'], 'invalid_colour'],
        ] as [$body, $code]) {
            $res = $this->create($body);
            self::assertSame(422, $res['status'], json_encode($body) ?: '');
            self::assertSame($code, $res['body']['details']['code']);
        }
        self::assertSame($before, $this->tenantCount());
    }

    public function testWithTheSettingOffNothingIsCreated(): void
    {
        $this->settings->setGlobal(SettingsRegistry::SSO_SELF_PROVISION_WORKSPACE, 'false');
        $pid = $this->profile('Awss Haddad', 'awss@example.test');
        $this->signedInWithoutWorkspace($pid, 'awss@example.test');
        $before = $this->tenantCount();

        $res = $this->create(['name' => 'Example Bakery']);

        self::assertSame(403, $res['status']);
        self::assertSame('not_allowed', $res['body']['details']['code']);
        self::assertSame($before, $this->tenantCount());
    }

    public function testAnInvitedPersonCannotCreateOneEither(): void
    {
        $pid = $this->profile('Ahmad Khalil', 'ahmad@example.test');
        $this->membership($pid, $this->acmeId, 'invited');
        $this->signedInWithoutWorkspace($pid, 'ahmad@example.test');
        $before = $this->tenantCount();

        self::assertSame(403, $this->create(['name' => 'Example Bakery'])['status']);
        self::assertSame($before, $this->tenantCount());
        self::assertCount(1, $this->membershipsOf($pid));
    }

    public function testASecondSubmitCreatesNothingAndLandsInTheSameWorkspace(): void
    {
        $pid = $this->profile('Amro Saleh', 'amro@example.test');
        $this->signedInWithoutWorkspace($pid, 'amro@example.test');

        $first = $this->create(['name' => 'Example Bakery']);
        $afterFirst = $this->tenantCount();
        $second = $this->create(['name' => 'Another Bakery']);

        self::assertSame(200, $second['status']);
        self::assertSame($afterFirst, $this->tenantCount());
        self::assertCount(1, $this->membershipsOf($pid));
        self::assertSame($first['body']['user']['tenant_id'], $second['body']['user']['tenant_id']);
    }

    /**
     * Two requests really racing (a double click, two tabs), each in its own
     * process and connection: the profile row lock lets one through, the other
     * waits, then finds the membership and creates nothing.
     *
     * PostgreSQL only: that is where the row lock exists (SQLite serialises
     * writers on its own). CI runs the Postgres dialect on pull requests.
     */
    public function testTwoRacingRequestsMakeOneWorkspace(): void
    {
        if ((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            self::markTestSkipped('The profile row lock is PostgreSQL-only.');
        }
        $pid = $this->profile('Amro Saleh', 'amro@example.test');
        $before = $this->tenantCount();

        $database = $this->scalar('SELECT current_database()');
        $schema = $this->scalar('SELECT current_schema()');
        $baseDsn = (string) ($_ENV['PHPUNIT_PG_DSN'] ?? getenv('PHPUNIT_PG_DSN'));
        $dsn = (string) preg_replace('/dbname=[^;]+/', 'dbname=' . $database, $baseDsn);
        $user = (string) ($_ENV['PHPUNIT_PG_USER'] ?? getenv('PHPUNIT_PG_USER') ?: 'whity');
        $password = (string) ($_ENV['PHPUNIT_PG_PASSWORD'] ?? getenv('PHPUNIT_PG_PASSWORD') ?: 'whity_dev');

        // Hold the profile row so both children are waiting on it at once,
        // then let go: they race for the lock from the same starting line.
        $holder = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $holder->exec('SET search_path TO "' . str_replace('"', '""', $schema) . '"');
        $holder->beginTransaction();
        $holder->prepare('SELECT id FROM profiles WHERE id = :id FOR UPDATE')->execute([':id' => $pid]);

        $children = [];
        for ($i = 0; $i < 2; $i++) {
            $cmd = [PHP_BINARY, __DIR__ . '/fixtures/sso-workspace-race-child.php', $dsn, $user, $password, $schema, (string) $pid, (string) $this->providerId];
            $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($proc);
            $children[] = [$proc, $pipes];
        }
        usleep(700_000);
        $holder->rollBack();

        $results = [];
        foreach ($children as [$proc, $pipes]) {
            $out = (string) stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($proc), $err);
            $results[] = json_decode(trim($out), true);
        }

        $created = array_values(array_filter($results, static fn ($r): bool => is_array($r) && is_int($r['tenant_id'] ?? null)));
        self::assertCount(1, $created, json_encode($results) ?: '');
        self::assertSame($before + 1, $this->tenantCount());
        self::assertCount(1, $this->membershipsOf($pid));
    }
}
