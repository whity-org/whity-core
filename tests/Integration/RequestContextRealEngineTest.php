<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\RoleChecker;
use Whity\Auth\TokenValidator;
use Whity\Core\Audit\AuditContext;
use Whity\Core\i18n\LanguageRegistry;
use Whity\Core\i18n\LanguageRepository;
use Whity\Core\i18n\RequestLanguageResolver;
use Whity\Core\i18n\TranslationRepository;
use Whity\Core\Request;
use Whity\Core\Router;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\StaticTenantContextAdapter;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\Middleware\ResolveLanguage;
use Whity\Http\Middleware\StampRequestContext;
use Whity\Mcp\Auth\McpPrincipal;
use Whity\Mcp\Resources\ResourcesReadHandler;
use Whity\Mcp\Tools\ToolDeriver;
use Whity\Mcp\Tools\ToolsCallHandler;
use Whity\Sdk\Http\Request as SdkRequest;
use Whity\Sdk\Http\Response as SdkResponse;

/**
 * Workspace, language and caller on the SDK Request (WHIT-1015).
 *
 * A plugin's route handler is reached two ways: through the kernel, and from an
 * MCP tool or resource call that runs it directly. Both must tell it the same
 * things, so an agent can't look like a person on either.
 *
 * Runs on real Postgres in CI (PHPUNIT_PG_DSN), SQLite locally.
 */
final class RequestContextRealEngineTest extends TestCase
{
    private const WORKSPACE = 7;

    private PDO $pdo;
    private LanguageRegistry $languages;
    private RequestLanguageResolver $resolver;

    /** What the plugin's handler saw. */
    private ?SdkRequest $seen = null;

    protected function setUp(): void
    {
        ToolDeriver::clearCache();
        TenantContext::reset();
        AuditContext::reset();

        $this->pdo = SchemaFromMigrations::make(true);
        $this->languages = new LanguageRegistry(
            new LanguageRepository($this->pdo),
            new TranslationRepository($this->pdo),
            new StaticTenantContextAdapter(),
        );
        $settings = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo),
        );
        (new GlobalSettingsRepository($this->pdo))->set(SettingsRegistry::I18N_ENABLED, 'true');
        $this->resolver = new RequestLanguageResolver($this->pdo, $this->languages, $settings);
    }

    protected function tearDown(): void
    {
        ToolDeriver::clearCache();
        TenantContext::reset();
        AuditContext::reset();
    }

    public function testASignedInPersonSeesTheirWorkspaceAndLanguage(): void
    {
        $profile = $this->profileWithLanguage('ar');
        TenantContext::setTenantId(self::WORKSPACE);
        AuditContext::set($profile, null);
        $request = new Request('GET', '/api/v1/acme/things');
        $request->user = (object) ['profile_id' => $profile];

        $this->throughKernelMiddleware($request);

        self::assertNotNull($this->seen);
        self::assertSame(self::WORKSPACE, $this->seen->getWorkspaceId());
        self::assertSame('ar', $this->seen->getLanguage());
        self::assertSame(SdkRequest::ACTOR_PERSON, $this->seen->getActorKind());
        self::assertFalse($this->seen->isAgent());
    }

    /** An anonymous visitor must never read as the host. */
    public function testNobodySignedInHasNoActorKindAndIsNotTheSystem(): void
    {
        $this->throughKernelMiddleware(new Request('POST', '/api/v1/acme/forms/signup/abc'));

        self::assertNotNull($this->seen);
        self::assertNull($this->seen->getActorKind());
        self::assertNull($this->seen->getWorkspaceId());
        self::assertSame('en', $this->seen->getLanguage());
    }

    /** A public form's workspace comes from its key, with still nobody signed in. */
    public function testAPublicFormKnowsItsWorkspaceButNotACaller(): void
    {
        TenantContext::setTenantId(self::WORKSPACE);

        $this->throughKernelMiddleware(new Request('POST', '/api/v1/acme/forms/signup/abc'));

        self::assertNotNull($this->seen);
        self::assertSame(self::WORKSPACE, $this->seen->getWorkspaceId());
        self::assertNull($this->seen->getActorKind());
    }

    public function testTheSystemWorkspaceIsNotAWorkspace(): void
    {
        $profile = $this->profileWithLanguage(null);
        TenantContext::setTenantId(0);
        AuditContext::set($profile, null);
        $request = new Request('GET', '/api/v1/acme/things');
        $request->user = (object) ['profile_id' => $profile];

        $this->throughKernelMiddleware($request);

        self::assertNotNull($this->seen);
        self::assertNull($this->seen->getWorkspaceId());
    }

    public function testSignedInWithNoPersonBehindItIsTheSystem(): void
    {
        TenantContext::setTenantId(self::WORKSPACE);
        $request = new Request('GET', '/api/v1/acme/things');
        $request->user = (object) ['type' => 'service'];

        $this->throughKernelMiddleware($request);

        self::assertNotNull($this->seen);
        self::assertSame(SdkRequest::ACTOR_SYSTEM, $this->seen->getActorKind());
    }

    public function testAnAgentsToolCallReadsAsAnAgentInThePersonsLanguage(): void
    {
        $profile = $this->profileWithLanguage('ar');

        $this->callTool(new McpPrincipal($profile, $profile, self::WORKSPACE, McpPrincipal::KIND_AGENT, ['tools:call'], 'agent-jti'));

        self::assertNotNull($this->seen);
        self::assertTrue($this->seen->isAgent());
        self::assertSame(SdkRequest::ACTOR_AGENT, $this->seen->getActorKind());
        self::assertSame(self::WORKSPACE, $this->seen->getWorkspaceId());
        self::assertSame('ar', $this->seen->getLanguage());
    }

    public function testAPersonsToolCallReadsAsAPerson(): void
    {
        $profile = $this->profileWithLanguage(null);

        $this->callTool(new McpPrincipal($profile, $profile, self::WORKSPACE, 'user', ['tools:call'], 'person-jti'));

        self::assertNotNull($this->seen);
        self::assertSame(SdkRequest::ACTOR_PERSON, $this->seen->getActorKind());
        self::assertSame('en', $this->seen->getLanguage());
    }

    public function testAnAgentsResourceReadReadsAsAnAgent(): void
    {
        $profile = $this->profileWithLanguage('ar');
        $principal = new McpPrincipal($profile, $profile, self::WORKSPACE, McpPrincipal::KIND_AGENT, ['resources:read'], 'agent-jti');
        $router = new Router('');
        $router->registerUnversioned('GET', '/api/acme/things', $this->capture(...), null, null, 'acme:read', ['mcp' => true]);
        $tokens = $this->createMock(TokenValidator::class);
        $tokens->method('validateMcpToken')->willReturn($principal);
        TenantContext::setTenantId(self::WORKSPACE);

        (new ResourcesReadHandler($router, $this->roles(), $tokens, languages: $this->resolver))(['uri' => 'whity-api:///api/acme/things'], 'token');

        self::assertNotNull($this->seen);
        self::assertSame(SdkRequest::ACTOR_AGENT, $this->seen->getActorKind());
        self::assertSame(self::WORKSPACE, $this->seen->getWorkspaceId());
        self::assertSame('ar', $this->seen->getLanguage());
    }

    private function callTool(McpPrincipal $principal): void
    {
        $router = new Router('');
        $router->registerUnversioned('GET', '/api/acme/things', $this->capture(...), null, null, 'acme:read', [
            'operationId' => 'list_acme_things',
            'summary'     => 'List things',
        ]);
        $tokens = $this->createMock(TokenValidator::class);
        $tokens->method('validateBearerForMcp')->willReturn($principal);
        TenantContext::setTenantId(self::WORKSPACE);

        $handler = new ToolsCallHandler(new ToolDeriver([], [], $router), $router, $this->roles(), $tokens, languages: $this->resolver);
        $handler(['name' => 'list_acme_things', 'arguments' => []], 'token');
    }

    private function roles(): RoleChecker
    {
        $roles = $this->createMock(RoleChecker::class);
        $roles->method('hasPermissionForProfile')->willReturn(true);
        return $roles;
    }

    private function throughKernelMiddleware(Request $request): void
    {
        $stamp = new StampRequestContext($this->languages);
        (new ResolveLanguage($this->resolver, $this->languages))->handle(
            $request,
            fn (SdkRequest $r): SdkResponse => $stamp->handle($r, $this->capture(...)),
        );
    }

    private function capture(SdkRequest $request): SdkResponse
    {
        $this->seen = $request;
        return SdkResponse::json(['ok' => true]);
    }

    private function profileWithLanguage(?string $code): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO profiles (display_name, password_hash, language_code, created_at)
             VALUES (:name, :hash, :code, NOW())'
        );
        $stmt->execute([':name' => 'Rana', ':hash' => 'not-a-credential', ':code' => $code]);

        return (int) $this->pdo->lastInsertId();
    }
}
