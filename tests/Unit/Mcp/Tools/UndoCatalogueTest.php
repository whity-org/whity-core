<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\Tools;

use PHPUnit\Framework\TestCase;
use Whity\Core\Router;
use Whity\Mcp\Tools\ToolDeriver;
use Whity\Mcp\Tools\Undo\UndoSpec;
use Whity\OpenAPI\CoreApiSchemas;

/**
 * Every undo a core route declares can actually run (WHIT-812): it is well
 * formed, its inverse and its read are real operations an MCP client can be
 * offered, and the inverse needs no more than the operation it reverses.
 */
final class UndoCatalogueTest extends TestCase
{
    protected function setUp(): void
    {
        ToolDeriver::clearCache();
    }

    protected function tearDown(): void
    {
        ToolDeriver::clearCache();
    }

    public function testEveryDeclaredUndoCanRun(): void
    {
        $deriver = new ToolDeriver(CoreApiSchemas::routes(), [], new Router(''), static fn (string $m): null => null);
        $access  = $deriver->buildAccessMap();
        $checked = 0;

        foreach (CoreApiSchemas::routes() as $route) {
            $schema = $route['schema'];
            $raw    = is_array($schema['mcp'] ?? null) ? ($schema['mcp']['undo'] ?? null) : null;
            if ($raw === null) {
                continue;
            }
            $where = "{$route['method']} {$route['path']}";
            self::assertNull(UndoSpec::problem($raw), "{$where}: malformed undo");
            $spec = UndoSpec::fromRouteSchema($schema);
            self::assertNotNull($spec);
            $checked++;

            foreach (array_filter([$spec->operation, $spec->readOperation]) as $operation) {
                self::assertNotNull($deriver->findDeclarationByName($operation), "{$where}: {$operation} is not an operation");
                self::assertTrue($deriver->isExposed($operation), "{$where}: {$operation} is not offered to MCP clients");
            }
            self::assertSame(
                $route['requiredPermission'],
                $access[(string) $spec->operation]['requiredPermission'] ?? null,
                "{$where}: undoing it must need the same permission as doing it",
            );
        }

        self::assertGreaterThanOrEqual(6, $checked, 'tags, tag groups and forms declare an undo');
    }
}
