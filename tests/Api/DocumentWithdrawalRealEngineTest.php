<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Whity\Api\DocumentQrApiHandler;
use Whity\Api\DocumentRoutingApiHandler;
use Whity\Api\DocumentVerificationApiHandler;
use Whity\Api\DocumentWithdrawalApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Document\DocumentPeople;
use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\DocumentVisibilityPolicy;
use Whity\Core\Document\DocumentWithdrawal;
use Whity\Core\Document\Qr\DocumentQrScanRepository;
use Whity\Core\Document\Qr\DocumentQrService;
use Whity\Core\Document\Qr\DocumentQrTokenRepository;
use Whity\Core\Document\RouteTemplate\RouteTemplateRepository;
use Whity\Core\Document\Routing\DocumentRouter;
use Whity\Core\Document\Routing\RouteEdgeRepository;
use Whity\Core\Document\Routing\RouteEventRepository;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\Document\Routing\RouteRepository;
use Whity\Core\Document\Routing\RouteStepRepository;
use Whity\Core\Document\Routing\RoutingRejectedException;
use Whity\Core\Document\Routing\RoutingRuleRegistry;
use Whity\Core\Form\FormRepository;
use Whity\Core\Audience\ExplicitRuleResolver;
use Whity\Core\Document\Routing\RoleBelowActorRuleResolver;
use Whity\Core\Document\Routing\RoleRuleResolver;
use Whity\Core\Group\GroupResolver;
use Whity\Core\Group\GroupRuleResolver;
use Whity\Core\Group\UserGroupRepository;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\RBAC\ResourceRoleAssignmentRepository;
use Whity\Core\RBAC\ResourceTypeRegistry;
use Whity\Core\i18n\LanguageRegistry;
use Whity\Core\i18n\LanguageRepository;
use Whity\Core\i18n\ServerLabels;
use Whity\Core\i18n\TranslationRepository;
use Whity\Core\Tenant\StaticTenantContextAdapter;
use Whity\Database\Database;
use Whity\Core\Request;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Store\DatabaseSharedStore;
use Whity\Core\Tenant\TenantContext;
use Tests\Support\SchemaFromMigrations;

/**
 * S5: withdrawing a document, naming the people on it, route usage and the route
 * name on a form. Runs on real Postgres when `PHPUNIT_PG_DSN` is set, as CI does.
 *
 * Each of the withdrawal tests asserts the ordinary state first, so a handler that
 * refused everything could not pass them.
 */
final class DocumentWithdrawalRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const OTHER_TENANT = 2;

    /** Raised the documents below. */
    private const OWNER = 10;
    /** Holds documents:withdraw and documents:read:all. */
    private const OFFICER = 11;
    /** A member with no permission, who can see only what reaches them. */
    private const MEMBER = 12;
    /** A member who holds documents:withdraw but cannot see the owner's documents. */
    private const WITHDRAWER_WITHOUT_REACH = 13;
    /** Belongs only to the other tenant. */
    private const STRANGER = 20;

    private PDO $pdo;
    private DocumentRepository $documents;
    private DocumentQrService $qr;
    private DocumentQrTokenRepository $tokens;
    private SettingsService $settings;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = SchemaFromMigrations::make();
        $this->documents = new DocumentRepository($this->pdo);
        $this->tokens = new DocumentQrTokenRepository($this->pdo);
        $this->settings = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo)
        );
        $this->qr = new DocumentQrService(
            $this->pdo,
            $this->tokens,
            new DocumentQrScanRepository($this->pdo),
            'https://records.example.test'
        );
        $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_QR_ENABLED, 'true');

        $this->seed();
        TenantContext::setTenantId(self::TENANT);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    // ── keeping the record, and the audit ───────────────────────────────────

    public function testWithdrawingKeepsTheRecordAndRecordsWhoDidIt(): void
    {
        $id = $this->raise();
        self::assertNull($this->row($id)['withdrawn_at']);

        $response = $this->withdraw($id, self::OFFICER);

        self::assertSame(200, $response->getStatusCode(), $response->getBody());
        $row = $this->row($id);
        self::assertNotNull($row['withdrawn_at']);
        self::assertSame(self::OFFICER, $row['withdrawn_by']);
    }

    public function testTheAuditLogNamesTheActAndTheActor(): void
    {
        $id = $this->raise();
        $this->withdraw($id, self::OFFICER);

        $stmt = $this->pdo->prepare(
            "SELECT tenant_id, actor_user_id, target_id, metadata FROM audit_log
              WHERE action = 'documents.document.withdrawn' AND target_id = :id"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($row, 'an audit row was written');
        self::assertSame(self::TENANT, (int) $row['tenant_id']);
        self::assertSame(self::OFFICER, (int) $row['actor_user_id']);
        self::assertSame(0, json_decode((string) $row['metadata'], true)['qr_withdrawn'], 'the logger keeps the count');
    }

    public function testASecondWithdrawalIsRefusedAndChangesNothing(): void
    {
        $id = $this->raise();
        $this->withdraw($id, self::OFFICER);
        $first = $this->row($id);

        self::assertSame(409, $this->withdraw($id, self::OWNER)->getStatusCode());
        self::assertSame($first['withdrawn_by'], $this->row($id)['withdrawn_by']);
    }

    // ── RBAC and tenant isolation ───────────────────────────────────────────

    public function testTheRouteIsGatedOnItsOwnPermission(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
        $line = '';
        foreach (explode("\n", $source) as $candidate) {
            if (str_contains($candidate, "/withdraw'") && str_contains($candidate, "register('POST'")) {
                $line = $candidate;
            }
        }

        self::assertStringContainsString('CorePermissions::DOCUMENTS_WITHDRAW', $line);
    }

    /**
     * Migration 201 grants by capability, so a renamed administrative role keeps
     * the act, and its down() removes the grant and the columns.
     */
    public function testTheMigrationGrantsToWhoeverMayIssueAndReverses(): void
    {
        $this->pdo->exec('DELETE FROM role_permissions WHERE permission_id IN (SELECT id FROM permissions WHERE name = \'documents:withdraw\')');
        $this->grant(900, CorePermissions::DOCUMENTS_ISSUE);

        $pdo = $this->pdo;
        $db = Database::withFactory(static fn (): PDO => $pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();

        $holders = function () use ($pdo): array {
            $stmt = $pdo->prepare(
                "SELECT rp.role_id FROM role_permissions rp
                   JOIN permissions p ON p.id = rp.permission_id
                  WHERE p.name = 'documents:withdraw' AND rp.role_id >= 900"
            );
            $stmt->execute();

            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        };

        self::assertSame([], $holders());

        \Database\Migrations\AddDocumentWithdrawal::up($db);
        self::assertSame([900], $holders(), 'the role holding documents:issue, and only that');

        \Database\Migrations\AddDocumentWithdrawal::down($db);
        self::assertSame([], $holders());
        $this->assertColumnGone('withdrawn_at');
        $this->assertColumnGone('withdrawn_by');

        // up() again, so a migrate-down-up cycle works.
        \Database\Migrations\AddDocumentWithdrawal::up($db);
        self::assertSame([900], $holders());
    }

    public function testACallerWhoCannotSeeTheDocumentGetsTheSame404(): void
    {
        $id = $this->raise();

        $response = $this->withdraw($id, self::WITHDRAWER_WITHOUT_REACH);

        self::assertSame(404, $response->getStatusCode());
        self::assertNull($this->row($id)['withdrawn_at']);
    }

    public function testAnotherTenantsDocumentCannotBeWithdrawn(): void
    {
        $id = $this->raise();

        TenantContext::reset();
        TenantContext::setTenantId(self::OTHER_TENANT);
        self::assertSame(404, $this->withdraw($id, self::STRANGER)->getStatusCode());

        self::assertNull($this->row($id)['withdrawn_at']);
    }

    // ── the QR page ─────────────────────────────────────────────────────────

    public function testALiveCodeStopsVerifyingAtEveryDisclosureLevel(): void
    {
        $id = $this->raise();
        $token = $this->mintToken($id);
        self::assertTrue($this->verify($token)['verified']);

        $this->withdraw($id, self::OFFICER);

        self::assertSame(['verified' => false, 'reason' => 'unrecognised'], $this->verify($token));

        $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_QR_PUBLIC_DETAIL, 'stage');
        $answer = $this->verify($token);
        self::assertFalse($answer['verified']);
        self::assertSame('document_withdrawn', $answer['reason']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) $answer['revoked_on']);
    }

    /**
     * The trap: a holder of an older printing must not be told a newer version
     * exists, because the document it would point them to has been taken back.
     */
    public function testAnOlderPrintingIsToldTheDocumentWasWithdrawnNotReplaced(): void
    {
        $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_QR_PUBLIC_DETAIL, 'stage');
        $id = $this->raise();
        $old = $this->mintToken($id);
        $this->qr->mint(self::TENANT, $id, self::OWNER);
        self::assertSame('superseded', $this->verify($old)['reason']);

        $this->withdraw($id, self::OFFICER);

        self::assertSame('document_withdrawn', $this->verify($old)['reason']);
    }

    /** The race: a code that went live in the instant of the withdrawal. */
    public function testALiveCodeOnAWithdrawnDocumentIsStillRefusedAndRecordedAsRefused(): void
    {
        $id = $this->raise();
        $this->withdraw($id, self::OFFICER);
        $this->tokens->insert(self::TENANT, $id, str_repeat('a', 64), self::OWNER);

        self::assertFalse($this->verify(str_repeat('a', 64))['verified']);

        $stmt = $this->pdo->prepare('SELECT outcome FROM document_qr_scans WHERE document_id = :id');
        $stmt->execute([':id' => $id]);
        self::assertSame(['refused'], $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testNoNewCodeCanBeMadeForAWithdrawnDocument(): void
    {
        $id = $this->raise();
        self::assertNotNull($this->qr->ensure(self::TENANT, $id, self::OWNER));

        $this->withdraw($id, self::OFFICER);

        self::assertNull($this->qr->ensure(self::TENANT, $id, self::OWNER), 'a queued render cannot mint');
        self::assertNull($this->qr->mint(self::TENANT, $id, self::OWNER));
        self::assertSame(409, $this->qrHandler()->mint($this->request(self::OFFICER), ['id' => (string) $id])->getStatusCode());
    }

    public function testASignedInReaderIsToldWhatTheCodeOpens(): void
    {
        $id = $this->raise();
        $token = $this->mintToken($id);
        $this->withdraw($id, self::OFFICER);

        $data = $this->json($this->qrHandler()->resolveToken($this->request(self::OWNER), ['token' => $token]))['data'];

        self::assertFalse($data['code_honoured']);
        self::assertTrue($data['document_withdrawn']);
    }

    // ── routing ─────────────────────────────────────────────────────────────

    public function testOpenItemsCloseAndTheRouteCannotMove(): void
    {
        $id = $this->raise();
        $route = $this->route($id);
        $recipients = new RouteRecipientRepository($this->pdo);
        self::assertSame(1, $recipients->countForProfile(self::TENANT, self::MEMBER, true));

        $this->withdraw($id, self::OFFICER);

        self::assertSame(0, $recipients->countForProfile(self::TENANT, self::MEMBER, true));
        $closed = $recipients->listForProfile(self::TENANT, self::MEMBER, false, 10, 0);
        self::assertTrue($closed[0]['document_withdrawn']);

        try {
            $this->router()->act(self::TENANT, self::MEMBER, $route, 'noted', 'Too late');
            self::fail('A withdrawn document took a note.');
        } catch (RoutingRejectedException $e) {
            self::assertStringContainsString('withdrawn', $e->clientMessage);
        }
    }

    public function testTheClosingRowIsFlaggedSoAScreenDoesNotShowItAsANote(): void
    {
        $id = $this->raise();
        $this->route($id);

        $this->withdraw($id, self::OFFICER);

        $trail = $this->json($this->routingHandler()->trail($this->request(self::OWNER), ['id' => (string) $id]))['data'];
        $last = end($trail);
        self::assertSame('noted', $last['action']);
        self::assertTrue($last['withdrawal']);
        self::assertSame(self::OFFICER, $last['actor_profile_id']);
        self::assertFalse($trail[0]['withdrawal']);

        $recipients = $this->json($this->routingHandler()->recipients($this->request(self::OWNER), ['id' => (string) $id]))['data'];
        self::assertFalse($recipients[0]['open']);
        self::assertTrue($recipients[0]['closed_by_withdrawal'], 'nobody acted on it');
    }

    public function testAWithdrawnDocumentCannotBeRouted(): void
    {
        $id = $this->raise();
        $this->withdraw($id, self::OFFICER);

        $this->expectException(RoutingRejectedException::class);
        $this->route($id);
    }

    // ── names ───────────────────────────────────────────────────────────────

    public function testTheRecordNamesThePeopleOnItAndNoOneElse(): void
    {
        $id = $this->raise();
        $this->route($id);

        $names = $this->json($this->routingHandler()->people($this->request(self::OWNER), ['id' => (string) $id]))['data'];

        self::assertSame('Owner Person', $names[(string) self::OWNER], 'the display name');
        self::assertSame('member', $names[(string) self::MEMBER], 'before the @, never the address');
        self::assertArrayNotHasKey((string) self::OFFICER, $names, 'not on this record');
        self::assertStringNotContainsString('@', (string) json_encode($names));
    }

    public function testACallerWhoCannotSeeTheRecordGetsNoNames(): void
    {
        $id = $this->raise();

        $response = $this->routingHandler()->people($this->request(self::MEMBER), ['id' => (string) $id]);

        self::assertSame(404, $response->getStatusCode());
    }

    public function testARecipientWithoutAnyPermissionIsToldWhoItPassedThrough(): void
    {
        $id = $this->raise();
        $this->route($id);

        $names = $this->json($this->routingHandler()->people($this->request(self::MEMBER), ['id' => (string) $id]))['data'];

        self::assertSame('Owner Person', $names[(string) self::OWNER]);
    }

    public function testWhoIssuedACodeIsNamedOnlyToAReaderOfCodes(): void
    {
        $id = $this->raise();
        $this->route($id);
        $this->qr->mint(self::TENANT, $id, self::OFFICER);

        $forRecipient = $this->json($this->routingHandler()->people($this->request(self::MEMBER), ['id' => (string) $id]))['data'];
        $forOfficer = $this->json($this->routingHandler()->people($this->request(self::OFFICER), ['id' => (string) $id]))['data'];

        self::assertArrayNotHasKey((string) self::OFFICER, $forRecipient);
        self::assertArrayHasKey((string) self::OFFICER, $forOfficer);
    }

    public function testAScannerIsNamedOnlyToAReaderOfTheDirectory(): void
    {
        $this->grant(904, CorePermissions::USERS_READ);
        $id = $this->raise();
        $token = $this->qr->mint(self::TENANT, $id, self::OWNER);
        self::assertIsArray($token);
        self::assertTrue($this->qr->recordScan($token, self::WITHDRAWER_WITHOUT_REACH));

        $forOwner = $this->json($this->routingHandler()->people($this->request(self::OWNER), ['id' => (string) $id]))['data'];
        $forOfficer = $this->json($this->routingHandler()->people($this->request(self::OFFICER), ['id' => (string) $id]))['data'];

        self::assertArrayHasKey((string) self::WITHDRAWER_WITHOUT_REACH, $forOwner);
        self::assertArrayNotHasKey((string) self::WITHDRAWER_WITHOUT_REACH, $forOfficer);
    }

    public function testANameIsNeverReadAcrossTenants(): void
    {
        $people = new DocumentPeople($this->pdo);

        self::assertSame([], $people->names(self::TENANT, [self::STRANGER]));
        self::assertSame('Stranger', $people->names(self::OTHER_TENANT, [self::STRANGER])[self::STRANGER]);
    }

    public function testNamesAreOneStatementForMany(): void
    {
        $names = (new DocumentPeople($this->pdo))->names(self::TENANT, [self::OWNER, self::MEMBER, self::OWNER, 9999]);

        self::assertSame([self::OWNER, self::MEMBER], array_values(array_intersect([self::OWNER, self::MEMBER], array_keys($names))));
        self::assertCount(2, $names);
    }

    // ── route usage, and the route's name on a form ─────────────────────────

    public function testUsageCountsRoutesStartedFromTheDesignInThisTenant(): void
    {
        $repository = new RouteTemplateRepository($this->pdo);
        $template = $repository->create(self::TENANT, 'Approval', null, self::OWNER);
        $unused = $repository->create(self::TENANT, 'Unused', null, self::OWNER);
        $this->route($this->raise(), $template);
        $this->route($this->raise(), $template);

        $rows = array_column($repository->listForTenant(self::TENANT, 10, 0), 'usage_count', 'id');
        self::assertSame(2, $rows[$template]);
        self::assertSame(0, $rows[$unused]);
        $found = $repository->findById($template, self::TENANT);
        self::assertIsArray($found);
        self::assertSame(2, $found['usage_count']);
        self::assertNull($repository->findById($template, self::OTHER_TENANT));
    }

    public function testAFormCarriesItsRoutesNameFromItsOwnTenantOnly(): void
    {
        $template = (new RouteTemplateRepository($this->pdo))->create(self::TENANT, 'Approval', null, self::OWNER);
        $forms = new FormRepository($this->pdo);
        $this->pdo->exec(
            "INSERT INTO forms (id, tenant_id, form_key, name, status, version, route_template_id, created_at, updated_at)
             VALUES (500, 1, 'mine', '{\"en\":\"Mine\"}', 'draft', 1, {$template}, NOW(), NOW()),
                    (501, 2, 'theirs', '{\"en\":\"Theirs\"}', 'draft', 1, {$template}, NOW(), NOW()),
                    (502, 1, 'none', '{\"en\":\"None\"}', 'draft', 1, NULL, NOW(), NOW())"
        );

        self::assertSame('Approval', $this->routeName($forms, self::TENANT, 500));
        self::assertNull($this->routeName($forms, self::OTHER_TENANT, 501), 'never another tenant\'s design');
        self::assertNull($this->routeName($forms, self::TENANT, 502));
    }

    // ── fixture ─────────────────────────────────────────────────────────────

    private function seed(): void
    {
        $this->pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 'Ministry of Records') ON CONFLICT DO NOTHING");
        $this->pdo->exec("INSERT INTO tenants (id, name) VALUES (2, 'Other') ON CONFLICT DO NOTHING");
        $this->pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES (900, 'plain', '', 1, NOW()), (901, 'officer', '', 1, NOW()), (902, 'withdrawer', '', 1, NOW()), (904, 'owner', '', 1, NOW())");
        $this->pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES (903, 'plain-other', '', 2, NOW())");

        foreach ([
            self::OWNER => ['Owner Person', 'owner@example.test', 1, 904],
            self::OFFICER => ['Officer Person', 'officer@example.test', 1, 901],
            self::MEMBER => ['', 'member@example.test', 1, 900],
            self::WITHDRAWER_WITHOUT_REACH => ['Withdrawer', 'withdrawer@example.test', 1, 902],
            self::STRANGER => ['Stranger', 'stranger@example.test', 2, 903],
        ] as $id => [$name, $email, $tenant, $role]) {
            $stmt = $this->pdo->prepare("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at) VALUES (:id, :name, 'x', false, 0, 0, NOW(), NOW())");
            $stmt->execute([':id' => $id, ':name' => $name]);
            $stmt = $this->pdo->prepare('INSERT INTO profile_emails (profile_id, email, is_primary, created_at) VALUES (:id, :email, true, NOW())');
            $stmt->execute([':id' => $id, ':email' => $email]);
            $stmt = $this->pdo->prepare("INSERT INTO memberships (profile_id, tenant_id, role_id, is_primary, status, created_at) VALUES (:id, :tenant, :role, true, 'active', NOW())");
            $stmt->execute([':id' => $id, ':tenant' => $tenant, ':role' => $role]);
        }

        $this->grant(904, CorePermissions::DOCUMENTS_READ);
        $this->grant(901, CorePermissions::DOCUMENTS_READ);
        $this->grant(901, CorePermissions::DOCUMENTS_READ_ALL);
        $this->grant(901, CorePermissions::DOCUMENTS_WITHDRAW);
        $this->grant(902, CorePermissions::DOCUMENTS_READ);
        $this->grant(902, CorePermissions::DOCUMENTS_WITHDRAW);
        $this->grant(903, CorePermissions::DOCUMENTS_WITHDRAW);
    }

    private function grant(int $roleId, string $permission): void
    {
        $this->pdo->prepare('INSERT INTO permissions (name, description, created_at) VALUES (:name, \'\', NOW()) ON CONFLICT (name) DO NOTHING')
            ->execute([':name' => $permission]);
        $stmt = $this->pdo->prepare(
            'INSERT INTO role_permissions (role_id, permission_id, created_at)
             SELECT :role, id, NOW() FROM permissions WHERE name = :name
             ON CONFLICT DO NOTHING'
        );
        $stmt->execute([':role' => $roleId, ':name' => $permission]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $id): array
    {
        $row = $this->documents->findById($id, self::TENANT);
        self::assertIsArray($row, 'the record is kept');

        return $row;
    }

    private function mintToken(int $id): string
    {
        $token = $this->qr->mint(self::TENANT, $id, self::OWNER);
        self::assertIsArray($token);

        return (string) $token['token'];
    }

    private function routeName(FormRepository $forms, int $tenantId, int $id): mixed
    {
        $form = $forms->find($tenantId, $id);
        self::assertIsArray($form);

        return $form['route_template_name'];
    }

    private function assertColumnGone(string $column): void
    {
        $stmt = $this->pdo->prepare('SELECT * FROM documents LIMIT 0');
        $stmt->execute();
        $names = [];
        for ($i = 0; $i < $stmt->columnCount(); $i++) {
            $meta = $stmt->getColumnMeta($i);
            $names[] = $meta === false ? '' : $meta['name'];
        }
        self::assertNotContains($column, $names);
    }

    private function raise(): int
    {
        return $this->documents->create(self::TENANT, [
            'template_name' => 'Letter',
            'title' => 'Letter of appointment',
            'created_by' => self::OWNER,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function route(int $documentId, ?int $templateId = null): array
    {
        $document = $this->documents->findById($documentId, self::TENANT);
        self::assertNotNull($document);

        $issued = $this->router()->issue(
            self::TENANT,
            self::OWNER,
            $document,
            'Route',
            [[
                'rule_kind' => 'explicit',
                'rule_config' => ['profile_ids' => [self::MEMBER]],
                'label' => 'Member',
            ]],
            $templateId,
            $templateId === null ? null : 'Approval',
        );

        return (new RouteRepository($this->pdo))->findById((int) $issued['route']['id'], self::TENANT) ?? [];
    }

    private function router(): DocumentRouter
    {
        return new DocumentRouter(
            $this->pdo,
            new RouteRepository($this->pdo),
            new RouteStepRepository($this->pdo),
            new RouteEventRepository($this->pdo),
            new RouteRecipientRepository($this->pdo),
            new RouteEdgeRepository($this->pdo),
            $this->rules(),
            $this->settings,
        );
    }

    private function rules(): RoutingRuleRegistry
    {
        $rules = new RoutingRuleRegistry();
        $rules->registerCoreRoutingRules(
            new RoleRuleResolver($this->pdo),
            new RoleBelowActorRuleResolver($this->pdo),
            new ExplicitRuleResolver(),
            new GroupRuleResolver(new GroupResolver(
                $this->pdo,
                new UserGroupRepository($this->pdo),
                static fn (): RoutingRuleRegistry => $rules
            ))
        );

        return $rules;
    }

    private function visibility(): DocumentVisibilityPolicy
    {
        return new DocumentVisibilityPolicy(
            new RouteRecipientRepository($this->pdo),
            new ResourceRoleAssignmentRepository($this->pdo, new ResourceTypeRegistry())
        );
    }

    private function roleChecker(): RoleChecker
    {
        $pdo = $this->pdo;
        $db = Database::withFactory(static fn (): PDO => $pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();

        return new RoleChecker($db, new PermissionRegistry());
    }

    private function qrHandler(): DocumentQrApiHandler
    {
        return new DocumentQrApiHandler(
            $this->documents,
            $this->visibility(),
            $this->qr,
            new DocumentQrScanRepository($this->pdo),
            $this->roleChecker(),
            $this->settings,
        );
    }

    private function routingHandler(): DocumentRoutingApiHandler
    {
        return new DocumentRoutingApiHandler(
            $this->documents,
            new RouteRepository($this->pdo),
            new RouteStepRepository($this->pdo),
            new RouteEventRepository($this->pdo),
            new RouteRecipientRepository($this->pdo),
            new RouteEdgeRepository($this->pdo),
            $this->router(),
            $this->rules(),
            $this->visibility(),
            $this->roleChecker(),
            new RouteTemplateRepository($this->pdo),
            new ServerLabels(new LanguageRegistry(
                new LanguageRepository($this->pdo),
                new TranslationRepository($this->pdo),
                new StaticTenantContextAdapter(),
            )),
            new DocumentPeople($this->pdo),
        );
    }

    private function withdraw(int $documentId, int $actorId): \Whity\Sdk\Http\Response
    {
        $handler = new DocumentWithdrawalApiHandler(
            $this->documents,
            $this->visibility(),
            $this->roleChecker(),
            new DocumentWithdrawal(
                $this->pdo,
                $this->documents,
                $this->tokens,
                new RouteRecipientRepository($this->pdo),
                new RouteEventRepository($this->pdo),
                new AuditLogger($this->pdo),
            ),
        );

        return $handler->withdraw($this->request($actorId), ['id' => (string) $documentId]);
    }

    /**
     * @return array<string, mixed>
     */
    private function verify(string $token): array
    {
        $handler = new DocumentVerificationApiHandler(
            $this->qr,
            $this->documents,
            new RouteEventRepository($this->pdo),
            $this->settings,
            new DatabaseSharedStore($this->pdo),
        );
        $response = $handler->verify(
            new Request('GET', '/api/v1/document-verifications/' . $token, ['REMOTE_ADDR' => '198.51.100.4'], ''),
            ['token' => $token]
        );

        return $this->json($response)['data'];
    }

    private function request(int $profileId): Request
    {
        $request = new Request('GET', '/api/v1/documents', [], '');
        $request->user = (object) ['profile_id' => $profileId];

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(\Whity\Sdk\Http\Response $response): array
    {
        $decoded = json_decode($response->getBody(), true);
        self::assertIsArray($decoded, $response->getBody());

        return $decoded;
    }
}
