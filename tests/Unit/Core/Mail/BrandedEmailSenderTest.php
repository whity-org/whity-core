<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Mail;

use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Mail\BrandedEmailSender;
use Whity\Core\Mail\EmailBranding;
use Whity\Core\Mail\EmailContent;
use Whity\Core\Mail\EmailLayout;
use Whity\Core\Mail\EmailOptions;
use Whity\Core\Mail\MailAttachment;
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

    public function testTheInstancesOwnEmailKeepsTheTransportsFromNameAndPlainSend(): void
    {
        [$sender, $mailer] = self::sender();

        $sender->deliver(0, 'amro@example.test', 'Reset your password', new EmailContent(heading: 'Reset your password'));

        self::assertSame([], $mailer->threaded);
        self::assertCount(1, $mailer->plain);
        self::assertSame('Reset your password', $mailer->plain[0]['subject']);
        self::assertStringContainsString('Reset your password', (string) $mailer->plain[0]['html']);
    }

    public function testOptionsCarryTheBrandFilesAndThread(): void
    {
        [$sender, $mailer] = self::sender();
        $ics = new MailAttachment('booking.ics', 'text/calendar; charset=utf-8; method=REQUEST', "BEGIN:VCALENDAR
END:VCALENDAR
");

        $sender->deliver(0, 'amro@example.test', 'Booked', new EmailContent(heading: 'Booked'), new EmailOptions(
            branding: new EmailBranding('Example Bakery', '#123456', '', ''),
            attachments: [$ics],
            replyTo: 'reply+abc@example.test',
            messageId: '<m1@example.test>',
            inReplyTo: '<m0@example.test>',
            references: ['<m0@example.test>'],
        ));

        self::assertSame([], $mailer->plain);
        $sent = $mailer->threaded[0];
        self::assertNull($sent->fromName);
        self::assertSame([$ics], $sent->attachments);
        self::assertSame('reply+abc@example.test', $sent->replyTo);
        self::assertSame('<m0@example.test>', $sent->inReplyTo);
        self::assertStringContainsString('Example Bakery', (string) $sent->htmlBody);
        self::assertStringContainsString('#123456', (string) $sent->htmlBody);
    }

    public function testDeliverLetsAFailureThroughAndSendReportsIt(): void
    {
        $pdo = SchemaFromMigrations::make(true);
        $settings = new SettingsService(new GlobalSettingsRepository($pdo), new TenantSettingsRepository($pdo));
        $mailer = new class implements Mailer {
            public function send(string $toEmail, string $subject, string $textBody, ?string $htmlBody = null): void
            {
                throw new \RuntimeException('relay down');
            }
        };
        $sender = new BrandedEmailSender($mailer, new EmailLayout(), $settings);

        self::assertFalse($sender->send(0, 'amro@example.test', 'Hello', new EmailContent(heading: 'Hello')));
        $this->expectExceptionMessage('relay down');
        $sender->deliver(0, 'amro@example.test', 'Hello', new EmailContent(heading: 'Hello'));
    }

    public function testAMailerThatCannotThreadStillSendsTheEmail(): void
    {
        $pdo = SchemaFromMigrations::make(true);
        $settings = new SettingsService(new GlobalSettingsRepository($pdo), new TenantSettingsRepository($pdo));
        $mailer = new class implements Mailer {
            /** @var list<string> */
            public array $subjects = [];

            public function send(string $toEmail, string $subject, string $textBody, ?string $htmlBody = null): void
            {
                $this->subjects[] = $subject;
            }
        };
        $sender = new BrandedEmailSender($mailer, new EmailLayout(), $settings);
        $ics = new MailAttachment('booking.ics', 'text/calendar', 'x');

        $sender->deliver(3, 'amro@example.test', 'Booked', new EmailContent(heading: 'Booked'), new EmailOptions(attachments: [$ics]));

        self::assertSame(['Booked'], $mailer->subjects);
    }

    /**
     * @return array{0: BrandedEmailSender, 1: object{plain: list<array{to: string, subject: string, html: ?string}>, threaded: list<OutgoingEmail>}}
     */
    private static function sender(): array
    {
        $pdo = SchemaFromMigrations::make(true);
        $settings = new SettingsService(new GlobalSettingsRepository($pdo), new TenantSettingsRepository($pdo));
        $mailer = new class implements Mailer, ThreadedMailer {
            /** @var list<array{to: string, subject: string, html: ?string}> */
            public array $plain = [];
            /** @var list<OutgoingEmail> */
            public array $threaded = [];

            public function send(string $toEmail, string $subject, string $textBody, ?string $htmlBody = null): void
            {
                $this->plain[] = ['to' => $toEmail, 'subject' => $subject, 'html' => $htmlBody];
            }

            public function sendThreaded(OutgoingEmail $email): void
            {
                $this->threaded[] = $email;
            }
        };

        return [new BrandedEmailSender($mailer, new EmailLayout(), $settings), $mailer];
    }
}
