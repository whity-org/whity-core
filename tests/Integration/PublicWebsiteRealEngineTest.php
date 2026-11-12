<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\PublicContactApiHandler;
use Whity\Api\PublicPlansApiHandler;
use Whity\Core\Branding\TenantHostRepository;
use Whity\Core\Mail\OutgoingEmail;
use Whity\Core\Mail\ThreadedMailer;
use Whity\Core\Messaging\CoreMessaging;
use Whity\Core\Messaging\MessageChannelRegistry;
use Whity\Core\Messaging\MessagingService;
use Whity\Core\Plan\WebsitePlans;
use Whity\Core\Request;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Store\ArraySharedStore;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\Middleware\RequestBodyValidator;
use Whity\Http\WebsiteCors;
use Whity\Sdk\Http\Response;

/**
 * The two endpoints whity.dev calls: `GET /api/public/plans` (WS.5) and
 * `POST /api/public/contact` (section 29), on the real schema.
 */
final class PublicWebsiteRealEngineTest extends TestCase
{
    private const TEAM_INBOX = 7;

    private PDO $pdo;
    private GlobalSettingsRepository $globals;
    private MessagingService $messaging;

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name, slug) VALUES (0, 'system', 'system')");
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (" . self::TEAM_INBOX . ", 'Whity team', 'whity-team')");
        SchemaFromMigrations::syncSequences($this->pdo);
        $this->globals = new GlobalSettingsRepository($this->pdo);
        $mailer = new class () implements ThreadedMailer {
            public function sendThreaded(OutgoingEmail $email): void
            {
            }
        };
        $this->messaging = CoreMessaging::build(
            $this->pdo,
            new MessageChannelRegistry(),
            $mailer,
            new EncryptedSecretStore(['v1' => str_repeat('k', 32)], 'v1'),
            static fn (): int => 1,
            null,
            'https://app.whity.test',
        );
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    // ── plans ───────────────────────────────────────────────────────────────

    public function testPlansFollowTheContractFromTheCatalogueAndTheMarketingLayer(): void
    {
        $free = $this->plan('free', 'Free', 1, 'For trying it out');
        $team = $this->plan('team', 'Team', 2);
        $this->plan('hidden', 'Hidden', 3, 'Not on the website');
        $this->plan('bare', 'Bare', 4, null);
        $this->plan('pack', 'Storage pack', 4, null, true);
        $this->plan('old', 'Old', 5, null, false, false);
        $this->price($team, 29000, 'month');
        $this->price($team, 290000, 'year');
        $this->limit($team, 'members.max', '25');
        $this->limit($team, 'customers.per_month', '5000');
        $this->limit($free, 'members.max', '-1');
        $this->globals->set(SettingsRegistry::WEBSITE_PLANS, (string) json_encode([
            'plans' => [
                'team' => [
                    'highlight' => true,
                    'name' => ['en' => 'Team', 'ar' => 'الفريق'],
                    'tagline' => ['en' => 'For growing teams', 'ar' => 'للفرق المتنامية'],
                    'features' => [['en' => 'Your own web address and sign-in', 'ar' => 'عنوانك وصفحة دخولك الخاصة']],
                ],
                'free' => ['limits' => ['staff' => 3, 'customersPerMonth' => 100]],
                'hidden' => ['visible' => false],
            ],
            'dedicatedHosting' => ['fromMonthly' => '150'],
        ]));

        $response = $this->plansHandler()->index(new Request('GET', '/api/public/plans'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('public, max-age=300', $response->getHeaders()['cache-control'] ?? null);
        $body = json_decode($response->getBody(), true);
        self::assertSame(1, $body['version']);
        self::assertSame('JOD', $body['currency']);
        self::assertSame(3, $body['decimals']);
        self::assertSame(['free', 'team'], array_column($body['plans'], 'id'), 'active tiers only, in order; add-ons, retired, hidden and incomplete ones left out');
        [$freeOut, $teamOut] = $body['plans'];

        self::assertNull($freeOut['price']['monthly'], 'no price is null, never 0');
        self::assertSame(['staff' => 3, 'customersPerMonth' => 100], $freeOut['limits'], 'the layer sets what unlimited shows as');
        self::assertSame(['en' => 'For trying it out'], $freeOut['tagline'], 'the description is the fallback tagline');
        self::assertFalse($freeOut['highlight']);
        self::assertSame('https://app.whity.test/register?plan=free', $freeOut['cta']['href']);

        self::assertSame('29.000', $teamOut['price']['monthly'], 'normal units with the currency decimals');
        self::assertSame(['staff' => 25, 'customersPerMonth' => 5000], $teamOut['limits']);
        self::assertTrue($teamOut['highlight']);
        self::assertSame(['en' => 'Team', 'ar' => 'الفريق'], $teamOut['name']);
        self::assertCount(1, $teamOut['features']);
        self::assertSame('150.000', $body['dedicatedHosting']['fromMonthly']);
    }

    public function testPlansAreCappedAtFourAndLinksMustBeHttps(): void
    {
        foreach (['a', 'b', 'c', 'd', 'e'] as $i => $key) {
            $id = $this->plan($key, strtoupper($key), $i, 'Plan ' . $key);
            $this->limit($id, 'members.max', '5');
            $this->limit($id, 'customers.per_month', '50');
        }

        $body = json_decode($this->plansHandler()->index(new Request('GET', '/api/public/plans'))->getBody(), true);
        self::assertSame(['a', 'b', 'c', 'd'], array_column($body['plans'], 'id'));

        $local = new PublicPlansApiHandler(new WebsitePlans($this->pdo), $this->globals, 'http://localhost:3000');
        $body = json_decode($local->index(new Request('GET', '/api/public/plans'))->getBody(), true);
        self::assertSame([], $body['plans'], 'an http link would make the page throw every plan away');
    }

    public function testTheMarketingSettingRefusesWhatThePageWouldReject(): void
    {
        self::assertNull(WebsitePlans::problem(''));
        self::assertNull(WebsitePlans::problem('{"plans":{"team":{"highlight":true}}}'));
        self::assertNotNull(WebsitePlans::problem('[1,2]'));
        self::assertNotNull(WebsitePlans::problem('{"plans":{"team":{"features":[{"ar":"فقط"}]}}}'));
        self::assertNotNull(WebsitePlans::problem('{"plans":{"team":{"cta":{"href":"javascript:alert(1)"}}}}'));
        self::assertNotNull(WebsitePlans::problem('{"plans":{"team":{"features":[1,2,3,4,5,6,7]}}}'));
        self::assertNotNull(WebsitePlans::problem('{"dedicatedHosting":{"fromMonthly":"1,500"}}'));
        self::assertNotNull(WebsitePlans::problem('{"plans":{"team":{"limits":{"staff":-1}}}}'));
        self::assertNull(WebsitePlans::problem('{"plans":{"team":{"limits":{"staff":1000,"customersPerMonth":100000}}}}'));
    }

    // ── contact ─────────────────────────────────────────────────────────────

    public function testAMessageLandsInTheTeamInboxAndIsNotEchoed(): void
    {
        $this->globals->set(SettingsRegistry::WEBSITE_CONTACT_WORKSPACE, 'whity-team');

        $response = $this->contact([
            'topic' => 'demo', 'name' => 'Ahmad Khalil', 'company' => 'Example Bakery',
            'contact' => 'ahmad@example.com', 'message' => 'Can we see the stock screens?', 'lang' => 'en', 'website' => '',
        ]);

        self::assertSame(201, $response->getStatusCode());
        self::assertStringNotContainsString('stock screens', $response->getBody());
        $stmt = $this->pdo->prepare(
            'SELECT c.channel, c.contact_address, m.body_text FROM messages m JOIN conversations c ON c.id = m.conversation_id
              WHERE m.tenant_id = :tid'
        );
        $stmt->execute([':tid' => self::TEAM_INBOX]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame('email', $row['channel'], 'an email address can be answered by email');
        self::assertSame('ahmad@example.com', $row['contact_address']);
        self::assertStringContainsString('Can we see the stock screens?', (string) $row['body_text']);
        self::assertStringContainsString('Example Bakery', (string) $row['body_text']);
    }

    public function testTheTrapFieldDropsTheMessageQuietly(): void
    {
        $this->globals->set(SettingsRegistry::WEBSITE_CONTACT_WORKSPACE, 'whity-team');

        $response = $this->contact(['name' => 'Bot', 'contact' => 'bot@example.com', 'message' => 'Buy', 'website' => 'http://spam']);

        self::assertSame(201, $response->getStatusCode(), 'same answer as a real one');
        self::assertSame(0, $this->messageCount());
    }

    public function testMissingFieldsAreNamedAndNothingIsWritten(): void
    {
        $this->globals->set(SettingsRegistry::WEBSITE_CONTACT_WORKSPACE, 'whity-team');

        $response = $this->contact(['name' => '', 'contact' => 'not a contact', 'message' => '']);

        self::assertSame(422, $response->getStatusCode());
        $fields = json_decode($response->getBody(), true)['details']['fields'] ?? json_decode($response->getBody(), true)['fields'] ?? [];
        self::assertSame(['name', 'contact', 'message'], array_keys($fields));
        self::assertSame(0, $this->messageCount());
    }

    public function testWithNoInboxNamedTheFormSaysItCannotReceive(): void
    {
        $response = $this->contact(['name' => 'Ahmad', 'contact' => '+962 79 000 0000', 'message' => 'Hello']);

        self::assertSame(503, $response->getStatusCode());
    }

    public function testFivePerAddressAnHour(): void
    {
        $this->globals->set(SettingsRegistry::WEBSITE_CONTACT_WORKSPACE, 'whity-team');
        $handler = $this->contactHandler();
        $codes = [];
        for ($i = 0; $i < 6; $i++) {
            $codes[] = $handler->send($this->contactRequest(['name' => 'Ahmad', 'contact' => '+962 79 000 0000', 'message' => "Hello {$i}"]))->getStatusCode();
        }

        self::assertSame([201, 201, 201, 201, 201, 429], $codes);
    }

    public function testTheScriptsOffFormIsSentBackToTheContactPage(): void
    {
        $this->globals->set(SettingsRegistry::WEBSITE_CONTACT_WORKSPACE, 'whity-team');
        $request = new Request(
            'POST',
            '/api/public/contact',
            ['Content-Type' => 'application/x-www-form-urlencoded', 'X-Whity-Client-Ip' => '198.51.100.7'],
            http_build_query(['topic' => 'question', 'name' => 'Awss Haddad', 'contact' => 'awss@example.com', 'message' => 'Hi', 'lang' => 'ar'])
        );
        self::assertTrue(RequestBodyValidator::isWebsiteContactForm($request));

        $response = (new RequestBodyValidator())->handle($request, fn (Request $r): Response => $this->contactHandler()->send($r));

        self::assertSame(303, $response->getStatusCode());
        self::assertSame(WebsiteCors::siteOrigin() . '/ar/contact.html?sent=1', $response->getHeaders()['location'] ?? null);
        self::assertSame(1, $this->messageCount());
    }

    public function testCorsAllowsTheWebsiteWithoutCredentials(): void
    {
        $preflight = WebsiteCors::preflight(new Request('OPTIONS', '/api/v1/public/contact', ['Origin' => 'https://whity.dev']));
        self::assertNotNull($preflight);
        self::assertSame(204, $preflight->getStatusCode());
        $headers = $preflight->getHeaders();
        self::assertSame('https://whity.dev', $headers['access-control-allow-origin'] ?? null);
        self::assertArrayNotHasKey('access-control-allow-credentials', $headers);
        self::assertNull(WebsiteCors::preflight(new Request('OPTIONS', '/api/v1/documents', ['Origin' => 'https://whity.dev'])));
        self::assertSame('https://whity.dev', WebsiteCors::headers('https://evil.example')['Access-Control-Allow-Origin']);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function messageCount(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM messages');
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    private function plansHandler(): PublicPlansApiHandler
    {
        return new PublicPlansApiHandler(new WebsitePlans($this->pdo), $this->globals, 'https://app.whity.test');
    }

    private function contactHandler(): PublicContactApiHandler
    {
        return new PublicContactApiHandler($this->messaging, new TenantHostRepository($this->pdo), $this->globals, new ArraySharedStore());
    }

    /** @param array<string, string> $body */
    private function contact(array $body): Response
    {
        return $this->contactHandler()->send($this->contactRequest($body));
    }

    /** @param array<string, string> $body */
    private function contactRequest(array $body): Request
    {
        return new Request('POST', '/api/public/contact', ['Content-Type' => 'application/json', 'X-Whity-Client-Ip' => '198.51.100.7'], (string) json_encode($body));
    }

    private function plan(string $key, string $name, int $order, ?string $description = null, bool $addon = false, bool $active = true): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO plans (plan_key, name, description, is_active, sort_order, is_addon) VALUES (:k, :n, :d, :a, :o, :addon)'
        );
        $stmt->bindValue(':k', $key);
        $stmt->bindValue(':n', $name);
        $stmt->bindValue(':d', $description);
        $stmt->bindValue(':a', $active, PDO::PARAM_BOOL);
        $stmt->bindValue(':o', $order, PDO::PARAM_INT);
        $stmt->bindValue(':addon', $addon, PDO::PARAM_BOOL);
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    private function price(int $planId, int $minor, string $period): void
    {
        $this->pdo->prepare(
            "INSERT INTO plan_prices (plan_id, currency, unit_amount, billing_period) VALUES (:p, 'JOD', :a, :per)"
        )->execute([':p' => $planId, ':a' => $minor, ':per' => $period]);
    }

    private function limit(int $planId, string $key, string $value): void
    {
        $this->pdo->prepare('INSERT INTO plan_entitlements (plan_id, entitlement_key, value) VALUES (:p, :k, :v)')
            ->execute([':p' => $planId, ':k' => $key, ':v' => $value]);
    }
}
