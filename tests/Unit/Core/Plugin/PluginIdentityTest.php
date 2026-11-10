<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Plugin;

use PHPUnit\Framework\TestCase;
use Whity\Core\Plugin\PluginIdentity;
use Whity\Sdk\PluginInterface;

final class PluginIdentityTest extends TestCase
{
    public function testALoadedPluginHasTheSlugItWasLoadedUnder(): void
    {
        $plugin = self::plugin('identity-one');
        PluginIdentity::bind($plugin, 'identity-one');

        self::assertSame('identity_one', PluginIdentity::slugOf($plugin));
    }

    public function testAStubClaimingALoadedPluginsNameIsRefused(): void
    {
        PluginIdentity::bind(self::plugin('identity-two'), 'identity-two');

        $this->expectException(\InvalidArgumentException::class);
        PluginIdentity::slugOf(self::plugin('identity-two'));
    }

    public function testAPluginThatRenamesItselfAfterLoadingKeepsItsSlug(): void
    {
        $name = new \ArrayObject(['identity-three']);
        $plugin = self::plugin('identity-three', $name);
        PluginIdentity::bind($plugin, 'identity-three');
        $name[0] = 'someone-else';

        self::assertSame('identity_three', PluginIdentity::slugOf($plugin));
    }

    public function testASecondNameReducingToTheSameSlugGetsNoIdentity(): void
    {
        PluginIdentity::bind(self::plugin('identity-four'), 'identity-four');
        $second = self::plugin('identity_four');
        PluginIdentity::bind($second, 'identity_four');

        $this->expectException(\InvalidArgumentException::class);
        PluginIdentity::slugOf($second);
    }

    /** @param \ArrayObject<int, string>|null $changing lets a test change the name after loading */
    private static function plugin(string $name, ?\ArrayObject $changing = null): PluginInterface
    {
        return new class ($name, $changing) implements PluginInterface {
            /** @param \ArrayObject<int, string>|null $changing */
            public function __construct(private string $name, private ?\ArrayObject $changing)
            {
            }

            public function getName(): string
            {
                return $this->changing[0] ?? $this->name;
            }

            public function getVersion(): string
            {
                return '1.0.0';
            }

            public function getRoutes(): array
            {
                return [];
            }

            public function getPermissions(): array
            {
                return [];
            }

            public function getHooks(): array
            {
                return [];
            }

            public function getMigrations(): array
            {
                return [];
            }
        };
    }
}
