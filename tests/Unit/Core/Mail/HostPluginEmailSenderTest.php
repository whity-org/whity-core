<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Mail;

use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Mail\EmailTemplateRegistry;
use Whity\Core\Mail\HostPluginEmailSender;
use Whity\Core\Mail\Mailer;
use Whity\Core\Mail\OutgoingEmail;
use Whity\Core\Mail\ThreadedMailer;
use Whity\Core\Plugin\PluginIdentity;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Sdk\Mail\EmailAttachment;
use Whity\Sdk\Mail\EmailMessage;
use Whity\Sdk\Mail\EmailTemplate;
use Whity\Sdk\Mail\PluginEmailTemplatesInterface;
use Whity\Sdk\PluginInterface;

/**
 * The email sender extension point (WHIT-959): a plugin declares templates and
 * sends them through the host, in the core layout and the workspace's brand,
 * reaching only its own templates.
 */
final class HostPluginEmailSenderTest extends TestCase
{
    private SettingsService $settings;
    private SenderCapturingMailer $mailer;

    protected function setUp(): void
    {
        $pdo = SchemaFromMigrations::make(true);
        $this->settings = new SettingsService(new GlobalSettingsRepository($pdo), new TenantSettingsRepository($pdo));
        $this->mailer = new SenderCapturingMailer();
    }

    private function sender(?int $tenantId = 7, ?\Closure $sentWithWhity = null): HostPluginEmailSender
    {
        return new HostPluginEmailSender(
            $this->mailer,
            $this->settings,
            'https://app.example.test',
            static fn (): ?int => $tenantId,
            $sentWithWhity,
        );
    }

    public function testSendsADeclaredTemplateInTheWorkspaceBrand(): void
    {
        $this->settings->setTenant(7, SettingsRegistry::SITE_NAME, 'Example Bakery');
        $this->settings->setTenant(7, SettingsRegistry::BRANDING_MAIN_COLOR, '#B4532A');
        $plugin = self::plugin('wh-email-front-office', [new InvoiceTemplate()]);

        $this->sender()->for($plugin)->send('invoice', 'accounts@example.test', ['number' => 'INV-0042', 'total' => 'JOD 49.000'], 'ar');

        self::assertCount(1, $this->mailer->sent);
        [$to, $subject, $text, $html] = $this->mailer->sent[0];
        self::assertSame('accounts@example.test', $to);
        self::assertSame('فاتورة INV-0042', $subject);
        self::assertStringContainsString('dir="rtl"', (string) $html);
        self::assertStringContainsString('#B4532A', (string) $html);
        self::assertStringContainsString('Example Bakery', (string) $html);
        self::assertStringContainsString('<span dir="ltr">JOD 49.000</span>', (string) $html);
        self::assertStringContainsString('https://app.example.test/invoices/INV-0042', $text);
        self::assertStringContainsString('أُرسلت عبر Whity', (string) $html);
    }

    public function testWhiteLabelWorkspaceDropsTheCredit(): void
    {
        $plugin = self::plugin('wh-email-white', [new InvoiceTemplate()]);

        $preview = $this->sender(7, static fn (int $tenantId): bool => false)->for($plugin)->preview('invoice', ['number' => 'INV-1', 'total' => 'JOD 1.000']);

        self::assertSame('Invoice INV-1', $preview['subject']);
        self::assertStringNotContainsString('Sent with Whity', $preview['html']);
        self::assertCount(0, $this->mailer->sent);
    }

    public function testAttachmentsGoThroughTheThreadedTransport(): void
    {
        $plugin = self::plugin('wh-email-attach', [new InvoiceTemplate()]);

        $this->sender()->for($plugin)->send('invoice', 'accounts@example.test', ['number' => 'INV-2', 'total' => 'JOD 2.000', 'pdf' => '%PDF-1.7']);

        self::assertCount(1, $this->mailer->threaded);
        $email = $this->mailer->threaded[0];
        self::assertSame('Invoice INV-2', $email->subject);
        self::assertCount(1, $email->attachments);
        self::assertSame('INV-2.pdf', $email->attachments[0]->filename);
    }

    public function testRefusesAnUndeclaredOrForeignKeyAndABadAddress(): void
    {
        $sender = $this->sender()->for(self::plugin('wh-email-strict', [new InvoiceTemplate()]));

        foreach (['receipt', 'other:invoice'] as $key) {
            try {
                $sender->send($key, 'a@example.test');
                self::fail("{$key} was sent");
            } catch (\InvalidArgumentException) {
            }
        }
        try {
            $sender->send('invoice', "a@example.test\r\nBcc: x@example.test", ['number' => '1', 'total' => '1']);
            self::fail('A header injection was sent');
        } catch (\InvalidArgumentException) {
        }
        self::assertCount(0, $this->mailer->sent);
    }

    public function testAPluginWithoutTemplatesCanSendNothing(): void
    {
        $sender = $this->sender()->for(self::plugin('wh-email-none', null));

        $this->expectException(\InvalidArgumentException::class);
        $sender->send('invoice', 'a@example.test');
    }

    public function testAnObjectTheLoaderDidNotLoadIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sender()->for(self::plugin(null, [new InvoiceTemplate()]));
    }

    public function testAMalformedDeclarationIsRefusedWhole(): void
    {
        $bad = new class implements EmailTemplate {
            public function key(): string
            {
                return 'Bad Key';
            }

            public function build(array $data, string $locale): EmailMessage
            {
                return new EmailMessage('s', 'h');
            }
        };

        $this->expectException(\InvalidArgumentException::class);
        EmailTemplateRegistry::fromPlugin(self::plugin(null, [new InvoiceTemplate(), $bad]), 'acme');
    }

    public function testARepeatedKeyIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EmailTemplateRegistry::fromPlugin(self::plugin(null, [new InvoiceTemplate(), new InvoiceTemplate()]), 'acme');
    }

    /**
     * @param list<EmailTemplate>|null $templates null: the plugin does not declare the capability
     */
    private static function plugin(?string $bindAs, ?array $templates): PluginInterface
    {
        $plugin = $templates === null
            ? new class implements PluginInterface {
                use EmailTestPluginBasics;
            }
            : new class ($templates) implements PluginInterface, PluginEmailTemplatesInterface {
                use EmailTestPluginBasics;

                /** @param list<EmailTemplate> $templates */
                public function __construct(private readonly array $templates)
                {
                }

                public function getEmailTemplates(): array
                {
                    return $this->templates;
                }
            };
        if ($bindAs !== null) {
            PluginIdentity::bind($plugin, $bindAs);
        }

        return $plugin;
    }
}

trait EmailTestPluginBasics
{
    public function getName(): string
    {
        return 'email-test';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getRoutes(): array
    {
        return [];
    }

    public function getPermissions(): array
    {
        return [];
    }

    public function getHooks(): array
    {
        return [];
    }

    public function getMigrations(): array
    {
        return [];
    }
}

final class InvoiceTemplate implements EmailTemplate
{
    public function key(): string
    {
        return 'invoice';
    }

    public function build(array $data, string $locale): EmailMessage
    {
        $ar = $locale === 'ar';
        $number = (string) ($data['number'] ?? '');

        return new EmailMessage(
            subject: ($ar ? 'فاتورة ' : 'Invoice ') . $number,
            heading: $ar ? 'فاتورة جديدة' : 'A new invoice',
            buttonLabel: $ar ? 'اعرض الفاتورة' : 'View the invoice',
            buttonUrl: 'https://app.example.test/invoices/' . $number,
            rows: [[$ar ? 'المجموع' : 'Total', (string) ($data['total'] ?? '')]],
            attachments: isset($data['pdf']) ? [new EmailAttachment($number . '.pdf', 'application/pdf', (string) $data['pdf'])] : [],
        );
    }
}

final class SenderCapturingMailer implements Mailer, ThreadedMailer
{
    /** @var list<array{0: string, 1: string, 2: string, 3: ?string}> */
    public array $sent = [];

    /** @var list<OutgoingEmail> */
    public array $threaded = [];

    public function send(string $toEmail, string $subject, string $textBody, ?string $htmlBody = null): void
    {
        $this->sent[] = [$toEmail, $subject, $textBody, $htmlBody];
    }

    public function sendThreaded(OutgoingEmail $email): void
    {
        $this->threaded[] = $email;
    }
}
