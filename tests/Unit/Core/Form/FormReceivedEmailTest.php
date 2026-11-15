<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Form;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Tests\Unit\Core\Mail\CapturingMailer;
use Whity\Core\Form\FormReceivedEmail;
use Whity\Core\Form\FormSubmissionRepository;
use Whity\Core\Identity\ProfileEmailRepository;
use Whity\Core\Mail\BrandedEmailSender;
use Whity\Core\Mail\EmailGate;
use Whity\Core\Mail\EmailLayout;
use Whity\Core\Notification\NotificationPreferenceRepository;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;

/**
 * E2 `form-received`: a signed-in person who sends a form gets "We received
 * it" from the workspace; a public submission (no submitter) gets nothing.
 */
final class FormReceivedEmailTest extends TestCase
{
    private const TENANT = 1;

    private PDO $pdo;
    private CapturingMailer $mailer;
    private FormReceivedEmail $email;
    private int $profileId;
    private int $formId;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example University', 'example-university')");
        $this->pdo->exec(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES ('Ahmad Khalil', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $this->profileId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec(
            "INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES ({$this->profileId}, 'ahmad@example.test', true, true, CURRENT_TIMESTAMP)"
        );
        $this->pdo->exec(
            "INSERT INTO forms (tenant_id, form_key, name, status, version, created_at, updated_at)
             VALUES (1, 'ethics', '{\"en\":\"ethics application\",\"ar\":\"طلب الموافقة الأخلاقية\"}', 'published', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $this->formId = (int) $this->pdo->lastInsertId();

        $settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        $settings->setGlobal('site_name', 'Example University');
        $this->mailer = new CapturingMailer();
        $sender = new BrandedEmailSender($this->mailer, new EmailLayout(), $settings);
        $sender->useGate(new EmailGate($settings, $this->pdo));
        $this->email = new FormReceivedEmail($this->pdo, new ProfileEmailRepository($this->pdo), $sender, $settings, 'https://app.example.test');
    }

    public function testTheSubmitterIsToldItArrivedWithItsReference(): void
    {
        $id = (new FormSubmissionRepository($this->pdo))->create(self::TENANT, $this->formId, 1, $this->profileId, null, [], 'REC-EU 14/3/2026-27');

        self::assertTrue($this->email->send(self::TENANT, $id, $this->profileId, true));

        [$to, $subject, $text, $html] = $this->mailer->sent[0];
        self::assertSame('ahmad@example.test', $to);
        self::assertSame('We received your ethics application', $subject);
        self::assertStringContainsString('Hello Ahmad Khalil, thank you. Your ethics application is with Example University.', $text);
        self::assertStringContainsString('Reference: REC-EU 14/3/2026-27', $text);
        self::assertStringContainsString('Next: It goes for review', $text);
        self::assertStringContainsString('See what you sent: https://app.example.test/admin/x/my-form-submissions', $text);
        self::assertStringContainsString('font-weight:600', (string) $html);
    }

    public function testAPublicSubmissionAndAnotherPersonsGetNothing(): void
    {
        $public = (new FormSubmissionRepository($this->pdo))->create(self::TENANT, $this->formId, 1, null, null, []);
        self::assertFalse($this->email->send(self::TENANT, $public, null, false));

        $mine = (new FormSubmissionRepository($this->pdo))->create(self::TENANT, $this->formId, 1, $this->profileId, null, []);
        self::assertFalse($this->email->send(self::TENANT, $mine, $this->profileId + 1, false), 'only the submitter');
        self::assertSame([], $this->mailer->sent);
    }

    public function testThePersonCanTurnItOff(): void
    {
        (new NotificationPreferenceRepository($this->pdo))->set(self::TENANT, $this->profileId, FormReceivedEmail::TYPE, 'email', false);
        $id = (new FormSubmissionRepository($this->pdo))->create(self::TENANT, $this->formId, 1, $this->profileId, null, []);

        self::assertFalse($this->email->send(self::TENANT, $id, $this->profileId, false));
        self::assertSame([], $this->mailer->sent);
    }
}
