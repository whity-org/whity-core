<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Mail;

use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Mail\BrandedEmailSender;
use Whity\Core\Mail\EmailLayout;
use Whity\Core\Mail\SdkBillingEmails;
use Whity\Core\Mail\WorkspaceRecipients;
use Whity\Core\Plugin\PluginIdentity;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Store\ArraySharedStore;
use Whity\Core\Store\SharedStoreInterface;
use Whity\Sdk\Mail\BillingEmails;
use Whity\Sdk\Mail\WorkspaceEmail;
use Whity\Sdk\PluginBillingEmailsInterface;
use Whity\Sdk\PluginInterface;

/**
 * SDK 1.102 (WHIT-985): Whity's own billing email reaches a workspace's
 * payers only from a plugin that declares it AND the operator granted, only
 * with a button into the app, and only a few times a day.
 */
final class SdkBillingEmailsTest extends TestCase
{
    private const APP = 'https://app.example.test';

    private CapturingMailer $mailer;
    private SettingsService $settings;
    private SdkBillingEmails $emails;
    /** @var list<array{int, string}> */
    private array $asked = [];

    protected function setUp(): void
    {
        $pdo = SchemaFromMigrations::make(true);
        $this->settings = new SettingsService(new GlobalSettingsRepository($pdo), new TenantSettingsRepository($pdo));
        $this->mailer = new CapturingMailer();
        $who = $this->createStub(WorkspaceRecipients::class);
        $who->method('holding')->willReturnCallback(function (int $tenantId, string $permission): array {
            $this->asked[] = [$tenantId, $permission];

            return ['amro@example.test', 'awss@example.test'];
        });
        $this->emails = new SdkBillingEmails(
            new BrandedEmailSender($this->mailer, new EmailLayout(), $this->settings),
            $who,
            $this->settings,
            new ArraySharedStore(),
            self::APP,
        );
    }

    public function testAGrantedPluginReachesTheWorkspacesPayersInTheInstancesBrand(): void
    {
        $plugin = self::declaring('billing-test-granted');
        $this->settings->setGlobal(SettingsRegistry::PLUGINS_BILLING_EMAIL_SENDERS, 'some-other, billing-test-granted');

        self::assertInstanceOf(BillingEmails::class, $this->emails);
        self::assertTrue($this->emails->isAllowed($plugin));
        $sent = $this->emails->send($plugin, 7, 'Your Whity receipt for November', self::receipt());

        self::assertSame(2, $sent);
        self::assertSame([[7, CorePermissions::BILLING_PAY]], $this->asked);
        [$to, $subject, $text] = $this->mailer->sent[0];
        self::assertSame('amro@example.test', $to);
        self::assertSame('Your Whity receipt for November', $subject);
        self::assertStringContainsString('Thank you. Here’s your receipt.', $text);
        self::assertStringContainsString('See billing: ' . self::APP . '/billing', $text);
        self::assertSame(self::APP . '/billing', $this->emails->billingUrl(7));
    }

    public function testAPluginWithoutTheGrantIsRefused(): void
    {
        $plugin = self::declaring('billing-test-not-granted');

        self::assertFalse($this->emails->isAllowed($plugin), 'nothing is granted by default');
        self::assertSame(0, $this->emails->send($plugin, 7, 'Your Whity receipt', self::receipt()));

        $this->settings->setGlobal(SettingsRegistry::PLUGINS_BILLING_EMAIL_SENDERS, 'whity-pay');
        self::assertFalse($this->emails->isAllowed($plugin), 'another plugin\'s grant is not this one\'s');
        self::assertSame(0, $this->emails->send($plugin, 7, 'Your Whity receipt', self::receipt()));

        self::assertSame([], $this->mailer->sent);
        self::assertSame([], $this->asked, 'a refused plugin does not even learn who the payers are');
    }

    public function testGrantedButNotDeclaredIsRefused(): void
    {
        $plugin = self::plain('billing-test-undeclared');
        PluginIdentity::bind($plugin, 'billing-test-undeclared');
        $this->settings->setGlobal(SettingsRegistry::PLUGINS_BILLING_EMAIL_SENDERS, 'billing-test-undeclared');

        self::assertFalse($this->emails->isAllowed($plugin));
        self::assertSame(0, $this->emails->send($plugin, 7, 'Your Whity receipt', self::receipt()));
        self::assertSame([], $this->mailer->sent);
    }

    public function testAPluginCannotBorrowAGrantedName(): void
    {
        // Loaded under one name, claiming the granted one: the loader's binding decides.
        $impostor = self::declaring('billing-test-impostor', 'whity-pay');
        $this->settings->setGlobal(SettingsRegistry::PLUGINS_BILLING_EMAIL_SENDERS, 'whity-pay');
        self::assertFalse($this->emails->isAllowed($impostor));

        // An object the loader never loaded has no identity at all.
        $unloaded = self::declaringUnbound('whity-pay');
        self::assertFalse($this->emails->isAllowed($unloaded));
        self::assertSame(0, $this->emails->send($unloaded, 7, 'Your Whity receipt', self::receipt()));
        self::assertSame([], $this->mailer->sent);
    }

    public function testTheGrantIsForTheExactNameNotOneThatSharesItsSlug(): void
    {
        $plugin = self::declaring('Billing_Test_Exact');
        $this->settings->setGlobal(SettingsRegistry::PLUGINS_BILLING_EMAIL_SENDERS, 'billing-test-exact');
        self::assertFalse($this->emails->isAllowed($plugin));

        $this->settings->setGlobal(SettingsRegistry::PLUGINS_BILLING_EMAIL_SENDERS, 'Billing_Test_Exact');
        self::assertTrue($this->emails->isAllowed($plugin));
    }

    public function testTheWordsCannotLinkOutsideTheApp(): void
    {
        $plugin = self::declaring('billing-test-links');
        $this->settings->setGlobal(SettingsRegistry::PLUGINS_BILLING_EMAIL_SENDERS, 'billing-test-links');

        $phishing = [
            new WorkspaceEmail(heading: 'Payment failed', paragraphs: ['Update it at https://whity-billing-update.com/login within a day.']),
            new WorkspaceEmail(heading: 'Payment failed', rows: [['Pay now', 'evil.example/pay']]),
            new WorkspaceEmail(heading: 'Payment failed', note: 'Or go to www.evil.example'),
            new WorkspaceEmail(heading: 'Go to http://localhost.evil.example now'),
            new WorkspaceEmail(heading: 'Payment failed', preheader: 'Pay at app.example.test.evil.example'),
            new WorkspaceEmail(heading: 'Payment failed', notice: 'Questions: ftp://files.evil.example'),
        ];
        foreach ($phishing as $i => $email) {
            self::assertSame(0, $this->emails->send($plugin, 7, 'Payment failed', $email), 'email ' . $i);
        }
        self::assertSame(0, $this->emails->send($plugin, 7, 'Pay at evil.example', new WorkspaceEmail(heading: 'Payment failed')));
        self::assertSame([], $this->mailer->sent);
        self::assertSame([], $this->asked, 'refused before anyone is looked up');

        // Money, receipt numbers, dates and this app's own address are fine.
        $ok = new WorkspaceEmail(
            heading: 'Thank you. Here’s your receipt.',
            paragraphs: ['You paid JOD 49.000 on 12 Nov 2026. See ' . self::APP . '/billing or app.example.test/billing.'],
            rows: [['Receipt', 'INV-2026.0042'], ['Total', 'JOD 3,412.000'], ['Plan', 'Team (e.g. 5 people)']],
        );
        self::assertSame(2, $this->emails->send($plugin, 7, 'Your receipt for November 2026', $ok));
    }

    public function testAnUnavailableCountSendsNothing(): void
    {
        $plugin = self::declaring('billing-test-store-down');
        $this->settings->setGlobal(SettingsRegistry::PLUGINS_BILLING_EMAIL_SENDERS, 'billing-test-store-down');
        $store = $this->createStub(SharedStoreInterface::class);
        $store->method('increment')->willThrowException(new \RuntimeException('database is down'));
        $who = $this->createStub(WorkspaceRecipients::class);
        $who->method('holding')->willReturn(['amro@example.test']);
        $emails = new SdkBillingEmails(
            new BrandedEmailSender($this->mailer, new EmailLayout(), $this->settings),
            $who,
            $this->settings,
            $store,
            self::APP,
        );

        self::assertSame(0, $emails->send($plugin, 7, 'Receipt', self::receipt()));
        self::assertSame([], $this->mailer->sent);
    }

    public function testRevokingTheGrantAppliesAtOnce(): void
    {
        $plugin = self::declaring('billing-test-revoked');
        $this->settings->setGlobal(SettingsRegistry::PLUGINS_BILLING_EMAIL_SENDERS, 'billing-test-revoked');
        self::assertSame(2, $this->emails->send($plugin, 7, 'Receipt', self::receipt()));

        $this->settings->setGlobal(SettingsRegistry::PLUGINS_BILLING_EMAIL_SENDERS, '');
        self::assertSame(0, $this->emails->send($plugin, 7, 'Receipt', self::receipt()));
        self::assertCount(2, $this->mailer->sent);
    }

    public function testAButtonOutsideTheAppIsRefused(): void
    {
        $plugin = self::declaring('billing-test-button');
        $this->settings->setGlobal(SettingsRegistry::PLUGINS_BILLING_EMAIL_SENDERS, 'billing-test-button');

        foreach (['https://evil.example/billing', 'https://app.example.test.evil.example/billing'] as $url) {
            $email = new WorkspaceEmail(heading: 'Update the card', buttonLabel: 'Update the card', buttonUrl: $url);
            self::assertSame(0, $this->emails->send($plugin, 7, 'Payment failed', $email), $url);
        }
        self::assertSame([], $this->mailer->sent);
    }

    public function testAWorkspaceGetsAFewADayAtMost(): void
    {
        $plugin = self::declaring('billing-test-cap');
        $this->settings->setGlobal(SettingsRegistry::PLUGINS_BILLING_EMAIL_SENDERS, 'billing-test-cap');

        for ($i = 0; $i < SdkBillingEmails::DAILY_CAP; $i++) {
            self::assertSame(2, $this->emails->send($plugin, 7, 'Receipt ' . $i, self::receipt()));
        }
        self::assertSame(0, $this->emails->send($plugin, 7, 'One too many', self::receipt()));
        self::assertSame(2, $this->emails->send($plugin, 8, 'Another workspace has its own count', self::receipt()));
        self::assertCount(2 * SdkBillingEmails::DAILY_CAP + 2, $this->mailer->sent);
    }

    public function testTheWorkspacesLanguageIsUsed(): void
    {
        $plugin = self::declaring('billing-test-arabic');
        $this->settings->setGlobal(SettingsRegistry::PLUGINS_BILLING_EMAIL_SENDERS, 'billing-test-arabic');
        $this->settings->setTenant(9, SettingsRegistry::LOCALE, 'ar');

        self::assertSame('ar', $this->emails->language(9));
        self::assertSame(2, $this->emails->send($plugin, 9, 'إيصال Whity', new WorkspaceEmail(heading: 'شكراً لك. هذا إيصالك.')));
        self::assertStringContainsString('dir="rtl"', (string) $this->mailer->sent[0][3]);
    }

    public function testTheSettingTakesOnlyPluginNames(): void
    {
        self::assertTrue(SettingsRegistry::isGlobalOnly(SettingsRegistry::PLUGINS_BILLING_EMAIL_SENDERS));
        self::assertSame('', SettingsRegistry::defaultFor(SettingsRegistry::PLUGINS_BILLING_EMAIL_SENDERS));
        self::assertNull(SettingsRegistry::validate(SettingsRegistry::PLUGINS_BILLING_EMAIL_SENDERS, 'whity-pay, acme\\Billing'));
        self::assertNotNull(SettingsRegistry::validate(SettingsRegistry::PLUGINS_BILLING_EMAIL_SENDERS, 'whity-pay,,'));
        self::assertNotNull(SettingsRegistry::validate(SettingsRegistry::PLUGINS_BILLING_EMAIL_SENDERS, '123'));
    }

    private static function receipt(): WorkspaceEmail
    {
        return new WorkspaceEmail(
            heading: 'Thank you. Here’s your receipt.',
            rows: [['Workspace', 'Example Bakery'], ['Total', 'JOD 49.000']],
            buttonLabel: 'See billing',
            buttonUrl: self::APP . '/billing',
        );
    }

    private static function declaring(string $loadedAs, ?string $claims = null): PluginInterface
    {
        $plugin = self::declaringUnbound($claims ?? $loadedAs);
        PluginIdentity::bind($plugin, $loadedAs);

        return $plugin;
    }

    private static function declaringUnbound(string $name): PluginInterface
    {
        return new class ($name) extends BillingStubPlugin implements PluginBillingEmailsInterface {
            public function billingEmailsPurpose(): string
            {
                return 'Receipts and failed payments for the plans this instance sells';
            }
        };
    }

    private static function plain(string $name): PluginInterface
    {
        return new class ($name) extends BillingStubPlugin {
        };
    }
}
