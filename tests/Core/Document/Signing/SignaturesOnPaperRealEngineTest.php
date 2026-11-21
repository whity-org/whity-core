<?php

declare(strict_types=1);

namespace Tests\Core\Document\Signing;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\DocumentVerificationApiHandler;
use Whity\Auth\TotpService;
use Whity\Core\Audience\ExplicitRuleResolver;
use Whity\Core\Document\DocumentArtifactRepository;
use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\DocumentSourceRepository;
use Whity\Core\Document\Qr\DocumentQrScanRepository;
use Whity\Core\Document\Qr\DocumentQrService;
use Whity\Core\Document\Qr\DocumentQrTokenRepository;
use Whity\Core\Document\Qr\VerificationPresenter;
use Whity\Core\Document\Routing\DocumentRouter;
use Whity\Core\Document\Routing\DocumentRouteSubject;
use Whity\Core\Document\Routing\RoleBelowActorRuleResolver;
use Whity\Core\Document\Routing\RoleRuleResolver;
use Whity\Core\Document\Routing\RouteEdgeRepository;
use Whity\Core\Document\Routing\RouteEventRepository;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\Document\Routing\RouteRepository;
use Whity\Core\Document\Routing\RouteStepRepository;
use Whity\Core\Document\Routing\RouteSubjectRegistry;
use Whity\Core\Document\Routing\RouteSubjectVersionRepository;
use Whity\Core\Document\Routing\RoutingRuleRegistry;
use Whity\Core\Document\Signing\CompanyStampRepository;
use Whity\Core\Document\Signing\InstanceSealKeys;
use Whity\Core\Document\Signing\SavedSignatureRepository;
use Whity\Core\Document\Signing\SignatureEvidence;
use Whity\Core\Document\Signing\SignatureRepository;
use Whity\Core\Document\Signing\SigningChallengeRepository;
use Whity\Core\Document\Signing\SigningConsent;
use Whity\Core\Document\Signing\SigningSecondFactor;
use Whity\Core\Document\Signing\SigningService;
use Whity\Core\Group\GroupResolver;
use Whity\Core\Group\GroupRuleResolver;
use Whity\Core\Group\UserGroupRepository;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Request;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Store\DatabaseSharedStore;
use Whity\Core\Tenant\TenantContext;

/**
 * #1730: signatures on the final PDF and on the public document check.
 *
 * A document is signed by a director and stamped by a stamp holder, for real,
 * through {@see SigningService}. Then: the blocks the final PDF gets
 * ({@see SignatureEvidence}), the public check's `signed` level, the names
 * switch, the broken-chain answer, and "Check a file".
 *
 * Runs on SQLite, and on real PostgreSQL when PHPUNIT_PG_DSN is set.
 */
final class SignaturesOnPaperRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const AUTHOR = 10;
    private const SIGNER = 11;
    private const HOLDER = 12;
    private const ENCRYPTION_KEY = 'whity-1730-test-encryption-key-0123456789';

    /** A 40x40 PNG. */
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAACgAAAAoCAIAAAADnC86AAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAHElEQVRYhe3BAQ0AAADCoPdPbQ43oAAAAADg3wAS6AABuHYUGQAAAABJRU5ErkJggg==';

    private PDO $pdo;
    private DocumentRouter $router;
    private SigningChallengeRepository $challenges;
    private SigningService $signing;
    private SettingsService $settings;
    private DocumentQrService $qr;
    private DocumentVerificationApiHandler $verification;

    protected function setUp(): void
    {
        $this->pdo = $this->makeSchema();
        $recipients = new RouteRecipientRepository($this->pdo);
        $versions = new RouteSubjectVersionRepository($this->pdo);
        $steps = new RouteStepRepository($this->pdo);
        $rules = new RoutingRuleRegistry();
        $registry = $rules;
        $rules->registerCoreRoutingRules(
            new RoleRuleResolver($this->pdo),
            new RoleBelowActorRuleResolver($this->pdo),
            new ExplicitRuleResolver(),
            new GroupRuleResolver(new GroupResolver($this->pdo, new UserGroupRepository($this->pdo), static fn (): RoutingRuleRegistry => $registry))
        );
        $subjects = new RouteSubjectRegistry();
        $subjects->register(new DocumentRouteSubject($this->pdo));
        $this->settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));

        $this->router = new DocumentRouter(
            $this->pdo,
            new RouteRepository($this->pdo),
            $steps,
            new RouteEventRepository($this->pdo),
            $recipients,
            new RouteEdgeRepository($this->pdo),
            $rules,
            $this->settings,
            new HookManager(),
            $subjects,
            $versions,
        );
        $this->challenges = new SigningChallengeRepository($this->pdo);
        $this->signing = new SigningService(
            $this->pdo,
            $this->router,
            $recipients,
            $steps,
            $subjects,
            $versions,
            new SignatureRepository($this->pdo),
            new SavedSignatureRepository($this->pdo),
            new SigningSecondFactor($this->pdo, $this->challenges, new TotpService(self::ENCRYPTION_KEY)),
            $this->challenges,
            new InstanceSealKeys($this->pdo, new EncryptedSecretStore(['v1' => self::ENCRYPTION_KEY], 'v1')),
            null,
            new CompanyStampRepository($this->pdo),
        );

        $this->qr = new DocumentQrService(
            $this->pdo,
            new DocumentQrTokenRepository($this->pdo),
            new DocumentQrScanRepository($this->pdo),
            'https://records.example.test'
        );
        $this->verification = new DocumentVerificationApiHandler(
            $this->qr,
            new DocumentRepository($this->pdo),
            new RouteEventRepository($this->pdo),
            $this->settings,
            new DatabaseSharedStore($this->pdo),
            new DocumentSourceRepository($this->pdo),
            new DocumentArtifactRepository($this->pdo),
            null,
            $this->pdo,
        );
        $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_QR_ENABLED, 'true');
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testTheFinalPdfGetsTheSignatureBlockTheStampAndTheEvidencePage(): void
    {
        [$documentId, $route] = $this->signedAndStamped();

        $blocks = (new SignatureEvidence($this->pdo))->blocksForRoute(self::TENANT, (int) $route['id'], 'en');
        $types = array_column($blocks, 'type');

        self::assertSame('heading', $types[0]);
        $row = $this->first($blocks, 'signatureRow');
        self::assertSame('signer', $row['name']);
        self::assertSame('Director, for Example Bakery', $row['role']);
        self::assertMatchesRegularExpression('/^Signed electronically on \d{1,2} [A-Z][a-z]{2} \d{4} \d\d:\d\d · [0-9a-f]{16}$/', $row['confirmed']);
        self::assertNotEmpty($row['drawing']['paths']);
        self::assertSame([300, 100], $row['drawing']['viewBox']);

        $figure = $this->first($blocks, 'figure');
        self::assertSame(self::PNG, $figure['src']);
        self::assertStringStartsWith('Example Bakery LLC · Applied on ', $figure['caption']);

        self::assertContains('pageBreak', $types);
        $notice = $this->first($blocks, 'notice');
        self::assertStringContainsString('chain is intact', $notice['text']);
        $table = $this->first($blocks, 'table');
        self::assertSame(['Sent', 'Signed', 'Stamp applied'], array_column($table['rows'], 2));
        self::assertSame('signer', $table['rows'][1][1]);

        // What travels on paper never carries contact details.
        $json = (string) json_encode($blocks);
        self::assertStringNotContainsString('@example.test', $json);
        self::assertStringNotContainsString('203.0.113.7', $json);

        $arabic = (new SignatureEvidence($this->pdo))->blocksForRoute(self::TENANT, (int) $route['id'], 'ar');
        self::assertSame('التواقيع', $arabic[0]['text']);
        self::assertSame($documentId, (int) $route['document_id']);
    }

    public function testARouteWithNoSignatureAddsNothingToThePdf(): void
    {
        $documentId = $this->seedDocument();
        $route = $this->router->issue(self::TENANT, self::AUTHOR, ['id' => $documentId], 'Plain', [[
            'rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT,
            'rule_config' => ['profile_ids' => [self::SIGNER]],
        ]])['route'];

        self::assertSame([], (new SignatureEvidence($this->pdo))->blocksForRoute(self::TENANT, (int) $route['id'], 'en'));
    }

    public function testTheSignedLevelShowsCapacitiesAndDatesButNoNamesUnlessChosen(): void
    {
        [$documentId] = $this->signedAndStamped();
        $token = $this->mint($documentId);

        // Off by default: nothing about signatures below `signed`.
        $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_QR_PUBLIC_DETAIL, VerificationPresenter::DETAIL_DESCRIBED);
        self::assertArrayNotHasKey('signatures', $this->verify($token));
        self::assertSame('false', SettingsRegistry::defaultFor(SettingsRegistry::DOCUMENTS_QR_PUBLIC_SIGNER_NAMES));

        $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_QR_PUBLIC_DETAIL, VerificationPresenter::DETAIL_SIGNED);
        $data = $this->verify($token);
        self::assertTrue($data['verified']);
        self::assertTrue($data['trail_intact']);
        self::assertSame('stamped', $data['stage']);
        self::assertSame('A', $data['revision']);
        self::assertCount(2, $data['signatures']);
        self::assertSame(['kind', 'capacity', 'on_behalf_of', 'version', 'signed_on'], array_keys($data['signatures'][0]));
        self::assertSame('signature', $data['signatures'][0]['kind']);
        self::assertSame('Director', $data['signatures'][0]['capacity']);
        self::assertSame('Example Bakery', $data['signatures'][0]['on_behalf_of']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d$/', (string) $data['signatures'][0]['signed_on']);
        self::assertSame('stamp', $data['signatures'][1]['kind']);
        self::assertSame('Example Bakery LLC', $data['signatures'][1]['capacity']);
        $body = (string) json_encode($data);
        self::assertStringNotContainsString('signer', $body);
        self::assertStringNotContainsString('holder', $body);

        $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_QR_PUBLIC_SIGNER_NAMES, 'true');
        $named = $this->verify($token);
        self::assertSame('signer', $named['signatures'][0]['name']);
        self::assertSame('holder', $named['signatures'][1]['name']);
    }

    public function testTheCheckSaysWhenTheTrailChainIsBroken(): void
    {
        [$documentId] = $this->signedAndStamped();
        $token = $this->mint($documentId);
        $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_QR_PUBLIC_DETAIL, VerificationPresenter::DETAIL_SIGNED);

        $this->pdo->exec("UPDATE document_route_events SET note = 'rewritten' WHERE action = 'signed'");

        self::assertFalse($this->verify($token)['trail_intact']);
    }

    public function testCheckingAFileMatchesItsHashOnly(): void
    {
        $documentId = $this->seedDocument();
        $artifacts = new DocumentArtifactRepository($this->pdo);
        $older = str_repeat('b', 64);
        $current = hash('sha256', "%PDF-1.4\nsigned\n%%EOF");
        foreach ([$older, $current] as $hash) {
            $artifacts->create(self::TENANT, [
                'document_id' => $documentId,
                'storage_key' => 'documents/' . $documentId . '/' . bin2hex(random_bytes(4)) . '.pdf',
                'content_type' => 'application/pdf',
                'byte_size' => 10,
                'checksum_sha256' => $hash,
                'pages' => 1,
            ]);
        }
        $token = $this->mint($documentId);

        self::assertSame('current', $this->checkFile($token, $current));
        self::assertSame('current', $this->checkFile($token, strtoupper($current)));
        self::assertSame('earlier', $this->checkFile($token, $older));
        self::assertSame('none', $this->checkFile($token, str_repeat('c', 64)));
        self::assertSame('none', $this->checkFile(str_repeat('x', 43), $current), 'An unknown code answers none, like a wrong file.');

        $this->pdo->exec('UPDATE documents SET withdrawn_at = CURRENT_TIMESTAMP WHERE id = ' . $documentId);
        self::assertSame('none', $this->checkFile($token, $current));

        $request = new Request('GET', '/api/v1/document-verifications/' . $token . '/files/abc', ['REMOTE_ADDR' => '198.51.100.9'], '');
        self::assertSame(422, $this->verification->checkFile($request, ['token' => $token, 'sha256' => 'abc'])->getStatusCode());
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function signedAndStamped(): array
    {
        $stamps = new CompanyStampRepository($this->pdo);
        $stampId = $stamps->create(self::TENANT, 'Company stamp', 'Example Bakery LLC', self::PNG, self::AUTHOR);
        $stamps->setHolders(self::TENANT, $stampId, [self::HOLDER], []);

        $documentId = $this->seedDocument();
        $route = $this->router->issue(self::TENANT, self::AUTHOR, ['id' => $documentId], 'Supply agreement', [
            [
                'rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT,
                'rule_config' => ['profile_ids' => [self::SIGNER]],
                'kind' => 'sign',
                'sign_capacity' => 'Director',
                'sign_on_behalf_of' => 'Example Bakery',
            ],
            [
                'rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT,
                'rule_config' => ['profile_ids' => [self::HOLDER]],
                'kind' => 'stamp',
                'stamp_id' => $stampId,
            ],
        ])['route'];

        $hash = (string) $this->signing->context(self::TENANT, self::SIGNER, $route)['subject']['content_hash'];
        $this->signing->sign(self::TENANT, self::SIGNER, $route, [
            'content_hash' => $hash,
            'consent' => true,
            'consent_version' => SigningConsent::CURRENT,
            'appearance' => ['width' => 300, 'height' => 100, 'strokes' => [[[10, 10], [40, 30], [80, 20], [120, 60], [160, 40], [200, 70]]]],
            'second_factor' => ['method' => 'email_code', 'code' => $this->challenges->issueEmailCode(self::TENANT, self::SIGNER, (int) $route['id'], $hash)],
        ], '203.0.113.7', 'TestBrowser/1.0');

        $hash = (string) $this->signing->stampContext(self::TENANT, self::HOLDER, $route)['subject']['content_hash'];
        $this->signing->stamp(self::TENANT, self::HOLDER, $route, [
            'content_hash' => $hash,
            'consent' => true,
            'consent_version' => SigningConsent::STAMP_CURRENT,
            'second_factor' => ['method' => 'email_code', 'code' => $this->challenges->issueEmailCode(self::TENANT, self::HOLDER, (int) $route['id'], $hash)],
        ], '203.0.113.7', 'TestBrowser/1.0');

        return [$documentId, $route];
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @return array<string, mixed>
     */
    private function first(array $blocks, string $type): array
    {
        foreach ($blocks as $block) {
            if (($block['type'] ?? null) === $type) {
                return $block;
            }
        }
        self::fail("No '{$type}' block.");
    }

    private function mint(int $documentId): string
    {
        $token = $this->qr->mint(self::TENANT, $documentId, self::AUTHOR);
        self::assertIsArray($token);

        return (string) $token['token'];
    }

    /** @return array<string, mixed> */
    private function verify(string $token): array
    {
        TenantContext::reset();
        $request = new Request('GET', '/api/v1/document-verifications/' . $token, ['REMOTE_ADDR' => '198.51.100.4'], '');
        $decoded = json_decode($this->verification->verify($request, ['token' => $token])->getBody(), true);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['data'] ?? null);

        return $decoded['data'];
    }

    private function checkFile(string $token, string $hash): string
    {
        TenantContext::reset();
        $request = new Request('GET', '/api/v1/document-verifications/' . $token . '/files/' . $hash, ['REMOTE_ADDR' => '198.51.100.5'], '');
        $response = $this->verification->checkFile($request, ['token' => $token, 'sha256' => $hash]);
        self::assertSame(200, $response->getStatusCode(), $response->getBody());
        $decoded = json_decode($response->getBody(), true);

        return is_array($decoded) ? (string) ($decoded['data']['match'] ?? '') : '';
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

        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'bakery') ON CONFLICT DO NOTHING");
        $pdo->exec(
            'INSERT INTO organizational_units (id, tenant_id, parent_id, name, slug, created_at) VALUES
                (20, 1, NULL, ' . $quote('Kitchen') . ', ' . $quote('kitchen') . ', ' . $now . ')'
        );
        $pdo->exec(
            'INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES
                (100, ' . $quote('member') . ', ' . $quote('') . ', 1, ' . $now . ')'
        );
        foreach ([[10, 'author'], [11, 'signer'], [12, 'holder']] as [$id, $name]) {
            $pdo->exec(
                'INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                                       two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                 VALUES (' . $id . ', ' . $quote($name) . ', ' . $quote('x') . ', false, 0, 0, ' . $now . ', ' . $now . ')'
            );
            $pdo->exec(
                'INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at) VALUES ('
                . $id . ', ' . $quote($name . '@example.test') . ', true, true, ' . $now . ')'
            );
            $pdo->exec(
                "INSERT INTO memberships (id, profile_id, tenant_id, role_id, ou_id, is_primary, status, created_at) VALUES
                    (" . (1000 + $id) . ", {$id}, 1, 100, 20, true, 'active', {$now})"
            );
        }

        return $pdo;
    }
}
