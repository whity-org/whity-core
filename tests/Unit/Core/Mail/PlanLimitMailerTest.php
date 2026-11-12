<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Mail;

use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Entitlement\EntitlementRegistry;
use Whity\Core\Mail\BrandedEmailSender;
use Whity\Core\Mail\EmailLayout;
use Whity\Core\Mail\PlanLimitMailer;
use Whity\Core\Mail\WorkspaceRecipients;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;

/**
 * EM.1 `plan-limit`: sent to whoever can choose a plan, worded by what will
 * really happen (warn mode: nothing stops; block mode: new ones wait).
 */
final class PlanLimitMailerTest extends TestCase
{
    private CapturingMailer $mailer;
    private SettingsService $settings;

    /** @param list<string> $recipients */
    private function mailer(array $recipients): PlanLimitMailer
    {
        $pdo = SchemaFromMigrations::make(true);
        $this->settings = new SettingsService(new GlobalSettingsRepository($pdo), new TenantSettingsRepository($pdo));
        $this->mailer = new CapturingMailer();
        $who = $this->createStub(WorkspaceRecipients::class);
        $who->method('holding')->willReturn($recipients);

        return new PlanLimitMailer(
            new BrandedEmailSender($this->mailer, new EmailLayout(), $this->settings),
            $who,
            $this->settings,
            'https://app.example.test/billing',
            static fn (int $id): string => 'Example Bakery',
        );
    }

    public function testCloseToTheLimitOnAWarningInstanceSaysNothingStops(): void
    {
        $m = $this->mailer(['amro@example.test', 'awss@example.test']);
        $this->settings->setGlobal(SettingsRegistry::METERS_ENFORCEMENT, 'warn');

        $sent = $m->crossed(5, EntitlementRegistry::CUSTOMERS_PER_MONTH, 450, 500, 'near', '2026-12-01T00:00:00+00:00');

        self::assertSame(2, $sent);
        [$to, $subject, $text] = $this->mailer->sent[0];
        self::assertSame('amro@example.test', $to);
        self::assertSame('Example Bakery is close to its plan’s limit', $subject);
        self::assertStringContainsString('450 of 500 customers this month', $text);
        self::assertStringContainsString('Nothing will stop or be locked.', $text);
        self::assertStringContainsString('The count starts again on 1 Dec 2026.', $text);
        self::assertStringContainsString('See the plans: https://app.example.test/billing', $text);
    }

    public function testCloseToTheLimitOnABlockingInstanceSaysNewOnesWait(): void
    {
        $m = $this->mailer(['amro@example.test']);

        // Customers never block (WARN_ONLY); document renders do, on a blocking instance.
        $m->crossed(5, EntitlementRegistry::DOCUMENTS_RENDER_PER_MONTH, 450, 500, 'near', '2026-12-01T00:00:00+00:00');

        [, , $text] = $this->mailer->sent[0];
        self::assertStringContainsString('450 of 500 documents made this month', $text);
        self::assertStringContainsString('new ones wait', $text);
        self::assertStringNotContainsString('Nothing will stop', $text);
    }

    public function testNobodyWhoCanPaySendsNothing(): void
    {
        $m = $this->mailer([]);

        self::assertSame(0, $m->crossed(5, EntitlementRegistry::CUSTOMERS_PER_MONTH, 501, 500, 'passed', '2026-12-01T00:00:00+00:00'));
        self::assertSame([], $this->mailer->sent);
    }
}
