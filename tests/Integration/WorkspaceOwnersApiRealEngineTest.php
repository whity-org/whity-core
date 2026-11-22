<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\UsersApiHandler;
use Whity\Api\WorkspaceOwnersApiHandler;
use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;
use Whity\Auth\TokenValidator;
use Whity\Core\Document\Signing\SigningChallengeRepository;
use Whity\Core\Hooks\HookManager;
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

    public function testRemovingAnOwnerNeedsTheCodeAndNeverTheLastOne(): void
    {
        $refused = $this->api->removeIdentityCheck($this->as($this->amro), ['profileId' => (string) $this->amro]);
        self::assertSame(409, $refused->getStatusCode());
        self::assertSame('last_owner', $this->json($refused)['details']['code'] ?? null);

        $offerId = $this->offer($this->awss, 'co_owner');
        $this->api->accept($this->as($this->awss, ['code' => self::GOOD_CODE]), ['id' => (string) $offerId]);

        self::assertSame(403, $this->api->removeIdentityCheck($this->as($this->ahmad), ['profileId' => (string) $this->awss])->getStatusCode());
        self::assertSame(200, $this->api->removeIdentityCheck($this->as($this->amro), ['profileId' => (string) $this->awss])->getStatusCode());
        self::assertSame(403, $this->api->removeOwner($this->as($this->amro, ['code' => '000000']), ['profileId' => (string) $this->awss])->getStatusCode());
        self::assertSame(204, $this->api->removeOwner($this->as($this->amro, ['code' => self::GOOD_CODE]), ['profileId' => (string) $this->awss])->getStatusCode());
        self::assertContains(
            $this->amro . '@' . GatedApproval::purpose(GatedApproval::WORKSPACE_OWNERSHIP, 'remove-' . $this->tenantId . '-' . $this->awss),
            $this->verified
        );
        self::assertSame([$this->amro], array_column($this->json($this->api->list($this->as($this->amro)))['data']['owners'], 'profile_id'));
    }

    public function testPeopleWontRemoveAnOwner(): void
    {
        $users = new UsersApiHandler($this->pdo, new HookManager());
        $request = $this->as($this->ahmad);

        $res = $users->delete($request, ['id' => (string) $this->amro]);

        self::assertSame(409, $res->getStatusCode());
        self::assertSame('is_owner', $this->json($res)['details']['code'] ?? null);
        self::assertTrue((new WorkspaceOwnership($this->pdo))->isStaff($this->tenantId, $this->amro));
        // Anyone else can still be removed.
        self::assertSame(200, $users->delete($request, ['id' => (string) $this->awss])->getStatusCode());
    }

    /**
     * Found on app.whity.dev on 2026-11-22: an owner who signs in only through Whity ID (no
     * password, no authenticator) confirms by signing in there again. The approval sends no code;
     * the fresh sign-in recorded on return for exactly this offer is what lets it through, once,
     * within five minutes. The callback that records it is covered in SsoAuthHandlerRealEngineTest.
     */
    public function testAnOwnerWhoSignsInOnlyThroughWhityIdOffersAfterSigningInAgain(): void
    {
        $api = $this->withRealCheck($this->amro);
        $body = ['to_profile_id' => $this->awss, 'kind' => 'co_owner'];

        $started = $this->json($api->offerIdentityCheck($this->as($this->amro, $body)))['data'] ?? [];
        self::assertSame('fresh_sign_in', $started['method'] ?? null);
        self::assertSame('whity_id', $started['provider_key'] ?? null);

        $before = $api->offer($this->as($this->amro, $body + ['code' => '']));
        self::assertSame(403, $before->getStatusCode(), 'not before signing in again');
        self::assertSame('identity_check_failed', $this->json($before)['details']['reason'] ?? null);

        $this->signedInAgain($this->amro, (string) $started['purpose']);
        $made = $api->offer($this->as($this->amro, $body + ['code' => '']));
        self::assertSame(201, $made->getStatusCode(), (string) $made->getBody());
        self::assertSame('co_owner', $this->json($made)['data']['kind'] ?? null);
    }

    public function testAFreshSignInForOneOwnerActionDoesNotApproveAnother(): void
    {
        $api = $this->withRealCheck($this->amro);
        $toAwss = ['to_profile_id' => $this->awss, 'kind' => 'co_owner'];
        $started = $this->json($api->offerIdentityCheck($this->as($this->amro, $toAwss)))['data'] ?? [];
        $this->signedInAgain($this->amro, (string) $started['purpose']);

        $other = $api->offer($this->as($this->amro, ['to_profile_id' => $this->ahmad, 'kind' => 'co_owner', 'code' => '']));
        self::assertSame(403, $other->getStatusCode(), 'another person');
        $handover = $api->offer($this->as($this->amro, ['to_profile_id' => $this->awss, 'kind' => 'transfer', 'code' => '']));
        self::assertSame(403, $handover->getStatusCode(), 'another kind');

        $this->pdo->exec("UPDATE signing_challenges SET expires_at = '2000-01-01 00:00:00'");
        self::assertSame(403, $api->offer($this->as($this->amro, $toAwss + ['code' => '']))->getStatusCode(), 'after five minutes');
    }

    public function testRemovingAnOwnerAndAcceptingWorkTheSameWayAfterSigningInAgain(): void
    {
        $api = $this->withRealCheck($this->amro, $this->awss);
        $toAwss = ['to_profile_id' => $this->awss, 'kind' => 'co_owner'];
        $this->signedInAgain($this->amro, (string) ($this->json($api->offerIdentityCheck($this->as($this->amro, $toAwss)))['data']['purpose'] ?? ''));
        $offerId = (int) $this->json($api->offer($this->as($this->amro, $toAwss + ['code' => ''])))['data']['id'];

        // Accepting: Awss signs in again too.
        $accept = $this->json($api->acceptIdentityCheck($this->as($this->awss), ['id' => (string) $offerId]))['data'] ?? [];
        self::assertSame('fresh_sign_in', $accept['method'] ?? null);
        self::assertSame(403, $api->accept($this->as($this->awss, ['code' => '']), ['id' => (string) $offerId])->getStatusCode());
        $this->signedInAgain($this->awss, (string) $accept['purpose']);
        self::assertSame(200, $api->accept($this->as($this->awss, ['code' => '']), ['id' => (string) $offerId])->getStatusCode());

        // Removing: Amro's sign-in for the offer does not count; a new one for this does.
        $remove = $this->json($api->removeIdentityCheck($this->as($this->amro), ['profileId' => (string) $this->awss]))['data'] ?? [];
        self::assertSame(403, $api->removeOwner($this->as($this->amro, ['code' => '']), ['profileId' => (string) $this->awss])->getStatusCode());
        $this->signedInAgain($this->amro, (string) $remove['purpose']);
        self::assertSame(204, $api->removeOwner($this->as($this->amro, ['code' => '']), ['profileId' => (string) $this->awss])->getStatusCode());
    }

    /** The owners API with the real check, for people who sign in only through Whity ID. */
    private function withRealCheck(int ...$idpOnly): WorkspaceOwnersApiHandler
    {
        foreach ($idpOnly as $profileId) {
            $this->pdo->exec("UPDATE profiles SET auth_method = 'idp' WHERE id = " . $profileId);
            $this->pdo->exec("INSERT INTO external_identities (profile_id, provider_id, provider_key, issuer, subject, last_login_at)
                VALUES ({$profileId}, NULL, 'whity_id', 'https://id.whity.test', 'sub-{$profileId}', NOW())");
        }

        return new WorkspaceOwnersApiHandler(
            new WorkspaceOwnership($this->pdo),
            new TokenValidator(new JwtParser('test_jwt_secret_min_32_chars_for_hs256_zz'), $this->pdo),
            new GatedApproval(new IdentityCheck($this->pdo, new SigningChallengeRepository($this->pdo))),
        );
    }

    /** What the SSO callback records when this person comes back from signing in again for `$purpose`. */
    private function signedInAgain(int $profileId, string $purpose): void
    {
        self::assertNotSame('', $purpose);
        (new SigningChallengeRepository($this->pdo))->recordGateFreshSignIn(IdentityCheck::FRESH_SIGN_IN_TENANT, $profileId, IdentityCheck::key($purpose));
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
