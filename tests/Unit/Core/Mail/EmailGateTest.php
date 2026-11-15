<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Mail;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Identity\ProfileEmailRepository;
use Whity\Core\Mail\BrandedEmailSender;
use Whity\Core\Mail\EmailGate;
use Whity\Core\Mail\EmailLayout;
use Whity\Core\Notification\CoreTransports;
use Whity\Core\Notification\NotificationDispatcher;
use Whity\Core\Notification\NotificationEmailTransport;
use Whity\Core\Notification\NotificationPreferenceRepository;
use Whity\Core\Notification\NotificationRepository;
use Whity\Core\Queue\JobRepository;
use Whity\Core\Queue\QueueService;
use Whity\Core\Notification\TenantNotificationSettingsRepository;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Sdk\Notification\NotificationMessage;

/**
 * E2: one answer to "may this email go?", and the notification `email`
 * channel delivered through core mail instead of a log line.
 */
final class EmailGateTest extends TestCase
{
    private const TENANT = 1;

    private PDO $pdo;
    private SettingsService $settings;
    private EmailGate $gate;
    private int $profileId;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery')");
        $this->pdo->exec(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES ('Amro Saleh', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $this->profileId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec(
            "INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES ({$this->profileId}, 'amro@example.test', true, true, CURRENT_TIMESTAMP)"
        );
        $this->settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        $this->gate = new EmailGate($this->settings, $this->pdo);
    }

    public function testEverythingIsOnUntilSomeoneTurnsItOff(): void
    {
        self::assertTrue($this->gate->allows(self::TENANT, $this->profileId, 'documents.routing.awaiting'));
        self::assertTrue($this->gate->instanceEvent(SettingsRegistry::MAIL_EVENT_PASSWORD_RESET));
    }

    public function testTheOperatorTurnsOffAnAccountEmail(): void
    {
        $this->settings->setGlobal(SettingsRegistry::MAIL_EVENT_INVITATION, 'false');

        self::assertFalse($this->gate->instanceEvent(SettingsRegistry::MAIL_EVENT_INVITATION));
        self::assertTrue($this->gate->instanceEvent(SettingsRegistry::MAIL_EVENT_PASSWORD_RESET));
    }

    public function testThePersonTurnsOffOneKindOrAllEmail(): void
    {
        $prefs = new NotificationPreferenceRepository($this->pdo);
        $prefs->set(self::TENANT, $this->profileId, 'agent.daily_summary', 'email', false);

        self::assertFalse($this->gate->allows(self::TENANT, $this->profileId, 'agent.daily_summary'));
        self::assertTrue($this->gate->allows(self::TENANT, $this->profileId, 'agent.needs_approval'));

        $prefs->set(self::TENANT, $this->profileId, '*', 'email', false);
        self::assertFalse($this->gate->allows(self::TENANT, $this->profileId, 'agent.needs_approval'));

        // The exact kind wins over "all email".
        $prefs->set(self::TENANT, $this->profileId, 'agent.needs_approval', 'email', true);
        self::assertTrue($this->gate->allows(self::TENANT, $this->profileId, 'agent.needs_approval'));
    }

    public function testTheWorkspaceTurnsOffItsEmailButNotSecurityEmail(): void
    {
        (new TenantNotificationSettingsRepository($this->pdo))->upsertConfig(self::TENANT, 'email', ['enabled' => false]);

        self::assertFalse($this->gate->workspace(self::TENANT));
        self::assertFalse($this->gate->allows(self::TENANT, $this->profileId, 'documents.routing.awaiting'));
        self::assertTrue($this->gate->allows(self::TENANT, $this->profileId, 'security.two_step_reminder'));
    }

    public function testSecurityEmailIgnoresThePersonsChoice(): void
    {
        (new NotificationPreferenceRepository($this->pdo))->set(self::TENANT, $this->profileId, '*', 'email', false);

        self::assertTrue($this->gate->allows(self::TENANT, $this->profileId, 'security.two_step_reminder'));
    }

    public function testTheEmailChannelSendsARealEmailToTheProfile(): void
    {
        $mailer = new CapturingMailer();
        $transport = new NotificationEmailTransport(
            new BrandedEmailSender($mailer, new EmailLayout(), $this->settings),
            new ProfileEmailRepository($this->pdo),
        );

        $result = $transport->send(new NotificationMessage(
            channel: 'email',
            recipient: (string) $this->profileId,
            tenantId: self::TENANT,
            type: 'documents.routing.awaiting',
            subject: 'Leave request is waiting for you',
            body: "Open it in Whity.\nIt waits at your step.",
        ));

        self::assertTrue($result->success);
        self::assertCount(1, $mailer->sent);
        [$to, $subject, $text, $html] = $mailer->sent[0];
        self::assertSame('amro@example.test', $to);
        self::assertSame('Leave request is waiting for you', $subject);
        self::assertStringContainsString('It waits at your step.', $text);
        self::assertStringContainsString('Leave request is waiting for you', (string) $html);
    }

    public function testTheDispatcherQueuesNoEmailWhenTheWorkspaceTurnedItOff(): void
    {
        $repo = new NotificationRepository($this->pdo);
        $dispatcher = new NotificationDispatcher(
            $repo,
            CoreTransports::make(),
            new QueueService(new JobRepository($this->pdo)),
            emailGate: $this->gate,
        );
        $channels = fn (int $id): array => array_map(
            static fn (array $d): string => (string) $d['channel'],
            $repo->listDeliveries(self::TENANT, $id),
        );

        $on = $dispatcher->dispatch(self::TENANT, $this->profileId, 'documents.routing.awaiting', ['channels' => ['in_app', 'email']]);
        self::assertSame(['in_app', 'email'], $channels($on));

        (new TenantNotificationSettingsRepository($this->pdo))->upsertConfig(self::TENANT, 'email', ['enabled' => false]);
        $off = $dispatcher->dispatch(self::TENANT, $this->profileId, 'documents.routing.awaiting', ['channels' => ['in_app', 'email']]);
        self::assertSame(['in_app'], $channels($off));
    }

    public function testAnAddressGivenByTheCallerWinsAndNoAddressFails(): void
    {
        $mailer = new CapturingMailer();
        $transport = new NotificationEmailTransport(
            new BrandedEmailSender($mailer, new EmailLayout(), $this->settings),
            new ProfileEmailRepository($this->pdo),
        );

        $message = static fn (string $to): NotificationMessage => new NotificationMessage(
            channel: 'email',
            recipient: $to,
            tenantId: self::TENANT,
            type: 'security.two_step_reminder',
            subject: 'Please set up two-step sign-in',
            body: 'Open your account.',
        );

        self::assertTrue($transport->send($message('awss@example.test'))->success);
        self::assertSame('awss@example.test', $mailer->sent[0][0]);
        self::assertFalse($transport->send($message('99999'))->success);
        self::assertCount(1, $mailer->sent);
    }
}
