<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Mail;

use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Mail\BrandedEmailSender;
use Whity\Core\Mail\EmailBranding;
use Whity\Core\Mail\EmailContent;
use Whity\Core\Mail\EmailLayout;
use Whity\Core\Mail\Mailer;
use Whity\Core\Mail\OutgoingEmail;
use Whity\Core\Mail\ThreadedMailer;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;

/**
 * EM.1: a workspace's email comes from "the workspace, via Whity", and a
 * white-label plan drops "via Whity".
 */
final class BrandedEmailSenderTest extends TestCase
{
    public function testAWorkspaceEmailComesFromTheWorkspaceViaWhity(): void
    {
        $pdo = SchemaFromMigrations::make(true);
        $settings = new SettingsService(new GlobalSettingsRepository($pdo), new TenantSettingsRepository($pdo));
        $mailer = new class implements Mailer, ThreadedMailer {
            /** @var list<OutgoingEmail> */
            public array $sent = [];

            public function send(string $toEmail, string $subject, string $textBody, ?string $htmlBody = null): void
            {
                throw new \LogicException('a workspace email goes through sendThreaded');
            }

            public function sendThreaded(OutgoingEmail $email): void
            {
                $this->sent[] = $email;
            }
        };
        $settings->setGlobal('site_name', 'Example Bakery');

        $sender = new BrandedEmailSender($mailer, new EmailLayout(), $settings);
        self::assertTrue($sender->send(3, 'amro@example.test', 'Hello', new EmailContent(heading: 'Hello')));
        self::assertSame('Example Bakery via Whity', $mailer->sent[0]->fromName);
    }

    public function testWhiteLabelDropsViaWhity(): void
    {
        self::assertSame('Example Bakery', BrandedEmailSender::fromName(new EmailBranding('Example Bakery', '#123456', '', '', whiteLabel: true)));
        self::assertSame('Example Bakery via Whity', BrandedEmailSender::fromName(new EmailBranding('Example Bakery', '#123456', '', '')));
        self::assertSame('Whity', BrandedEmailSender::fromName(new EmailBranding('Whity', '#123456', '', '')));
        self::assertSame('Example Bakery عبر Whity', BrandedEmailSender::fromName(new EmailBranding('Example Bakery', '#123456', '', ''), 'ar'));
    }
}
