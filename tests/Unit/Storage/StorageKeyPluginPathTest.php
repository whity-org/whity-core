<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Whity\Storage\StorageKey;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * StorageKey::pluginPath() is the one place a plugin's file name becomes a
 * storage key (WHIT-886). It must REJECT a traversal rather than rewrite it,
 * because a rewritten `../other/x` lands somewhere the plugin did not ask for.
 */
final class StorageKeyPluginPathTest extends TestCase
{
    public function testBuildsAKeyInsideThePluginsOwnPrefix(): void
    {
        $this->assertSame(
            'tenants/7/reports/exports/march.csv',
            StorageKey::pluginPath(7, 'reports', 'exports/march.csv'),
        );
        $this->assertSame('tenants/7/reports/a.pdf', StorageKey::pluginPath(7, 'reports', 'a.pdf'));
    }

    public function testThePrefixIsTheSameForEveryFileOfThePlugin(): void
    {
        $prefix = StorageKey::pluginPrefix(7, 'reports');

        $this->assertSame('tenants/7/reports/', $prefix);
        $this->assertTrue(str_starts_with(StorageKey::pluginPath(7, 'reports', 'x/y.txt'), $prefix));
    }

    /** @return iterable<string, array{string}> */
    public static function traversalAttempts(): iterable
    {
        yield 'parent segment' => ['../ledger/secret.txt'];
        yield 'parent in middle' => ['exports/../../ledger/secret.txt'];
        yield 'dot segment' => ['./a.txt'];
        yield 'dot-name segment' => ['.hidden'];
        yield 'dot-dot alone' => ['..'];
        yield 'absolute path' => ['/etc/passwd'];
        yield 'backslash' => ['exports\a.txt'];
        yield 'nul byte' => ["a\0b.txt"];
        yield 'empty segment' => ['exports//a.txt'];
        yield 'trailing slash' => ['exports/'];
        yield 'empty path' => [''];
        yield 'control character' => ["a\nb.txt"];
    }

    #[DataProvider('traversalAttempts')]
    public function testRefusesAPathThatWouldLeaveThePluginsPrefix(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);

        StorageKey::pluginPath(7, 'reports', $path);
    }

    public function testRefusesAPathLongerThanTheLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        StorageKey::pluginPath(7, 'reports', str_repeat('a', 513));
    }

    public function testRefusesAWorkspaceOfZeroOrLess(): void
    {
        $this->expectException(InvalidArgumentException::class);

        StorageKey::pluginPath(0, 'reports', 'a.txt');
    }

    public function testRefusesAPluginSlugThatIsNotAPlainSegment(): void
    {
        $this->expectException(InvalidArgumentException::class);

        StorageKey::pluginPrefix(7, '../ledger');
    }
}
