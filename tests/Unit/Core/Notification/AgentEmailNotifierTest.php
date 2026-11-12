<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Notification;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Tests\Unit\Core\Mail\CapturingMailer;
use Whity\Core\Agent\AgentAssignmentRepository;
use Whity\Core\Agent\AgentProposalRepository;
use Whity\Core\Agent\AgentRepository;
use Whity\Core\Agent\AgentStatsRepository;
use Whity\Core\Identity\ProfileEmailRepository;
use Whity\Core\Mail\BrandedEmailSender;
use Whity\Core\Mail\EmailLayout;
use Whity\Core\Notification\AgentEmailNotifier;
use Whity\Core\Notification\Jobs\AgentDailySummaryJob;
use Whity\Core\Notification\NotificationPreferenceRepository;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Store\DatabaseSharedStore;

/**
 * A12 agent notifications by email and EM.1's agent emails: needs-approval goes
 * to the person the agent acts for, only opens the app, at most one an hour per
 * agent, and stops when the agent's page or the person's preferences say so.
 * The daily summary goes at 18:00 in the workspace's time zone, once a day.
 */
final class AgentEmailNotifierTest extends TestCase
{
    private const TENANT = 1;

    private PDO $pdo;
    private CapturingMailer $mailer;
    private AgentProposalRepository $proposals;
    private NotificationPreferenceRepository $preferences;
    private BrandedEmailSender $sender;
    private SettingsService $settings;
    private AgentEmailNotifier $notifier;
    private int $profileId;
    private int $agentId;
    private string $principal;

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

        $agents = new AgentRepository($this->pdo);
        $this->agentId = $agents->create(self::TENANT, ['slug' => 'billing', 'name' => 'Billing assistant', 'name_ar' => 'مساعد الفواتير', 'role' => 'Sends reminders', 'tone' => 'plain', 'language' => 'auto'], $this->profileId);
        $assignmentId = (new AgentAssignmentRepository($this->pdo))->assign($this->agentId, $this->profileId, self::TENANT, []);
        $this->principal = AgentAssignmentRepository::principalId($assignmentId);

        $this->settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        $this->mailer = new CapturingMailer();
        $this->sender = new BrandedEmailSender($this->mailer, new EmailLayout(), $this->settings);
        $this->proposals = new AgentProposalRepository($this->pdo);
        $this->preferences = new NotificationPreferenceRepository($this->pdo);
        $this->notifier = new AgentEmailNotifier(
            $this->pdo,
            $this->proposals,
            $this->preferences,
            new ProfileEmailRepository($this->pdo),
            new DatabaseSharedStore($this->pdo),
            $this->sender,
            'https://app.example.test/admin/approvals',
        );
    }

    /** @return array<string, mixed> */
    private function propose(string $summary = 'Send 12 payment reminders', string $tool = 'invoices.remind'): array
    {
        $id = $this->proposals->create(self::TENANT, $this->principal, $this->profileId, $tool, [], [], [], $summary);

        return ['tenant_id' => self::TENANT, 'proposal_id' => $id, 'on_behalf_of_profile_id' => $this->profileId, 'tool' => $tool];
    }

    public function testTheProposalReachesThePersonAndOnlyOpensTheApp(): void
    {
        self::assertTrue($this->notifier->proposalCreated($this->propose()));

        [$to, $subject, $text, $html] = $this->mailer->sent[0];
        self::assertSame('amro@example.test', $to);
        self::assertSame('Billing assistant needs your approval: Send 12 payment reminders', $subject);
        self::assertStringContainsString('Billing assistant · for Amro Saleh', (string) $html);
        self::assertStringContainsString('dashed', (string) $html);
        // One link, to the approvals page; nothing approves from the email.
        self::assertSame(1, preg_match_all('/<a href="https:/', (string) $html));
        self::assertStringContainsString('href="https://app.example.test/admin/approvals"', (string) $html);
        self::assertStringNotContainsString('Approve', $text);
    }

    public function testAtMostOneAnHourAndTheNextSaysHowManyWait(): void
    {
        self::assertTrue($this->notifier->proposalCreated($this->propose('First')));
        self::assertFalse($this->notifier->proposalCreated($this->propose('Second')));
        self::assertCount(1, $this->mailer->sent);
    }

    public function testSignInApprovalsAreNeverHeldBack(): void
    {
        $this->notifier->proposalCreated($this->propose('First'));
        self::assertTrue($this->notifier->proposalCreated($this->propose('Let Billing assistant sign in', 'auth.sign_in')));
        self::assertCount(2, $this->mailer->sent);
        self::assertStringContainsString('1 more is waiting for you.', $this->mailer->sent[1][2]);
    }

    public function testTheAgentsPageTurnsItOff(): void
    {
        (new AgentRepository($this->pdo))->update($this->agentId, self::TENANT, ['notify_by_email' => false], $this->profileId);

        self::assertFalse($this->notifier->proposalCreated($this->propose()));
        self::assertSame([], $this->mailer->sent);
        $agent = (new AgentRepository($this->pdo))->find($this->agentId, self::TENANT);
        self::assertNotNull($agent);
        self::assertFalse($agent['notify_by_email'] ?? null);
    }

    public function testThePersonTurnsItOff(): void
    {
        $this->preferences->set(self::TENANT, $this->profileId, AgentEmailNotifier::TYPE_NEEDS_APPROVAL, 'email', false);

        self::assertFalse($this->notifier->proposalCreated($this->propose()));
        self::assertSame([], $this->mailer->sent);
    }

    public function testTheDailySummaryGoesAtSixInTheWorkspacesTimeZoneOnce(): void
    {
        $this->propose('Waiting one');
        $job = fn (string $utc): AgentDailySummaryJob => new AgentDailySummaryJob(
            $this->pdo,
            $this->settings,
            new AgentStatsRepository($this->pdo),
            $this->preferences,
            new ProfileEmailRepository($this->pdo),
            new DatabaseSharedStore($this->pdo),
            $this->sender,
            'https://app.example.test',
            static fn (): \DateTimeImmutable => new \DateTimeImmutable($utc, new \DateTimeZone('UTC')),
        );

        // 17:00 UTC is 17:00 in UTC (the default zone): not yet.
        self::assertSame(['sent' => 0], $job('2026-11-12 17:00:00')->handle([]));
        self::assertSame(['sent' => 1], $job('2026-11-12 18:00:00')->handle([]));
        // A second run in the same hour sends nothing twice.
        self::assertSame(['sent' => 0], $job('2026-11-12 18:30:00')->handle([]));

        [, $subject, $text] = $this->mailer->sent[0];
        self::assertSame('Today in Whity: 0 done, 1 waiting for you', $subject);
        self::assertStringContainsString('Good evening, Amro Saleh', $text);
        self::assertStringContainsString('Waiting for you: 1', $text);
    }
}
