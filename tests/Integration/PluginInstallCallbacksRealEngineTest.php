<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Plugin\PluginInstallCallbacks;
use Whity\Sdk\PluginInstallLifecycleInterface;
use Whity\Sdk\PluginInterface;

/**
 * A plugin hears once that it was installed, and once per new version that it
 * was upgraded; a failing callback is retried (#1686).
 */
final class PluginInstallCallbacksRealEngineTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
    }

    private static function plugin(string $version, bool $fail = false, bool $listens = true): PluginInterface
    {
        if (!$listens) {
            return new class ($version) implements PluginInterface {
                public function __construct(private string $version)
                {
                }

                public function getName(): string
                {
                    return 'KeyHub';
                }

                public function getVersion(): string
                {
                    return $this->version;
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

        return new class ($version, $fail) implements PluginInterface, PluginInstallLifecycleInterface {
            /** @var list<string> */
            public array $calls = [];

            public function __construct(private string $version, private bool $fail)
            {
            }

            public function getName(): string
            {
                return 'KeyHub';
            }

            public function getVersion(): string
            {
                return $this->version;
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

            public function onInstalled(string $version): void
            {
                $this->calls[] = "installed {$version}";
                if ($this->fail) {
                    throw new \RuntimeException('not yet');
                }
            }

            public function onUpgraded(string $fromVersion, string $toVersion): void
            {
                $this->calls[] = "upgraded {$fromVersion} {$toVersion}";
                if ($this->fail) {
                    throw new \RuntimeException('not yet');
                }
            }
        };
    }

    public function testInstalledOnceThenUpgradedOncePerVersion(): void
    {
        $callbacks = new PluginInstallCallbacks($this->pdo);

        $v1 = self::plugin('1.0.0');
        self::assertSame(PluginInstallCallbacks::INSTALLED, $callbacks->run($v1));
        self::assertSame(PluginInstallCallbacks::UNCHANGED, $callbacks->run($v1));
        self::assertSame(['installed 1.0.0'], $v1->calls ?? null);

        $v2 = self::plugin('1.1.0');
        self::assertSame(PluginInstallCallbacks::UPGRADED, $callbacks->run($v2));
        self::assertSame(PluginInstallCallbacks::UNCHANGED, $callbacks->run($v2));
        self::assertSame(['upgraded 1.0.0 1.1.0'], $v2->calls ?? null);
    }

    public function testAFailingCallbackRunsAgainNextTime(): void
    {
        $callbacks = new PluginInstallCallbacks($this->pdo);

        self::assertSame(PluginInstallCallbacks::FAILED, $callbacks->run(self::plugin('1.0.0', fail: true)));
        $retry = self::plugin('1.0.0');
        self::assertSame(PluginInstallCallbacks::INSTALLED, $callbacks->run($retry));
        self::assertSame(['installed 1.0.0'], $retry->calls ?? null);
    }

    public function testAPluginThatLaterListensHearsAnUpgradeNotAnInstall(): void
    {
        $callbacks = new PluginInstallCallbacks($this->pdo);

        self::assertSame(PluginInstallCallbacks::INSTALLED, $callbacks->run(self::plugin('1.0.0', listens: false)));
        $listening = self::plugin('2.0.0');
        self::assertSame(PluginInstallCallbacks::UPGRADED, $callbacks->run($listening));
        self::assertSame(['upgraded 1.0.0 2.0.0'], $listening->calls ?? null);
    }
}
