<?php

declare(strict_types=1);

namespace Tests\Core\Document\Routing;

use Database\Migrations\AddRouteSubjectsAndStepKinds;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Audience\ExplicitRuleResolver;
use Whity\Core\Document\Routing\DocumentRouter;
use Whity\Core\Document\Routing\DocumentRouteSubject;
use Whity\Core\Document\Routing\RoleBelowActorRuleResolver;
use Whity\Core\Document\Routing\RoleRuleResolver;
use Whity\Core\Document\Routing\RouteEdgeRepository;
use Whity\Core\Document\Routing\RouteEventRepository;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\Document\Routing\RouteRepository;
use Whity\Core\Document\Routing\RouteSatisfaction;
use Whity\Core\Document\Routing\RouteStepKind;
use Whity\Core\Document\Routing\RouteStepRepository;
use Whity\Core\Document\Routing\RouteSubjectRegistry;
use Whity\Core\Document\Routing\RoutingPresenter;
use Whity\Core\Document\Routing\RoutingRuleRegistry;
use Whity\Core\Group\GroupResolver;
use Whity\Core\Group\GroupRuleResolver;
use Whity\Core\Group\UserGroupRepository;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Database\Database;

/**
 * WHIT-918 (#1722): routes name a subject, and steps name their kind, with no
 * change to how any route behaves.
 *
 * Runs on SQLite, and on real PostgreSQL when PHPUNIT_PG_DSN is set
 * ({@see SchemaFromMigrations::make()}). The backfill test re-runs migration 224
 * and therefore needs the real engine, where `ADD COLUMN IF NOT EXISTS` is native.
 */
final class RouteSubjectsAndKindsRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const RAISER = 10;
    private const HEAD = 11;
    private const STAFF = 12;

    private PDO $pdo;
    private DocumentRouter $router;
    private RouteRepository $routes;
    private RouteStepRepository $steps;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 4) . '/database/migrations/224_add_route_subjects_and_step_kinds.php';

        $this->pdo = $this->makeSchema();
        $this->routes = new RouteRepository($this->pdo);
        $this->steps = new RouteStepRepository($this->pdo);

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

        $this->router = new DocumentRouter(
            $this->pdo,
            $this->routes,
            $this->steps,
            new RouteEventRepository($this->pdo),
            new RouteRecipientRepository($this->pdo),
            new RouteEdgeRepository($this->pdo),
            $rules,
            new SettingsService(
                new GlobalSettingsRepository($this->pdo),
                new TenantSettingsRepository($this->pdo)
            ),
            null
        );
    }

    public function testARouteOnADocumentTravelsOnThatDocument(): void
    {
        $documentId = $this->seedDocument();
        $route = $this->issueThreeKinds($documentId);

        self::assertSame(DocumentRouteSubject::TYPE, $route['subject_type']);
        self::assertSame($documentId, $route['subject_id']);

        $stored = $this->rows('SELECT subject_type, subject_id FROM document_routes WHERE id = ' . (int) $route['id'])[0] ?? null;
        self::assertIsArray($stored);
        self::assertSame('document', (string) $stored['subject_type']);
        self::assertSame($documentId, (int) $stored['subject_id']);

        $listed = $this->routes->listForSubject(DocumentRouteSubject::TYPE, $documentId, self::TENANT);
        self::assertSame([(int) $route['id']], array_map(static fn (array $r): int => (int) $r['id'], $listed));
        self::assertSame([], $this->routes->listForSubject('form_submission', $documentId, self::TENANT));
        self::assertSame([], $this->routes->listForSubject(DocumentRouteSubject::TYPE, $documentId, 2), 'Another tenant sees nothing.');

        $presented = RoutingPresenter::route($route, $this->steps->listForRoute((int) $route['id'], self::TENANT));
        self::assertSame(['type' => 'document', 'id' => $documentId], $presented['subject']);
        self::assertSame($documentId, $presented['document_id'], 'document_id is still published.');
    }

    public function testEachStepStoresTheKindItsOldColumnsDescribe(): void
    {
        $route = $this->issueThreeKinds($this->seedDocument());

        $stored = array_column(
            $this->rows('SELECT kind FROM document_route_steps WHERE route_id = ' . (int) $route['id'] . ' ORDER BY position'),
            'kind'
        );
        self::assertSame([RouteStepKind::REVIEW, RouteStepKind::DECIDE, RouteStepKind::DELIVER], array_map('strval', $stored));

        $read = $this->steps->listForRoute((int) $route['id'], self::TENANT);
        self::assertSame(['review', 'decide', 'deliver'], array_column($read, 'kind'));

        $presented = RoutingPresenter::route($route, $read);
        self::assertSame(['review', 'decide', 'deliver'], array_column($presented['steps'], 'kind'));
    }

    public function testARowWrittenWithoutAKindReadsAsTheStepTheEngineTreatsItAs(): void
    {
        $route = $this->issueThreeKinds($this->seedDocument());
        // A fixture or seeder that never heard of `kind` leaves the default.
        $this->pdo->exec("UPDATE document_route_steps SET kind = 'review' WHERE route_id = " . (int) $route['id']);

        $read = $this->steps->listForRoute((int) $route['id'], self::TENANT);
        self::assertSame(['review', 'decide', 'deliver'], array_column($read, 'kind'));
    }

    public function testTheKindsTheOldColumnsCannotExpressAreReadAsStored(): void
    {
        $route = $this->issueThreeKinds($this->seedDocument());
        $this->pdo->exec(
            "UPDATE document_route_steps SET kind = 'sign' WHERE route_id = " . (int) $route['id'] . ' AND position = 1'
        );

        $read = $this->steps->listForRoute((int) $route['id'], self::TENANT);
        self::assertSame(RouteStepKind::SIGN, $read[0]['kind']);
    }

    public function testTheKindColumnRefusesAWordOutsideTheVocabulary(): void
    {
        $route = $this->issueThreeKinds($this->seedDocument());

        $this->expectException(\PDOException::class);
        $this->pdo->exec("UPDATE document_route_steps SET kind = 'teleport' WHERE route_id = " . (int) $route['id']);
    }

    public function testDeriveMatchesTheMigrationBackfill(): void
    {
        self::assertSame('decide', RouteStepKind::derive(true, RouteSatisfaction::ACT));
        self::assertSame('deliver', RouteStepKind::derive(false, RouteSatisfaction::DELIVERY));
        self::assertSame('review', RouteStepKind::derive(false, RouteSatisfaction::ACT));
    }

    public function testMigrationBackfillsRoutesAndStepsWrittenBeforeIt(): void
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            self::markTestSkipped('Re-running migration 224 needs native ADD COLUMN IF NOT EXISTS (PostgreSQL).');
        }

        $documentId = $this->seedDocument();
        $route = $this->issueThreeKinds($documentId);
        $routeId = (int) $route['id'];
        // As a row from before migration 224 looks.
        $this->pdo->exec("UPDATE document_routes SET subject_type = NULL, subject_id = NULL WHERE id = {$routeId}");
        $this->pdo->exec("UPDATE document_route_steps SET kind = 'review' WHERE route_id = {$routeId}");

        $pdo = $this->pdo;
        AddRouteSubjectsAndStepKinds::up(Database::withFactory(static fn (): PDO => $pdo));

        $row = $this->rows("SELECT subject_type, subject_id FROM document_routes WHERE id = {$routeId}")[0] ?? null;
        self::assertIsArray($row);
        self::assertSame('document', $row['subject_type']);
        self::assertSame($documentId, (int) $row['subject_id']);

        $kinds = array_column($this->rows("SELECT kind FROM document_route_steps WHERE route_id = {$routeId} ORDER BY position"), 'kind');
        self::assertSame(['review', 'decide', 'deliver'], $kinds);
    }

    public function testTheRegistryKnowsDocumentsAndRefusesBadOrDuplicateTypes(): void
    {
        $registry = new RouteSubjectRegistry();
        $documents = new DocumentRouteSubject($this->pdo);
        $registry->register($documents);

        self::assertSame(['document'], $registry->types());
        self::assertSame($documents, $registry->get('document'));
        self::assertNull($registry->get('form_submission'));

        $documentId = $this->seedDocument();
        self::assertSame('Ethics application', $documents->title(self::TENANT, $documentId));
        self::assertSame($documentId, $documents->documentId(self::TENANT, $documentId));
        self::assertNull($documents->title(2, $documentId), 'Another tenant\'s document is not found.');
        self::assertNull($documents->documentId(self::TENANT, $documentId + 1000));

        try {
            $registry->register(new DocumentRouteSubject($this->pdo));
            self::fail('A second provider for one type must be refused.');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);
        $registry->register(new class () implements \Whity\Core\Document\Routing\RouteSubjectProviderInterface {
            public function type(): string
            {
                return 'Not A Type';
            }

            public function title(int $tenantId, int $subjectId): ?string
            {
                return null;
            }

            public function documentId(int $tenantId, int $subjectId): ?int
            {
                return null;
            }

            public function author(int $tenantId, int $subjectId): ?int
            {
                return null;
            }

            public function snapshot(int $tenantId, int $subjectId): ?array
            {
                return null;
            }

            public function editableWhenSentBack(): bool
            {
                return false;
            }

            public function isWithdrawn(int $tenantId, int $subjectId): bool
            {
                return false;
            }
        });
    }

    /**
     * One step of each kind the old columns can express.
     *
     * @return array<string, mixed>
     */
    private function issueThreeKinds(int $documentId): array
    {
        return $this->router->issue(self::TENANT, self::RAISER, ['id' => $documentId], 'Ethics application', [
            ['rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT, 'rule_config' => ['profile_ids' => [self::HEAD]]],
            [
                'rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT,
                'rule_config' => ['profile_ids' => [self::HEAD]],
                'decision' => true,
            ],
            [
                'rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT,
                'rule_config' => ['profile_ids' => [self::STAFF]],
                'satisfied_by' => RouteSatisfaction::DELIVERY,
            ],
        ])['route'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    private function seedDocument(): int
    {
        $this->pdo->exec(
            'INSERT INTO documents (tenant_id, document_template_id, template_name, title, origin_ou_id, created_by, created_at)
             VALUES (1, NULL, ' . $this->pdo->quote('Application') . ', ' . $this->pdo->quote('Ethics application')
             . ', 20, 10, ' . $this->now() . ')'
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

        $pdo->exec('INSERT INTO tenants (id, name) VALUES (1, ' . $quote('Tenant One') . ') ON CONFLICT DO NOTHING');
        $pdo->exec('INSERT INTO tenants (id, name) VALUES (2, ' . $quote('Tenant Two') . ') ON CONFLICT DO NOTHING');
        $pdo->exec(
            'INSERT INTO organizational_units (id, tenant_id, parent_id, name, slug, created_at) VALUES
                (20, 1, NULL, ' . $quote('Faculty') . ', ' . $quote('faculty') . ', ' . $now . ')'
        );
        $pdo->exec(
            'INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES
                (100, ' . $quote('member') . ', ' . $quote('') . ', 1, ' . $now . ')'
        );
        foreach ([[10, 'raiser'], [11, 'head'], [12, 'staff']] as [$id, $name]) {
            $pdo->exec(
                'INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                                       two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                 VALUES (' . $id . ', ' . $quote($name) . ', ' . $quote('x') . ', false, 0, 0, ' . $now . ', ' . $now . ')'
            );
        }
        $pdo->exec(
            "INSERT INTO memberships (id, profile_id, tenant_id, role_id, ou_id, is_primary, status, created_at) VALUES
                (1010, 10, 1, 100, 20, true, 'active', {$now}),
                (1011, 11, 1, 100, 20, true, 'active', {$now}),
                (1012, 12, 1, 100, 20, true, 'active', {$now})"
        );

        return $pdo;
    }
}
