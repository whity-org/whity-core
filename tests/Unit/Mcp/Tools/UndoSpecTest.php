<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\Tools;

use PHPUnit\Framework\TestCase;
use Whity\Mcp\Tools\Undo\UndoSpec;

/** How an undo declaration is read and its inverse built (WHIT-812). */
final class UndoSpecTest extends TestCase
{
    public function testPointersResolveAgainstArgumentsResultAndBefore(): void
    {
        $document = [
            'arguments' => ['id' => 7],
            'result'    => ['data' => ['id' => 9, 'name' => 'Urgent']],
            'before'    => ['data' => ['name' => 'Old', 'description' => null]],
        ];

        self::assertSame(
            ['id' => 7, 'name' => 'Old', 'description' => null, 'kind' => 'tag', 'nested' => ['ref' => 9]],
            UndoSpec::resolve(
                ['id' => '/arguments/id', 'name' => '/before/data/name', 'description' => '/before/data/description', 'kind' => 'tag', 'nested' => ['ref' => '/result/data/id']],
                $document,
            ),
        );
    }

    public function testAnInverseThatPointsAtNothingIsNotBuilt(): void
    {
        self::assertNull(UndoSpec::resolve(['id' => '/result/data/id'], ['result' => []]));
    }

    public function testPointerEscapes(): void
    {
        self::assertSame([true, 1], UndoSpec::pointer(['a/b' => ['c~d' => 1]], '/a~1b/c~0d'));
        self::assertSame([false, null], UndoSpec::pointer(['a' => 1], '/b'));
    }

    /** @return array<string, array{mixed}> */
    public static function malformed(): array
    {
        return [
            'no window'        => [['operation' => 'x']],
            'zero window'      => [['windowSeconds' => 0, 'operation' => 'x']],
            'no inverse'       => [['windowSeconds' => 60]],
            'arguments a list' => [['windowSeconds' => 60, 'operation' => 'x', 'arguments' => 'id']],
            'read without op'  => [['windowSeconds' => 60, 'operation' => 'x', 'read' => ['arguments' => []]]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformed')]
    public function testAMalformedUndoIsRefused(mixed $raw): void
    {
        self::assertNotNull(UndoSpec::problem($raw));
        self::assertNull(UndoSpec::fromRouteSchema(['mcp' => ['undo' => $raw]]));
    }

    public function testWatchedPathsAreTheBeforePointers(): void
    {
        $spec = UndoSpec::fromRouteSchema(['mcp' => ['undo' => [
            'windowSeconds' => 60,
            'operation'     => 'patch_x',
            'arguments'     => ['id' => '/arguments/id', 'name' => '/before/data/name', 'meta' => ['k' => '/before/data/key']],
            'read'          => ['operation' => 'get_x', 'arguments' => ['id' => '/arguments/id']],
        ]]]);

        self::assertNotNull($spec);
        self::assertSame(['/data/name', '/data/key'], $spec->watchedPaths());
    }

    public function testOnlyAnAuthoredToolMayUseAHandler(): void
    {
        $raw = ['windowSeconds' => 60, 'handler' => static fn (): null => null];

        self::assertNull(UndoSpec::fromRouteSchema(['mcp' => ['undo' => $raw]]));
        self::assertTrue(UndoSpec::fromAuthored($raw)?->hasHandler);
    }
}
