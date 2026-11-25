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
use Whity\Core\Http\HttpClient;
use Whity\Core\Identity\ExternalIdentityRepository;
use Whity\Core\Identity\FederatedIdentityLinker;
use Whity\Core\Identity\IdentityProviderRepository;
use Whity\Core\Identity\MembershipRepository;
use Whity\Core\Identity\ProfileEmailRepository;
use Whity\Core\Request;
use Whity\Core\Security\EncryptedSecretStore;

/**
 * Real-engine tests for the federated sign-in flow (WC-ae16, A5c). Exercises
 * start() (authorize redirect) and callback() end-to-end with a stubbed HTTP
 * client + a real RSA-signed ID token, against the migration-built schema.
 *
 * Focus: the security-critical callback behaviour — state (CSRF) validation,
 * flow-cookie requirement, login only for an already-LINKED identity, and the
 * happy-path session mint → dashboard redirect.
 */
final class SsoAuthHandlerRealEngineTest extends TestCase
{
    private const APP_URL = 'https://app.test';
    private const ISSUER = 'https://accounts.google.com';
    private const CLIENT_ID = 'client-abc.apps.googleusercontent.com';
    private const SUBJECT = 'google-sub-777';

    private PDO $pdo;
    private JwtParser $jwtParser;
    private SsoAuthHandler $handler;

    /** @var \Closure(?\Whity\Core\Http\FrontendUrls): SsoAuthHandler */
    private \Closure $makeHandler;
    private string $privatePem;
    /** @var array<string, mixed> */
    private array $jwks;
    private string $kid = 'kid-a';
    private StubOidcHttp $http;
    private int $tenantId;
    private int $profileId;
    private int $providerId;        // GLOBAL-TRUST provider (system tenant 0) — default flow
    private int $tenantProviderId;  // TENANT-TRUST provider (Acme's own IdP)
    private \Whity\Core\Settings\SettingsService $settings;
    private \Whity\Core\Hooks\HookManager $hooks;
    /** The native sign-in codes' clock (WHIT-1406); tests move it. */
    private int $now = 1_800_000_000;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->jwtParser = new JwtParser('internal_hs256_secret_min_32_chars_xxxx');

        // RSA keypair + JWKS.
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
            'kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => $this->kid,
            'n' => self::b64url($d['rsa']['n']), 'e' => self::b64url($d['rsa']['e']),
        ]]];

        $this->http = new StubOidcHttp();
        $this->http->discovery = [
            'issuer' => self::ISSUER,
            'authorization_endpoint' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_endpoint' => 'https://oauth2.googleapis.com/token',
            'jwks_uri' => 'https://www.googleapis.com/oauth2/v3/certs',
        ];

        $engine = new OidcEngine(
            $this->http,
            new JwksProvider(fn(string $uri): array => $this->jwks, 3600),
            $this->jwtParser
        );

        $auth = new AuthHandler(
            $this->pdo,
            $this->jwtParser,
            null,
            null,
            null,
            new NullLogger(),
            new AuditLogger($this->pdo, new NullLogger()),
            null
        );

        $extRepo = new ExternalIdentityRepository($this->pdo);
        $emailRepo = new ProfileEmailRepository($this->pdo);
        $this->settings = new \Whity\Core\Settings\SettingsService(
            new \Whity\Core\Settings\GlobalSettingsRepository($this->pdo),
            new \Whity\Core\Settings\TenantSettingsRepository($this->pdo)
        );
        $this->hooks = new \Whity\Core\Hooks\HookManager();
        $this->makeHandler = fn (?\Whity\Core\Http\FrontendUrls $frontendUrls = null): SsoAuthHandler => new SsoAuthHandler(
            $engine,
            new IdentityProviderRepository($this->pdo),
            $emailRepo,
            new HostResolver(new TenantHostRepository($this->pdo), 'example.test'),
            $this->jwtParser,
            new EncryptedSecretStore(['v1' => 'sso_test_key_0123456789abcdef0123456789'], 'v1'),
            $auth,
            new FederatedIdentityLinker($this->pdo, $extRepo, $emailRepo, new MembershipRepository($this->pdo), new \Whity\Core\Identity\TenantEmailDomainsRepository($this->pdo)),
            $this->settings,
            self::APP_URL,
            // Present but switched OFF (the default), so every test below that
            // does not turn it on sees the pre-existing behaviour.
            new \Whity\Core\Tenant\FederatedWorkspaceProvisioner($this->pdo, $this->settings),
            $this->hooks,
            $frontendUrls,
            invitations: new \Whity\Core\Identity\InvitationService($this->pdo, new \Whity\Core\Identity\ProfileProvisioner($this->pdo)),
            phones: new \Whity\Core\Identity\PersonalPhone($this->pdo),
            audit: new AuditLogger($this->pdo, new NullLogger()),
            nativeCodes: new \Whity\Auth\NativeSignInCodes($this->pdo, fn (): int => $this->now),
        );
        $this->handler = ($this->makeHandler)();

        $this->seed();
        $_GET = [];
        $_COOKIE = [];
    }

    /** Puts error_log back after a test that captured it. */
    private ?\Closure $restoreErrorLog = null;

    protected function tearDown(): void
    {
        $_GET = [];
        $_COOKIE = [];
        if ($this->restoreErrorLog !== null) {
            ($this->restoreErrorLog)();
            $this->restoreErrorLog = null;
        }
    }

    private function seed(): void
    {
        // Tenant (id 0 exists from migration 010; create tenant 1 as the SSO tenant).
        $this->exec("INSERT INTO tenants (name, slug, created_at) VALUES ('Acme', 'acme', NOW())");
        $this->tenantId = (int) $this->pdo->lastInsertId();

        $roleId = (int) $this->col('SELECT id FROM roles ORDER BY id ASC LIMIT 1');

        // Profile + primary verified email.
        $this->exec("INSERT INTO profiles
            (display_name, password_hash, two_factor_enabled, two_factor_secret,
             two_factor_backup_codes_version, token_epoch, created_at, updated_at)
            VALUES ('Alice', '" . password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]) . "', false, NULL, 0, 0, NOW(), NOW())");
        $this->profileId = (int) $this->pdo->lastInsertId();
        (new ProfileEmailRepository($this->pdo))->insert($this->profileId, 'alice@acme.test', true, true);

        // Active membership so completeFederatedLogin can mint a session.
        $this->pdo->prepare("INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, status, created_at)
            VALUES (:p, :t, :r, NULL, 'active', NOW())")
            ->execute([':p' => $this->profileId, ':t' => $this->tenantId, ':r' => $roleId]);

        // GLOBAL-TRUST provider: operator-configured at the system tenant (0). The
        // default flow uses this, so the person-centric behaviours (verified-email
        // link, provisioning) are exercised on the tier that permits them.
        $providers = new IdentityProviderRepository($this->pdo);
        $this->providerId = $providers->insert(0, [
            'provider_key' => 'google',
            'display_name' => 'Google',
            'client_id' => self::CLIENT_ID,
            'client_secret_encrypted' => null,
            'issuer' => self::ISSUER,
            'discovery_url' => 'https://accounts.google.com/.well-known/openid-configuration',
            'scopes' => 'openid email profile',
            'enabled' => true,
        ]);

        // TENANT-TRUST provider: Acme's own bring-your-own IdP.
        $this->tenantProviderId = $providers->insert($this->tenantId, [
            'provider_key' => 'google',
            'display_name' => 'Acme Google',
            'client_id' => self::CLIENT_ID,
            'client_secret_encrypted' => null,
            'issuer' => self::ISSUER,
            'discovery_url' => 'https://accounts.google.com/.well-known/openid-configuration',
            'scopes' => 'openid email profile',
            'enabled' => true,
        ]);
    }

    private function exec(string $sql): void
    {
        if ($this->pdo->exec($sql) === false) {
            self::fail("exec failed: {$sql}");
        }
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

    private function linkIdentity(): void
    {
        (new ExternalIdentityRepository($this->pdo))->link($this->profileId, 'google', self::ISSUER, self::SUBJECT, 'alice@acme.test');
    }

    /**
     * Build a valid oidc_flow cookie + matching $_GET, and a signed id_token in
     * the stubbed token response.
     *
     * @param array<string, mixed> $extraClaims more claims in the id_token (WHIT-1181: phone_number)
     */
    private function primeFlow(
        string $state = 'state-1',
        string $nonce = 'nonce-1',
        string $sub = self::SUBJECT,
        string $email = 'alice@acme.test',
        bool $emailVerified = true,
        ?int $providerId = null,
        ?int $tenantId = null,
        ?string $invite = null,
        array $extraClaims = [],
    ): void {
        // Default flow is GLOBAL-TRUST (operator provider @ system tenant 0).
        $flow = $this->jwtParser->create([
            'state' => $state,
            'nonce' => $nonce,
            'code_verifier' => 'verifier-1',
            'provider_id' => $providerId ?? $this->providerId,
            'tenant_id' => $tenantId ?? 0,
            'provider_key' => 'google',
            'invite_token' => $invite,
        ], 600, 'oidc_flow');
        $_COOKIE['sso_flow_token'] = $flow;

        $now = time();
        $idToken = JWT::encode([
            'iss' => self::ISSUER, 'aud' => self::CLIENT_ID, 'sub' => $sub,
            'email' => $email, 'email_verified' => $emailVerified, 'name' => 'Alice',
            'nonce' => $nonce, 'iat' => $now, 'exp' => $now + 3600,
        ] + $extraClaims, $this->privatePem, 'RS256', $this->kid);
        $this->http->tokenResponse = ['id_token' => $idToken, 'access_token' => 'at'];
    }

    private function runCallback(): \Whity\Sdk\Http\Response
    {
        return $this->handler->callback(new Request('GET', '/api/v1/auth/sso/google/callback', [], ''), ['provider' => 'google']);
    }

    private function location(\Whity\Sdk\Http\Response $res): string
    {
        return (string) ($res->getHeaders()['location'] ?? $res->getHeaders()['Location'] ?? '');
    }

    // ── start ─────────────────────────────────────────────────────────────────

    public function testStartRedirectsToAuthorizeEndpointWithPkceAndState(): void
    {
        // No Host header → tenant 0; the seeded GLOBAL-TRUST provider resolves.
        $res = $this->handler->start(new Request('GET', '/api/v1/auth/sso/google/start', [], ''), ['provider' => 'google']);
        self::assertSame(302, $res->getStatusCode());
        $loc = $this->location($res);
        self::assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $loc);
        self::assertStringContainsString('code_challenge_method=S256', $loc);
        self::assertStringContainsString('state=', $loc);
        self::assertStringContainsString('access_type=offline', $loc);
    }

    public function testProvidersListsEnabledForTenantWithoutSecrets(): void
    {
        // No Host → tenant 0; the seeded GLOBAL google provider (enabled) is listed.
        $res = $this->handler->providers(new Request('GET', '/api/v1/auth/sso/providers', [], ''));
        self::assertSame(200, $res->getStatusCode());
        $data = json_decode($res->getBody(), true)['data'];
        self::assertNotSame([], $data);
        self::assertSame('google', $data[0]['provider_key']);
        self::assertSame('Google', $data[0]['display_name']);
        // Display-safe only — never client_id/secret/issuer/has_secret.
        self::assertArrayNotHasKey('client_id', $data[0]);
        self::assertArrayNotHasKey('client_secret', $data[0]);
        self::assertArrayNotHasKey('has_secret', $data[0]);
        self::assertArrayNotHasKey('issuer', $data[0]);
    }

    public function testProvidersEmptyWhenSsoDisabledGlobally(): void
    {
        $this->settings->setGlobal(\Whity\Core\Settings\SettingsRegistry::SSO_ENABLED, 'false');
        $res = $this->handler->providers(new Request('GET', '/api/v1/auth/sso/providers', [], ''));
        self::assertSame([], json_decode($res->getBody(), true)['data']);
    }

    public function testProvidersSayWhetherPasswordSignInIsOn(): void
    {
        $on = $this->handler->providers(new Request('GET', '/api/v1/auth/sso/providers', [], ''));
        self::assertTrue(json_decode($on->getBody(), true)['password_sign_in']);

        // WHIT-1168: off, the pages show the provider and nothing else.
        $this->settings->setGlobal(\Whity\Core\Settings\SettingsRegistry::PASSWORD_SIGN_IN, 'false');
        $off = $this->handler->providers(new Request('GET', '/api/v1/auth/sso/providers', [], ''));
        self::assertFalse(json_decode($off->getBody(), true)['password_sign_in']);
    }

    public function testProvidersOmitsDisabledProviders(): void
    {
        (new IdentityProviderRepository($this->pdo))->update($this->providerId, 0, ['enabled' => false]);
        $res = $this->handler->providers(new Request('GET', '/api/v1/auth/sso/providers', [], ''));
        self::assertSame([], json_decode($res->getBody(), true)['data']);
    }

    public function testStartBouncesWhenSsoDisabledGlobally(): void
    {
        // Operator kill-switch (WC-28fb2e19): SSO off instance-wide.
        $this->settings->setGlobal(\Whity\Core\Settings\SettingsRegistry::SSO_ENABLED, 'false');

        $res = $this->handler->start(new Request('GET', '/api/v1/auth/sso/google/start', [], ''), ['provider' => 'google']);
        self::assertSame(302, $res->getStatusCode());
        self::assertStringContainsString('/login?sso_error=sso_disabled', $this->location($res));
    }

    public function testCallbackBouncesWhenSsoDisabledGlobally(): void
    {
        $this->linkIdentity();
        $this->primeFlow();
        $_GET = ['code' => 'authcode', 'state' => 'state-1'];
        $this->settings->setGlobal(\Whity\Core\Settings\SettingsRegistry::SSO_ENABLED, 'false');

        $res = $this->runCallback();
        // Even a valid linked flow is refused when SSO is globally disabled, and no
        // session is minted.
        self::assertStringContainsString('/login?sso_error=sso_disabled', $this->location($res));
    }

    public function testStartUnknownProviderBounces(): void
    {
        $res = $this->handler->start(new Request('GET', '/x', [], ''), ['provider' => 'nope']);
        self::assertSame(302, $res->getStatusCode());
        self::assertStringContainsString('/login?sso_error=provider_unavailable', $this->location($res));
    }

    /**
     * Signing in again to confirm a gated approval (WHIT-1147) needs a signed-in person and the
     * wiring that checks the answer; without them it is refused, never started as a plain sign-in.
     */
    public function testAGateReauthWithoutASessionIsRefused(): void
    {
        $_GET = ['reauth' => 'gate', 'purpose' => 'password-reset:7', 'return_to' => '/admin/approval-gating/password-resets'];
        try {
            $res = $this->handler->start(new Request('GET', '/api/v1/auth/sso/google/start', [], ''), ['provider' => 'google']);
        } finally {
            $_GET = [];
        }

        self::assertSame(302, $res->getStatusCode());
        self::assertStringContainsString('sso_error=reauth_unavailable', $this->location($res));
    }

    /**
     * #2194: re-authentication starts from a REAL session token, which carries
     * `active_tenant_id`, not the retired `tenant_id`. Reading the old claim
     * refused every signer and approver. Workspace-ownership approvals use the
     * same fresh sign-in.
     *
     * @return iterable<string, array{array<string, string>}>
     */
    public static function reauthRequests(): iterable
    {
        yield 'sign' => [['reauth' => 'sign', 'route_id' => '5', 'return_to' => '/documents/5']];
        yield 'gate: password reset' => [['reauth' => 'gate', 'purpose' => 'password-reset:7', 'return_to' => '/admin/approval-gating/password-resets']];
        yield 'gate: workspace ownership' => [['reauth' => 'gate', 'purpose' => 'workspace-ownership:offer-1-2-transfer', 'return_to' => '/settings/workspace']];
    }

    /**
     * @param array<string, string> $query
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('reauthRequests')]
    public function testReauthStartsFromARealSessionToken(array $query): void
    {
        $handler = new SsoAuthHandler(
            ...$this->handlerArgsWithReauth(),
        );
        foreach ([$this->tenantId, 0] as $activeTenant) {
            if ($activeTenant === 0) {
                $this->pdo->prepare("INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, status, created_at)
                    VALUES (?, 0, (SELECT id FROM roles ORDER BY id ASC LIMIT 1), NULL, 'active', NOW())")
                    ->execute([$this->profileId]);
            }
            $_COOKIE = ['access_token' => $this->jwtParser->create([
                'profile_id'       => $this->profileId,
                'active_tenant_id' => $activeTenant,
                'email'            => 'alice@acme.test',
                'role'             => 'admin',
                'token_epoch'      => 0,
            ], 900, 'access')];
            $_GET = $query;
            try {
                $res = $handler->start(new Request('GET', '/api/v1/auth/sso/google/start', [], ''), ['provider' => 'google']);
            } finally {
                $_GET = [];
                $_COOKIE = [];
            }

            self::assertSame(302, $res->getStatusCode());
            self::assertStringNotContainsString('sso_error', $this->location($res), 'refused in tenant ' . $activeTenant);
            self::assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth', $this->location($res));
        }
    }

    /**
     * The whole fresh-sign-in round trip for a gated approval (WHIT-1147, found on
     * app.whity.dev 2026-11-22): an owner who signs in only through the provider starts
     * the check, signs in again there, comes back, and the approval goes through.
     */
    public function testAnOwnerWhoSignsInAgainCanApproveWithinFiveMinutes(): void
    {
        $purpose = 'workspace-ownership:offer-' . $this->tenantId . '-9-co_owner';
        $result = $this->gateRoundTrip($purpose, ['auth_time' => time()]);

        self::assertStringEndsWith('/admin/settings/owners?identity_check=signed_in', $result['location']);
        self::assertSame('fresh_sign_in', $result['verify']($purpose));
        self::assertNull($result['verify']($purpose), 'used once');
    }

    /**
     * Accepting an ownership offer is checked in the OFFER's workspace, while the sign-in comes
     * back under whichever workspace the person has open. Both must find the same record.
     */
    public function testAFreshSignInCountsWhicheverWorkspaceIsOpen(): void
    {
        $this->exec("INSERT INTO tenants (name, slug, created_at) VALUES ('AAU IRB', 'aau-irb', NOW())");
        $offerTenant = (int) $this->pdo->lastInsertId();
        $purpose = 'workspace-ownership:accept-1';
        $result = $this->gateRoundTrip($purpose, ['auth_time' => time()]);

        self::assertStringEndsWith('?identity_check=signed_in', $result['location']);
        $check = new \Whity\Core\Security\IdentityCheck($this->pdo, new \Whity\Core\Document\Signing\SigningChallengeRepository($this->pdo));
        self::assertSame('fresh_sign_in', $check->verify($offerTenant, $this->profileId, $purpose, null));
    }

    /**
     * A sign-in the provider did not make just now (an old session it answered from, or one
     * with no `auth_time` at all) confirms nothing, and the page is told why.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function staleSignIns(): iterable
    {
        yield 'ten minutes old' => [['auth_time' => time() - 600]];
        yield 'no auth_time' => [[]];
        yield 'in the future' => [['auth_time' => time() + 3600]];
    }

    /** @param array<string, mixed> $claims */
    #[\PHPUnit\Framework\Attributes\DataProvider('staleSignIns')]
    public function testAStaleSignInConfirmsNothing(array $claims): void
    {
        $purpose = 'workspace-ownership:offer-' . $this->tenantId . '-9-co_owner';
        $result = $this->gateRoundTrip($purpose, $claims);

        self::assertStringEndsWith('?identity_check=sign_in_not_fresh', $result['location']);
        self::assertNull($result['verify']($purpose));
    }

    /**
     * When the provider refuses or the callback fails, a person signing in AGAIN goes back to the
     * page they came from, told it didn't work, never to the login page, where "Continue with
     * Whity ID" signed them in afresh and recorded nothing (WHIT-1371). The log says why.
     */
    public function testAFailedReauthCallbackGoesBackToThePageAndIsLogged(): void
    {
        $log = $this->captureErrorLog();
        $_COOKIE = ['sso_flow_token' => $this->jwtParser->create([
            'state' => 's', 'nonce' => 'n', 'code_verifier' => 'v',
            'provider_id' => $this->providerId, 'tenant_id' => 0, 'provider_key' => 'google',
            'reauth_profile_id' => $this->profileId, 'reauth_tenant_id' => $this->tenantId,
            'reauth_return_to' => '/admin/settings/owners', 'reauth_route_id' => null,
            'reauth_purpose' => 'workspace-ownership:offer-1-9-co_owner',
        ], 600, 'oidc_flow')];
        $_GET = ['error' => 'login_required', 'state' => 's'];
        $handler = new SsoAuthHandler(...$this->handlerArgsWithReauth());

        $res = $handler->callback(new Request('GET', '/api/v1/auth/sso/google/callback', [], ''), ['provider' => 'google']);

        self::assertSame(self::APP_URL . '/admin/settings/owners?identity_check=sign_in_failed', $this->location($res));
        $line = $this->lastReauthLog($log);
        self::assertSame('sign_in_failed', $line['outcome']);
        self::assertSame('denied:login_required', $line['detail']);
        self::assertSame('workspace-ownership', $line['kind'], 'the kind, never the subject');
        self::assertSame($this->profileId, $line['profile_id']);

        // The next, ordinary sign-in on the same worker still lands on the login page.
        $_COOKIE = [];
        $_GET = ['code' => 'c', 'state' => 's'];
        self::assertStringContainsString('sso_error=expired', $this->location($handler->callback(new Request('GET', '/api/v1/auth/sso/google/callback', [], ''), ['provider' => 'google'])));
    }

    public function testEveryReauthOutcomeIsLoggedWithoutSecrets(): void
    {
        $log = $this->captureErrorLog();
        $this->gateRoundTrip('workspace-ownership:offer-' . $this->tenantId . '-9-co_owner', ['auth_time' => time() - 900]);

        $text = (string) file_get_contents($log);
        self::assertStringContainsString('"outcome":"started"', $text);
        $line = $this->lastReauthLog($log);
        self::assertSame('sign_in_not_fresh', $line['outcome']);
        self::assertMatchesRegularExpression('/^auth_time_age=9\d\d$/', (string) $line['detail']);
        self::assertStringNotContainsString(self::SUBJECT, $text);
        self::assertStringNotContainsString('alice@acme.test', $text);
        self::assertStringNotContainsString('offer-', $text);
    }

    private function captureErrorLog(): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'reauth-log');
        $previous = ini_set('error_log', $file);
        $this->restoreErrorLog = static function () use ($previous, $file): void {
            ini_set('error_log', $previous === false ? '' : $previous);
            @unlink($file);
        };

        return $file;
    }

    /** @return array<string, mixed> */
    private function lastReauthLog(string $file): array
    {
        $lines = array_values(array_filter(
            explode("\n", (string) file_get_contents($file)),
            static fn (string $l): bool => str_contains($l, '[sso.reauth] '),
        ));
        self::assertNotSame([], $lines, 'a reauth outcome is logged');
        $json = substr((string) end($lines), (int) strpos((string) end($lines), '[sso.reauth] ') + 13);

        return (array) json_decode(trim($json), true);
    }

    /** Someone else signing in at the provider never confirms this approver. */
    public function testADifferentPersonsSignInConfirmsNothing(): void
    {
        $this->exec("INSERT INTO profiles (display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
            VALUES ('Mallory', 'x', false, 0, 0, NOW(), NOW())");
        $other = (int) $this->pdo->lastInsertId();
        (new ExternalIdentityRepository($this->pdo))->link($other, 'google', self::ISSUER, 'google-sub-mallory', 'mallory@acme.test');

        $purpose = 'workspace-ownership:offer-' . $this->tenantId . '-9-co_owner';
        $result = $this->gateRoundTrip($purpose, ['auth_time' => time()], 'google-sub-mallory');

        self::assertStringEndsWith('?identity_check=sign_in_mismatch', $result['location']);
        self::assertNull($result['verify']($purpose));
    }

    /** A fresh sign-in for one approval does not approve another, and runs out after five minutes. */
    public function testAFreshSignInCountsForThatApprovalOnlyAndForFiveMinutes(): void
    {
        $purpose = 'workspace-ownership:remove-' . $this->tenantId . '-9';
        $result = $this->gateRoundTrip($purpose, ['auth_time' => time()]);

        self::assertNull($result['verify']('workspace-ownership:remove-' . $this->tenantId . '-10'), 'another approval');
        $this->exec("UPDATE signing_challenges SET expires_at = '2000-01-01 00:00:00'");
        self::assertNull($result['verify']($purpose), 'after five minutes');
    }

    /**
     * @param array<string, mixed> $claims
     * @return array{location: string, verify: \Closure(string): ?string}
     */
    private function gateRoundTrip(string $purpose, array $claims, string $sub = self::SUBJECT): array
    {
        $this->linkIdentity();
        $this->exec("UPDATE profiles SET auth_method = 'idp' WHERE id = " . $this->profileId);
        $handler = new SsoAuthHandler(...$this->handlerArgsWithReauth());
        $challenges = new \Whity\Core\Document\Signing\SigningChallengeRepository($this->pdo);
        $check = new \Whity\Core\Security\IdentityCheck($this->pdo, $challenges);

        $started = $check->start($this->tenantId, $this->profileId, $purpose, 'changing who owns a workspace');
        self::assertSame('fresh_sign_in', $started['method'] ?? null);
        self::assertSame($purpose, $started['purpose'] ?? null, 'the page is told which approval to sign in again for');

        $_COOKIE = ['access_token' => $this->jwtParser->create([
            'profile_id' => $this->profileId, 'active_tenant_id' => $this->tenantId,
            'email' => 'alice@acme.test', 'role' => 'admin', 'token_epoch' => 0,
        ], 900, 'access')];
        $_GET = ['reauth' => 'gate', 'purpose' => $purpose, 'return_to' => '/admin/settings/owners'];
        $start = $handler->start(new Request('GET', '/api/v1/auth/sso/google/start', [], ''), ['provider' => 'google']);
        $authorize = $this->location($start);
        self::assertStringStartsWith('https://accounts.google.com/', $authorize, $authorize);
        parse_str((string) parse_url($authorize, PHP_URL_QUERY), $q);
        self::assertSame('login', $q['prompt'] ?? null);

        // The flow cookie start() set (headers can't be read back under the CLI).
        $_COOKIE['sso_flow_token'] = $this->jwtParser->create([
            'state' => $q['state'], 'nonce' => $q['nonce'], 'code_verifier' => 'v',
            'provider_id' => $this->providerId, 'tenant_id' => 0, 'provider_key' => 'google',
            'reauth_profile_id' => $this->profileId, 'reauth_tenant_id' => $this->tenantId,
            'reauth_return_to' => '/admin/settings/owners', 'reauth_route_id' => null,
            'reauth_purpose' => $purpose,
        ], 600, 'oidc_flow');
        $now = time();
        $this->http->tokenResponse = ['id_token' => JWT::encode([
            'iss' => self::ISSUER, 'aud' => self::CLIENT_ID, 'sub' => $sub, 'email' => 'alice@acme.test',
            'email_verified' => true, 'nonce' => $q['nonce'], 'iat' => $now, 'exp' => $now + 3600,
        ] + $claims, $this->privatePem, 'RS256', $this->kid), 'access_token' => 'at'];
        $_GET = ['code' => 'c', 'state' => $q['state']];
        $back = $handler->callback(new Request('GET', '/api/v1/auth/sso/google/callback', [], ''), ['provider' => 'google']);
        $_GET = [];
        $_COOKIE = [];

        return [
            'location' => $this->location($back),
            'verify' => fn (string $p): ?string => $check->verify($this->tenantId, $this->profileId, $p, null),
        ];
    }

    /** @return array<string, mixed> */
    private function handlerArgsWithReauth(): array
    {
        $prop = new \ReflectionProperty(SsoAuthHandler::class, 'engine');
        $engine = $prop->getValue($this->handler);
        $authProp = new \ReflectionProperty(SsoAuthHandler::class, 'auth');
        $emailRepo = new ProfileEmailRepository($this->pdo);

        return [
            'engine' => $engine,
            'providers' => new IdentityProviderRepository($this->pdo),
            'emails' => $emailRepo,
            'hostResolver' => new HostResolver(new TenantHostRepository($this->pdo), 'example.test'),
            'jwtParser' => $this->jwtParser,
            'secrets' => new EncryptedSecretStore(['v1' => 'sso_test_key_0123456789abcdef0123456789'], 'v1'),
            'auth' => $authProp->getValue($this->handler),
            'linker' => new FederatedIdentityLinker($this->pdo, new ExternalIdentityRepository($this->pdo), $emailRepo, new MembershipRepository($this->pdo), new \Whity\Core\Identity\TenantEmailDomainsRepository($this->pdo)),
            'settings' => $this->settings,
            'appUrl' => self::APP_URL,
            'sessions' => new \Whity\Auth\TokenValidator($this->jwtParser, $this->pdo),
            'identities' => new ExternalIdentityRepository($this->pdo),
            'signingChallenges' => new \Whity\Core\Document\Signing\SigningChallengeRepository($this->pdo),
        ];
    }

    // ── linking from Settings › Security (WHIT-1350) ─────────────────────────

    private function linkHandler(): SsoAuthHandler
    {
        return new SsoAuthHandler(...$this->handlerArgsWithReauth() + ['audit' => new AuditLogger($this->pdo, new NullLogger())]);
    }

    /** @param array<string, mixed> $extra */
    private function sessionCookie(array $extra = []): string
    {
        return $this->jwtParser->create([
            'profile_id'       => $this->profileId,
            'active_tenant_id' => $this->tenantId,
            'email'            => 'alice@acme.test',
            'role'             => 'admin',
            'token_epoch'      => 0,
        ] + $extra, 900, 'access');
    }

    /** A link flow whose provider account is a DIFFERENT address from the core account. */
    private function primeLinkFlow(string $sub = 'wid-sub-1', ?int $linkProfileId = null): void
    {
        $this->primeFlow(sub: $sub, email: 'amro@personal.test');
        $flow = $this->jwtParser->parse($_COOKIE['sso_flow_token']);
        self::assertIsArray($flow);
        $_COOKIE['sso_flow_token'] = $this->jwtParser->create([
            'state' => 'state-1', 'nonce' => 'nonce-1', 'code_verifier' => 'verifier-1',
            'provider_id' => $this->providerId, 'tenant_id' => 0, 'provider_key' => 'google',
            'link_profile_id' => $linkProfileId ?? $this->profileId,
        ], 600, 'oidc_flow');
        $_COOKIE['access_token'] = $this->sessionCookie();
        $_GET = ['code' => 'authcode', 'state' => 'state-1'];
    }

    public function testLinkingStartsOnlyFromASessionAndAlwaysUsesTheOperatorsProvider(): void
    {
        $handler = $this->linkHandler();
        $_GET = ['link' => '1'];
        $refused = $handler->start(new Request('GET', '/api/v1/auth/sso/google/start', [], ''), ['provider' => 'google']);
        self::assertStringContainsString('/settings/security?identity_link=sign_in_first', $this->location($refused));

        $_COOKIE = ['access_token' => $this->sessionCookie()];
        $_GET = ['link' => '1'];
        $res = $handler->start(new Request('GET', '/api/v1/auth/sso/google/start', [], ''), ['provider' => 'google']);
        self::assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth', $this->location($res));
        self::assertStringContainsString('prompt=login', $this->location($res));
    }

    public function testASessionConfinedByAWorkspaceProviderCannotLink(): void
    {
        $_COOKIE = ['access_token' => $this->sessionCookie([\Whity\Auth\TenantConfinement::CLAIM => $this->tenantId])];
        $_GET = ['link' => '1'];
        $res = $this->linkHandler()->start(new Request('GET', '/api/v1/auth/sso/google/start', [], ''), ['provider' => 'google']);

        self::assertStringContainsString('identity_link=sign_in_first', $this->location($res));
    }

    public function testLinkingAttachesTheProviderAccountWhateverItsAddressAndIsAudited(): void
    {
        $this->primeLinkFlow();
        $res = $this->linkHandler()->callback(new Request('GET', '/api/v1/auth/sso/google/callback', [], ''), ['provider' => 'google']);

        self::assertStringContainsString('/settings/security?identity_link=linked', $this->location($res));
        $link = (new ExternalIdentityRepository($this->pdo))->findGlobalByIssuerSubject(self::ISSUER, 'wid-sub-1');
        self::assertNotNull($link);
        self::assertSame($this->profileId, (int) $link['profile_id']);
        self::assertSame(1, (int) $this->col("SELECT COUNT(*) FROM audit_log WHERE action = 'auth.identity.linked'"));
        self::assertArrayNotHasKey('user', json_decode($res->getBody(), true) ?? [], 'no session is minted');
    }

    public function testAProviderAccountLinkedToSomebodyElseIsRefused(): void
    {
        $this->exec("INSERT INTO profiles (display_name, password_hash, two_factor_enabled, token_epoch, created_at, updated_at)
            VALUES ('Awss Haddad', '', false, 0, NOW(), NOW())");
        $other = (int) $this->pdo->lastInsertId();
        (new ExternalIdentityRepository($this->pdo))->link($other, 'google', self::ISSUER, 'wid-sub-1', 'awss@acme.test');

        $this->primeLinkFlow();
        $res = $this->linkHandler()->callback(new Request('GET', '/api/v1/auth/sso/google/callback', [], ''), ['provider' => 'google']);

        self::assertStringContainsString('identity_link=taken', $this->location($res));
        self::assertSame(0, (int) $this->col("SELECT COUNT(*) FROM external_identities WHERE profile_id = {$this->profileId}"));
    }

    public function testTheCallbackRefusesWhenTheSessionIsNoLongerThePersonWhoStarted(): void
    {
        $this->primeLinkFlow(linkProfileId: $this->profileId + 999);
        $res = $this->linkHandler()->callback(new Request('GET', '/api/v1/auth/sso/google/callback', [], ''), ['provider' => 'google']);

        self::assertStringContainsString('identity_link=failed', $this->location($res));
        self::assertSame(0, (int) $this->col('SELECT COUNT(*) FROM external_identities'));
    }

    // ── headless frontend (WHIT-693) ──────────────────────────────────────────

    public function testHeadlessFrontendReceivesTheRedirectsWhileTheCallbackStaysOnTheBackend(): void
    {
        $this->handler = ($this->makeHandler)(new \Whity\Core\Http\FrontendUrls([
            'APP_URL' => self::APP_URL,
            'FRONTEND_URL' => 'https://portal.example',
            'SSO_SUCCESS_URL' => 'https://portal.example/home',
        ]));

        // The provider is sent back to the BACKEND's callback, never the frontend.
        $start = $this->handler->start(new Request('GET', '/api/v1/auth/sso/google/start', [], ''), ['provider' => 'google']);
        self::assertStringContainsString(
            'redirect_uri=' . rawurlencode(self::APP_URL . '/api/v1/auth/sso/google/callback'),
            $this->location($start)
        );

        // A failure bounces to the frontend's login page...
        $bounce = $this->handler->start(new Request('GET', '/x', [], ''), ['provider' => 'nope']);
        self::assertSame('https://portal.example/login?sso_error=provider_unavailable', $this->location($bounce));

        // ...and a success lands on the overridden page.
        $this->linkIdentity();
        $this->primeFlow();
        $_GET = ['code' => 'authcode', 'state' => 'state-1'];
        self::assertSame('https://portal.example/home', $this->location($this->runCallback()));
    }

    // ── callback ────────────────────────────────────────────────────────────────

    public function testCallbackLinkedIdentityLogsInAndRedirectsToDashboard(): void
    {
        $this->linkIdentity();
        $this->primeFlow();
        $_GET = ['code' => 'authcode', 'state' => 'state-1'];

        $res = $this->runCallback();
        self::assertSame(302, $res->getStatusCode(), $res->getBody());
        self::assertSame(self::APP_URL . '/dashboard', $this->location($res));

        // The link's last_login_at was stamped.
        self::assertNotNull(
            $this->col("SELECT last_login_at FROM external_identities WHERE subject = '" . self::SUBJECT . "'"),
            'a successful federated login stamps last_login_at'
        );
    }

    public function testCallbackRefusesADeactivatedProfileEvenWithAnActiveMembership(): void
    {
        // Password login and refresh refuse profiles.status = 'inactive'; the
        // federated path must too, or deactivation is bypassed by the IdP button.
        $this->linkIdentity();
        $this->exec("UPDATE profiles SET status = 'inactive' WHERE id = {$this->profileId}");
        $this->primeFlow();
        $_GET = ['code' => 'authcode', 'state' => 'state-1'];

        $res = $this->runCallback();

        self::assertStringContainsString('/login?sso_error=no_membership', $this->location($res));
        self::assertArrayNotHasKey('access_token', $_COOKIE);
    }

    public function testCallbackVerifiedEmailMatchAutoLinksExistingProfileAndLogsIn(): void
    {
        // No prior link, but the verified IdP email matches Alice's VERIFIED
        // primary email → link-by-verified-email → login (WC-f3b17bd2).
        $this->primeFlow(email: 'alice@acme.test', emailVerified: true);
        $_GET = ['code' => 'authcode', 'state' => 'state-1'];

        $res = $this->runCallback();
        self::assertSame(self::APP_URL . '/dashboard', $this->location($res), $res->getBody());
        // A link to Alice's profile now exists.
        self::assertSame(
            (string) $this->profileId,
            (string) $this->col("SELECT profile_id FROM external_identities WHERE subject = '" . self::SUBJECT . "'")
        );
    }

    public function testCallbackUnverifiedEmailIsRefused(): void
    {
        // Verified-match would link, but the IdP says the email is NOT verified →
        // never link/provision by an unproven address (anti-takeover).
        $this->primeFlow(email: 'alice@acme.test', emailVerified: false);
        $_GET = ['code' => 'authcode', 'state' => 'state-1'];

        $res = $this->runCallback();
        self::assertStringContainsString('/login?sso_error=email_unverified', $this->location($res));
        self::assertSame(0, (int) $this->col("SELECT COUNT(*) FROM external_identities WHERE subject = '" . self::SUBJECT . "'"));
    }

    public function testCallbackNewVerifiedIdentityProvisionsAPasswordlessProfile(): void
    {
        // A verified identity whose email matches NO local profile → provision a
        // new passwordless profile + verified email + link. It has no membership,
        // so it lands on the "no workspace yet" page (WHIT-994), but the account
        // is provisioned and linked.
        $this->primeFlow(sub: 'brand-new-sub', email: 'newperson@fresh.test', emailVerified: true);
        $_GET = ['code' => 'authcode', 'state' => 'state-1'];

        $res = $this->runCallback();
        self::assertSame(self::APP_URL . '/welcome', $this->location($res));

        // A passwordless profile + verified email + link were created.
        $pid = (int) $this->col("SELECT profile_id FROM external_identities WHERE subject = 'brand-new-sub'");
        self::assertGreaterThan(0, $pid);
        self::assertSame('', (string) $this->col("SELECT password_hash FROM profiles WHERE id = {$pid}"));
        self::assertContains(
            (string) $this->col("SELECT verified FROM profile_emails WHERE email = 'newperson@fresh.test'"),
            ['1', 't', 'true']
        );
    }

    public function testCallbackUnverifiedLocalEmailConflictIsRefused(): void
    {
        // A local profile_email for the IdP's email exists but is UNVERIFIED →
        // refuse (a half-registered local account must not be seizable via SSO).
        $this->exec("INSERT INTO profiles
            (display_name, password_hash, two_factor_enabled, two_factor_secret,
             two_factor_backup_codes_version, token_epoch, created_at, updated_at)
            VALUES ('Pending', 'x', false, NULL, 0, 0, NOW(), NOW())");
        $pendingId = (int) $this->pdo->lastInsertId();
        (new ProfileEmailRepository($this->pdo))->insert($pendingId, 'pending@acme.test', false, true);

        $this->primeFlow(sub: 'conflict-sub', email: 'pending@acme.test', emailVerified: true);
        $_GET = ['code' => 'authcode', 'state' => 'state-1'];

        $res = $this->runCallback();
        self::assertStringContainsString('/login?sso_error=link_conflict', $this->location($res));
        self::assertSame(0, (int) $this->col("SELECT COUNT(*) FROM external_identities WHERE subject = 'conflict-sub'"));
    }

    public function testCallbackTenantTrustLinksActiveMemberAndConfinesSession(): void
    {
        // Acme's own IdP (tenant-trust) asserts a verified email belonging to an
        // ACTIVE MEMBER of Acme → link in the tenant namespace + session into Acme.
        $this->primeFlow(
            sub: 'tenant-sub',
            email: 'alice@acme.test',
            emailVerified: true,
            providerId: $this->tenantProviderId,
            tenantId: $this->tenantId,
        );
        $_GET = ['code' => 'authcode', 'state' => 'state-1'];

        $res = $this->runCallback();
        self::assertSame(self::APP_URL . '/dashboard', $this->location($res), $res->getBody());
        // Linked in the TENANT namespace (provider_id = Acme's provider), so it can
        // never collide with or spoof the global (issuer, subject) namespace.
        self::assertSame(
            (string) $this->tenantProviderId,
            (string) $this->col("SELECT provider_id FROM external_identities WHERE subject = 'tenant-sub'")
        );
    }

    public function testCallbackTenantTrustRefusesNonMember(): void
    {
        // A DIFFERENT tenant's member (Bob) — Acme's IdP asserts his verified email.
        // Bob is NOT a member of Acme, so the tenant-trust IdP must not reach him
        // (the cross-tenant takeover the review flagged). Refused, no link.
        $this->exec("INSERT INTO tenants (name, slug, created_at) VALUES ('Other', 'other', NOW())");
        $otherTenant = (int) $this->pdo->lastInsertId();
        $roleId = (int) $this->col('SELECT id FROM roles ORDER BY id ASC LIMIT 1');
        $this->exec("INSERT INTO profiles
            (display_name, password_hash, two_factor_enabled, two_factor_secret,
             two_factor_backup_codes_version, token_epoch, created_at, updated_at)
            VALUES ('Bob', '" . password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]) . "', false, NULL, 0, 0, NOW(), NOW())");
        $bobId = (int) $this->pdo->lastInsertId();
        (new ProfileEmailRepository($this->pdo))->insert($bobId, 'bob@other.test', true, true);
        $this->pdo->prepare("INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, status, created_at)
            VALUES (:p, :t, :r, NULL, 'active', NOW())")
            ->execute([':p' => $bobId, ':t' => $otherTenant, ':r' => $roleId]);

        $this->primeFlow(
            sub: 'bob-sub',
            email: 'bob@other.test',
            emailVerified: true,
            providerId: $this->tenantProviderId,
            tenantId: $this->tenantId,
        );
        $_GET = ['code' => 'authcode', 'state' => 'state-1'];

        $res = $this->runCallback();
        self::assertStringContainsString('/login?sso_error=no_account', $this->location($res));
        self::assertSame(0, (int) $this->col("SELECT COUNT(*) FROM external_identities WHERE subject = 'bob-sub'"));
    }

    public function testCallbackStateMismatchIsRejected(): void
    {
        $this->linkIdentity();
        $this->primeFlow('real-state');
        $_GET = ['code' => 'authcode', 'state' => 'forged-state'];

        $res = $this->runCallback();
        self::assertStringContainsString('/login?sso_error=state_mismatch', $this->location($res));
    }

    public function testCallbackMissingFlowCookieIsRejected(): void
    {
        $_GET = ['code' => 'authcode', 'state' => 'state-1'];
        // No $_COOKIE['sso_flow_token'].
        $res = $this->runCallback();
        self::assertStringContainsString('/login?sso_error=expired', $this->location($res));
    }

    public function testCallbackProviderErrorIsRejected(): void
    {
        $this->primeFlow();
        $_GET = ['error' => 'access_denied', 'state' => 'state-1'];
        $res = $this->runCallback();
        self::assertStringContainsString('/login?sso_error=denied', $this->location($res));
    }

    public function testCallbackNonceMismatchFailsVerification(): void
    {
        $this->linkIdentity();
        // Flow cookie nonce differs from the id_token nonce → verifyIdToken rejects.
        $this->primeFlow('state-1', 'cookie-nonce');
        // Re-sign the id_token with a DIFFERENT nonce.
        $now = time();
        $this->http->tokenResponse = ['id_token' => JWT::encode([
            'iss' => self::ISSUER, 'aud' => self::CLIENT_ID, 'sub' => self::SUBJECT,
            'nonce' => 'attacker-nonce', 'iat' => $now, 'exp' => $now + 3600,
        ], $this->privatePem, 'RS256', $this->kid)];
        $_GET = ['code' => 'authcode', 'state' => 'state-1'];

        $res = $this->runCallback();
        self::assertStringContainsString('/login?sso_error=failed', $this->location($res));
    }

    // ── self-service workspace on a first global-trust sign-in ───────────────

    // ── WHIT-1168: an invitation accepted by signing in ──────────────────────

    /** @return array{tenant: int, token: string} */
    private function inviteTo(string $email): array
    {
        $this->exec("INSERT INTO tenants (name, slug, created_at) VALUES ('Example Bakery', 'example-bakery', NOW())");
        $tenant = (int) $this->pdo->lastInsertId();
        $roleId = (int) $this->col('SELECT id FROM roles ORDER BY id ASC LIMIT 1');
        $invited = (new \Whity\Core\Identity\InvitationService($this->pdo, new \Whity\Core\Identity\ProfileProvisioner($this->pdo)))
            ->invite($tenant, $email, $roleId);

        return ['tenant' => $tenant, 'token' => $invited['token']];
    }

    public function testANewPersonAcceptsAnInvitationBySigningInAndLandsInTheWorkspace(): void
    {
        $invite = $this->inviteTo('newbie@fresh.test');
        $this->primeFlow(sub: 'newbie-sub', email: 'newbie@fresh.test', invite: $invite['token']);
        $_GET = ['code' => 'authcode', 'state' => 'state-1'];

        $res = $this->runCallback();

        self::assertSame(self::APP_URL . '/dashboard', $this->location($res), $res->getBody());
        $profile = $this->profileFor('newbie-sub');
        self::assertSame(1, (int) $this->col(
            "SELECT COUNT(*) FROM memberships WHERE profile_id = {$profile} AND tenant_id = {$invite['tenant']} AND status = 'active'"
        ));
        self::assertSame('accepted', (string) $this->col("SELECT status FROM invitations WHERE tenant_id = {$invite['tenant']}"));
        // No password was ever chosen.
        self::assertSame('', (string) $this->col("SELECT password_hash FROM profiles WHERE id = {$profile}"));
    }

    public function testAnExistingPersonAcceptsAnInvitationBySigningIn(): void
    {
        $this->linkIdentity();
        $invite = $this->inviteTo('alice@acme.test');
        $this->primeFlow(invite: $invite['token']);
        $_GET = ['code' => 'authcode', 'state' => 'state-1'];

        $res = $this->runCallback();

        self::assertSame(self::APP_URL . '/dashboard', $this->location($res), $res->getBody());
        self::assertSame(1, (int) $this->col(
            "SELECT COUNT(*) FROM memberships WHERE profile_id = {$this->profileId} AND tenant_id = {$invite['tenant']} AND status = 'active'"
        ));
    }

    public function testAnInvitationSentToAnotherAddressIsNotTaken(): void
    {
        $invite = $this->inviteTo('someone-else@fresh.test');
        $this->primeFlow(sub: 'newbie-sub', email: 'newbie@fresh.test', invite: $invite['token']);
        $_GET = ['code' => 'authcode', 'state' => 'state-1'];

        $res = $this->runCallback();

        self::assertStringContainsString('/login?sso_error=invite_other_email', $this->location($res));
        self::assertSame('pending', (string) $this->col("SELECT status FROM invitations WHERE tenant_id = {$invite['tenant']}"));
    }

    public function testAnUnusableInvitationSaysSo(): void
    {
        $this->primeFlow(sub: 'newbie-sub', email: 'newbie@fresh.test', invite: 'not-a-real-token');
        $_GET = ['code' => 'authcode', 'state' => 'state-1'];

        self::assertStringContainsString('/login?sso_error=invite_invalid', $this->location($this->runCallback()));
    }

    private function enableSelfProvision(): void
    {
        $this->settings->setGlobal(\Whity\Core\Settings\SettingsRegistry::SSO_SELF_PROVISION_WORKSPACE, 'true');
    }

    private function signInNewPerson(string $sub = 'fresh-sub', string $email = 'fresh@person.test'): \Whity\Sdk\Http\Response
    {
        $this->primeFlow(sub: $sub, email: $email, emailVerified: true);
        $_GET = ['code' => 'authcode', 'state' => 'state-1'];
        return $this->runCallback();
    }

    private function profileFor(string $sub): int
    {
        return (int) $this->col("SELECT profile_id FROM external_identities WHERE subject = '{$sub}'");
    }

    public function testSelfProvisionOffSendsANewPersonToThePlainNeedAnInvitationPage(): void
    {
        // WHIT-994: no error code. They land on the "no workspace yet" page,
        // which says they need an invitation and names the address.
        $tenantsBefore = (int) $this->col('SELECT COUNT(*) FROM tenants');

        $res = $this->signInNewPerson();

        self::assertSame(self::APP_URL . '/welcome', $this->location($res));
        self::assertSame($tenantsBefore, (int) $this->col('SELECT COUNT(*) FROM tenants'));
    }

    public function testSelfProvisionOnAsksInsteadOfCreatingAWorkspaceSilently(): void
    {
        $this->enableSelfProvision();
        $tenantsBefore = (int) $this->col('SELECT COUNT(*) FROM tenants');

        $res = $this->signInNewPerson();

        self::assertSame(self::APP_URL . '/welcome', $this->location($res), $res->getBody());
        self::assertSame($tenantsBefore, (int) $this->col('SELECT COUNT(*) FROM tenants'));
        self::assertSame(0, (int) $this->col('SELECT COUNT(*) FROM memberships WHERE profile_id = ' . $this->profileFor('fresh-sub')));
    }

    public function testOnceTheyHaveCreatedTheirWorkspaceASignInGoesStraightIn(): void
    {
        $this->enableSelfProvision();
        $this->signInNewPerson();
        $pid = $this->profileFor('fresh-sub');
        (new \Whity\Core\Tenant\FederatedWorkspaceProvisioner($this->pdo, $this->settings))
            ->provisionFor($pid, 'fresh@person.test', new \Whity\Core\Identity\FederatedProviderContext($this->providerId, 'google', 0), 'Example Bakery');
        $tenantsAfter = (int) $this->col('SELECT COUNT(*) FROM tenants');

        $res = $this->signInNewPerson();

        self::assertSame(self::APP_URL . '/dashboard', $this->location($res), $res->getBody());
        self::assertSame($tenantsAfter, (int) $this->col('SELECT COUNT(*) FROM tenants'));
        self::assertSame(1, (int) $this->col('SELECT COUNT(*) FROM memberships WHERE profile_id = ' . $pid));
    }

    public function testSelfProvisionLeavesAlonePeopleWhoAlreadyBelongSomewhere(): void
    {
        // Somebody already invited (not yet active) has been placed by a human;
        // a fresh workspace would route around that decision. They land on the
        // same page, which tells them their place is waiting (WHIT-994).
        $this->enableSelfProvision();
        $this->exec("INSERT INTO profiles
            (display_name, password_hash, two_factor_enabled, two_factor_secret,
             two_factor_backup_codes_version, token_epoch, created_at, updated_at)
            VALUES ('Invitee', '', false, NULL, 0, 0, NOW(), NOW())");
        $inviteeId = (int) $this->pdo->lastInsertId();
        (new ProfileEmailRepository($this->pdo))->insert($inviteeId, 'invitee@acme.test', true, true);
        $roleId = (int) $this->col('SELECT id FROM roles ORDER BY id ASC LIMIT 1');
        $this->pdo->prepare("INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, status, created_at)
            VALUES (:p, :t, :r, NULL, 'invited', NOW())")
            ->execute([':p' => $inviteeId, ':t' => $this->tenantId, ':r' => $roleId]);
        $tenantsBefore = (int) $this->col('SELECT COUNT(*) FROM tenants');

        $res = $this->signInNewPerson('invitee-sub', 'invitee@acme.test');

        self::assertSame(self::APP_URL . '/welcome', $this->location($res));
        self::assertSame($tenantsBefore, (int) $this->col('SELECT COUNT(*) FROM tenants'));
    }

    public function testATenantsOwnProviderIsNeverSentToTheCreateStep(): void
    {
        // A bring-your-own IdP is confined to its tenant: a person it signs in
        // who is not a member there is refused, never offered a workspace.
        $this->enableSelfProvision();
        $tenantsBefore = (int) $this->col('SELECT COUNT(*) FROM tenants');
        $this->primeFlow(sub: 'outsider-sub', email: 'outsider@elsewhere.test', providerId: $this->tenantProviderId, tenantId: $this->tenantId);
        $_GET = ['code' => 'authcode', 'state' => 'state-1'];

        $res = $this->runCallback();

        self::assertStringContainsString('/login?sso_error=', $this->location($res));
        self::assertStringNotContainsString('/welcome', $this->location($res));
        self::assertSame($tenantsBefore, (int) $this->col('SELECT COUNT(*) FROM tenants'));
    }

    public function testASignInIsAnnouncedWithTheSubjectAndNoWorkspace(): void
    {
        $this->enableSelfProvision();
        $seen = [];
        $this->hooks->listen(SsoAuthHandler::SIGNED_IN_EVENT, function (array $data) use (&$seen): array {
            $seen[] = $data;
            return $data;
        });

        $this->signInNewPerson();

        self::assertCount(1, $seen);
        self::assertSame($this->profileFor('fresh-sub'), $seen[0]['profile_id']);
        self::assertSame('fresh-sub', $seen[0]['subject']);
        self::assertSame(self::ISSUER, $seen[0]['issuer']);
        self::assertSame('google', $seen[0]['provider_key']);
        self::assertTrue($seen[0]['global_trust']);
        // A sign-in no longer creates a workspace (WHIT-994).
        self::assertNull($seen[0]['provisioned_tenant_id']);
    }

    public function testADeactivatedAccountSigningInGetsNoWorkspace(): void
    {
        // An operator's deactivation is their deny switch; removing somebody's
        // last membership must not turn it into a free new workspace.
        $this->enableSelfProvision();
        $this->exec("INSERT INTO profiles
            (display_name, password_hash, two_factor_enabled, two_factor_secret,
             two_factor_backup_codes_version, token_epoch, status, created_at, updated_at)
            VALUES ('Banned', '', false, NULL, 0, 0, 'inactive', NOW(), NOW())");
        $bannedId = (int) $this->pdo->lastInsertId();
        (new ProfileEmailRepository($this->pdo))->insert($bannedId, 'banned@example.test', true, true);
        $tenantsBefore = (int) $this->col('SELECT COUNT(*) FROM tenants');

        $res = $this->signInNewPerson('banned-sub', 'banned@example.test');

        self::assertStringContainsString('/login?sso_error=', $this->location($res));
        self::assertSame($tenantsBefore, (int) $this->col('SELECT COUNT(*) FROM tenants'));
    }

    // ── WHIT-1181: the person's phone from a verified phone_number ─────────

    private function phoneOf(int $profileId): ?string
    {
        $phone = $this->col('SELECT phone FROM profiles WHERE id = ' . $profileId);

        return is_string($phone) ? $phone : null;
    }

    private function signInWithPhone(string $phone, bool $verified = true): void
    {
        $this->primeFlow(extraClaims: ['phone_number' => $phone, 'phone_number_verified' => $verified]);
        $_GET = ['code' => 'authcode', 'state' => 'state-1'];
        $res = $this->runCallback();
        self::assertSame(self::APP_URL . '/dashboard', $this->location($res), $res->getBody());
    }

    public function testAVerifiedPhoneFromTheOperatorsProviderFillsAnEmptyPhoneAndRefreshesItsOwn(): void
    {
        $this->linkIdentity();
        $this->signInWithPhone('+962 79 123 4567');
        self::assertSame('+962791234567', $this->phoneOf($this->profileId));
        self::assertSame('identity_provider', $this->col('SELECT phone_source FROM profiles WHERE id = ' . $this->profileId));

        $this->signInWithPhone('+962791112222');
        self::assertSame('+962791112222', $this->phoneOf($this->profileId), 'a phone that came from a sign-in follows the provider');
    }

    public function testASignInNeverReplacesOrRefillsAPhoneThePersonSetOrCleared(): void
    {
        $this->linkIdentity();
        $phones = new \Whity\Core\Identity\PersonalPhone($this->pdo);

        $phones->setByPerson($this->profileId, '+447400123456');
        $this->signInWithPhone('+962791234567');
        self::assertSame('+447400123456', $this->phoneOf($this->profileId));

        $phones->setByPerson($this->profileId, null);
        $this->signInWithPhone('+962791234567');
        self::assertNull($this->phoneOf($this->profileId), 'cleared on purpose stays cleared');
    }

    public function testAnUnverifiedOrUnreadablePhoneIsIgnored(): void
    {
        $this->linkIdentity();
        $this->signInWithPhone('+962791234567', verified: false);
        self::assertNull($this->phoneOf($this->profileId));

        $this->signInWithPhone('0791234567');
        self::assertNull($this->phoneOf($this->profileId), 'no country code, nothing to store');
    }

    public function testAWorkspacesOwnProviderNeverWritesThePersonsPhone(): void
    {
        $this->primeFlow(
            sub: 'tenant-sub',
            email: 'alice@acme.test',
            emailVerified: true,
            providerId: $this->tenantProviderId,
            tenantId: $this->tenantId,
            extraClaims: ['phone_number' => '+962791234567', 'phone_number_verified' => true],
        );
        $_GET = ['code' => 'authcode', 'state' => 'state-1'];
        $res = $this->runCallback();
        self::assertSame(self::APP_URL . '/dashboard', $this->location($res), $res->getBody());
        self::assertNull($this->phoneOf($this->profileId));
    }
    private const NATIVE_VERIFIER = 'native-verifier-0123456789abcdefghijklmnopqrstuvwxyz-ABCDEF';

    /** @return array{port: int, challenge: string, state: string} */
    private function nativeTarget(): array
    {
        return ['port' => 43127, 'challenge' => \Whity\Auth\NativeSignInCodes::challengeFor(self::NATIVE_VERIFIER), 'state' => 'app-state-12345678'];
    }

    private function primeNativeFlow(): void
    {
        $this->primeFlow();
        $_COOKIE['sso_flow_token'] = $this->jwtParser->create([
            'state' => 'state-1',
            'nonce' => 'nonce-1',
            'code_verifier' => 'verifier-1',
            'provider_id' => $this->providerId,
            'tenant_id' => 0,
            'provider_key' => 'google',
            'native_port' => 43127,
            'native_challenge' => $this->nativeTarget()['challenge'],
            'native_state' => $this->nativeTarget()['state'],
        ], 600, 'oidc_flow');
        $_GET = ['code' => 'authcode', 'state' => 'state-1'];
    }

    /** @return array<string, string> */
    private function loopbackQuery(\Whity\Sdk\Http\Response $res): array
    {
        $loc = $this->location($res);
        self::assertStringStartsWith('http://127.0.0.1:43127/callback?', $loc, 'only ever to the loopback port the app named');
        parse_str((string) parse_url($loc, PHP_URL_QUERY), $query);
        /** @var array<string, string> $query */
        return $query;
    }

    /** @param array<string, string> $headers */
    private function exchange(string $code, string $verifier = self::NATIVE_VERIFIER, array $headers = ['X-Auth-Mode' => 'token']): \Whity\Sdk\Http\Response
    {
        return $this->handler->exchange(new Request(
            'POST',
            '/api/v1/auth/sso/native/exchange',
            $headers,
            (string) json_encode(['code' => $code, 'code_verifier' => $verifier]),
        ));
    }

    public function testNativeStartCarriesTheReturnInsideTheFlowCookie(): void
    {
        $target = $this->nativeTarget();
        $_GET = ['mode' => 'native', 'port' => (string) $target['port'], 'code_challenge' => $target['challenge'], 'state' => $target['state']];
        $res = $this->handler->start(new Request('GET', '/api/v1/auth/sso/google/start', [], ''), ['provider' => 'google']);

        // The flow cookie is sent as a header, which this harness can't read; the
        // callback tests build the same flow and prove what the claims do.
        self::assertSame(302, $res->getStatusCode());
        $loc = $this->location($res);
        self::assertStringStartsWith('https://accounts.google.com/', $loc);
        self::assertStringContainsString('code_challenge_method=S256', $loc);
        self::assertStringNotContainsString($target['state'], $loc, "the app's state is not the provider's state");
        self::assertStringNotContainsString($target['challenge'], $loc, "the app's challenge is not the provider's challenge");
        self::assertStringNotContainsString('43127', $loc, 'the loopback port stays inside the signed flow cookie');
    }

    public function testNativeStartRefusesAnythingButALoopbackPortAChallengeAndAState(): void
    {
        $target = $this->nativeTarget();
        $cases = [
            'privileged port' => ['port' => '80'],
            'port too high' => ['port' => '70000'],
            'not a port' => ['port' => 'abc'],
            'no challenge' => ['code_challenge' => ''],
            'short challenge' => ['code_challenge' => 'abc'],
            'no state' => ['state' => ''],
        ];
        foreach ($cases as $label => $override) {
            $_GET = ['mode' => 'native', 'port' => (string) $target['port'], 'code_challenge' => $target['challenge'], 'state' => $target['state']] + [];
            foreach ($override as $k => $v) {
                $_GET[$k] = $v;
            }
            $res = $this->handler->start(new Request('GET', '/api/v1/auth/sso/google/start', [], ''), ['provider' => 'google']);
            self::assertStringContainsString('sso_error=native_invalid', $this->location($res), $label);
            self::assertArrayNotHasKey('sso_flow_token', $_COOKIE, $label . ': no flow starts');
            $_GET = [];
            $_COOKIE = [];
        }

        // Native never rides with a join, a form, a link, an invitation or a reauth.
        $_GET = ['mode' => 'native', 'port' => '43127', 'code_challenge' => $target['challenge'], 'state' => $target['state'], 'invite' => 'tok'];
        $res = $this->handler->start(new Request('GET', '/api/v1/auth/sso/google/start', [], ''), ['provider' => 'google']);
        self::assertStringContainsString('sso_error=native_unavailable', $this->location($res));
    }

    public function testNativeCallbackSendsAOneTimeCodeToTheLoopbackAndSetsNoSession(): void
    {
        $this->linkIdentity();
        $this->primeNativeFlow();

        $res = $this->runCallback();

        self::assertSame(302, $res->getStatusCode());
        $query = $this->loopbackQuery($res);
        self::assertSame('app-state-12345678', $query['state']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $query['code']);
        self::assertArrayNotHasKey('access_token', $_COOKIE, 'the browser gets no session');
        self::assertArrayNotHasKey('refresh_token', $_COOKIE);
        self::assertSame(1, (int) $this->col('SELECT COUNT(*) FROM native_sign_in_codes'));
        self::assertSame(0, (int) $this->col("SELECT COUNT(*) FROM native_sign_in_codes WHERE code_hash = " . $this->pdo->quote($query['code'])), 'only the hash is stored');
    }

    public function testTheCodeExchangesOnceForATokenModeSessionAndNeverAgain(): void
    {
        $this->linkIdentity();
        $this->primeNativeFlow();
        $code = $this->loopbackQuery($this->runCallback())['code'];

        $res = $this->exchange($code);
        self::assertSame(200, $res->getStatusCode(), $res->getBody());
        $body = json_decode($res->getBody(), true);
        self::assertSame('Bearer', $body['token_type']);
        self::assertNotEmpty($body['access_token']);
        self::assertNotEmpty($body['refresh_token']);
        self::assertSame('alice@acme.test', $body['user']['email']);
        self::assertSame($this->tenantId, $body['user']['tenant_id']);
        self::assertArrayNotHasKey('access_token', $_COOKIE, 'token mode sets no cookie');
        $claims = $this->jwtParser->parse($body['access_token']);
        self::assertNotNull($claims);
        self::assertSame($this->profileId, $claims['profile_id']);
        self::assertSame(0, (int) $this->col('SELECT COUNT(*) FROM native_sign_in_codes'), 'spent');

        // Replay.
        $again = $this->exchange($code);
        self::assertSame(401, $again->getStatusCode());
        self::assertSame(1, (int) $this->col("SELECT COUNT(*) FROM audit_log WHERE action = 'auth.native.exchange_refused'"));
        self::assertSame(1, (int) $this->col("SELECT COUNT(*) FROM audit_log WHERE action = 'auth.native.exchanged'"));
    }

    public function testAWrongVerifierSpendsTheCodeAndGetsNothing(): void
    {
        $this->linkIdentity();
        $this->primeNativeFlow();
        $code = $this->loopbackQuery($this->runCallback())['code'];

        $wrong = $this->exchange($code, 'another-verifier-0123456789abcdefghijklmnopqrstuvwxyz');
        self::assertSame(401, $wrong->getStatusCode());
        self::assertStringNotContainsString('access_token', $wrong->getBody());

        // The right verifier is too late now: one attempt spends the code.
        self::assertSame(401, $this->exchange($code)->getStatusCode());
        self::assertSame(
            'wrong_verifier',
            json_decode((string) $this->col("SELECT metadata FROM audit_log WHERE action = 'auth.native.exchange_refused' ORDER BY id ASC LIMIT 1"), true)['reason'] ?? null,
        );
    }

    public function testAnExpiredCodeIsRefused(): void
    {
        $this->linkIdentity();
        $this->primeNativeFlow();
        $code = $this->loopbackQuery($this->runCallback())['code'];

        $this->now += SsoAuthHandler::NATIVE_CODE_TTL + 1;
        $res = $this->exchange($code);
        self::assertSame(401, $res->getStatusCode());
        self::assertSame(
            'expired',
            json_decode((string) $this->col("SELECT metadata FROM audit_log WHERE action = 'auth.native.exchange_refused' ORDER BY id DESC LIMIT 1"), true)['reason'] ?? null,
        );
    }

    public function testTheExchangeIsTokenModeOnlyAndChecksItsInput(): void
    {
        $this->linkIdentity();
        $this->primeNativeFlow();
        $code = $this->loopbackQuery($this->runCallback())['code'];

        self::assertSame(400, $this->exchange($code, self::NATIVE_VERIFIER, [])->getStatusCode(), 'no X-Auth-Mode: token');
        self::assertSame(400, $this->exchange('short', self::NATIVE_VERIFIER)->getStatusCode());
        self::assertSame(400, $this->exchange($code, 'short')->getStatusCode());
        self::assertSame(1, (int) $this->col('SELECT COUNT(*) FROM native_sign_in_codes'), 'a refused shape spends nothing');
        self::assertSame(200, $this->exchange($code)->getStatusCode());
    }

    public function testAProviderErrorOnANativeFlowGoesBackToTheLoopback(): void
    {
        $this->primeNativeFlow();
        $_GET = ['error' => 'access_denied', 'state' => 'state-1'];

        $query = $this->loopbackQuery($this->runCallback());
        self::assertSame('denied', $query['error']);
        self::assertSame('app-state-12345678', $query['state']);
        self::assertArrayNotHasKey('code', $query);
    }


    public function testExpiredCodesAreSweptOnTheNextExchangeAndByTheCleanupJob(): void
    {
        $this->linkIdentity();
        $this->primeNativeFlow();
        $stale = $this->loopbackQuery($this->runCallback())['code'];
        $this->now += SsoAuthHandler::NATIVE_CODE_TTL + 1;
        self::assertSame(1, (int) $this->col('SELECT COUNT(*) FROM native_sign_in_codes'), 'the expired row waits for a sweep');

        // Another exchange sweeps it, whatever that exchange's own outcome.
        self::assertSame(401, $this->exchange(str_repeat('x', 43))->getStatusCode());
        self::assertSame(0, (int) $this->col('SELECT COUNT(*) FROM native_sign_in_codes'));
        self::assertSame(401, $this->exchange($stale)->getStatusCode(), 'and it cannot be used afterwards');

        // The daily job covers an instance nobody exchanges on. It runs on the
        // real clock, so these rows are dated against it.
        $codes = new \Whity\Auth\NativeSignInCodes($this->pdo);
        $codes->issue($this->profileId, 'alice@acme.test', $this->providerId, 0, false, 'challenge-0123456789012345678901234567890123', 60);
        $codes->issue($this->profileId, 'alice@acme.test', $this->providerId, 0, false, 'challenge-0123456789012345678901234567890123', -1);
        ob_start();
        (new \Whity\Commands\RevokedTokensCleanupCommand($this->pdo))->execute();
        $output = (string) ob_get_clean();
        self::assertStringContainsString('Cleaned 1 expired native sign-in codes', $output);
        self::assertSame(1, (int) $this->col('SELECT COUNT(*) FROM native_sign_in_codes'), 'the live one stays');
    }
}

/**
 * Stub OIDC HTTP client: canned discovery (getJson) + token response (postForm).
 */
final class StubOidcHttp implements HttpClient
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

    // ── native sign-in return (WHIT-1406) ────────────────────────────────────

}
