<?php

declare(strict_types=1);

namespace Tests\Integration;

use OTPHP\TOTP;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\GatedApprovalHttp;
use Whity\Auth\TotpService;
use Whity\Core\Document\Signing\SigningAttemptLimiter;
use Whity\Core\Document\Signing\SigningChallengeRepository;
use Whity\Core\Security\GatedApproval;
use Whity\Core\Security\IdentityCheck;
use Whity\Core\Security\IdentityCheckThrottledException;
use Whity\Core\Store\ArraySharedStore;
use Whity\Core\Response;

/**
 * #2223: "confirm it's you" before a gated approval limits authenticator-code
 * tries per person, like document signing does. Without it, someone holding a
 * stolen session could guess codes against a password-reset or two-step-reset
 * approval as fast as the general API limit allowed.
 *
 * Real engine (SQLite by default, PostgreSQL with PHPUNIT_PG_DSN).
 */
final class IdentityCheckAttemptLimitRealEngineTest extends TestCase
{
    private const TOTP_KEY = 'identity-check-limit-totp-key-32-chars-x';
    private const PROFILE  = 94001;
    private const TENANT   = 1;
    private const LIMIT    = 3;

    private PDO $pdo;
    private TotpService $totp;
    /** @var non-empty-string */
    private string $plainSecret;
    private SigningAttemptLimiter $limiter;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->totp = new TotpService(self::TOTP_KEY);
        $plain = $this->totp->generateSecret();
        if ($plain === '') {
            self::fail('no secret generated');
        }
        $this->plainSecret = $plain;
        $this->limiter = new SigningAttemptLimiter(new ArraySharedStore(), self::LIMIT);

        $now = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? 'NOW()' : "datetime('now')";
        $this->pdo->prepare(
            "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_secret,
                two_factor_backup_codes_version, token_epoch, status, created_at, updated_at)
             VALUES (?, 'approver', 'x', true, ?, 0, 0, 'active', {$now}, {$now})"
        )->execute([self::PROFILE, $this->totp->encryptSecret($this->plainSecret)]);
    }

    public function testWrongCodesUseUpTheBudgetAndThenNothingIsChecked(): void
    {
        $check = $this->check();
        for ($i = 0; $i < self::LIMIT; $i++) {
            self::assertNull($check->verify(self::TENANT, self::PROFILE, 'password-reset:1', '000000'));
        }

        // Even the right code is refused now, without being checked (the step
        // floor is not advanced, so it still works once the window passes).
        try {
            $check->verify(self::TENANT, self::PROFILE, 'password-reset:1', $this->code());
            self::fail('the try past the limit must be refused');
        } catch (IdentityCheckThrottledException) {
            self::addToAssertionCount(1);
        }
        $floor = $this->pdo->query('SELECT two_factor_last_used_step FROM profiles WHERE id = ' . self::PROFILE);
        self::assertNotFalse($floor);
        self::assertNull($floor->fetchColumn());
    }

    public function testARightCodeGivesItsSlotBack(): void
    {
        $check = $this->check();
        for ($i = 0; $i < self::LIMIT - 1; $i++) {
            self::assertNull($check->verify(self::TENANT, self::PROFILE, 'password-reset:1', '000000'));
        }
        self::assertSame(IdentityCheck::TOTP, $check->verify(self::TENANT, self::PROFILE, 'password-reset:1', $this->code()));

        // The success did not use the last slot: one more wrong try is still checked.
        self::assertNull($check->verify(self::TENANT, self::PROFILE, 'password-reset:1', '000000'));
    }

    public function testTheApproveRouteAnswers429(): void
    {
        $gate = new GatedApproval($this->check());
        for ($i = 0; $i < self::LIMIT; $i++) {
            $refused = GatedApprovalHttp::confirm($gate, self::TENANT, self::PROFILE, GatedApproval::PASSWORD_RESET, '1', ['code' => '000000']);
            self::assertInstanceOf(Response::class, $refused);
            self::assertSame(403, $refused->getStatusCode());
        }

        $throttled = GatedApprovalHttp::confirm($gate, self::TENANT, self::PROFILE, GatedApproval::PASSWORD_RESET, '1', ['code' => $this->code()]);

        self::assertInstanceOf(Response::class, $throttled);
        self::assertSame(429, $throttled->getStatusCode());
        $body = json_decode($throttled->getBody(), true);
        self::assertIsArray($body);
        self::assertSame(GatedApprovalHttp::REASON_THROTTLED, $body['details']['reason'] ?? null);
        self::assertSame('900', $throttled->getHeaders()['retry-after'] ?? null);
    }

    private function check(): IdentityCheck
    {
        return new IdentityCheck($this->pdo, new SigningChallengeRepository($this->pdo), $this->totp, null, $this->limiter);
    }

    private function code(): string
    {
        return TOTP::create($this->plainSecret)->now();
    }
}
