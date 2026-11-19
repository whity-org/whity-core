<?php

declare(strict_types=1);

namespace Tests\Core\Document\Routing;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Audience\ExplicitRuleResolver;
use Whity\Core\Delegation\AwayService;
use Whity\Core\Document\Routing\DocumentRouter;
use Whity\Core\Document\Routing\DocumentRoutingInboxSource;
use Whity\Core\Document\Routing\RouteAction;
use Whity\Core\Document\Routing\RouteEdgeRepository;
use Whity\Core\Document\Routing\RouteEventRepository;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\Document\Routing\RouteRepository;
use Whity\Core\Document\Routing\RouteStepRepository;
use Whity\Core\Document\Routing\RouteTrailChain;
use Whity\Core\Document\Routing\RouteVerdict;
use Whity\Core\Document\Routing\RoleBelowActorRuleResolver;
use Whity\Core\Document\Routing\RoleRuleResolver;
use Whity\Core\Document\Routing\RoutingRejectedException;
use Whity\Core\Document\Routing\RoutingRuleRegistry;
use Whity\Core\Group\GroupResolver;
use Whity\Core\Group\GroupRuleResolver;
use Whity\Core\Group\UserGroupRepository;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;

/**
 * Route steps read "I'm away" (merge map A2, migration 292).
 *
 * Amro (11) approves budget requests. He is away and named Awss (12) to decide
 * his approvals. Awss then finds Amro's items in his own Waiting for me, can
 * read the document, and decides it; the step counts it as Amro's answer and
 * the trail says "Awss for Amro". A signature stays Amro's. Emad (13) is not
 * covering anyone and gets nothing.
 */
final class RouteAwayRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const SENDER = 10;
    private const AMRO = 11;
    private const AWSS = 12;
    private const EMAD = 13;
    private const TECH = 14;

    private PDO $pdo;
    private DocumentRouter $router;
    private RouteRecipientRepository $recipients;
    private RouteEventRepository $events;

    protected function setUp(): void
    {
        $this->pdo = $this->makeSchema();
        $this->events = new RouteEventRepository($this->pdo);
        $this->recipients = new RouteRecipientRepository($this->pdo);
        $rules = new RoutingRuleRegistry();
        $registry = $rules;
        $rules->registerCoreRoutingRules(
            new RoleRuleResolver($this->pdo),
            new RoleBelowActorRuleResolver($this->pdo),
            new ExplicitRuleResolver(),
            new GroupRuleResolver(new GroupResolver($this->pdo, new UserGroupRepository($this->pdo), static fn (): RoutingRuleRegistry => $registry)),
        );
        $this->router = new DocumentRouter(
            $this->pdo,
            new RouteRepository($this->pdo),
            new RouteStepRepository($this->pdo),
            $this->events,
            $this->recipients,
            new RouteEdgeRepository($this->pdo),
            $rules,
            new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo)),
            null,
        );
    }

    public function testTheDelegateDecidesForThePersonAwayAndTheTrailSaysSo(): void
    {
        $this->away(['flows']);
        $documentId = $this->seedDocument();
        $route = $this->issueGate($documentId);

        $this->router->act(self::TENANT, self::AWSS, $route, RouteAction::ACKNOWLEDGED, 'Fine by me', RouteVerdict::APPROVED);

        $decided = array_values(array_filter(
            $this->events->listForRoute((int) $route['id'], self::TENANT),
            static fn (array $e): bool => $e['verdict'] !== null,
        ));
        self::assertCount(1, $decided);
        self::assertSame([self::AWSS, self::AMRO, 'approved'], [$decided[0]['actor_profile_id'], $decided[0]['on_behalf_of_profile_id'], $decided[0]['verdict']]);
        self::assertNull($this->recipients->findOpenForProfile(self::TENANT, (int) $route['id'], self::AMRO), "Amro's item is answered");
        self::assertNotNull($this->recipients->findOpenForProfile(self::TENANT, (int) $route['id'], self::TECH), 'the approval moved it on');
        self::assertTrue(RouteTrailChain::verify($this->pdo, self::TENANT, $documentId)['intact'], 'the trail still verifies with the new fact in it');
    }

    public function testTheDelegateSeesTheItemsAndReadsTheDocumentOnlyWhileCovering(): void
    {
        $documentId = $this->seedDocument();
        $route = $this->issueGate($documentId);
        $inbox = new DocumentRoutingInboxSource($this->recipients);

        self::assertSame(0, $inbox->count(self::TENANT, self::AWSS, true));
        self::assertFalse($this->recipients->hasAnyForProfile(self::TENANT, $documentId, self::AWSS));

        $this->away(['flows']);
        $items = $inbox->list(self::TENANT, self::AWSS, true, 10, 0);
        self::assertSame(1, $inbox->count(self::TENANT, self::AWSS, true));
        self::assertCount(1, $items);
        self::assertSame(['profile_id' => self::AMRO, 'name' => 'Amro Saleh'], $items[0]->meta['on_behalf_of']);
        self::assertTrue($this->recipients->hasAnyForProfile(self::TENANT, $documentId, self::AWSS));

        self::assertSame(0, $inbox->count(self::TENANT, self::EMAD, true), 'someone not covering sees nothing');
        self::assertFalse($this->recipients->hasAnyForProfile(self::TENANT, $documentId, self::EMAD));

        // Having acted for Amro, Awss can still read what he decided.
        $this->router->act(self::TENANT, self::AWSS, $route, RouteAction::ACKNOWLEDGED, null, RouteVerdict::APPROVED);
        $this->pdo->exec('UPDATE away_periods SET ended_at = CURRENT_TIMESTAMP');
        self::assertTrue($this->recipients->hasAnyForProfile(self::TENANT, $documentId, self::AWSS));
        self::assertSame(0, $inbox->count(self::TENANT, self::AWSS, true));
    }

    /**
     * #2221: an away period only works while both people are still active
     * staff. Suspending the person away, or the delegate, stops the cover at
     * once, without anybody ending the period.
     */
    public function testCoverStopsWhenEitherPersonIsNoLongerActiveStaff(): void
    {
        $this->away(['flows']);
        $documentId = $this->seedDocument();
        $this->issueGate($documentId);
        $inbox = new DocumentRoutingInboxSource($this->recipients);
        self::assertSame(1, $inbox->count(self::TENANT, self::AWSS, true), 'precondition: Awss covers Amro');

        foreach ([self::AMRO, self::AWSS] as $gone) {
            $this->pdo->exec("UPDATE memberships SET status = 'suspended' WHERE profile_id = {$gone}");
            self::assertSame(0, $inbox->count(self::TENANT, self::AWSS, true), "no cover while {$gone} is suspended");
            self::assertFalse($this->recipients->hasAnyForProfile(self::TENANT, $documentId, self::AWSS));
            $this->pdo->exec("UPDATE memberships SET status = 'active' WHERE profile_id = {$gone}");
        }

        $this->pdo->exec("UPDATE memberships SET kind = 'customer' WHERE profile_id = " . self::AWSS);
        self::assertSame(0, $inbox->count(self::TENANT, self::AWSS, true), 'a customer covers nobody');
    }

    public function testOnlyAnAwayPeriodThatCoversApprovalsCounts(): void
    {
        $this->away(['proposals']);
        $route = $this->issueGate($this->seedDocument());

        $this->expectException(RoutingRejectedException::class);
        $this->router->act(self::TENANT, self::AWSS, $route, RouteAction::ACKNOWLEDGED, null, RouteVerdict::APPROVED);
    }

    public function testASignatureWaitsForThePersonItIsFor(): void
    {
        $this->away(['flows', 'documents']);
        $route = $this->issueGate($this->seedDocument());

        try {
            $this->router->act(self::TENANT, self::AWSS, $route, RouteAction::DECLINED, 'Not mine to sign');
            self::fail('a delegate must not answer a signature');
        } catch (RoutingRejectedException $e) {
            self::assertStringContainsString('Only the person it is for can sign', $e->getMessage());
        }
    }

    /** @param list<string> $covers */
    private function away(array $covers): void
    {
        $period = (new AwayService($this->pdo))->start(
            self::TENANT,
            self::AMRO,
            self::AWSS,
            gmdate('Y-m-d'),
            gmdate('Y-m-d', time() + 3 * 86400),
            $covers,
            null,
        );
        self::assertIsArray($period);
    }

    /** @return array<string, mixed> */
    private function issueGate(int $documentId): array
    {
        return $this->router->issue(self::TENANT, self::SENDER, ['id' => $documentId], 'Budget approval', [
            [
                'rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT,
                'rule_config' => ['profile_ids' => [self::AMRO]],
                'decision' => true,
            ],
            [
                'rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT,
                'rule_config' => ['profile_ids' => [self::TECH]],
            ],
        ])['route'];
    }

    private function seedDocument(): int
    {
        $this->pdo->exec(
            'INSERT INTO documents (tenant_id, document_template_id, template_name, title, origin_ou_id, created_by, created_at)
             VALUES (1, NULL, ' . $this->pdo->quote('Circular') . ', ' . $this->pdo->quote('Budget request') . ', 20, 10, ' . $this->now() . ')'
        );

        return (int) $this->pdo->lastInsertId();
    }

    private function now(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? "datetime('now')" : 'NOW()';
    }

    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make();
        $quote = static fn (string $v): string => $pdo->quote($v);
        $now = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? "datetime('now')" : 'NOW()';

        $pdo->exec('INSERT INTO tenants (id, name) VALUES (1, ' . $quote('Example Bakery') . ') ON CONFLICT DO NOTHING');
        $pdo->exec('INSERT INTO organizational_units (id, tenant_id, parent_id, name, slug, created_at) VALUES (20, 1, NULL, '
            . $quote('Bakery') . ', ' . $quote('bakery') . ', ' . $now . ')');
        $pdo->exec('INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES (100, ' . $quote('staff') . ', ' . $quote('') . ', 1, ' . $now . ')');

        $people = [10 => 'Ahmad Khalil', 11 => 'Amro Saleh', 12 => 'Awss Haddad', 13 => 'Emad Nasser', 14 => 'Ibrahim Mansour'];
        foreach ($people as $id => $name) {
            $pdo->exec(
                'INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                 VALUES (' . $id . ', ' . $quote($name) . ', ' . $quote('x') . ', false, 0, 0, ' . $now . ', ' . $now . ')'
            );
            $pdo->exec(
                'INSERT INTO memberships (id, profile_id, tenant_id, role_id, ou_id, is_primary, status, created_at)
                 VALUES (' . (1000 + $id) . ', ' . $id . ", 1, 100, 20, true, 'active', " . $now . ')'
            );
        }

        return $pdo;
    }
}
