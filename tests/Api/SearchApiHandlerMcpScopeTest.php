<?php

declare(strict_types=1);

namespace Tests\Api;

use PHPUnit\Framework\TestCase;
use Whity\Api\SearchApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Request;
use Whity\Core\Search\SearchContext;
use Whity\Core\Search\SearchHit;
use Whity\Core\Search\SearchService;
use Whity\Core\Search\SearchSource;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\Auth\McpCallerScope;

/**
 * #2253: `/api/search` has no route gate, so tools/call cannot apply an MCP
 * credential's narrowing to it. The handler does: a type whose permission an
 * agent's grant sets to Off (or Ask first: there is no approval for a read),
 * or a narrowed token leaves out, is neither searched nor listed, even though
 * the person behind it holds every permission.
 */
final class SearchApiHandlerMcpScopeTest extends TestCase
{
    private const TENANT  = 3;
    private const PROFILE = 7;

    /** @var \ArrayObject<int, string> */
    private \ArrayObject $searched;

    protected function setUp(): void
    {
        TenantContext::setTenantId(self::TENANT);
        $this->searched = new \ArrayObject();
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    private function handler(): SearchApiHandler
    {
        $roles = $this->createMock(RoleChecker::class);
        $roles->method('hasPermissionForProfile')->willReturn(true);
        $sources = [$this->source('people', 'users:read'), $this->source('documents', 'documents:read')];

        return new SearchApiHandler(new SearchService(static fn (): array => $sources), $roles);
    }

    private function source(string $type, string $permission): SearchSource
    {
        return new class ($type, $permission, $this->searched) implements SearchSource {
            /** @param \ArrayObject<int, string> $searched */
            public function __construct(private string $type, private string $permission, private \ArrayObject $searched)
            {
            }

            public function type(): string
            {
                return $this->type;
            }

            public function label(): array
            {
                return ['en' => $this->type, 'ar' => $this->type];
            }

            public function permission(): string
            {
                return $this->permission;
            }

            public function search(SearchContext $context, string $pattern, int $limit): array
            {
                $this->searched->append($this->type);
                return [];
            }
        };
    }

    /** @param array<string, mixed> $claims */
    private static function request(string $path, array $claims): Request
    {
        $request = new Request('GET', $path, [], '');
        $request->user = (object) (['profile_id' => self::PROFILE, 'active_tenant_id' => self::TENANT] + $claims);
        return $request;
    }

    /**
     * @param array<string, mixed> $claims
     * @return list<string>
     */
    private function typesFor(array $claims): array
    {
        $response = $this->handler()->types(self::request('/api/search/types', $claims));
        self::assertSame(200, $response->getStatusCode(), $response->getBody());
        $decoded = json_decode($response->getBody(), true);
        self::assertIsArray($decoded);

        return array_column($decoded['data'], 'type');
    }

    public function testAPersonSignedInSearchesEveryTypeTheyHold(): void
    {
        self::assertSame(['people', 'documents'], $this->typesFor([]));
    }

    public function testAnAgentDoesNotSearchATypeItsGrantSetsToOffOrAskFirst(): void
    {
        $agent = ['type' => 'mcp', 'principal_kind' => 'ai-agent', 'scope' => ['grant:users:read=off', 'grant:documents:read=ask']];

        self::assertSame([], $this->typesFor($agent));

        $this->handler()->search(self::request('/api/search?q=amro', $agent));
        self::assertSame([], $this->searched->getArrayCopy(), 'no source was searched');
    }

    public function testAnAgentSearchesWhatItsGrantLeavesAllowed(): void
    {
        $agent = ['type' => 'mcp', 'principal_kind' => 'ai-agent', 'scope' => ['grant:users:read=off']];

        self::assertSame(['documents'], $this->typesFor($agent));

        $this->handler()->search(self::request('/api/search?q=amro', $agent));
        self::assertSame(['documents'], $this->searched->getArrayCopy());
    }

    public function testATokenNarrowedToOnePermissionSearchesOnlyThatType(): void
    {
        self::assertSame(['documents'], $this->typesFor(['type' => 'mcp', 'principal_kind' => 'user', 'scope' => ['tools:call', 'documents:read']]));
    }

    public function testAWriteIsRefusedOnlyWhenItsGrantIsOff(): void
    {
        $agent = (object) ['type' => 'mcp', 'principal_kind' => 'ai-agent', 'scope' => ['grant:acme:manage=off', 'grant:acme:retire=ask']];

        self::assertFalse(McpCallerScope::permits($agent, 'acme:manage', false));
        self::assertTrue(McpCallerScope::permits($agent, 'acme:retire', false), 'Ask first: tools/call held it for approval already');
        self::assertFalse(McpCallerScope::permits($agent, 'acme:retire', true), 'a read needs Allowed');
        self::assertTrue(McpCallerScope::permits((object) ['profile_id' => 1, 'scope' => ['grant:acme:manage=off']], 'acme:manage', false), 'not over MCP');
    }
}
