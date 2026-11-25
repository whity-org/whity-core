<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\InvitationAcceptHandler;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Identity\ExternalIdentityRepository;
use Whity\Core\Identity\IdentityProviderRepository;
use Whity\Core\Identity\InvitationService;
use Whity\Core\Identity\PasswordSignIn;
use Whity\Core\Identity\ProfileEmailRepository;
use Whity\Core\Identity\ProfileProvisioner;
use Whity\Core\RateLimit\ClientIp;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Store\DatabaseSharedStore;
use Whity\Core\Tenant\TenantContext;

/**
 * WHIT-1168: a server that signs people in only through its identity provider.
 *
 * `auth.password_sign_in` is on by default (a self-hosted core keeps its own
 * sign-in, ADR 0019). Off, every way in that takes a password refuses, the
 * operator cannot switch it off into a lock-out, and WHITY_PASSWORD_SIGN_IN=force
 * turns it back on from the server.
 */
final class PasswordSignInRealEngineTest extends TestCase
{
    private PDO $pdo;
    private SettingsService $settings;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->settings = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo),
        );
        $this->clearBreakGlass();
    }

    protected function tearDown(): void
    {
        $this->clearBreakGlass();
        TenantContext::reset();
        unset($_GET['token']);
    }

    private function clearBreakGlass(): void
    {
        unset($_ENV[PasswordSignIn::BREAK_GLASS_ENV]);
        putenv(PasswordSignIn::BREAK_GLASS_ENV);
    }

    private function turnOff(): void
    {
        $this->settings->setGlobal(SettingsRegistry::PASSWORD_SIGN_IN, 'false');
    }

    public function testOnByDefaultSoASelfHostedServerKeepsItsOwnSignIn(): void
    {
        self::assertTrue(PasswordSignIn::allowed($this->settings));
        self::assertSame('true', SettingsRegistry::defaultFor(SettingsRegistry::PASSWORD_SIGN_IN));
        self::assertTrue(SettingsRegistry::isGlobalOnly(SettingsRegistry::PASSWORD_SIGN_IN));
    }

    public function testOffRefusesEveryGuardedRoute(): void
    {
        $calls = new \ArrayObject();
        $route = PasswordSignIn::guard(function () use ($calls): Response {
            $calls->append(true);

            return Response::json(['ok' => true]);
        }, $this->settings);

        self::assertSame(200, $route(new Request('POST', '/api/v1/login', [], '{}'))->getStatusCode());
        self::assertCount(1, $calls);

        $this->turnOff();
        $refused = $route(new Request('POST', '/api/v1/login', [], '{}'));

        self::assertSame(403, $refused->getStatusCode());
        self::assertCount(1, $calls, 'the password handler never runs');
        self::assertSame(PasswordSignIn::REFUSED_CODE, json_decode($refused->getBody(), true)['details']['code'] ?? null, $refused->getBody());
    }

    public function testTheBreakGlassTurnsItBackOnWhateverTheSettingSays(): void
    {
        $this->turnOff();
        self::assertFalse(PasswordSignIn::allowed($this->settings));

        $_ENV[PasswordSignIn::BREAK_GLASS_ENV] = 'force';
        self::assertTrue(PasswordSignIn::allowed($this->settings));

        // Only the exact word counts: a stray value never opens it.
        $_ENV[PasswordSignIn::BREAK_GLASS_ENV] = '1';
        self::assertFalse(PasswordSignIn::allowed($this->settings));
    }

    public function testItCannotBeTurnedOffWithoutAProviderForTheWholeServer(): void
    {
        self::assertNotNull(PasswordSignIn::cannotTurnOff($this->pdo, null));
    }

    public function testItCannotBeTurnedOffByAnOperatorWhoNeverSignedInWithTheProvider(): void
    {
        $this->addOperatorProvider();
        $operator = $this->addProfile('operator@whity.test');

        self::assertNotNull(PasswordSignIn::cannotTurnOff($this->pdo, $operator));

        (new ExternalIdentityRepository($this->pdo))->link($operator, 'whity_id', 'https://id.whity.test', 'op-sub', 'operator@whity.test');
        // WHIT-1350: the install's own admin is a platform operator too, still unlinked.
        self::assertStringContainsString('system@whity.local', (string) PasswordSignIn::cannotTurnOff($this->pdo, $operator));

        $installAdmin = (int) $this->scalar("SELECT profile_id FROM profile_emails WHERE email = 'system@whity.local'");
        (new ExternalIdentityRepository($this->pdo))->link($installAdmin, 'whity_id', 'https://id.whity.test', 'system-sub', null);
        self::assertNull(PasswordSignIn::cannotTurnOff($this->pdo, $operator));
    }

    public function testANewInviteeIsSentToTheProviderInsteadOfChoosingAPassword(): void
    {
        $token = $this->invite('newbie@whity.test');
        $handler = new InvitationAcceptHandler(
            new InvitationService($this->pdo, new ProfileProvisioner($this->pdo)),
            new DatabaseSharedStore($this->pdo),
            new AuditLogger($this->pdo, new NullLogger()),
            $this->settings,
        );
        $this->turnOff();

        $_GET['token'] = $token;
        $preview = json_decode($handler->preview(new Request('GET', '/api/invitations/accept?token=' . urlencode($token), [ClientIp::HEADER => '203.0.113.7'], ''))->getBody(), true);
        self::assertFalse($preview['data']['password_sign_in']);

        $accept = $handler->accept(new Request(
            'POST',
            '/api/invitations/accept',
            ['Content-Type' => 'application/json', ClientIp::HEADER => '203.0.113.7'],
            (string) json_encode(['token' => $token, 'password' => 'a-long-enough-password']),
        ));

        self::assertSame(403, $accept->getStatusCode(), $accept->getBody());
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM profile_emails WHERE email = 'newbie@whity.test'"), 'no account with a password was made');
        self::assertSame('pending', (string) $this->scalar("SELECT status FROM invitations WHERE email = 'newbie@whity.test'"), 'the invitation still works through the provider');
    }

    private function scalar(string $sql): mixed
    {
        $statement = $this->pdo->query($sql);
        self::assertNotFalse($statement, $sql);

        return $statement->fetchColumn();
    }

    private function addOperatorProvider(): void
    {
        (new IdentityProviderRepository($this->pdo))->insert(0, [
            'provider_key' => 'whity_id',
            'display_name' => 'Whity ID',
            'client_id' => 'core',
            'client_secret_encrypted' => null,
            'issuer' => 'https://id.whity.test',
            'discovery_url' => 'https://id.whity.test/.well-known/openid-configuration',
            'scopes' => 'openid email profile',
            'enabled' => true,
        ]);
    }

    private function addProfile(string $email): int
    {
        $this->pdo->exec("INSERT INTO profiles
            (display_name, password_hash, two_factor_enabled, two_factor_secret,
             two_factor_backup_codes_version, token_epoch, created_at, updated_at)
            VALUES ('Amro Saleh', '', false, NULL, 0, 0, NOW(), NOW())");
        $id = (int) $this->pdo->lastInsertId();
        (new ProfileEmailRepository($this->pdo))->insert($id, $email, true, true);

        return $id;
    }

    private function invite(string $email): string
    {
        $this->pdo->exec("INSERT INTO tenants (name, slug, created_at) VALUES ('Example Bakery', 'example-bakery', NOW())");
        $tenant = (int) $this->pdo->lastInsertId();
        $role = (int) $this->scalar('SELECT id FROM roles ORDER BY id ASC LIMIT 1');

        return (new InvitationService($this->pdo, new ProfileProvisioner($this->pdo)))->invite($tenant, $email, $role)['token'];
    }
}
