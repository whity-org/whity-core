<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\WorkspaceOwnersApiHandler;
use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;
use Whity\Auth\TokenValidator;
use Whity\Core\Document\Signing\SigningChallengeRepository;
use Whity\Core\Identity\ProfileEmailRepository;
use Whity\Core\Request;
use Whity\Core\Security\GatedApproval;
use Whity\Core\Security\IdentityCheck;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\TenantContext;
use Whity\Core\Tenant\WorkspaceCreator;
use Whity\Core\Tenant\WorkspaceOwnership;

/**
 * The owners API (WHIT-1170): "Confirm it's you" gates offering and accepting,
 * the code is bound to exactly that offer, only owners act, and an offer is
 * only ever the person's own.
 */
final class WorkspaceOwnersApiRealEngineTest extends TestCase
{
    public const GOOD_CODE = '246810';

    private PDO $pdo;
    private WorkspaceOwnersApiHandler $api;
    private int $tenantId;
    private int $amro;
    private int $awss;
    private int $ahmad;
    /** @var list<string> purposes the stand-in check was asked to verify */
    public array $verified = [];

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        $settings->setGlobal(SettingsRegistry::WORKSPACES_SELF_CREATE_ENABLED, 'true');
        $this->amro = $this->profile('Amro Saleh');
        $this->tenantId = (int) (new WorkspaceCreator($this->pdo, $settings))->createFor($this->amro, 'Example Bakery')['tenant_id'];
        $this->awss = $this->member('Awss Haddad');
        $this->ahmad = $this->member('Ahmad Khalil');

        $test = $this;
        $identity = new class ($this->pdo, $test) extends IdentityCheck {
            public function __construct(PDO $pdo, private readonly WorkspaceOwnersApiRealEngineTest $test)
            {
                parent::__construct($pdo, new SigningChallengeRepository($pdo));
            }

            /** @return array{method: string, email_hint: ?string} */
            public function start(int $tenantId, int $profileId, string $purpose, string $what): array
            {
                return ['method' => self::TOTP, 'email_hint' => null];
            }

            public function verify(int $tenantId, int $profileId, string $purpose, ?string $code): ?string
            {
                $this->test->verified[] = $profileId . '@' . $purpose;
                return $code === WorkspaceOwnersApiRealEngineTest::GOOD_CODE ? self::TOTP : null;
            }
        };
        $this->api = new WorkspaceOwnersApiHandler(
            new WorkspaceOwnership($this->pdo),
            new TokenValidator(new JwtParser('test_jwt_secret_min_32_chars_for_hs256_zz'), $this->pdo),
            new GatedApproval($identity),
        );
        TenantContext::reset();
        TenantContext::setTenantId($this->tenantId);
        RoleChecker::clearCache();
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testAnOfferNeedsTheCode(): void
    {
        $body = ['to_profile_id' => $this->awss, 'kind' => 'transfer'];

        self::assertSame(200, $this->api->offerIdentityCheck($this->as($this->amro, $body))->getStatusCode());
        $refused = $this->api->offer($this->as($this->amro, $body + ['code' => '000000']));
        self::assertSame(403, $refused->getStatusCode());
        self::assertSame([], (new WorkspaceOwnership($this->pdo))->openForTenant($this->tenantId));

        $made = $this->api->offer($this->as($this->amro, $body + ['code' => self::GOOD_CODE]));
        self::assertSame(201, $made->getStatusCode());
        self::assertContains(
            $this->amro . '@' . GatedApproval::purpose(GatedApproval::WORKSPACE_OWNERSHIP, 'offer-' . $this->tenantId . '-' . $this->awss . '-transfer'),
            $this->verified
        );
    }

    public function testOnlyAnOwnerCanOffer(): void
    {
        $res = $this->api->offerIdentityCheck($this->as($this->awss, ['to_profile_id' => $this->ahmad, 'kind' => 'co_owner']));

        self::assertSame(403, $res->getStatusCode());
        self::assertSame('not_owner', $this->json($res)['details']['code'] ?? $this->json($res)['code'] ?? null);
    }

    public function testOnlyThePersonOfferedSeesAndAcceptsIt(): void
    {
        $offerId = $this->offer($this->awss, 'transfer');

        foreach ([$this->amro, $this->ahmad] as $other) {
            self::assertSame(404, $this->api->showMine($this->as($other), ['id' => (string) $offerId])->getStatusCode());
            self::assertSame(404, $this->api->accept($this->as($other, ['code' => self::GOOD_CODE]), ['id' => (string) $offerId])->getStatusCode());
        }
        self::assertSame(200, $this->api->showMine($this->as($this->awss), ['id' => (string) $offerId])->getStatusCode());
        self::assertSame([$offerId], array_column($this->json($this->api->mine($this->as($this->awss)))['data'], 'id'));
    }

    public function testAcceptingNeedsTheCodeBoundToThatOffer(): void
    {
        $offerId = $this->offer($this->awss, 'transfer');

        $wrong = $this->api->accept($this->as($this->awss, ['code' => '000000']), ['id' => (string) $offerId]);
        self::assertSame(403, $wrong->getStatusCode());
        self::assertFalse((new WorkspaceOwnership($this->pdo))->owners()->isOwner($this->tenantId, $this->awss));

        $ok = $this->api->accept($this->as($this->awss, ['code' => self::GOOD_CODE]), ['id' => (string) $offerId]);
        self::assertSame(200, $ok->getStatusCode());
        self::assertSame('accepted', $this->json($ok)['data']['status']);
        self::assertContains(
            $this->awss . '@' . GatedApproval::purpose(GatedApproval::WORKSPACE_OWNERSHIP, 'accept-' . $offerId),
            $this->verified
        );

        $owners = $this->json($this->api->list($this->as($this->awss)))['data'];
        self::assertSame([$this->awss], array_column($owners['owners'], 'profile_id'));
        self::assertTrue($owners['is_owner']);
    }

    public function testMembersSeeOwnersButNotOffers(): void
    {
        $this->offer($this->awss, 'co_owner');

        $asMember = $this->json($this->api->list($this->as($this->ahmad)))['data'];
        self::assertSame([$this->amro], array_column($asMember['owners'], 'profile_id'));
        self::assertSame([], $asMember['offers']);
        self::assertSame([], $asMember['candidates']);
        self::assertFalse($asMember['is_owner']);

        $asOwner = $this->json($this->api->list($this->as($this->amro)))['data'];
        self::assertCount(1, $asOwner['offers']);
        // Awss has an offer waiting, so only Ahmad can be offered now.
        self::assertSame([$this->ahmad], array_column($asOwner['candidates'], 'profile_id'));
    }

    public function testTheSystemWorkspaceHasNoOwners(): void
    {
        TenantContext::reset();
        TenantContext::setTenantId(0);
        self::assertSame(422, $this->api->list($this->as($this->amro))->getStatusCode());
    }

    private function offer(int $to, string $kind): int
    {
        $res = $this->api->offer($this->as($this->amro, ['to_profile_id' => $to, 'kind' => $kind, 'code' => self::GOOD_CODE]));
        self::assertSame(201, $res->getStatusCode(), (string) $res->getBody());
        return (int) $this->json($res)['data']['id'];
    }

    /** @param array<string, mixed> $body */
    private function as(int $profileId, array $body = []): Request
    {
        $request = new Request('POST', '/api/workspace/owners', ['Content-Type' => 'application/json'], $body === [] ? '' : (string) json_encode($body));
        $request->user = (object) ['profile_id' => $profileId];
        return $request;
    }

    /** @return array<string, mixed> */
    private function json(\Whity\Sdk\Http\Response $response): array
    {
        return (array) json_decode((string) $response->getBody(), true);
    }

    private function member(string $name): int
    {
        $profileId = $this->profile($name);
        $stmt = $this->pdo->prepare("SELECT id FROM roles WHERE name = 'admin' AND tenant_id IS NULL");
        $stmt->execute();
        $this->pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, is_primary, status, created_at)
             VALUES (:p, :t, :r, NULL, true, 'active', NOW())"
        )->execute([':p' => $profileId, ':t' => $this->tenantId, ':r' => (int) $stmt->fetchColumn()]);
        return $profileId;
    }

    private function profile(string $name): int
    {
        $this->pdo->prepare(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled, two_factor_secret,
                 two_factor_backup_codes_version, token_epoch, status, created_at, updated_at)
             VALUES (:n, '', false, NULL, 0, 0, 'active', NOW(), NOW())"
        )->execute([':n' => $name]);
        $id = (int) $this->pdo->lastInsertId();
        (new ProfileEmailRepository($this->pdo))->insert($id, 'p' . $id . '@example.test', true, true);
        return $id;
    }
}
