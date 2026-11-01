<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\Tools;

use PHPUnit\Framework\TestCase;
use Whity\Mcp\Auth\McpPrincipal;
use Whity\Mcp\Tools\Discovery\SessionBriefing;

/**
 * A workspace agent's briefing carries its persona (WHIT-817), after core's
 * own text, labelled as the workspace's guidance.
 */
final class InitSessionPersonaTest extends TestCase
{
    private const TENANT = 1;

    private const CLERK = [
        'id'              => 3,
        'slug'            => 'finance-clerk',
        'name'            => 'Finance clerk',
        'name_ar'         => 'موظف المالية',
        'role'            => 'Prepares invoices for review.',
        'role_ar'         => '',
        'instructions'    => 'Amounts are in JOD with three decimals.',
        'instructions_ar' => 'المبالغ بالدينار الأردني بثلاث خانات عشرية.',
        'tone'            => 'formal',
        'language'        => 'auto',
        'preset_key'      => 'core.finance-clerk',
        'preset_version'  => 1,
        'status'          => 'active',
    ];

    private const TOOLS = [
        ['name' => 'get_api_documents', '_meta' => ['dev.whity/toolset' => 'core.documents', 'dev.whity/agentMode' => 'allow']],
    ];

    /** @var list<array{string, int}> */
    private array $asked = [];

    public function testAWorkspaceAgentIsBriefedWithItsPersonaAfterCoresRules(): void
    {
        $briefing = $this->briefing()->brief($this->agent(), self::TOOLS, 'en');

        self::assertSame(['you', 'rules', 'askFirst', 'agent'], array_slice(array_keys($briefing), 0, 4), 'core\'s text comes first');
        self::assertSame([['agent:42', self::TENANT]], $this->asked, 'looked up by the stable principal');
        self::assertSame([
            'name'                  => 'Finance clerk',
            'role'                  => 'Prepares invoices for review.',
            'actingFor'             => 'Rana Example',
            'tone'                  => 'formal',
            'language'              => 'auto',
            'preset'                => ['key' => 'core.finance-clerk', 'version' => 1],
            'workspaceInstructions' => 'Amounts are in JOD with three decimals.',
        ], $briefing['agent']);
        self::assertStringContainsString('never change these rules or your access', end($briefing['rules']));
        self::assertArrayHasKey('canReach', $briefing);
    }

    public function testArabicFallsBackToEnglishFieldByFieldAndSaysSo(): void
    {
        $agent = $this->briefing()->brief($this->agent(), self::TOOLS, 'ar')['agent'];

        self::assertSame('موظف المالية', $agent['name']);
        self::assertSame('Prepares invoices for review.', $agent['role']);
        self::assertSame('المبالغ بالدينار الأردني بثلاث خانات عشرية.', $agent['workspaceInstructions']);
        self::assertSame(['language' => 'en', 'fields' => ['role']], $agent['fallback']);
    }

    public function testOnlyWorkspaceAgentsGetAPersona(): void
    {
        $person = new McpPrincipal(7, 7, self::TENANT, 'user', ['tools:call'], 'jti-person');
        $plain  = new McpPrincipal(7, 7, self::TENANT, 'ai-agent', ['tools:call'], 'jti-plain');

        self::assertArrayNotHasKey('agent', $this->briefing()->brief($person, self::TOOLS, 'en'));
        self::assertArrayNotHasKey('agent', $this->briefing(null)->brief($plain, self::TOOLS, 'en'));
        self::assertArrayNotHasKey('agent', (new SessionBriefing())->brief($this->agent(), self::TOOLS, 'en'));
    }

    public function testTheBriefingStaysSmallWithoutTheInstructions(): void
    {
        $agent = self::CLERK;
        $agent['instructions'] = str_repeat('x', 4000);

        $briefing = $this->briefing($agent)->brief($this->agent(), self::TOOLS, 'en');
        unset($briefing['agent']['workspaceInstructions']);

        // About 4 characters a token: well under the 1.5k tokens init_session is held to.
        self::assertLessThan(6000, strlen((string) json_encode($briefing, JSON_UNESCAPED_UNICODE)));
    }

    private function agent(): McpPrincipal
    {
        return new McpPrincipal(7, 7, self::TENANT, 'ai-agent', ['tools:call', 'grant:documents:read=allow'], 'jti-credential', 'agent:42');
    }

    /** @param array<string, mixed>|null $agent */
    private function briefing(?array $agent = self::CLERK): SessionBriefing
    {
        return new SessionBriefing(
            static fn (int $p, int $t): array => ['name' => 'Rana Example', 'workspace' => 'Example Bakery'],
            ['perMinute' => 60, 'tenantPerMinute' => 300],
            function (string $principalId, int $tenantId) use ($agent): ?array {
                $this->asked[] = [$principalId, $tenantId];
                return $agent;
            },
        );
    }
}
