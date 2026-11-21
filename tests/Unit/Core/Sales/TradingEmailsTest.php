<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Sales;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Tests\Unit\Core\Mail\CapturingMailer;
use Whity\Core\Mail\BrandedEmailSender;
use Whity\Core\Mail\EmailGate;
use Whity\Core\Mail\EmailLayout;
use Whity\Core\Sales\TradingEmails;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;

/**
 * The trading emails (WHIT-1323, design section 34 §5, TR.2-TR.4): the words
 * of `handoff/emails/` in English and Arabic, one main button, amounts in the
 * row's currency, and the workspace's switch for each.
 */
final class TradingEmailsTest extends TestCase
{
    private const TENANT = 1;

    private PDO $pdo;
    private CapturingMailer $mailer;
    private SettingsService $settings;
    private TradingEmails $emails;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery')");
        $this->settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        $this->settings->setGlobal('site_name', 'Example Bakery');
        $this->mailer = new CapturingMailer();
        $sender = new BrandedEmailSender($this->mailer, new EmailLayout(), $this->settings);
        $sender->useGate(new EmailGate($this->settings, $this->pdo));
        $this->emails = new TradingEmails($sender, $this->settings);
    }

    public function testAQuoteHasItsTotalItsDateAndOneButtonToAccept(): void
    {
        self::assertTrue($this->emails->quote(self::TENANT, 'cafe@example.test', $this->quote(), 'https://app.example.test/quote/abc'));

        [$to, $subject, $text] = $this->mailer->sent[0];
        self::assertSame('cafe@example.test', $to);
        self::assertSame('Quote QUO-0318 from Example Bakery', $subject);
        self::assertStringContainsString('Your quote, Example Café', $text);
        self::assertStringContainsString('As promised, here is our quote for Birthday cake.', $text);
        self::assertStringContainsString('$139.20', $text);
        self::assertStringContainsString('21 December 2026', $text);
        self::assertStringContainsString('Review and accept: https://app.example.test/quote/abc', $text);
    }

    public function testInArabicTheWordsAreArabicAndTheAmountStaysLeftToRight(): void
    {
        $this->settings->setTenant(self::TENANT, SettingsRegistry::LOCALE, 'ar');

        self::assertTrue($this->emails->quote(self::TENANT, 'cafe@example.test', $this->quote(), 'https://app.example.test/quote/abc'));

        [, $subject, $text] = $this->mailer->sent[0];
        self::assertSame('عرض السعر QUO-0318 من Example Bakery', $subject);
        self::assertStringContainsString('راجع واقبل', $text);
        self::assertStringContainsString("\u{2066}\$139.20\u{2069}", $text);
        self::assertStringContainsString('21 كانون الأول 2026', $text);
    }

    public function testAnInvoiceOffersPayNowOnlyWithAPaymentLink(): void
    {
        $invoice = ['number' => 'INV-1042', 'customer' => 'Example Clinic', 'total_minor' => 301600, 'currency' => 'JOD', 'due_date' => '2026-12-05'];

        self::assertTrue($this->emails->invoice(self::TENANT, 'clinic@example.test', $invoice, 'https://app.example.test/i/1'));
        self::assertTrue($this->emails->invoice(self::TENANT, 'clinic@example.test', $invoice, 'https://app.example.test/i/1', 'https://pay.example.test/x'));

        self::assertStringContainsString('View the invoice: https://app.example.test/i/1', $this->mailer->sent[0][2]);
        self::assertStringContainsString('Pay now: https://pay.example.test/x', $this->mailer->sent[1][2]);
        self::assertStringContainsString('JOD 301.600', $this->mailer->sent[0][1], 'the subject carries the total in its own currency');
    }

    public function testAReminderAssumesTheyMayHavePaid(): void
    {
        $invoice = ['number' => 'INV-1042', 'customer' => 'Example Clinic', 'total_minor' => 30160, 'currency' => 'USD', 'due_date' => '2026-11-10'];

        self::assertTrue($this->emails->reminder(self::TENANT, 'clinic@example.test', $invoice, 'https://app.example.test/i/1'));
        self::assertStringContainsString("If you've already paid, thank you, and please ignore this.", $this->mailer->sent[0][2]);
    }

    public function testAReceiptThanksForThePayment(): void
    {
        $payment = ['amount_minor' => 20300, 'currency' => 'USD', 'for' => 'INV-1042', 'paid_on' => '2026-11-21', 'method' => 'cliq'];

        self::assertTrue($this->emails->receipt(self::TENANT, 'clinic@example.test', $payment));
        self::assertSame('Thank you: we received $203.00', $this->mailer->sent[0][1]);
        self::assertStringContainsString('CliQ', $this->mailer->sent[0][2]);
    }

    public function testTheWorkspaceCanTurnEachOneOff(): void
    {
        $this->settings->setTenant(self::TENANT, SettingsRegistry::SALES_EMAIL_QUOTE, 'false');

        self::assertFalse($this->emails->quote(self::TENANT, 'cafe@example.test', $this->quote(), 'https://app.example.test/quote/abc'));
        self::assertSame([], $this->mailer->sent);
        self::assertTrue($this->emails->enabled(self::TENANT, TradingEmails::INVOICE), 'the others stay on');
    }

    public function testNoEmailIsSentToSomethingThatIsNotAnAddress(): void
    {
        self::assertFalse($this->emails->quote(self::TENANT, 'not an address', $this->quote(), 'https://app.example.test/quote/abc'));
        self::assertSame([], $this->mailer->sent);
    }

    /** @return array{number: string, customer: string, what: string, total_minor: int, currency: string, valid_until: string} */
    private function quote(): array
    {
        return ['number' => 'QUO-0318', 'customer' => 'Example Café', 'what' => 'Birthday cake', 'total_minor' => 13920, 'currency' => 'USD', 'valid_until' => '2026-12-21'];
    }
}
