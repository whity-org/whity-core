<?php

declare(strict_types=1);

namespace Tests\Integration;

use Firebase\JWT\JWT;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\SsoAuthHandler;
use Whity\Auth\AuthHandler;
use Whity\Auth\JwtParser;
use Whity\Auth\Oidc\JwksProvider;
use Whity\Auth\Oidc\OidcEngine;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Branding\HostResolver;
use Whity\Core\Branding\TenantHostRepository;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Http\HttpClient;
use Whity\Core\Identity\CustomerSafeRole;
use Whity\Core\Identity\ExternalIdentityRepository;
use Whity\Core\Identity\FederatedIdentityLinker;
use Whity\Core\Identity\IdentityProviderRepository;
use Whity\Core\Identity\MembershipRepository;
use Whity\Core\Identity\ProfileEmailRepository;
use Whity\Core\Request;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Signup\CustomerSignupService;
use Whity\Core\Signup\WorkspaceSignupSettings;
use Whity\Core\Tenant\FederatedWorkspaceProvisioner;

/**
 * WHIT-917: "Continue with <provider>" on a workspace's sign-up page joins that
 * workspace as a customer through the ordinary federated sign-in, under the
 * same rules as the email path, against the migration-built schema (SQLite
 * locally, PostgreSQL on the postgres jobs).
 */
final class CustomerSignupSsoJoinRealEngineTest extends TestCase
{
    private const APP_URL = 'https://app.test';
    private const ISSUER = 'https://id.whity.test';
    private const CLIENT_ID = 'whity-core';
    /** Bakery's form whose public link asks people to sign in (#2355). */
    private const FORM_ID = 5;
    private const FORM_SLUG = 'ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12';

    private PDO $pdo;
    private JwtParser $jwtParser;
    private SsoAuthHandler $handler;
    private JoinStubOidcHttp $http;
    private SettingsService $settings;
    private WorkspaceSignupSettings $signupSettings;
    private CustomerSignupService $signups;
    private string $privatePem;
    /** @var array<string, mixed> */
    private array $jwks;

    private int $workspaceId;   // "bakery", the workspace people join
    private int $otherId;       // "other", which must never be reached
    private int $providerId;    // the operator's global provider, whity_id
    private int $customerRole;
    private int $staffProfile;

    /** @var list<array<string, mixed>> */
    private array $joinedEvents = [];

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->jwtParser = new JwtParser('internal_hs256_secret_min_32_chars_xxxx');

        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($res === false) {
            self::fail('key gen failed');
        }
        openssl_pkey_export($res, $priv);
        $this->privatePem = $priv;
        $d = openssl_pkey_get_details($res);
        if ($d === false) {
            self::fail('key details failed');
        }
        $this->jwks = ['keys' => [[
            'kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => 'kid-a',
            'n' => self::b64url($d['rsa']['n']), 'e' => self::b64url($d['rsa']['e']),
        ]]];

        $this->http = new JoinStubOidcHttp();
        $this->http->discovery = [
            'issuer' => self::ISSUER,
            'authorization_endpoint' => self::ISSUER . '/authorize',
            'token_endpoint' => self::ISSUER . '/token',
            'jwks_uri' => self::ISSUER . '/jwks',
        ];
        $engine = new OidcEngine($this->http, new JwksProvider(fn (string $uri): array => $this->jwks, 3600), $this->jwtParser);

        $auth = new AuthHandler(
            $this->pdo, $this->jwtParser, null, null, null, new NullLogger(),
            new AuditLogger($this->pdo, new NullLogger()), null
        );
        $this->settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        // On, so a test proves the join path never creates a workspace even then.
        $this->settings->setGlobal(SettingsRegistry::SSO_SELF_PROVISION_WORKSPACE, 'true');

        $hooks = new HookManager();
        $hooks->listen(CustomerSignupService::HOOK_CUSTOMER_JOINED, function (array $data): array {
            $this->joinedEvents[] = $data;

            return $data;
        });

        $this->signupSettings = new WorkspaceSignupSettings($this->pdo);
        $this->signups = new CustomerSignupService($this->pdo, $this->signupSettings, CustomerSafeRole::make($this->pdo), $hooks);
        $emails = new ProfileEmailRepository($this->pdo);
        $hostRepo = new TenantHostRepository($this->pdo);

        $this->handler = new SsoAuthHandler(
            $engine,
            new IdentityProviderRepository($this->pdo),
            $emails,
            new HostResolver($hostRepo, 'whity.test'),
            $this->jwtParser,
            new EncryptedSecretStore(['v1' => 'sso_test_key_0123456789abcdef0123456789'], 'v1'),
            $auth,
            new FederatedIdentityLinker($this->pdo, new ExternalIdentityRepository($this->pdo), $emails, new MembershipRepository($this->pdo), new \Whity\Core\Identity\TenantEmailDomainsRepository($this->pdo)),
            $this->settings,
            self::APP_URL,
            new FederatedWorkspaceProvisioner($this->pdo, $this->settings),
            $hooks,
            null,
            $this->signups,
            $hostRepo,
            forms: new \Whity\Core\Form\FormRepository($this->pdo),
        );

        $this->seed();
        $_GET = [];
        $_COOKIE = [];
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_COOKIE = [];
    }

    // ── start ─────────────────────────────────────────────────────────────────

    public function testStartSendsAVisitorToTheProviderOnlyWhenTheWorkspaceTakesSignups(): void
    {
        $_GET = ['join' => 'bakery'];
        $closed = $this->start();
        self::assertSame('https://app.test/join/bakery?sso=closed', $this->location($closed));

        $this->open('open');
        $res = $this->start();
        self::assertStringStartsWith(self::ISSUER . '/authorize?', $this->location($res));

        // An unknown workspace answers exactly like a closed one.
        $_GET = ['join' => 'nowhere'];
        self::assertSame('https://app.test/join/nowhere?sso=closed', $this->location($this->start()));

        // A slug that disagrees with the host's workspace is refused the same way.
        $_GET = ['join' => 'bakery'];
        self::assertSame('https://app.test/join/bakery?sso=closed', $this->location($this->start('other.whity.test')));
    }

    /**
     * #2195 / WHIT-1294: a malformed X-Forwarded-Host (a list, here) named no
     * workspace, so the check that the host belongs to the workspace in
     * `?join=` was skipped. It now falls back to Host, which names "other".
     */
    public function testAMalformedForwardedHostCannotSkipTheDomainCheck(): void
    {
        $this->open('open');
        $_GET = ['join' => 'bakery'];

        $res = $this->handler->start(new Request('GET', '/api/v1/auth/sso/whity_id/start', [
            'Host' => 'other.whity.test',
            'X-Forwarded-Host' => 'localhost, other.whity.test',
        ], ''), ['provider' => 'whity_id']);

        self::assertSame('https://app.test/join/bakery?sso=closed', $this->location($res));
    }

    // ── callback ──────────────────────────────────────────────────────────────

    public function testOpenModeJoinsANewPersonAsACustomerAndSignsThemIn(): void
    {
        $this->open('open');
        $tenants = $this->rows('SELECT COUNT(*) FROM tenants');

        $res = $this->returnFromProvider('new-sub', 'nadia@customer.test');

        self::assertSame('https://app.test/dashboard', $this->location($res));
        $row = $this->membership('nadia@customer.test', $this->workspaceId);
        self::assertSame('active', $row['status']);
        self::assertSame('customer', $row['kind']);
        self::assertSame($this->customerRole, (int) $row['role_id']);
        // Created by the federated sign-in, so no local password: `idp`.
        self::assertSame('idp', $this->col("SELECT p.auth_method FROM profiles p JOIN profile_emails pe ON pe.profile_id = p.id WHERE pe.email = 'nadia@customer.test'"));
        // No workspace of their own, even with self-service workspaces on.
        self::assertSame($tenants, $this->rows('SELECT COUNT(*) FROM tenants'));
        self::assertSame(0, $this->rows("SELECT COUNT(*) FROM memberships m JOIN profile_emails pe ON pe.profile_id = m.profile_id WHERE pe.email = 'nadia@customer.test' AND m.tenant_id <> " . $this->workspaceId));
        self::assertCount(1, $this->joinedEvents);
        self::assertSame($this->workspaceId, $this->joinedEvents[0]['tenant_id']);
    }

    public function testApprovalModeQueuesThePersonWithoutAMembership(): void
    {
        $this->open('approval');

        $res = $this->returnFromProvider('wait-sub', 'waiting@customer.test');

        self::assertSame('https://app.test/join/bakery?sso=pending', $this->location($res));
        self::assertSame(0, $this->rows("SELECT COUNT(*) FROM memberships m JOIN profile_emails pe ON pe.profile_id = m.profile_id WHERE pe.email = 'waiting@customer.test'"));
        $queue = $this->signups->listForTenant($this->workspaceId, CustomerSignupService::STATUS_PENDING, 10, 0);
        self::assertSame(['waiting@customer.test'], array_column($queue['items'], 'email'));

        // A second round trip doesn't queue them twice.
        $this->returnFromProvider('wait-sub', 'waiting@customer.test');
        self::assertSame(1, $this->signups->listForTenant($this->workspaceId, CustomerSignupService::STATUS_PENDING, 10, 0)['total']);
    }

    public function testTheDomainListIsCheckedAgainstTheProviderVerifiedEmail(): void
    {
        $this->open('open', ['bakery.test']);

        $res = $this->returnFromProvider('out-sub', 'outsider@gmail.test');

        self::assertSame('https://app.test/join/bakery?sso=domain', $this->location($res));
        self::assertSame(0, $this->rows("SELECT COUNT(*) FROM memberships m JOIN profile_emails pe ON pe.profile_id = m.profile_id WHERE pe.email = 'outsider@gmail.test'"));

        $ok = $this->returnFromProvider('in-sub', 'baker@bakery.test');
        self::assertSame('https://app.test/dashboard', $this->location($ok));
    }

    public function testAnUnverifiedProviderEmailCannotJoin(): void
    {
        $this->open('open');

        $res = $this->returnFromProvider('unv-sub', 'unverified@customer.test', false);

        self::assertSame('https://app.test/join/bakery?sso=email_unverified', $this->location($res));
        self::assertSame(0, $this->rows("SELECT COUNT(*) FROM profile_emails WHERE email = 'unverified@customer.test'"));
    }

    public function testStaffWhoJoinStayStaffAndAreSignedIn(): void
    {
        $this->open('open');

        $res = $this->returnFromProvider('staff-sub', 'staff@bakery.test');

        self::assertSame('https://app.test/dashboard', $this->location($res));
        self::assertSame('member', $this->col(
            'SELECT kind FROM memberships WHERE tenant_id = ' . $this->workspaceId . ' AND profile_id = ' . $this->staffProfile
        ));
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM memberships WHERE tenant_id = ' . $this->workspaceId . ' AND profile_id = ' . $this->staffProfile));
        self::assertSame([], $this->joinedEvents);
    }

    public function testARejectedPersonIsRefused(): void
    {
        $this->open('approval');
        $this->returnFromProvider('rej-sub', 'rejected@customer.test');
        $id = (int) $this->signups->listForTenant($this->workspaceId, CustomerSignupService::STATUS_PENDING, 10, 0)['items'][0]['id'];
        $this->signups->reject($id, $this->workspaceId, $this->staffProfile);

        $res = $this->returnFromProvider('rej-sub', 'rejected@customer.test');

        self::assertSame('https://app.test/join/bakery?sso=refused', $this->location($res));
        self::assertSame(0, $this->rows("SELECT COUNT(*) FROM memberships m JOIN profile_emails pe ON pe.profile_id = m.profile_id WHERE pe.email = 'rejected@customer.test'"));
    }

    public function testTheWorkspaceComesFromTheSignedFlowAndNeverFromTheCallback(): void
    {
        $this->open('open');
        $this->signupSettings->save($this->otherId, 'open', $this->makeRole($this->otherId), [], null);

        // A tampered callback names the other workspace; the signed flow says bakery.
        $res = $this->returnFromProvider('swap-sub', 'swap@customer.test', true, ['join' => 'other']);

        self::assertSame('https://app.test/dashboard', $this->location($res));
        self::assertSame(1, $this->rows("SELECT COUNT(*) FROM memberships m JOIN profile_emails pe ON pe.profile_id = m.profile_id WHERE pe.email = 'swap@customer.test' AND m.tenant_id = " . $this->workspaceId));
        self::assertSame(0, $this->rows("SELECT COUNT(*) FROM memberships m JOIN profile_emails pe ON pe.profile_id = m.profile_id WHERE pe.email = 'swap@customer.test' AND m.tenant_id = " . $this->otherId));
    }

    public function testSignupClosedAfterTheVisitorLeftIsRefusedOnReturn(): void
    {
        $this->open('open');
        $this->signupSettings->save($this->workspaceId, 'off', $this->customerRole, [], null);

        $res = $this->returnFromProvider('late-sub', 'late@customer.test');

        self::assertSame('https://app.test/join/bakery?sso=closed', $this->location($res));
        self::assertSame(0, $this->rows("SELECT COUNT(*) FROM memberships m JOIN profile_emails pe ON pe.profile_id = m.profile_id WHERE pe.email = 'late@customer.test'"));
    }

    // ── a form's public link that asks people to sign in (#2355) ─────────────

    public function testStartFromAFormLinkCarriesTheFormsWorkspaceInTheSignedFlow(): void
    {
        $_GET = ['form' => self::FORM_SLUG];
        $res = $this->start();

        // Off to the operator's provider even though the workspace takes no
        // sign-ups: a member may still sign in from the form.
        self::assertStringStartsWith(self::ISSUER . '/authorize?', $this->location($res));

        // A form whose link doesn't ask for sign-in, an unknown slug, and a
        // host naming another workspace all go back to the form's page.
        $this->pdo->exec('UPDATE forms SET public_requires_sign_in = FALSE WHERE id = ' . self::FORM_ID);
        self::assertSame('https://app.test/f/' . self::FORM_SLUG . '?sso=closed', $this->location($this->start()));
        $this->pdo->exec('UPDATE forms SET public_requires_sign_in = TRUE WHERE id = ' . self::FORM_ID);
        self::assertSame('https://app.test/f/' . self::FORM_SLUG . '?sso=closed', $this->location($this->start('other.whity.test')));
        $_GET = ['form' => str_repeat('c', 64)];
        self::assertSame('https://app.test/f/' . str_repeat('c', 64) . '?sso=closed', $this->location($this->start()));
    }

    /**
     * The reported case: a new person creates their Whity ID account from the
     * form's link. They join the form's workspace under its open sign-up rules
     * and land on the form, and are never offered a workspace of their own.
     */
    public function testANewPersonFromAFormLinkJoinsItsWorkspaceAndLandsOnTheForm(): void
    {
        $this->open('open', ['customer.test']);
        $tenants = $this->rows('SELECT COUNT(*) FROM tenants');

        $res = $this->returnFromProvider('form-sub', 'applicant@customer.test', true, [], 'form');

        self::assertSame('https://app.test/forms/' . self::FORM_ID, $this->location($res));
        $row = $this->membership('applicant@customer.test', $this->workspaceId);
        self::assertSame('active', $row['status']);
        self::assertSame($this->customerRole, (int) $row['role_id']);
        self::assertSame($tenants, $this->rows('SELECT COUNT(*) FROM tenants'));
        self::assertCount(1, $this->joinedEvents);
    }

    public function testAFormLinkAppliesTheWorkspacesDomainList(): void
    {
        $this->open('open', ['bakery.test']);

        $res = $this->returnFromProvider('gmail-sub', 'outsider@gmail.test', true, [], 'form');

        self::assertSame('https://app.test/f/' . self::FORM_SLUG . '?sso=domain', $this->location($res));
        self::assertSame(0, $this->rows("SELECT COUNT(*) FROM memberships m JOIN profile_emails pe ON pe.profile_id = m.profile_id WHERE pe.email = 'outsider@gmail.test'"));
    }

    public function testAFormLinkWithSignupsOffNeverOffersANewWorkspace(): void
    {
        $tenants = $this->rows('SELECT COUNT(*) FROM tenants');

        $res = $this->returnFromProvider('off-sub', 'stranger@customer.test', true, [], 'form');

        self::assertSame('https://app.test/f/' . self::FORM_SLUG . '?sso=closed', $this->location($res));
        self::assertSame($tenants, $this->rows('SELECT COUNT(*) FROM tenants'));
    }

    /** A member outside the domain list still signs in: the list is for joining. */
    public function testAMemberFromAFormLinkIsSignedIntoTheWorkspaceAndLandsOnTheForm(): void
    {
        $this->open('open', ['customer.test']);

        $res = $this->returnFromProvider('staff-sub', 'staff@bakery.test', true, [], 'form');

        self::assertSame('https://app.test/forms/' . self::FORM_ID, $this->location($res));
        self::assertSame('member', $this->col(
            'SELECT kind FROM memberships WHERE tenant_id = ' . $this->workspaceId . ' AND profile_id = ' . $this->staffProfile
        ));
        self::assertSame([], $this->joinedEvents);
    }

    public function testAFormLinkInApprovalModeSaysTheRequestIsWaiting(): void
    {
        $this->open('approval');

        $res = $this->returnFromProvider('appr-sub', 'later@customer.test', true, [], 'form');

        self::assertSame('https://app.test/f/' . self::FORM_SLUG . '?sso=pending', $this->location($res));
        self::assertSame(0, $this->rows("SELECT COUNT(*) FROM memberships m JOIN profile_emails pe ON pe.profile_id = m.profile_id WHERE pe.email = 'later@customer.test'"));
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function start(string $host = 'app.test'): \Whity\Sdk\Http\Response
    {
        return $this->handler->start(new Request('GET', '/api/v1/auth/sso/whity_id/start', ['Host' => $host], ''), ['provider' => 'whity_id']);
    }

    /**
     * A callback for a flow that started on bakery's sign-up page.
     *
     * @param array<string, string> $extraQuery
     */
    private function returnFromProvider(string $sub, string $email, bool $verified = true, array $extraQuery = [], string $from = 'join'): \Whity\Sdk\Http\Response
    {
        $flow = [
            'state' => 'state-1',
            'nonce' => 'nonce-1',
            'code_verifier' => 'verifier-1',
            'provider_id' => $this->providerId,
            'tenant_id' => 0,
            'provider_key' => 'whity_id',
        ];
        // What /start writes: the sign-up page's workspace, or the form's (#2355).
        $flow += $from === 'form'
            ? ['form_tenant_id' => $this->workspaceId, 'form_id' => self::FORM_ID, 'form_slug' => self::FORM_SLUG]
            : ['join_tenant_id' => $this->workspaceId, 'join_slug' => 'bakery'];
        $_COOKIE['sso_flow_token'] = $this->jwtParser->create($flow, 600, 'oidc_flow');

        $now = time();
        $this->http->tokenResponse = ['id_token' => JWT::encode([
            'iss' => self::ISSUER, 'aud' => self::CLIENT_ID, 'sub' => $sub,
            'email' => $email, 'email_verified' => $verified, 'name' => 'Person ' . $sub,
            'nonce' => 'nonce-1', 'iat' => $now, 'exp' => $now + 3600,
        ], $this->privatePem, 'RS256', 'kid-a'), 'access_token' => 'at'];

        $_GET = ['code' => 'authcode', 'state' => 'state-1'] + $extraQuery;

        return $this->handler->callback(new Request('GET', '/api/v1/auth/sso/whity_id/callback', [], ''), ['provider' => 'whity_id']);
    }

    /** @param list<string> $domains */
    private function open(string $mode, array $domains = []): void
    {
        $this->signupSettings->save($this->workspaceId, $mode, $this->customerRole, $domains, null);
    }

    private function seed(): void
    {
        $this->pdo->exec("INSERT INTO tenants (name, slug, created_at) VALUES ('Example Bakery', 'bakery', NOW())");
        $this->workspaceId = (int) $this->col("SELECT id FROM tenants WHERE slug = 'bakery'");
        $this->pdo->exec("INSERT INTO tenants (name, slug, created_at) VALUES ('Other', 'other', NOW())");
        $this->otherId = (int) $this->col("SELECT id FROM tenants WHERE slug = 'other'");

        $this->customerRole = $this->makeRole($this->workspaceId);

        // Staff with a verified address, so the provider sign-in links to them.
        $this->pdo->exec("INSERT INTO profiles (display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
            VALUES ('Sami Staff', 'x', false, 0, 0, NOW(), NOW())");
        $this->staffProfile = (int) $this->pdo->lastInsertId();
        (new ProfileEmailRepository($this->pdo))->insert($this->staffProfile, 'staff@bakery.test', true, true);
        $userRole = (int) $this->col("SELECT id FROM roles WHERE name = 'user' AND tenant_id IS NULL");
        $this->pdo->exec("INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at) VALUES ({$this->staffProfile}, {$this->workspaceId}, {$userRole}, 'active', NOW())");

        $this->providerId = (new IdentityProviderRepository($this->pdo))->insert(0, [
            'provider_key' => 'whity_id',
            'display_name' => 'Whity ID',
            'client_id' => self::CLIENT_ID,
            'client_secret_encrypted' => null,
            'issuer' => self::ISSUER,
            'discovery_url' => self::ISSUER . '/.well-known/openid-configuration',
            'scopes' => 'openid email profile',
            'enabled' => true,
        ]);

        $this->pdo->exec("INSERT INTO forms (id, tenant_id, form_key, name, status, public_enabled, public_slug, public_requires_sign_in, created_at, updated_at)
            VALUES (" . self::FORM_ID . ", {$this->workspaceId}, 'application', 'Application', 'published', TRUE, '" . self::FORM_SLUG . "', TRUE, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");

        SchemaFromMigrations::syncSequences($this->pdo);
    }

    private function makeRole(int $tenantId): int
    {
        $this->pdo->prepare('INSERT INTO roles (name, description, tenant_id, created_at) VALUES (?, ?, ?, NOW())')
            ->execute(['customers-' . $tenantId, '', $tenantId]);
        $roleId = (int) $this->col("SELECT id FROM roles WHERE name = 'customers-{$tenantId}' AND tenant_id = {$tenantId}");
        $permissionId = (int) $this->col("SELECT id FROM permissions WHERE name = 'forms:submit'");
        $this->pdo->exec("INSERT INTO role_permissions (role_id, permission_id, created_at) VALUES ({$roleId}, {$permissionId}, NOW())");

        return $roleId;
    }

    /** @return array<string, mixed> */
    private function membership(string $email, int $tenantId): array
    {
        $stmt = $this->pdo->prepare('SELECT m.* FROM memberships m JOIN profile_emails pe ON pe.profile_id = m.profile_id WHERE pe.email = ? AND m.tenant_id = ?');
        $stmt->execute([$email, $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row, 'no membership for ' . $email);

        return $row;
    }

    private function location(\Whity\Sdk\Http\Response $res): string
    {
        return (string) ($res->getHeaders()['location'] ?? $res->getHeaders()['Location'] ?? '');
    }

    private function rows(string $sql): int
    {
        return (int) $this->col($sql);
    }

    private function col(string $sql): mixed
    {
        $stmt = $this->pdo->query($sql);
        if ($stmt === false) {
            self::fail("query failed: {$sql}");
        }

        return $stmt->fetchColumn();
    }

    private static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}

/** Canned discovery and token response for the join tests. */
final class JoinStubOidcHttp implements HttpClient
{
    /** @var array<string, mixed>|null */
    public ?array $discovery = null;
    /** @var array<string, mixed>|null */
    public ?array $tokenResponse = null;

    public function getJson(string $url): ?array
    {
        return $this->discovery;
    }

    public function postForm(string $url, array $params): ?array
    {
        return $this->tokenResponse;
    }
}
