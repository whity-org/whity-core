<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Mail;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Identity\ProfileEmailRepository;
use Whity\Core\Mail\AccountSecurityMailer;
use Whity\Core\Mail\BrandedEmailSender;
use Whity\Core\Mail\DeviceLabel;
use Whity\Core\Mail\EmailLayout;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;

/**
 * EM.1's two security emails: a new sign-in goes out only for a browser the
 * account hasn't used before (and never for the very first sign-in), two-step
 * off always goes, neither can be turned off, and Arabic follows the locale.
 */
final class AccountSecurityMailerTest extends TestCase
{
    private const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
    private const SAFARI = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

    private PDO $pdo;
    private CapturingMailer $mailer;
    private SettingsService $settings;
    private AccountSecurityMailer $subject;
    private int $profileId;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        $this->mailer = new CapturingMailer();
        $this->subject = new AccountSecurityMailer(
            new BrandedEmailSender($this->mailer, new EmailLayout(), $this->settings),
            new ProfileEmailRepository($this->pdo),
            $this->pdo,
            'https://app.example.test/settings',
        );

        $this->pdo->exec(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES ('Amro Saleh', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $this->profileId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec(
            "INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES ({$this->profileId}, 'amro@example.test', true, true, CURRENT_TIMESTAMP)"
        );
    }

    private function session(string $ua, int $n): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO sessions (profile_id, tenant_id, refresh_jti, access_jti, user_agent, ip_address, expires_at)
             VALUES (:p, 0, :r, :a, :ua, '203.0.113.9', '2099-01-01 00:00:00')"
        );
        $stmt->execute([':p' => $this->profileId, ':r' => 'r' . $n, ':a' => 'a' . $n, ':ua' => $ua]);
    }

    public function testTheFirstSignInEverSendsNothing(): void
    {
        $this->session(self::CHROME, 1);

        self::assertFalse($this->subject->signedIn($this->profileId, 0, self::CHROME, '203.0.113.9'));
        self::assertSame([], $this->mailer->sent);
    }

    public function testTheSameBrowserAgainSendsNothing(): void
    {
        $this->session(self::CHROME, 1);
        $this->session(self::CHROME, 2);

        self::assertFalse($this->subject->signedIn($this->profileId, 0, self::CHROME, '203.0.113.9'));
        self::assertSame([], $this->mailer->sent);
    }

    public function testANewBrowserSendsTheNewSignInEmail(): void
    {
        $this->session(self::CHROME, 1);
        $this->session(self::SAFARI, 2);

        self::assertTrue($this->subject->signedIn($this->profileId, 0, self::SAFARI, '203.0.113.9'));
        [$to, $subject, $text, $html] = $this->mailer->sent[0];
        self::assertSame('amro@example.test', $to);
        self::assertStringContainsString('New sign-in to', $subject);
        self::assertStringContainsString('Safari on iPhone', (string) $html);
        self::assertStringContainsString("It wasn&#039;t me", (string) $html);
        self::assertStringContainsString('#b42318', (string) $html);
        self::assertStringContainsString("Security emails can't be turned off.", $text);
        self::assertStringNotContainsString('password_hash', $text);
    }

    public function testTwoStepOffAlwaysSendsEvenWithEveryMailToggleOff(): void
    {
        foreach ([SettingsRegistry::MAIL_EVENT_PASSWORD_RESET, SettingsRegistry::MAIL_EVENT_INVITATION, SettingsRegistry::MAIL_EVENT_WELCOME] as $key) {
            $this->settings->setGlobal($key, 'false');
        }

        self::assertTrue($this->subject->twoStepTurnedOff($this->profileId, 0));
        [, $subject, $text] = $this->mailer->sent[0];
        self::assertSame('Two-step sign-in was turned off', $subject);
        self::assertStringContainsString('Turn it back on: https://app.example.test/settings', $text);
    }

    public function testArabicFollowsTheLocale(): void
    {
        $this->settings->setGlobal(SettingsRegistry::LOCALE, 'ar');

        $this->subject->twoStepTurnedOff($this->profileId, 0);
        [, $subject, , $html] = $this->mailer->sent[0];
        self::assertSame('أُوقف الدخول بخطوتين', $subject);
        self::assertStringContainsString('dir="rtl"', (string) $html);
    }

    public function testDeviceLabelsInPlainWords(): void
    {
        self::assertSame('Chrome on Windows', DeviceLabel::from(self::CHROME));
        self::assertSame('Safari على iPhone', DeviceLabel::from(self::SAFARI, 'ar'));
        self::assertSame('An unknown device', DeviceLabel::from(null));
    }
}
