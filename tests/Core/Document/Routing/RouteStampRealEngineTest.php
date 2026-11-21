<?php

declare(strict_types=1);

namespace Tests\Core\Document\Routing;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\TotpService;
use Whity\Core\Audience\ExplicitRuleResolver;
use Whity\Core\Document\Routing\DocumentRouter;
use Whity\Core\Document\Routing\DocumentRouteSubject;
use Whity\Core\Document\Routing\RoleBelowActorRuleResolver;
use Whity\Core\Document\Routing\RoleRuleResolver;
use Whity\Core\Document\Routing\RouteAction;
use Whity\Core\Document\Routing\RouteEdgeRepository;
use Whity\Core\Document\Routing\RouteEventRepository;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\Document\Routing\RouteRepository;
use Whity\Core\Document\Routing\RouteStepRepository;
use Whity\Core\Document\Routing\RouteSubjectRegistry;
use Whity\Core\Document\Routing\RouteSubjectVersionRepository;
use Whity\Core\Document\Routing\RouteVerdict;
use Whity\Core\Document\Routing\RoutingRejectedException;
use Whity\Core\Document\Routing\RoutingRuleRegistry;
use Whity\Core\Document\Signing\CompanyStampRepository;
use Whity\Core\Document\Signing\InstanceSealKeys;
use Whity\Core\Document\Signing\SavedSignatureRepository;
use Whity\Core\Document\Signing\SignatureRepository;
use Whity\Core\Document\Signing\SigningChallengeRepository;
use Whity\Core\Document\Signing\SigningConsent;
use Whity\Core\Document\Signing\SigningRefusedException;
use Whity\Core\Document\Signing\SigningSecondFactor;
use Whity\Core\Document\Signing\SigningService;
use Whity\Core\Document\Signing\StampImage;
use Whity\Core\Group\GroupResolver;
use Whity\Core\Group\GroupRuleResolver;
use Whity\Core\Group\UserGroupRepository;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Sdk\Hooks\Events;

/**
 * #1729: a company stamp held by named people, applied on a route's `stamp`
 * step with the same step-up as a signature, and sealed in the same table.
 *
 * Runs on SQLite, and on real PostgreSQL when PHPUNIT_PG_DSN is set
 * ({@see SchemaFromMigrations::make()}).
 */
final class RouteStampRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const OTHER_TENANT = 2;
    private const AUTHOR = 10;
    private const HOLDER = 11;
    private const NOT_HOLDER = 12;
    private const ROLE_HOLDER = 13;
    private const CLERK_ROLE = 101;
    private const ENCRYPTION_KEY = 'whity-1729-test-encryption-key-0123456789';

    /** A 40x40 PNG. */
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAACgAAAAoCAIAAAADnC86AAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAHElEQVRYhe3BAQ0AAADCoPdPbQ43oAAAAADg3wAS6AABuHYUGQAAAABJRU5ErkJggg==';

    private PDO $pdo;
    private DocumentRouter $router;
    private RouteRecipientRepository $recipients;
    private SigningChallengeRepository $challenges;
    private CompanyStampRepository $stamps;
    private SigningService $signing;

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $fired = [];

    protected function setUp(): void
    {
        $this->pdo = $this->makeSchema();
        $this->recipients = new RouteRecipientRepository($this->pdo);
        $versions = new RouteSubjectVersionRepository($this->pdo);
        $steps = new RouteStepRepository($this->pdo);

        $rules = new RoutingRuleRegistry();
        $registry = $rules;
        $rules->registerCoreRoutingRules(
            new RoleRuleResolver($this->pdo),
            new RoleBelowActorRuleResolver($this->pdo),
            new ExplicitRuleResolver(),
            new GroupRuleResolver(new GroupResolver(
                $this->pdo,
                new UserGroupRepository($this->pdo),
                static fn (): RoutingRuleRegistry => $registry
            ))
        );
        $subjects = new RouteSubjectRegistry();
        $subjects->register(new DocumentRouteSubject($this->pdo));

        $hooks = new HookManager();
        foreach ([Events::ROUTE_STAMPED, Events::ROUTE_SIGNED] as $event) {
            $hooks->listen($event, function (array $payload) use ($event): array {
                $this->fired[] = [$event, $payload];

                return $payload;
            });
        }

        $this->router = new DocumentRouter(
            $this->pdo,
            new RouteRepository($this->pdo),
            $steps,
            new RouteEventRepository($this->pdo),
            $this->recipients,
            new RouteEdgeRepository($this->pdo),
            $rules,
            new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo)),
            $hooks,
            $subjects,
            $versions,
        );

        $this->challenges = new SigningChallengeRepository($this->pdo);
        $this->stamps = new CompanyStampRepository($this->pdo);
        $this->signing = new SigningService(
            $this->pdo,
            $this->router,
            $this->recipients,
            $steps,
            $subjects,
            $versions,
            new SignatureRepository($this->pdo),
            new SavedSignatureRepository($this->pdo),
            new SigningSecondFactor($this->pdo, $this->challenges, new TotpService(self::ENCRYPTION_KEY)),
            $this->challenges,
            new InstanceSealKeys($this->pdo, new EncryptedSecretStore(['v1' => self::ENCRYPTION_KEY], 'v1')),
            null,
            $this->stamps,
        );
    }

    public function testANamedHolderAppliesTheStampAndTheRecordIsSealed(): void
    {
        $stampId = $this->seedStamp();
        $route = $this->issueStampStep($this->seedDocument(), $stampId, [self::HOLDER], 'any');

        $context = $this->signing->stampContext(self::TENANT, self::HOLDER, $route);
        self::assertTrue($context['can_stamp']);
        self::assertSame('Company stamp', $context['stamp']['name']);
        self::assertSame(SigningConsent::STAMP_CURRENT, $context['consent_version']);

        $outcome = $this->stamp(self::HOLDER, $route);

        self::assertSame(RouteAction::STAMPED, $outcome['event']['action']);
        self::assertSame(RouteVerdict::APPROVED, $outcome['decided']);
        $record = $outcome['signature'];
        self::assertSame(SignatureRepository::KIND_STAMP, $record['kind']);
        self::assertSame('Example Bakery LLC', $record['on_behalf_of']);
        self::assertSame($stampId, $record['stamp_id']);
        self::assertSame(self::PNG, $record['appearance'], 'The stamp image is copied into the record.');

        $audit = $this->signing->audit(self::TENANT, (int) $route['document_id'], true);
        self::assertTrue($audit['chain']['intact']);
        self::assertSame([], $audit['chain']['signed_without_record']);
        self::assertCount(1, $audit['signatures']);
        self::assertSame('stamp', $audit['signatures'][0]['kind']);
        self::assertTrue($audit['signatures'][0]['seal_valid']);
        self::assertSame('Example Bakery LLC', $audit['signatures'][0]['stamp']['legal_name']);
        self::assertNull($audit['signatures'][0]['appearance']);

        $fired = array_values(array_filter($this->fired, static fn (array $f): bool => $f[0] === Events::ROUTE_STAMPED));
        self::assertCount(1, $fired);
        self::assertSame($stampId, $fired[0][1]['stamp_id']);
        self::assertSame([], array_filter($this->fired, static fn (array $f): bool => $f[0] === Events::ROUTE_SIGNED));
    }

    public function testChangingTheStampLaterKeepsTheRecordAndItsSeal(): void
    {
        $stampId = $this->seedStamp();
        $route = $this->issueStampStep($this->seedDocument(), $stampId, [self::HOLDER], 'any');
        $this->stamp(self::HOLDER, $route);

        $this->stamps->update(self::TENANT, $stampId, ['name' => 'Renamed', 'legal_name' => 'Someone else']);

        $audit = $this->signing->audit(self::TENANT, (int) $route['document_id']);
        self::assertSame('Example Bakery LLC', $audit['signatures'][0]['stamp']['legal_name']);
        self::assertTrue($audit['signatures'][0]['seal_valid']);

        // Tampering with the copied names breaks the seal.
        $this->pdo->exec("UPDATE route_signatures SET stamp_legal_name = 'Forged' WHERE kind = 'stamp'");
        $audit = $this->signing->audit(self::TENANT, (int) $route['document_id']);
        self::assertFalse($audit['signatures'][0]['seal_valid']);
    }

    public function testSomeoneTheStepReachedWhoDoesNotHoldTheStampIsRefused(): void
    {
        $stampId = $this->seedStamp();
        $route = $this->issueStampStep($this->seedDocument(), $stampId, [self::NOT_HOLDER], 'any');

        self::assertFalse($this->signing->stampContext(self::TENANT, self::NOT_HOLDER, $route)['can_stamp']);
        try {
            $this->stamp(self::NOT_HOLDER, $route);
            self::fail('Only a holder applies a stamp.');
        } catch (SigningRefusedException $e) {
            self::assertSame(SigningRefusedException::NOT_HOLDER, $e->reason);
            self::assertSame(403, $e->status);
        }
        self::assertNotNull($this->recipients->findOpenForProfile(self::TENANT, (int) $route['id'], self::NOT_HOLDER));
    }

    public function testAHolderThroughARoleAppliesIt(): void
    {
        $stampId = $this->seedStamp(roles: [self::CLERK_ROLE]);
        $route = $this->issueStampStep($this->seedDocument(), $stampId, [self::ROLE_HOLDER], 'any');

        $outcome = $this->stamp(self::ROLE_HOLDER, $route);

        self::assertSame(RouteAction::STAMPED, $outcome['event']['action']);
    }

    public function testTheStampConsentAndASecondCheckAreRequired(): void
    {
        $route = $this->issueStampStep($this->seedDocument(), $this->seedStamp(), [self::HOLDER], 'any');

        foreach ([
            [['consent' => false], SigningRefusedException::CONSENT_REQUIRED],
            [['consent_version' => SigningConsent::CURRENT], SigningRefusedException::CONSENT_REQUIRED],
            [['second_factor' => null], SigningRefusedException::SECOND_FACTOR_REQUIRED],
            [['content_hash' => str_repeat('0', 64)], SigningRefusedException::CONTENT_CHANGED],
        ] as [$overrides, $reason]) {
            try {
                $this->stamp(self::HOLDER, $route, $overrides);
                self::fail("Expected '{$reason}'.");
            } catch (SigningRefusedException $e) {
                self::assertSame($reason, $e->reason);
            }
        }
    }

    public function testAStampStepIsAnsweredOnlyByStampingOrDeclining(): void
    {
        $route = $this->issueStampStep($this->seedDocument(), $this->seedStamp(), [self::HOLDER], 'all');

        foreach ([[RouteAction::STAMPED, null], [RouteAction::ACKNOWLEDGED, RouteVerdict::APPROVED], [RouteAction::SIGNED, null]] as [$action, $verdict]) {
            try {
                $this->router->act(self::TENANT, self::HOLDER, $route, $action, null, $verdict);
                self::fail("'{$action}' must not answer a stamp step.");
            } catch (RoutingRejectedException) {
            }
        }

        $declined = $this->router->act(self::TENANT, self::HOLDER, $route, RouteAction::DECLINED, 'Wrong company.');
        self::assertSame(RouteVerdict::REJECTED, $declined['decided']);
    }

    public function testAStampStepMustNameAnActiveStampOfThisWorkspace(): void
    {
        $documentId = $this->seedDocument();
        $archived = $this->seedStamp();
        $this->stamps->setArchived(self::TENANT, $archived, true);
        $foreign = $this->seedStamp(tenantId: self::OTHER_TENANT);

        foreach ([null, 999, $archived, $foreign] as $stampId) {
            try {
                $this->issueStampStep($documentId, $stampId, [self::HOLDER], 'any');
                self::fail('A stamp step needs one of this workspace\'s stamps.');
            } catch (RoutingRejectedException $e) {
                self::assertStringContainsString("'stamp_id'", $e->clientMessage);
            }
        }

        try {
            $this->router->issue(self::TENANT, self::AUTHOR, ['id' => $documentId], 'Agreement', [[
                'rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT,
                'rule_config' => ['profile_ids' => [self::HOLDER]],
                'stamp_id' => $this->seedStamp(),
            ]]);
            self::fail("'stamp_id' only belongs on a stamp step.");
        } catch (RoutingRejectedException) {
        }
    }

    public function testTheStepKeepsItsStamp(): void
    {
        $stampId = $this->seedStamp();
        $route = $this->issueStampStep($this->seedDocument(), $stampId, [self::HOLDER], 'any');

        $step = (new RouteStepRepository($this->pdo))->listForRoute((int) $route['id'], self::TENANT)[0];
        self::assertSame('stamp', $step['kind']);
        self::assertSame($stampId, $step['stamp_id']);
    }

    public function testHoldersAndImagesAreChecked(): void
    {
        self::assertTrue($this->stamps->allMembers(self::TENANT, [self::HOLDER, self::NOT_HOLDER]));
        self::assertFalse($this->stamps->allMembers(self::TENANT, [self::HOLDER, 999]));
        self::assertTrue($this->stamps->allRoles(self::TENANT, [self::CLERK_ROLE]));
        self::assertFalse($this->stamps->allRoles(self::TENANT, [999]));

        self::assertSame(self::PNG, StampImage::normalise(self::PNG));
        foreach (['', 'data:image/gif;base64,R0lGODlhAQABAAAAACw=', 'data:image/jpeg;base64,' . substr(self::PNG, 22), 'data:image/png;base64,bm90IGFuIGltYWdl'] as $bad) {
            try {
                StampImage::normalise($bad);
                self::fail('Expected the image to be refused.');
            } catch (\InvalidArgumentException) {
            }
        }
    }

    /**
     * @param array<string, mixed> $route
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function stamp(int $holder, array $route, array $overrides = []): array
    {
        $input = $overrides + [
            'content_hash' => $this->signing->stampContext(self::TENANT, $holder, $route)['subject']['content_hash'] ?? '',
            'consent' => true,
            'consent_version' => SigningConsent::STAMP_CURRENT,
        ];
        if (!array_key_exists('second_factor', $overrides)) {
            $input['second_factor'] = ['method' => 'email_code', 'code' => $this->codeFor($holder, $route)];
        }

        return $this->signing->stamp(self::TENANT, $holder, $route, $input, '203.0.113.7', 'TestBrowser/1.0');
    }

    /** @param array<string, mixed> $route */
    private function codeFor(int $holder, array $route): ?string
    {
        $hash = $this->signing->stampContext(self::TENANT, $holder, $route)['subject']['content_hash'] ?? '';

        return $this->challenges->issueEmailCode(self::TENANT, $holder, (int) $route['id'], (string) $hash);
    }

    /**
     * @param list<int> $profiles
     * @return array<string, mixed>
     */
    private function issueStampStep(int $documentId, ?int $stampId, array $profiles, string $quorum): array
    {
        return $this->router->issue(self::TENANT, self::AUTHOR, ['id' => $documentId], 'Supply agreement', [[
            'rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT,
            'rule_config' => ['profile_ids' => $profiles],
            'kind' => 'stamp',
            'stamp_id' => $stampId,
            'decision_quorum' => $quorum,
        ]])['route'];
    }

    /** @param list<int> $roles */
    private function seedStamp(array $roles = [], int $tenantId = self::TENANT): int
    {
        $id = $this->stamps->create($tenantId, 'Company stamp', 'Example Bakery LLC', self::PNG, self::AUTHOR);
        $this->stamps->setHolders($tenantId, $id, $roles === [] ? [self::HOLDER] : [], $roles);

        return $id;
    }

    private function seedDocument(): int
    {
        $now = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? "datetime('now')" : 'NOW()';
        $this->pdo->exec(
            'INSERT INTO documents (tenant_id, document_template_id, template_name, title, origin_ou_id, created_by, created_at)
             VALUES (1, NULL, ' . $this->pdo->quote('Agreement') . ', ' . $this->pdo->quote('Supply agreement')
             . ', 20, ' . self::AUTHOR . ', ' . $now . ')'
        );

        return (int) $this->pdo->lastInsertId();
    }

    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make();
        $quote = static fn (string $v): string => $pdo->quote($v);
        $now = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? "datetime('now')" : 'NOW()';

        $pdo->exec('INSERT INTO tenants (id, name) VALUES (1, ' . $quote('Example Bakery') . '), (2, ' . $quote('Other') . ') ON CONFLICT DO NOTHING');
        $pdo->exec(
            'INSERT INTO organizational_units (id, tenant_id, parent_id, name, slug, created_at) VALUES
                (20, 1, NULL, ' . $quote('Kitchen') . ', ' . $quote('kitchen') . ', ' . $now . ')'
        );
        $pdo->exec(
            'INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES
                (100, ' . $quote('member') . ', ' . $quote('') . ', 1, ' . $now . '),
                (101, ' . $quote('clerk') . ', ' . $quote('') . ', 1, ' . $now . ')'
        );
        foreach ([[10, 'author', 100], [11, 'holder', 100], [12, 'not holder', 100], [13, 'clerk', 101]] as [$id, $name, $role]) {
            $pdo->exec(
                'INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                                       two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                 VALUES (' . $id . ', ' . $quote($name) . ', ' . $quote('x') . ', false, 0, 0, ' . $now . ', ' . $now . ')'
            );
            $pdo->exec(
                'INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at) VALUES ('
                . $id . ', ' . $quote(str_replace(' ', '.', $name) . '@example.test') . ', true, true, ' . $now . ')'
            );
            $pdo->exec(
                "INSERT INTO memberships (id, profile_id, tenant_id, role_id, ou_id, is_primary, status, created_at) VALUES
                    (" . (1000 + $id) . ", {$id}, 1, {$role}, 20, true, 'active', {$now})"
            );
        }

        return $pdo;
    }
}
