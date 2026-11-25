<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\ContactInviteApiHandler;
use Whity\Core\Branding\TenantHostRepository;
use Whity\Core\Contacts\ContactRepository;
use Whity\Core\CustomerArea\CustomerAccountInviteMailer;
use Whity\Core\CustomerArea\CustomerAreaLinks;
use Whity\Core\Identity\CustomerSafeRole;
use Whity\Core\Identity\InvitationService;
use Whity\Core\Identity\ProfileProvisioner;
use Whity\Core\Mail\BrandedEmailSender;
use Whity\Core\Mail\EmailBranding;
use Whity\Core\Mail\EmailContent;
use Whity\Core\Mail\EmailLayout;
use Whity\Core\Mail\Mailer;
use Whity\Core\Request;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Signup\WorkspaceSignupSettings;
use Whity\Core\Store\DatabaseSharedStore;
use Whity\Core\Tenant\TenantContext;

/**
 * Invite to their account (WHIT-1428; design section 41 Q4, CA.6, CA.8).
 *
 * Example Bakery (1) has public sign-up OFF. Staff invite the contact Ahmad
 * Khalil (61); accepting makes a customer membership and links contact 61 to
 * him, and his past bills show at once. "See it in your account" goes only to
 * an address that is a verified address of someone with an account here.
 */
final class ContactInviteRealEngineTest extends TestCase
{
    private const CUSTOMER_ROLE = 903;
    private const STAFF = 11;

    private PDO $pdo;
    private SettingsService $settings;
    private InvitationService $invitations;
    private ?Mailer $mail = null;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Other Bakery', 'other-bakery')");
        $this->pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES (901, 'staff', '', NULL, CURRENT_TIMESTAMP), (903, 'customer', '', 1, CURRENT_TIMESTAMP)");
        $this->pdo->exec(
            "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (11, 'Amro Saleh', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $this->pdo->exec("INSERT INTO memberships (profile_id, tenant_id, role_id, status, kind, created_at) VALUES (11, 1, 901, 'active', 'member', CURRENT_TIMESTAMP)");
        $this->pdo->exec(
            "INSERT INTO contacts (id, tenant_id, name, email, is_customer, is_supplier) VALUES
             (61, 1, 'Ahmad Khalil', 'ahmad@example.test', true, false),
             (62, 1, 'No Email', '', true, false)"
        );
        SchemaFromMigrations::syncSequences($this->pdo);

        $this->settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        // Sign-up stays OFF; the role customers get is chosen.
        $this->settings->setTenant(1, 'signup.mode', 'off');
        $this->settings->setTenant(1, 'signup.role_id', (string) self::CUSTOMER_ROLE);
        $this->invitations = new InvitationService($this->pdo, new ProfileProvisioner($this->pdo));
        TenantContext::reset();
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testAContactInviteWorksWithSignUpOffAndLinksThatContact(): void
    {
        $sent = $this->invitations->inviteContact(1, 61, 'ahmad@example.test', self::CUSTOMER_ROLE, self::STAFF);
        self::assertSame(InvitationService::INVITE_CREATED, $sent['result']);

        $preview = $this->invitations->preview($sent['token']);
        self::assertNotNull($preview);
        self::assertTrue($preview['customer_account']);
        self::assertSame('/@example-bakery/home', $preview['customer_area_path']);

        $accepted = $this->invitations->accept($sent['token'], password_hash('a long enough password', PASSWORD_BCRYPT));
        self::assertSame(InvitationService::ACCEPT_JOINED, $accepted['result']);
        $profileId = (int) $accepted['profile_id'];

        self::assertSame('customer', $this->scalar("SELECT kind FROM memberships WHERE tenant_id = 1 AND profile_id = {$profileId}"));
        self::assertSame($profileId, (int) $this->scalar('SELECT profile_id FROM contacts WHERE id = 61'));
    }

    public function testANewInviteReplacesTheContactsEarlierOne(): void
    {
        $first = $this->invitations->inviteContact(1, 61, 'ahmad@example.test', self::CUSTOMER_ROLE, self::STAFF);
        $this->invitations->inviteContact(1, 61, 'ahmad@example.test', self::CUSTOMER_ROLE, self::STAFF);

        self::assertSame(InvitationService::ACCEPT_INVALID, $this->invitations->accept($first['token'], password_hash('a long enough password', PASSWORD_BCRYPT))['result']);
    }

    public function testAcceptingNeverTakesAContactLinkedToSomeoneElse(): void
    {
        $sent = $this->invitations->inviteContact(1, 61, 'ahmad@example.test', self::CUSTOMER_ROLE, self::STAFF);
        // Meanwhile staff linked the contact to Amro.
        $this->pdo->exec('UPDATE contacts SET profile_id = 11 WHERE id = 61');

        $accepted = $this->invitations->accept($sent['token'], password_hash('a long enough password', PASSWORD_BCRYPT));
        self::assertSame(InvitationService::ACCEPT_JOINED, $accepted['result']);
        self::assertSame(11, (int) $this->scalar('SELECT profile_id FROM contacts WHERE id = 61'));
    }

    public function testTheEndpointRefusesInPlainWordsWhenItCannotWork(): void
    {
        $handler = $this->handler();
        TenantContext::setTenantId(1);

        self::assertSame(409, $this->invite($handler, 62)['status'], 'no email');
        self::assertSame(404, $this->invite($handler, 999)['status']);

        $this->settings->setTenant(1, 'customer_area.enabled', 'false');
        self::assertSame(409, $this->invite($handler, 61)['status'], 'area off');
        $this->settings->setTenant(1, 'customer_area.enabled', 'true');

        $this->settings->setTenant(1, 'signup.role_id', '0');
        $handler = $this->handler();
        $out = $this->invite($handler, 61);
        self::assertSame(409, $out['status']);
        self::assertSame('no_customer_role', $out['body']['details']['reason'] ?? null);

        $this->settings->setTenant(1, 'signup.role_id', (string) self::CUSTOMER_ROLE);
        // Settings are read once per request: a new request sees the change.
        $handler = $this->handler();
        $out = $this->invite($handler, 61);
        self::assertSame(202, $out['status'], (string) json_encode($out['body']));
        self::assertNotNull($this->mail);
        self::assertSame('ahmad@example.test', $this->mail->sent[0]['to'] ?? null);
        self::assertStringContainsString('accept-invitation?token=', $this->mail->sent[0]['body'] ?? '');
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM invitations WHERE contact_id = 61 AND status = 'pending'"));

        // Another workspace's request never reaches this contact.
        TenantContext::reset();
        TenantContext::setTenantId(2);
        self::assertSame(404, $this->invite($handler, 61)['status']);
    }

    public function testTheAccountLinkGoesOnlyToSomeoneWithAnAccountHere(): void
    {
        $links = new CustomerAreaLinks($this->pdo, $this->settings, new TenantHostRepository($this->pdo), 'https://app.whity.test');
        self::assertNull($links->forEmail(1, 'ahmad@example.test', 'bookings/5', false), 'no account yet');

        $sent = $this->invitations->inviteContact(1, 61, 'ahmad@example.test', self::CUSTOMER_ROLE, self::STAFF);
        $this->invitations->accept($sent['token'], password_hash('a long enough password', PASSWORD_BCRYPT));

        self::assertSame(
            ['label' => 'See it in your account', 'url' => 'https://app.whity.test/@example-bakery/bookings/5'],
            $links->forEmail(1, 'AHMAD@example.test', 'bookings/5', false)
        );
        self::assertSame('اعرضه في حسابك', $links->forEmail(1, 'ahmad@example.test', 'bookings/5', true)['label'] ?? null);
        self::assertNull($links->forEmail(2, 'ahmad@example.test', 'bookings/5', false), 'not a member of the other workspace');

        $this->settings->setTenant(1, 'customer_area.enabled', 'false');
        self::assertNull($links->forEmail(1, 'ahmad@example.test', 'bookings/5', false), 'area off');
    }

    public function testTheEmailDrawsTheAccountLinkAsItsOwnLine(): void
    {
        $content = (new EmailContent(heading: 'Your booking', ctaLabel: 'Add to calendar', ctaUrl: 'https://app.whity.test/x.ics'))
            ->withAccountLink(['label' => 'See it in your account', 'url' => 'https://app.whity.test/@example-bakery/bookings/5']);
        $rendered = (new EmailLayout())->render($content, new EmailBranding('Example Bakery', '#0b62c4', '', ''));

        self::assertStringContainsString('href="https://app.whity.test/@example-bakery/bookings/5"', $rendered->html);
        self::assertStringContainsString('See it in your account: https://app.whity.test/@example-bakery/bookings/5', $rendered->text);
        self::assertSame($content, $content->withAccountLink(null), 'no link, the same email');
    }

    private function scalar(string $sql): mixed
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt);

        return $stmt->fetchColumn();
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private function invite(ContactInviteApiHandler $handler, int $id): array
    {
        $request = new Request('POST', '/api/v1/contacts/' . $id . '/invite', [], '');
        $request->user = (object) ['profile_id' => self::STAFF];
        $response = $handler->invite($request, ['id' => (string) $id]);
        $body = json_decode($response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($body) ? $body : []];
    }

    private function handler(): ContactInviteApiHandler
    {
        $this->mail = new class implements Mailer {
            /** @var list<array{to: string, subject: string, body: string}> */
            public array $sent = [];

            public function send(string $toEmail, string $subject, string $textBody, ?string $htmlBody = null): void
            {
                $this->sent[] = ['to' => $toEmail, 'subject' => $subject, 'body' => $textBody];
            }
        };
        $sender = new BrandedEmailSender($this->mail, new EmailLayout(), $this->settings);

        return new ContactInviteApiHandler(
            new ContactRepository($this->pdo),
            $this->invitations,
            new CustomerAccountInviteMailer($sender, 'https://app.whity.test/accept-invitation'),
            new WorkspaceSignupSettings($this->pdo),
            CustomerSafeRole::make($this->pdo),
            $this->settings,
            new DatabaseSharedStore($this->pdo),
        );
    }
}
