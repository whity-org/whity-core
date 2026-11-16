<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;
use Whity\Mcp\Tools\ToolDeriver;
use Whity\OpenAPI\CoreApiSchemas;

/**
 * AG.1: a gated approval ({@see \Whity\Core\Security\GatedApproval}) is made by
 * a person who confirms it's them, on the gated page. An agent can't make one,
 * and can't even propose one, so neither the approve routes nor the routes
 * that start the identity check are tools.
 */
final class GatedApprovalsStayOffMcpTest extends TestCase
{
    /** Every route that applies a gated approval. */
    private const GATED_APPROVALS = [
        'post_api_password_resets_id_approve',
        'post_api_2fa_recovery_id_approve',
        'post_api_2fa_recovery_force_reset',
    ];

    protected function setUp(): void
    {
        ToolDeriver::clearCache();
    }

    protected function tearDown(): void
    {
        ToolDeriver::clearCache();
    }

    public function testNoGatedApprovalIsAnAgentTool(): void
    {
        $deriver = $this->deriver();
        $names   = array_column($deriver->deriveTools(), 'name');

        foreach (self::GATED_APPROVALS as $name) {
            self::assertContains($name, $names, "{$name} is still a catalogued route");
            self::assertFalse($deriver->isExposed($name), "{$name} must not be on the MCP surface");
        }
    }

    public function testNoIdentityCheckIsAnAgentTool(): void
    {
        $deriver = $this->deriver();
        $checks  = array_values(array_filter(
            array_column($deriver->deriveTools(), 'name'),
            static fn (string $name): bool => str_ends_with($name, '_identity_check'),
        ));

        self::assertNotEmpty($checks);
        foreach ($checks as $name) {
            self::assertFalse($deriver->isExposed($name), "{$name} must not be on the MCP surface");
        }
    }

    private function deriver(): ToolDeriver
    {
        return new ToolDeriver(CoreApiSchemas::routes(), CoreApiSchemas::components(), null, static fn (string $m): null => null);
    }
}
