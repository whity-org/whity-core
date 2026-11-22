<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\OperatorLinkHandler;
use Whity\Auth\AuthHandler;
use Whity\Auth\JwtParser;
use Whity\Cli\Commands\AuthCommand;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Identity\ExternalIdentityRepository;
use Whity\Core\Identity\IdentityProviderRepository;
use Whity\Core\Identity\OperatorSignIn;
use Whity\Core\Identity\PasswordSignIn;
use Whity\Core\Identity\ProfileEmailRepository;
use Whity\Core\Request;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Store\DatabaseSharedStore;

/**
 * WHIT-1350: on 2026-11-22 the platform operator could not sign in to the
 * system workspace. Password sign-in was off and their account had no Whity ID
 * linked, so there was no way in short of the shell break-glass.
 *
 * Turning password sign-in off now refuses while any operator has no link and
 * names them; status lists them; and `whity-cli auth operator-link <email>`
 * prints a one-time, 15-minute, audited sign-in link for one of them.
 */
final class OperatorLockoutRealEngineTest extends TestCase
{
    private const FRONTEND = 'https://app.whity.test';

    private PDO $pdo;
    private JwtParser $jwt;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->jwt = new JwtParser('operator_link_test_secret_min_32_chars_xx');
        unset($_ENV[PasswordSignIn::BREAK_GLASS_ENV]);
        putenv(PasswordSignIn::BREAK_GLASS_ENV);
        $this->addOperatorProvider();
        // Start from a server whose operators are the ones each test adds: the
        // install's own admin is an operator too (covered in its own test).
        $this->pdo->exec("DELETE FROM memberships WHERE tenant_id = 0 AND profile_id IN
            (SELECT id FROM profiles WHERE COALESCE(auth_method, '') <> 'service')");
    }

    public function testTheInstallAdminCountsAndTheCliServicePrincipalDoesNot(): void
    {
        $fresh = SchemaFromMigrations::make(true);

        $unlinked = OperatorSignIn::unlinked($fresh);

        self::assertSame(['system@whity.local'], array_column($unlinked, 'email'), 'the seeded install admin, not the CLI principal');
    }

    // ── refusing the lock-out ────────────────────────────────────────────────

    public function testTurningOffIsRefusedWhileAnOperatorHasNoLinkAndNamesThem(): void
    {
        $linked = $this->addOperator('amro@whity.test');
        $this->link($linked, 'sub-amro');
        $this->addOperator('ops@whity.test');

        $problem = PasswordSignIn::cannotTurnOff($this->pdo, $linked);

        self::assertNotNull($problem);
        self::assertStringContainsString('ops@whity.test', $problem);
        self::assertStringNotContainsString('amro@whity.test', $problem);
    }

    public function testWorkspaceMembersOutsideTheSystemWorkspaceAreNotOperators(): void
    {
        $operator = $this->addOperator('amro@whity.test');
        $this->link($operator, 'sub-amro');
        $this->addProfile('baker@example-bakery.test', $this->addTenant());

        self::assertNull(PasswordSignIn::cannotTurnOff($this->pdo, $operator));
        self::assertSame([], OperatorSignIn::unlinked($this->pdo));
    }

    public function testCliOffRefusesAndForceTurnsItOffWithAWarning(): void
    {
        $this->addOperator('ops@whity.test');

        [$code, $out] = $this->cli(['password-sign-in', 'off']);
        self::assertSame(1, $code);
        self::assertStringContainsString('Not changed', $out);
        self::assertStringContainsString('ops@whity.test', $out);
        self::assertStringContainsString('operator-link', $out);
        self::assertNotSame('false', $this->setting());

        [$code, $out] = $this->cli(['password-sign-in', 'off', '--force']);
        self::assertSame(0, $code);
        self::assertSame('false', $this->setting());
        self::assertStringContainsString('WARNING', $out);
        self::assertStringContainsString('ops@whity.test', $out);
        self::assertSame(1, $this->audits('auth.password_sign_in.forced_off'));
    }

    public function testForceDoesNotSkipTheProviderCheck(): void
    {
        $this->pdo->exec('DELETE FROM identity_providers');

        [$code] = $this->cli(['password-sign-in', 'off', '--force']);

        self::assertSame(1, $code);
        self::assertNotSame('false', $this->setting());
    }

    public function testStatusListsOperatorsWithNoLink(): void
    {
        $linked = $this->addOperator('amro@whity.test');
        $this->link($linked, 'sub-amro');
        $this->addOperator('ops@whity.test');

        [$code, $out] = $this->cli(['password-sign-in', 'status']);

        self::assertSame(0, $code);
        self::assertStringContainsString('no Whity ID linked', $out);
        self::assertStringContainsString('ops@whity.test', $out);
        self::assertStringNotContainsString('amro@whity.test', $out);

        $this->link($this->profileOf('ops@whity.test'), 'sub-system');
        [, $out] = $this->cli(['password-sign-in', 'status']);
        self::assertStringContainsString('Every platform operator has Whity ID linked', $out);
    }

    // ── the one-time sign-in link ────────────────────────────────────────────

    public function testTheLinkIsPrintedOnlyForAnOperatorWithNoLinkAndIsAudited(): void
    {
        $this->addProfile('baker@example-bakery.test', $this->addTenant());
        [$code, $out] = $this->cli(['operator-link', 'baker@example-bakery.test']);
        self::assertSame(1, $code);
        self::assertStringNotContainsString('#token=', $out);
        self::assertSame(1, $this->audits(OperatorSignIn::AUDIT_REFUSED));

        $linked = $this->addOperator('amro@whity.test');
        $this->link($linked, 'sub-amro');
        [$code, $out] = $this->cli(['operator-link', 'amro@whity.test']);
        self::assertSame(1, $code);
        self::assertStringContainsString('already has Whity ID', $out);

        $operator = $this->addOperator('ops@whity.test');
        [$code, $out] = $this->cli(['operator-link', 'OPS@whity.test']);
        self::assertSame(0, $code);
        self::assertStringContainsString(self::FRONTEND . '/operator-sign-in#token=', $out);
        self::assertStringContainsString('15 minutes', $out);
        self::assertSame(1, $this->audits(OperatorSignIn::AUDIT_ISSUED));
        self::assertSame((string) $operator, (string) $this->scalar("SELECT target_id FROM audit_log WHERE action = '" . OperatorSignIn::AUDIT_ISSUED . "'"));
    }

    public function testTheLinkSignsTheOperatorInOnceAndIsAudited(): void
    {
        $operator = $this->addOperator('ops@whity.test');
        $token = $this->tokenFromCli('ops@whity.test');

        $first = $this->open($token);
        self::assertSame(200, $first->getStatusCode(), $first->getBody());
        $body = json_decode($first->getBody(), true);
        self::assertSame(OperatorLinkHandler::NEXT, $body['next']);
        self::assertSame($operator, (int) $body['user']['id']);
        self::assertSame(0, (int) $body['user']['tenant_id'], 'a session in the system workspace');
        self::assertSame(1, $this->audits(OperatorSignIn::AUDIT_USED));

        $second = $this->open($token);
        self::assertSame(410, $second->getStatusCode());
        self::assertSame('operator_link_used', json_decode($second->getBody(), true)['details']['code']);
        self::assertSame(1, $this->audits(OperatorSignIn::AUDIT_USED));
        self::assertSame(1, $this->audits(OperatorSignIn::AUDIT_REFUSED));
    }

    public function testTheLinkExpiresAfterFifteenMinutes(): void
    {
        $operator = $this->addOperator('ops@whity.test');
        $token = OperatorSignIn::issueLink($this->jwt, $operator);
        $store = new DatabaseSharedStore($this->pdo);

        self::assertSame(['refused' => 'invalid'], OperatorSignIn::consumeLink($this->pdo, $this->jwt, $store, $token, time() + OperatorSignIn::LINK_TTL + 1));
        self::assertSame(['profile_id' => $operator], OperatorSignIn::consumeLink($this->pdo, $this->jwt, $store, $token, time() + OperatorSignIn::LINK_TTL - 5));
    }

    public function testTheLinkWorksForOperatorsOnly(): void
    {
        $baker = $this->addProfile('baker@example-bakery.test', $this->addTenant());
        // Even a token naming someone else (as if signed for them) is refused.
        $response = $this->open(OperatorSignIn::issueLink($this->jwt, $baker));
        self::assertSame(410, $response->getStatusCode());
        self::assertSame('operator_link_not_operator', json_decode($response->getBody(), true)['details']['code']);

        // An operator who has since linked Whity ID signs in with it instead.
        $operator = $this->addOperator('ops@whity.test');
        $token = OperatorSignIn::issueLink($this->jwt, $operator);
        $this->link($operator, 'sub-system');
        self::assertSame(410, $this->open($token)->getStatusCode());

        // An operator removed from the system workspace no longer can.
        $removed = $this->addOperator('former@whity.test');
        $token = OperatorSignIn::issueLink($this->jwt, $removed);
        $this->pdo->exec("UPDATE memberships SET status = 'suspended' WHERE profile_id = {$removed}");
        self::assertSame(410, $this->open($token)->getStatusCode());
    }

    public function testOtherTokensCannotStandInForTheLink(): void
    {
        $operator = $this->addOperator('ops@whity.test');
        $access = $this->jwt->create(['profile_id' => $operator, 'active_tenant_id' => 0], 900, 'access');
        $foreign = (new JwtParser('another_servers_secret_min_32_chars_xxxx'))->create(['profile_id' => $operator], 900, OperatorSignIn::TOKEN_TYPE);

        self::assertSame(410, $this->open($access)->getStatusCode());
        self::assertSame(410, $this->open($foreign)->getStatusCode());
        self::assertSame(410, $this->open('')->getStatusCode());
        self::assertSame(0, $this->audits(OperatorSignIn::AUDIT_USED));
    }

    public function testTheLinkWorksWhilePasswordSignInIsOff(): void
    {
        $this->addOperator('ops@whity.test');
        $this->cli(['password-sign-in', 'off', '--force']);
        self::assertFalse(PasswordSignIn::allowed(new \Whity\Core\Settings\SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new \Whity\Core\Settings\TenantSettingsRepository($this->pdo),
        )));

        self::assertSame(200, $this->open($this->tokenFromCli('ops@whity.test'))->getStatusCode());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @param list<string> $argv
     * @return array{int, string}
     */
    private function cli(array $argv): array
    {
        ob_start();
        try {
            $code = (new AuthCommand())->run($argv, $this->pdo, $this->jwt, self::FRONTEND);
        } finally {
            $out = (string) ob_get_clean();
        }

        return [$code, $out];
    }

    private function tokenFromCli(string $email): string
    {
        [$code, $out] = $this->cli(['operator-link', $email]);
        self::assertSame(0, $code, $out);
        if (preg_match('/#token=(\S+)/', $out, $m) !== 1) {
            self::fail('no link printed: ' . $out);
        }

        return $m[1];
    }

    private function open(string $token): \Whity\Sdk\Http\Response
    {
        $handler = new OperatorLinkHandler(
            $this->pdo,
            $this->jwt,
            new DatabaseSharedStore($this->pdo),
            new AuthHandler($this->pdo, $this->jwt, null, null, null, new NullLogger(), new AuditLogger($this->pdo, new NullLogger())),
            new AuditLogger($this->pdo, new NullLogger()),
        );

        return $handler->open(new Request('POST', '/api/auth/operator-link', ['Content-Type' => 'application/json'], (string) json_encode(['token' => $token])));
    }

    private function setting(): ?string
    {
        return (new GlobalSettingsRepository($this->pdo))->get(PasswordSignIn::SETTING);
    }

    private function audits(string $action): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM audit_log WHERE action = ' . $this->pdo->quote($action));
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

    private function addTenant(): int
    {
        $this->pdo->exec("INSERT INTO tenants (name, slug, created_at) VALUES ('Example Bakery', 'example-bakery', NOW())");

        return (int) $this->pdo->lastInsertId();
    }

    private function addOperator(string $email): int
    {
        return $this->addProfile($email, 0);
    }

    private function addProfile(string $email, int $tenantId): int
    {
        $this->pdo->exec("INSERT INTO profiles
            (display_name, password_hash, two_factor_enabled, two_factor_secret,
             two_factor_backup_codes_version, token_epoch, created_at, updated_at)
            VALUES ('Amro Saleh', '', false, NULL, 0, 0, NOW(), NOW())");
        $id = (int) $this->pdo->lastInsertId();
        (new ProfileEmailRepository($this->pdo))->insert($id, $email, true, true);
        $this->pdo->prepare("INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, status, created_at)
            VALUES (?, ?, (SELECT id FROM roles ORDER BY id ASC LIMIT 1), NULL, 'active', NOW())")
            ->execute([$id, $tenantId]);

        return $id;
    }

    private function profileOf(string $email): int
    {
        return (int) $this->scalar('SELECT profile_id FROM profile_emails WHERE email = ' . $this->pdo->quote($email));
    }

    private function link(int $profileId, string $subject): void
    {
        (new ExternalIdentityRepository($this->pdo))->link($profileId, 'whity_id', 'https://id.whity.test', $subject, null);
    }
}
