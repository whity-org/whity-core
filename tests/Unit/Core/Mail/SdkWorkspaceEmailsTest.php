<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Mail;

use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Mail\BrandedEmailSender;
use Whity\Core\Mail\EmailLayout;
use Whity\Core\Mail\SdkWorkspaceEmails;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Sdk\Mail\WorkspaceEmail;
use Whity\Sdk\Mail\WorkspaceEmails;

/**
 * SDK 1.100 (WHIT-978): a plugin's email goes out through the workspace's
 * branded sender, with only the words the plugin gave.
 */
final class SdkWorkspaceEmailsTest extends TestCase
{
    private CapturingMailer $mailer;
    private SdkWorkspaceEmails $emails;

    protected function setUp(): void
    {
        $pdo = SchemaFromMigrations::make(true);
        $settings = new SettingsService(new GlobalSettingsRepository($pdo), new TenantSettingsRepository($pdo));
        $this->mailer = new CapturingMailer();
        $this->emails = new SdkWorkspaceEmails(new BrandedEmailSender($this->mailer, new EmailLayout(), $settings));
    }

    public function testAnInvoiceGoesOutInTheWorkspaceLayout(): void
    {
        self::assertInstanceOf(WorkspaceEmails::class, $this->emails);

        $sent = $this->emails->send(1, 'cafe@example.com', 'Invoice INV-000042 from Example Bakery', new WorkspaceEmail(
            heading: 'Invoice INV-000042',
            paragraphs: ['Thank you for your order. Your invoice is attached below.'],
            rows: [['Total', 'JOD 58.000'], ['Due', '1 Dec 2026']],
            buttonLabel: 'View the invoice',
            buttonUrl: 'https://example.whity.dev/i/abc',
            notice: 'You received this because Example Bakery sent you an invoice.',
        ));

        self::assertTrue($sent);
        self::assertCount(1, $this->mailer->sent);
        $mail = $this->mailer->sent[0];
        self::assertSame('cafe@example.com', $mail[0]);
        $html = (string) $mail[3];
        self::assertStringContainsString('JOD 58.000', $html);
        self::assertStringContainsString('View the invoice', $html);
        self::assertStringContainsString('https://example.whity.dev/i/abc', $html);
    }

    public function testTheWordsArePlainTextAndTheLanguageCanBeChosen(): void
    {
        $content = $this->emails->content(1, new WorkspaceEmail(heading: '<b>Hi</b>', paragraphs: ['<script>x</script>'], language: 'ar'));
        self::assertSame('ar', $content->language);

        $this->emails->send(1, 'a@example.com', 'Hi', new WorkspaceEmail(heading: '<b>Hi</b>', paragraphs: ['<script>x</script>']));
        $html = (string) $this->mailer->sent[0][3];
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<b>Hi</b>', $html);
    }

    public function testRefusesWithoutThrowing(): void
    {
        $email = new WorkspaceEmail(heading: 'Hello');

        self::assertFalse($this->emails->send(1, 'not an address', 'Hi', $email));
        self::assertFalse($this->emails->send(0, 'a@example.com', 'Hi', $email), 'a workspace is required');
        self::assertFalse($this->emails->send(1, 'a@example.com', "\r\n", $email));
        self::assertSame([], $this->mailer->sent);
    }

    public function testTheValueObjectKeepsTheLayoutRules(): void
    {
        foreach ([
            static fn () => new WorkspaceEmail(heading: ' '),
            static fn () => new WorkspaceEmail(heading: 'x', buttonLabel: 'Pay'),
            static fn () => new WorkspaceEmail(heading: 'x', buttonLabel: 'Pay', buttonUrl: 'javascript:alert(1)'),
            static fn () => new WorkspaceEmail(heading: 'x', language: 'fr'),
            static fn () => new WorkspaceEmail(heading: 'x', rows: [['only one']]), // @phpstan-ignore argument.type
        ] as $make) {
            try {
                $make();
                self::fail('accepted an email the layout cannot draw');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
