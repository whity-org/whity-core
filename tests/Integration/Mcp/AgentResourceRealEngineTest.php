<?php

declare(strict_types=1);

namespace Tests\Integration\Mcp;

use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\JwtParser;
use Whity\Auth\Oidc\JwksProvider;
use Whity\Auth\TokenValidator;
use Whity\Core\Agent\AgentAssignmentRepository;
use Whity\Core\Agent\AgentRepository;
use Whity\Core\Http\HttpClient;
use Whity\Core\Identity\ExternalIdentityRepository;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\Middleware\EnforceTenantIsolation;
use Whity\Mcp\Auth\McpPrincipal;
use Whity\Mcp\Auth\McpTokenService;
use Whity\Mcp\Auth\OAuth\OAuthAccessTokenVerifier;
use Whity\Mcp\Auth\OAuth\OAuthTokenRefusedException;
use Whity\Mcp\Auth\OAuth\ProtectedResource;
use Whity\Mcp\JsonRpc\Dispatcher;
use Whity\Mcp\Lifecycle\PingHandler;
use Whity\Mcp\Transport\McpTransportHandler;

/**
 * A workspace agent as its own OAuth resource (WHIT-819): `/mcp/agents/<slug>`.
 * A token for it acts as that agent, for the person, under their grant. Nothing
 * else can use that address: least of all a person's own token, whose writes
 * would skip Ask first at a URL that says they are the agent's.
 */
final class AgentResourceRealEngineTest extends TestCase
{
    private const ISSUER   = 'https://id.whity.test';
    private const RESOURCE = 'https://app.whity.test/mcp';
    private const KID      = 'key-1';
    private const TENANT   = 1;
    private const RANA     = 10;

    private \PDO $pdo;
    private string $privatePem = '';
    /** @var array<string, mixed> */
    private array $jwks = [];
    private AgentRepository $agents;
    private AgentAssignmentRepository $assignments;
    private int $clerk;
    private int $hr;

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->pdo = SchemaFromMigrations::make(true);

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        openssl_pkey_export($key, $pem);
        $this->privatePem = $pem;
        $details = openssl_pkey_get_details($key);
        self::assertNotFalse($details);
        $this->jwks = ['keys' => [[
            'kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => self::KID,
            'n' => self::b64url($details['rsa']['n']), 'e' => self::b64url($details['rsa']['e']),
        ]]];

        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery')");
        $this->pdo->exec("INSERT INTO roles (id, name) VALUES (1, 'admin') ON CONFLICT DO NOTHING");
        $this->pdo->prepare("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                             VALUES (?, 'Rana', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)")->execute([self::RANA]);
        $this->pdo->prepare("INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at) VALUES (?, 1, 1, 'active', CURRENT_TIMESTAMP)")->execute([self::RANA]);
        SchemaFromMigrations::syncSequences($this->pdo);
        (new ExternalIdentityRepository($this->pdo))->link(self::RANA, 'whity-auth', self::ISSUER, 'sub-rana');

        $this->agents      = new AgentRepository($this->pdo);
        $this->assignments = new AgentAssignmentRepository($this->pdo);
        $this->clerk = $this->agents->create(self::TENANT, ['slug' => 'finance-clerk', 'name' => 'Finance clerk', 'toolsets' => ['core.billing']], self::RANA);
        $this->hr    = $this->agents->create(self::TENANT, ['slug' => 'hr-assistant', 'name' => 'HR assistant'], self::RANA);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testATokenForTheAgentActsAsThatAgentForThePerson(): void
    {
        $assignment = $this->assignments->assign($this->clerk, self::RANA, self::TENANT, ['billing:view' => 'allow']);

        $principal = $this->verifier()->verify($this->token(self::RESOURCE . '/agents/finance-clerk'));

        self::assertInstanceOf(McpPrincipal::class, $principal);
        self::assertSame('ai-agent', $principal->principalKind);
        self::assertSame(self::RANA, $principal->userId);
        self::assertSame('agent:' . $assignment, $principal->agentPrincipalId());
        self::assertSame('finance-clerk', $principal->agentSlug);
        // WHIT-837: what a plugin tool is told about who is calling.
        self::assertSame('Finance clerk', $principal->agentName);
        self::assertSame('sub-rana', $principal->subject);
        self::assertSame('agents/finance-clerk', $principal->resourceSplit);
        self::assertContains('toolset:core.billing', $principal->scope);
        self::assertContains('grant:billing:view=allow', $principal->scope);
    }

    public function testWithoutUsingTheAgentTheAnswerSaysWhatToDo(): void
    {
        $this->expectException(OAuthTokenRefusedException::class);
        $this->expectExceptionMessage("You don't use this agent");

        $this->verifier()->verify($this->token(self::RESOURCE . '/agents/finance-clerk'));
    }

    public function testAStoppedUseOrAnArchivedAgentIsRefused(): void
    {
        $assignment = $this->assignments->assign($this->clerk, self::RANA, self::TENANT, []);
        $this->assignments->revoke($assignment, self::TENANT, self::RANA);
        try {
            $this->verifier()->verify($this->token(self::RESOURCE . '/agents/finance-clerk'));
            self::fail('a stopped use is refused');
        } catch (OAuthTokenRefusedException) {
        }

        $this->assignments->assign($this->hr, self::RANA, self::TENANT, []);
        $this->agents->setStatus($this->hr, self::TENANT, AgentRepository::ARCHIVED, self::RANA);
        $this->expectException(OAuthTokenRefusedException::class);
        $this->verifier()->verify($this->token(self::RESOURCE . '/agents/hr-assistant'));
    }

    public function testOnlyThatAgentCanUseTheAddress(): void
    {
        $this->assignments->assign($this->clerk, self::RANA, self::TENANT, []);
        $hrAssignment = $this->assignments->assign($this->hr, self::RANA, self::TENANT, []);
        $clerk = $this->token(self::RESOURCE . '/agents/finance-clerk');

        self::assertSame(200, $this->post($clerk, ['agent' => 'finance-clerk'])->getStatusCode());
        self::assertSame(401, $this->post($clerk, ['agent' => 'hr-assistant'])->getStatusCode(), 'another agent\'s address');
        self::assertSame(401, $this->post($clerk, [])->getStatusCode(), 'bound to its own address, not /mcp');

        // A person's own token is refused: its writes would skip Ask first here.
        $person = $this->token(self::RESOURCE);
        self::assertSame(200, $this->post($person, [])->getStatusCode());
        $refused = $this->post($person, ['agent' => 'finance-clerk']);
        self::assertSame(401, $refused->getStatusCode());
        self::assertStringContainsString('/.well-known/oauth-protected-resource/mcp/agents/finance-clerk', $refused->getHeaders()['www-authenticate'] ?? '');

        // A Whity credential works at its own agent's address and at /mcp.
        $credential = (new McpTokenService($this->pdo, $this->jwtParser()))->issueForAssignment(self::RANA, self::TENANT, 'Claude Code', ['tools:call'], $hrAssignment)['token'];
        self::assertSame(200, $this->post($credential, ['agent' => 'hr-assistant'])->getStatusCode());
        self::assertSame(401, $this->post($credential, ['agent' => 'finance-clerk'])->getStatusCode());
        self::assertSame(200, $this->post($credential, [])->getStatusCode());
    }

    public function testTheAgentHasItsOwnPublicMetadata(): void
    {
        $resource = (new ProtectedResource(self::ISSUER, self::RESOURCE))->forAgent('finance-clerk');
        $document = json_decode($resource->metadata(new Request('GET', '/', [], ''))->getBody(), true);

        self::assertSame(self::RESOURCE . '/agents/finance-clerk', $document['resource']);
        self::assertSame('Finance clerk', $document['resource_name']);
        self::assertSame([self::ISSUER], $document['authorization_servers']);
        self::assertSame('https://app.whity.test/.well-known/oauth-protected-resource/mcp/agents/finance-clerk', $resource->metadataUrl());
    }

    public function testTheAddressBypassesTenantResolutionAndNothingUnderIt(): void
    {
        self::assertTrue(EnforceTenantIsolation::pathBypassesTenantResolution('/mcp/agents/finance-clerk'));
        self::assertTrue(EnforceTenantIsolation::pathBypassesTenantResolution('/.well-known/oauth-protected-resource/mcp/agents/finance-clerk'));
        self::assertFalse(EnforceTenantIsolation::pathBypassesTenantResolution('/mcp/agents/finance-clerk/more'));
        self::assertFalse(EnforceTenantIsolation::pathBypassesTenantResolution('/mcp/forms/more'));
    }

    /** @param array<string, string> $params */
    private function post(string $bearer, array $params): \Whity\Sdk\Http\Response
    {
        TenantContext::reset();
        $validator = new TokenValidator($this->jwtParser(), $this->pdo);
        $validator->acceptOAuthAccessTokens($this->verifier());
        $transport = new McpTransportHandler(
            new Dispatcher(['ping' => new PingHandler()], $validator),
            protectedResource: new ProtectedResource(self::ISSUER, self::RESOURCE),
        );
        $body = '{"jsonrpc":"2.0","method":"ping","id":1}';

        return $transport->handlePost(new Request('POST', '/mcp?tools=full', ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $bearer], $body), $params);
    }

    private function jwtParser(): JwtParser
    {
        return new JwtParser(str_repeat('i', 40));
    }

    private function verifier(): OAuthAccessTokenVerifier
    {
        $http = new class implements HttpClient {
            public function getJson(string $url): ?array
            {
                return ['issuer' => AgentResourceRealEngineTest::issuer(), 'jwks_uri' => AgentResourceRealEngineTest::issuer() . '/jwks'];
            }

            public function postForm(string $url, array $params): ?array
            {
                return null;
            }
        };
        $jwks = new JwksProvider(fn (string $uri): array => $this->jwks);

        return new OAuthAccessTokenVerifier(self::ISSUER, self::RESOURCE, $http, $jwks, $this->pdo);
    }

    public static function issuer(): string
    {
        return self::ISSUER;
    }

    private function token(string $audience): string
    {
        return \Firebase\JWT\JWT::encode([
            'iss' => self::ISSUER, 'sub' => 'sub-rana', 'aud' => $audience, 'client_id' => 'https://client.test/app.json',
            'scope' => 'tools:call', 'iat' => time(), 'exp' => time() + 600, 'jti' => bin2hex(random_bytes(8)),
        ], $this->privatePem, 'RS256', self::KID);
    }

    private static function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
