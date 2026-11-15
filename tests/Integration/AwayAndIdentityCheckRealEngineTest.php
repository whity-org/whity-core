<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\AwayApiHandler;
use Whity\Core\Delegation\AwayService;
use Whity\Core\Document\Signing\SigningChallengeRepository;
use Whity\Core\Mail\EmailLayout;
use Whity\Core\Mail\Mailer;
use Whity\Core\Request;
use Whity\Core\Security\IdentityCheck;
use Whity\Core\Security\IdentityCheckMailer;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\TenantContext;

/**
 * "I'm away" (OR.1) and the admin's "confirm it's you" check (AG.1).
 */
final class AwayAndIdentityCheckRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const OTHER_TENANT = 2;
    private const AMRO = 101;
    private const EMAD = 102;
    private const OUTSIDER = 103;
    private const TODAY = '2026-11-12';

    private PDO $pdo;
    private AwayService $away;

    /** @var list<string> */
    private array $sent = [];

    protected function setUp(): void
    {
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Example Cafe', 'example-cafe')");
        $this->pdo->exec("INSERT OR IGNORE INTO roles (id, name, description, tenant_id, created_at) VALUES (1, 'admin', '', NULL, datetime('now'))");
        foreach ([self::AMRO => ['Amro Saleh', self::TENANT], self::EMAD => ['Emad Nasser', self::TENANT], self::OUTSIDER => ['Awss Haddad', self::OTHER_TENANT]] as $id => [$name, $tenant]) {
            $this->pdo->prepare("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                                 VALUES (?, ?, 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)")->execute([$id, $name]);
            $this->pdo->prepare("INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at) VALUES (?, ?, 1, 'active', datetime('now'))")->execute([$id, $tenant]);
            $this->pdo->prepare("INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at) VALUES (?, ?, true, true, datetime('now'))")
                ->execute([$id, strtolower(explode(' ', $name)[0]) . '@examplebakery.test']);
        }
        $this->away = new AwayService($this->pdo);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testHandingOverIsInForceFromTheStartDayUntilTheDayYoureBack(): void
    {
        $period = $this->away->start(self::TENANT, self::AMRO, self::EMAD, '2026-11-15', '2026-11-20', ['flows', 'proposals'], 'Emad decides for me.', self::TODAY);

        self::assertIsArray($period);
        self::assertSame('starting', $period['status']);
        self::assertSame(['id' => self::EMAD, 'name' => 'Emad Nasser'], $period['delegate']);
        self::assertSame([], $this->away->coveredBy(self::TENANT, self::EMAD, 'proposals', '2026-11-14'));
        self::assertSame([self::AMRO], $this->away->coveredBy(self::TENANT, self::EMAD, 'proposals', '2026-11-15'));
        self::assertSame([self::AMRO], $this->away->coveredBy(self::TENANT, self::EMAD, 'flows', '2026-11-19'));
        self::assertSame([], $this->away->coveredBy(self::TENANT, self::EMAD, 'flows', '2026-11-20'), 'back on the 20th');
        self::assertSame([], $this->away->coveredBy(self::TENANT, self::EMAD, 'documents', '2026-11-16'), 'documents were not handed over');
    }

    public function testComingBackEarlyEndsItAtOnce(): void
    {
        $this->away->start(self::TENANT, self::AMRO, self::EMAD, self::TODAY, '2026-11-20', ['proposals'], null, self::TODAY);
        self::assertTrue($this->away->decidesFor(self::TENANT, self::EMAD, self::AMRO, 'proposals', self::TODAY));

        self::assertTrue($this->away->end(self::TENANT, self::AMRO));

        self::assertFalse($this->away->decidesFor(self::TENANT, self::EMAD, self::AMRO, 'proposals', self::TODAY));
        self::assertNull($this->away->current(self::TENANT, self::AMRO, self::TODAY));
        self::assertSame('ended', $this->away->listForTenant(self::TENANT, today: self::TODAY)[0]['status']);
    }

    public function testANewPeriodReplacesTheOneBefore(): void
    {
        $this->away->start(self::TENANT, self::AMRO, self::EMAD, '2026-11-15', '2026-11-20', ['proposals'], null, self::TODAY);
        $this->away->start(self::TENANT, self::AMRO, self::EMAD, '2026-12-01', '2026-12-05', ['flows'], null, self::TODAY);

        $current = $this->away->current(self::TENANT, self::AMRO, self::TODAY);
        self::assertIsArray($current);
        self::assertSame('2026-12-01', $current['startsOn']);
        self::assertSame([], $this->away->coveredBy(self::TENANT, self::EMAD, 'proposals', '2026-11-16'));
    }

    public function testRefusals(): void
    {
        self::assertSame('self', $this->away->start(self::TENANT, self::AMRO, self::AMRO, '2026-11-15', '2026-11-20', ['flows'], null, self::TODAY));
        self::assertSame('delegate', $this->away->start(self::TENANT, self::AMRO, self::OUTSIDER, '2026-11-15', '2026-11-20', ['flows'], null, self::TODAY), 'not in this workspace');
        self::assertSame('dates', $this->away->start(self::TENANT, self::AMRO, self::EMAD, '2026-11-20', '2026-11-15', ['flows'], null, self::TODAY));
        self::assertSame('dates', $this->away->start(self::TENANT, self::AMRO, self::EMAD, '2026-11-01', '2026-11-12', ['flows'], null, self::TODAY), 'already back');
        self::assertSame('dates', $this->away->start(self::TENANT, self::AMRO, self::EMAD, '2026-11-15', 'soon', ['flows'], null, self::TODAY));
        self::assertSame('covers', $this->away->start(self::TENANT, self::AMRO, self::EMAD, '2026-11-15', '2026-11-20', ['everything'], null, self::TODAY));
    }

    public function testThePersonSetsOnlyTheirOwn(): void
    {
        $handler = new AwayApiHandler($this->away);
        $body = ['delegateProfileId' => self::EMAD, 'startsOn' => gmdate('Y-m-d'), 'backOn' => gmdate('Y-m-d', time() + 5 * 86400), 'covers' => ['proposals']];

        $res = $handler->set($this->as(self::AMRO, $body));
        self::assertSame(200, $res->getStatusCode(), $res->getBody());
        self::assertSame(self::AMRO, json_decode($res->getBody(), true)['data']['profile']['id'], 'the caller is the one away, whatever the body says');

        self::assertNull(json_decode($handler->show($this->as(self::EMAD))->getBody(), true)['data'], 'Emad is not away');
        self::assertSame(404, $handler->end($this->as(self::EMAD))->getStatusCode());
        self::assertSame(200, $handler->end($this->as(self::AMRO))->getStatusCode());
    }

    public function testAnEmailCodeConfirmsOnlyWhatItWasSentFor(): void
    {
        $check = $this->identityCheck();
        $started = $check->start(self::TENANT, self::EMAD, 'request:7', 'approving a two-step reset');

        self::assertSame(['method' => 'email_code', 'email_hint' => 'e•••@examplebakery.test'], $started);
        self::assertCount(1, $this->sent);
        $code = $this->sent[0];

        self::assertNull($check->verify(self::TENANT, self::EMAD, 'request:8', $code), 'not for another request');
        self::assertNull($check->verify(self::TENANT, self::AMRO, 'request:7', $code), 'not for another admin');
        self::assertSame('email_code', $check->verify(self::TENANT, self::EMAD, 'request:7', $code));
        self::assertNull($check->verify(self::TENANT, self::EMAD, 'request:7', $code), 'used once');
    }

    public function testNoCodeConfirmsNothing(): void
    {
        $check = $this->identityCheck();
        $check->start(self::TENANT, self::EMAD, 'request:7', 'approving a two-step reset');

        self::assertNull($check->verify(self::TENANT, self::EMAD, 'request:7', null));
        self::assertNull($check->verify(self::TENANT, self::EMAD, 'request:7', '000000'));
    }

    private function identityCheck(): IdentityCheck
    {
        $sent = &$this->sent;
        $mailer = new class ($sent) implements Mailer {
            /** @var list<string> */
            private array $sent;

            /** @param list<string> $sent */
            public function __construct(array &$sent)
            {
                $this->sent = &$sent;
            }

            public function send(string $toEmail, string $subject, string $textBody, ?string $htmlBody = null): void
            {
                $this->sent[] = substr($subject, -6);
            }

            /** @return list<string> */
            public function sent(): array
            {
                return $this->sent;
            }
        };
        $settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));

        return new IdentityCheck($this->pdo, new SigningChallengeRepository($this->pdo), null, new IdentityCheckMailer(new \Whity\Core\Mail\BrandedEmailSender($mailer, new EmailLayout(), $settings)));
    }

    /** @param array<string, mixed> $body */
    private function as(int $profileId, array $body = []): Request
    {
        $request = new Request('PUT', '/api/me/away', ['Content-Type' => 'application/json'], $body === [] ? '' : (string) json_encode($body));
        $request->user = (object) ['profile_id' => $profileId];

        return $request;
    }
}
