<?php

declare(strict_types=1);

namespace Tests\Sdk;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `Whity\Sdk\Contribute\*` and the workspace-named aliases (SDK 1.161, D6).
 *
 * Every contribution point has the name it keeps in 2.0, and the two names are
 * one type: a plugin implementing either passes the host's checks, and the old
 * name says which name replaces it. A new contribution point that forgets its
 * `Contribute\` name fails here.
 */
final class ContributeAliasesTest extends TestCase
{
    /** Deprecated outright (D5, TH.13): they get no 2.0 name and go after 2.0. */
    private const RETIRING = [
        'Whity\\Sdk\\PluginHomeCardInterface',
        'Whity\\Sdk\\PluginThemeInterface',
    ];

    /** Not contribution points: the plugin itself. */
    private const NOT_POINTS = [
        'Whity\\Sdk\\PluginInterface',
    ];

    /** Names that change in 2.0 beyond the namespace. */
    private const RENAMED = [
        'Whity\\Sdk\\PluginMcpInterface' => 'Whity\\Sdk\\Contribute\\PluginMcpPromptsInterface',
        'Whity\\Sdk\\Testing\\TenantIsolationConformanceTestCase' => 'Whity\\Sdk\\Testing\\WorkspaceIsolationConformanceTestCase',
    ];

    /** @return array<string, array{string, string}> old => [old, new] */
    public static function aliases(): array
    {
        $cases = [];
        foreach (self::contributionPoints() as $old) {
            $new = self::RENAMED[$old] ?? 'Whity\\Sdk\\Contribute\\' . self::short($old);
            $cases[$old] = [$old, $new];
        }
        foreach (self::RENAMED as $old => $new) {
            $cases[$old] = [$old, $new];
        }
        ksort($cases);

        return $cases;
    }

    #[DataProvider('aliases')]
    public function testTheNewNameIsTheSameType(string $old, string $new): void
    {
        self::assertTrue(interface_exists($new) || class_exists($new), "{$new} should autoload");
        self::assertSame($old, (new \ReflectionClass($new))->getName(), "{$new} is a class_alias of {$old}");
    }

    #[DataProvider('aliases')]
    public function testTheOldNameIsDeprecatedInFavourOfTheNewOne(string $old, string $new): void
    {
        $doc = (string) (new \ReflectionClass($old))->getDocComment();

        self::assertMatchesRegularExpression(
            '/@deprecated [^\n]*' . preg_quote('{@see \\' . $new . '}', '/') . '/',
            $doc,
            "{$old} names {$new} as its replacement"
        );
    }

    public function testAPluginMayImplementEitherName(): void
    {
        $new = new class () implements \Whity\Sdk\Contribute\PluginDisplayNameInterface {
            public function getDisplayName(): array
            {
                return ['en' => 'Example Bakery', 'ar' => 'Example Bakery'];
            }
        };
        $old = new class () implements \Whity\Sdk\PluginDisplayNameInterface {
            public function getDisplayName(): array
            {
                return ['en' => 'Example Bakery', 'ar' => 'Example Bakery'];
            }
        };

        self::assertInstanceOf(\Whity\Sdk\PluginDisplayNameInterface::class, $new, 'the host checks the old name');
        self::assertInstanceOf(\Whity\Sdk\Contribute\PluginDisplayNameInterface::class, $old);
    }

    public function testEveryContributionPointIsCovered(): void
    {
        // A floor, so a scan that stops matching can't pass by finding nothing.
        self::assertGreaterThanOrEqual(41, count(self::contributionPoints()));
    }

    /** @return list<class-string> Plugin*Interface outside Contribute\, minus the plugin itself and those retiring. */
    private static function contributionPoints(): array
    {
        $base = dirname(__DIR__, 2) . '/sdk/src/';
        $points = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || preg_match('/^Plugin\w+Interface\.php$/', $file->getFilename()) !== 1) {
                continue;
            }
            $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen(str_replace('\\', '/', $base)), -4);
            if (str_starts_with($relative, 'Contribute/')) {
                continue;
            }
            $type = 'Whity\\Sdk\\' . str_replace('/', '\\', $relative);
            if (!interface_exists($type) || in_array($type, self::RETIRING, true) || in_array($type, self::NOT_POINTS, true)) {
                continue;
            }
            $points[] = $type;
        }
        sort($points);

        return $points;
    }

    private static function short(string $type): string
    {
        return substr($type, (int) strrpos($type, '\\') + 1);
    }
}
