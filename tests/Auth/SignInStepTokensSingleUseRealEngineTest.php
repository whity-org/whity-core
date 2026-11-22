<?php

declare(strict_types=1);

namespace Tests\Auth;

use OTPHP\TOTP;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\AuthHandler;
use Whity\Auth\JwtParser;
use Whity\Auth\TokenValidator;
use Whity\Auth\TotpService;
use Whity\Core\Request;
use Whity\Core\Response;

/**
 * #2267: the two short-lived sign-in step tokens (the 2FA `temp` token and the
 * `tenant_select` token) each mint at most one session, and die when the
 * profile's epoch moves (a password change) during their 5-minute window.
 *
 * In token mode nothing clears a cookie, so before this a captured token could
 * be replayed for its whole lifetime: a selection token minted one session per
 * workspace, a temp token one per fresh TOTP code.
 *
 * Real engine (SQLite by default, PostgreSQL with PHPUNIT_PG_DSN).
 */
final class SignInStepTokensSingleUseRealEngineTest extends TestCase
{
    private const SECRET   = 'sign-in-step-tokens-single-use-secret-32b';
    private const TOTP_KEY = 'sign-in-step-tokens-totp-test-key-32-chars';
    private const PROFILE  = 92001;
    private const EMAIL    = 'step-tokens@example.test';

    private PDO $pdo;
    private JwtParser $jwt;
    private AuthHandler $handler;
    /** @var non-empty-string */
    private string $plainSecret;

    protected function setUp(): void
    {
        $_COOKIE = [];
        $this->pdo = SchemaFromMigrations::make();
        $this->jwt = new JwtParser(self::SECRET);
        $totp = new TotpService(self::TOTP_KEY);
        $plain = $totp->generateSecret();
        if ($plain === '') {
            self::fail('no secret generated');
        }
        $this->plainSecret = $plain;
        $this->handler = new AuthHandler($this->pdo, $this->jwt, new TokenValidator($this->jwt, $this->pdo), null, $totp);

        $now = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? 'NOW()' : "datetime('now')";
        $this->pdo->exec("INSERT INTO tenants (id, name, created_at) VALUES (1, 'tenant-a', {$now}), (2, 'tenant-b', {$now}) ON CONFLICT (id) DO NOTHING");
        $this->pdo->prepare(
            "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_secret,
                two_factor_backup_codes_version, token_epoch, status, created_at, updated_at)
             VALUES (?, 'steps', 'x', true, ?, 0, 0, 'active', {$now}, {$now})"
        )->execute([self::PROFILE, $totp->encryptSecret($this->plainSecret)]);
        $this->pdo->prepare(
            "INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES (?, ?, true, true, {$now})"
        )->execute([self::PROFILE, self::EMAIL]);
        $roleStmt = $this->pdo->query("SELECT id FROM roles WHERE name = 'user'");
        self::assertNotFalse($roleStmt);
        $roleId = (int) $roleStmt->fetchColumn();
        foreach ([1, 2] as $tenant) {
            $this->pdo->prepare(
                "INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at)
                 VALUES (?, ?, ?, 'active', {$now})"
            )->execute([self::PROFILE, $tenant, $roleId]);
        }
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];
    }

    public function testATempTokenFinishesOneSignInOnly(): void
    {
        $temp = $this->tempToken();
        $totp = TOTP::create($this->plainSecret);
        $step = intdiv(time(), $totp->getPeriod());

        $first = $this->twoStep($temp, $totp->at(max(0, $step * $totp->getPeriod())));
        self::assertSame(200, $first->getStatusCode());

        // A fresh, unused code from the next step: only the token is reused.
        $second = $this->twoStep($temp, $totp->at(max(0, ($step + 1) * $totp->getPeriod())));
        self::assertSame(401, $second->getStatusCode());
    }

    public function testAWrongCodeDoesNotSpendTheTempToken(): void
    {
        $temp = $this->tempToken();

        self::assertSame(401, $this->twoStep($temp, '000000')->getStatusCode());
        self::assertSame(200, $this->twoStep($temp, TOTP::create($this->plainSecret)->now())->getStatusCode());
    }

    public function testAPasswordChangeDuringTheWindowVoidsTheTempToken(): void
    {
        $temp = $this->tempToken();
        $this->bumpEpoch();

        self::assertSame(401, $this->twoStep($temp, TOTP::create($this->plainSecret)->now())->getStatusCode());
    }

    public function testASelectionTokenPicksOneWorkspaceOnly(): void
    {
        $selection = $this->selectionToken();

        self::assertSame(200, $this->select($selection, 1)->getStatusCode());
        self::assertSame(401, $this->select($selection, 2)->getStatusCode());
        self::assertSame(401, $this->select($selection, 1)->getStatusCode());
    }

    public function testAPasswordChangeDuringTheWindowVoidsTheSelectionToken(): void
    {
        $selection = $this->selectionToken();
        $this->bumpEpoch();

        self::assertSame(401, $this->select($selection, 1)->getStatusCode());
    }

    /** A temp token as the password step mints it, for a two-workspace person. */
    private function tempToken(): string
    {
        return $this->jwt->create(['profile_id' => self::PROFILE, 'email' => self::EMAIL, 'token_epoch' => 0], 300, 'temp');
    }

    /** The selection token the two-step sign-in hands back for two workspaces. */
    private function selectionToken(): string
    {
        $response = $this->twoStep($this->tempToken(), TOTP::create($this->plainSecret)->now());
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['requires_tenant_selection'] ?? false);
        self::assertIsString($body['selection_token'] ?? null);

        return $body['selection_token'];
    }

    private function twoStep(string $temp, string $code): Response
    {
        return $this->handler->handle2fa(new Request(
            'POST',
            '/api/v1/login/2fa',
            ['X-Auth-Mode' => 'token'],
            (string) json_encode(['temp_token' => $temp, 'code' => $code])
        ));
    }

    private function select(string $selection, int $tenantId): Response
    {
        return $this->handler->handleSelectTenant(new Request(
            'POST',
            '/api/v1/auth/select-tenant',
            ['X-Auth-Mode' => 'token'],
            (string) json_encode(['selection_token' => $selection, 'tenant_id' => $tenantId])
        ));
    }

    private function bumpEpoch(): void
    {
        $this->pdo->prepare('UPDATE profiles SET token_epoch = token_epoch + 1 WHERE id = ?')->execute([self::PROFILE]);
    }
}
