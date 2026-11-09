<?php

declare(strict_types=1);

namespace Tests\Integration;

use OTPHP\TOTP;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\TwoFactorHandler;
use Whity\Auth\BackupCodesService;
use Whity\Auth\JwtParser;
use Whity\Auth\LoginThrottleService;
use Whity\Auth\TokenValidator;
use Whity\Auth\TotpService;
use Whity\Core\Identity\MembershipRepository;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Store\ArraySharedStore;
use Whity\Core\Tenant\TenantContext;

/**
 * A session alone cannot turn off or take over the second factor: disable and
 * regenerate-codes need a current code, a backup code, or the password on an
 * account that has one. Runs on real PostgreSQL in CI via PHPUNIT_PG_DSN.
 */
final class TwoFactorProofRealEngineTest extends TestCase
{
    private const JWT_SECRET = 'two-factor-proof-test-secret-padded-to-32-bytes';
    private const TOTP_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
    private const PASSWORD = 'correct horse battery staple';
    private const BACKUP_CODE = 'BACKUP-CODE-1';
    private const TENANT = 1;

    private PDO $pdo;
    private TotpService $totp;
    private BackupCodesService $backupCodes;
    private ArraySharedStore $store;
    private int $profileId;

    protected function setUp(): void
    {
        $_COOKIE = [];
        $this->pdo = SchemaFromMigrations::make();
        $this->totp = new TotpService(self::JWT_SECRET);
        $this->backupCodes = new BackupCodesService(new \Whity\Auth\DatabaseQueryWrapper($this->pdo));
        $this->store = new ArraySharedStore();

        $this->pdo->exec(
            "INSERT INTO tenants (id, name, created_at) VALUES (1, 'tenant-a', CURRENT_TIMESTAMP) ON CONFLICT (id) DO NOTHING"
        );
        $this->pdo->exec("INSERT INTO roles (id, name) VALUES (1, 'admin') ON CONFLICT (id) DO NOTHING");

        $stmt = $this->pdo->prepare(
            "INSERT INTO profiles (display_name, password_hash, auth_method, two_factor_enabled,
                two_factor_secret, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES ('Proof', ?, 'local', true, ?, 1, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $stmt->execute([password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]), $this->totp->encryptSecret(self::TOTP_SECRET)]);
        $this->profileId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            "INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES (?, 'proof@example.com', true, true, CURRENT_TIMESTAMP)"
        )->execute([$this->profileId]);

        $this->pdo->prepare(
            'INSERT INTO backup_codes (profile_id, code, version, used) VALUES (?, ?, 1, false)'
        )->execute([$this->profileId, $this->backupCodes->hashCode(self::BACKUP_CODE)]);

        (new MembershipRepository($this->pdo))->insert($this->profileId, self::TENANT, 1);

        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);

        $_COOKIE['access_token'] = (new JwtParser(self::JWT_SECRET))->create([
            'profile_id' => $this->profileId,
            'active_tenant_id' => self::TENANT,
            'email' => 'proof@example.com',
            'role' => 'admin',
            'token_epoch' => 0,
        ], 3600, 'access');
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];
        TenantContext::reset();
    }

    private function handler(int $userThreshold = 10): TwoFactorHandler
    {
        return new TwoFactorHandler(
            $this->pdo,
            $this->totp,
            $this->backupCodes,
            new TokenValidator(new JwtParser(self::JWT_SECRET), $this->pdo),
            null,
            new LoginThrottleService($this->store, $userThreshold, 100, 900, 'two_factor_proof'),
        );
    }

    /** @param array<string, mixed>|null $body */
    private function post(string $path, ?array $body): Request
    {
        return new Request('POST', $path, ['Content-Type' => 'application/json'], $body === null ? '' : (string) json_encode($body));
    }

    /** @param array<string, mixed>|null $body */
    private function disable(?array $body, ?TwoFactorHandler $handler = null): Response
    {
        return ($handler ?? $this->handler())->disable($this->post('/api/auth/2fa/disable', $body));
    }

    /** @param array<string, mixed>|null $body */
    private function regenerate(?array $body): Response
    {
        return $this->handler()->regenerateCodes($this->post('/api/auth/2fa/regenerate-codes', $body));
    }

    private function currentCode(): string
    {
        return TOTP::create(self::TOTP_SECRET)->now();
    }

    private function stillEnabled(): bool
    {
        $stmt = $this->pdo->prepare('SELECT two_factor_secret FROM profiles WHERE id = ?');
        $stmt->execute([$this->profileId]);

        return $stmt->fetchColumn() !== null;
    }

    private function codesVersion(): int
    {
        $stmt = $this->pdo->prepare('SELECT two_factor_backup_codes_version FROM profiles WHERE id = ?');
        $stmt->execute([$this->profileId]);

        return (int) $stmt->fetchColumn();
    }

    private function reason(Response $response): ?string
    {
        return json_decode($response->getBody(), true)['details']['reason'] ?? null;
    }

    public function testDisableRefusesWithoutProofAndChangesNothing(): void
    {
        foreach ([null, [], ['code' => ''], ['code' => ['123456']], ['password' => 12345]] as $body) {
            $response = $this->disable($body);

            self::assertSame(403, $response->getStatusCode());
            self::assertSame('proof_required', $this->reason($response));
        }

        self::assertTrue($this->stillEnabled());
    }

    public function testDisableRefusesAWrongCodeAndAWrongPassword(): void
    {
        foreach ([['code' => '000000'], ['password' => 'not the password']] as $body) {
            $response = $this->disable($body);

            self::assertSame(403, $response->getStatusCode());
            self::assertSame('proof_invalid', $this->reason($response));
        }

        self::assertTrue($this->stillEnabled());
    }

    public function testDisableAcceptsACurrentCode(): void
    {
        self::assertSame(200, $this->disable(['code' => $this->currentCode()])->getStatusCode());
        self::assertFalse($this->stillEnabled());
    }

    public function testDisableAcceptsABackupCode(): void
    {
        self::assertSame(200, $this->disable(['code' => self::BACKUP_CODE])->getStatusCode());
        self::assertFalse($this->stillEnabled());
    }

    public function testDisableAcceptsThePassword(): void
    {
        self::assertSame(200, $this->disable(['password' => self::PASSWORD])->getStatusCode());
        self::assertFalse($this->stillEnabled());
    }

    public function testACodeCannotBeUsedTwice(): void
    {
        $code = $this->currentCode();

        self::assertSame(200, $this->regenerate(['code' => $code])->getStatusCode());

        $replay = $this->disable(['code' => $code]);
        self::assertSame(403, $replay->getStatusCode());
        self::assertSame('proof_invalid', $this->reason($replay));
        self::assertTrue($this->stillEnabled());
    }

    /**
     * Without this, the gate on disable means nothing: a stolen session would
     * mint fresh backup codes here and use one of them to pass it.
     */
    public function testRegenerateRefusesWithoutProofOrWithAWrongCode(): void
    {
        $missing = $this->regenerate([]);
        self::assertSame(403, $missing->getStatusCode());
        self::assertSame('proof_required', $this->reason($missing));

        $wrong = $this->regenerate(['code' => '000000']);
        self::assertSame(403, $wrong->getStatusCode());
        self::assertSame('proof_invalid', $this->reason($wrong));

        self::assertSame(1, $this->codesVersion());
        self::assertStringNotContainsString('backup_codes', $wrong->getBody());
    }

    public function testRegenerateAcceptsThePassword(): void
    {
        $response = $this->regenerate(['password' => self::PASSWORD]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(2, $this->codesVersion());
    }

    public function testWrongProofsAreRateLimitedEvenWithTheRightProofAfterwards(): void
    {
        $handler = $this->handler(3);

        for ($i = 0; $i < 3; $i++) {
            self::assertSame(403, $this->disable(['code' => '000000'], $handler)->getStatusCode());
        }

        $response = $this->disable(['password' => self::PASSWORD], $handler);
        self::assertSame(429, $response->getStatusCode());
        self::assertSame('throttled', $this->reason($response));
        self::assertTrue($this->stillEnabled());
    }

    public function testWrongProofsDoNotCountAgainstSigningIn(): void
    {
        $this->disable(['code' => '000000']);

        self::assertSame(0, $this->store->count('login:fail:user:' . $this->profileId));
        self::assertSame(1, $this->store->count('two_factor_proof:fail:user:' . $this->profileId));
    }

    public function testAnAccountWithoutAPasswordCanOnlyUseACode(): void
    {
        $this->pdo->prepare("UPDATE profiles SET auth_method = 'idp', password_hash = '' WHERE id = ?")
            ->execute([$this->profileId]);

        $status = json_decode($this->handler()->status(new Request('GET', '/api/auth/2fa/status'))->getBody(), true);
        self::assertFalse($status['password_available']);

        self::assertSame(403, $this->disable(['password' => self::PASSWORD])->getStatusCode());
        self::assertTrue($this->stillEnabled());

        self::assertSame(200, $this->disable(['code' => $this->currentCode()])->getStatusCode());
    }

    public function testStatusOffersThePasswordOnlyWhenThereIsOne(): void
    {
        $status = json_decode($this->handler()->status(new Request('GET', '/api/auth/2fa/status'))->getBody(), true);

        self::assertTrue($status['password_available']);
    }

    public function testDisableRefusesWhenTwoStepIsNotOn(): void
    {
        $this->pdo->prepare('UPDATE profiles SET two_factor_enabled = false, two_factor_secret = NULL WHERE id = ?')
            ->execute([$this->profileId]);

        self::assertSame(400, $this->disable(['password' => self::PASSWORD])->getStatusCode());
    }
}
