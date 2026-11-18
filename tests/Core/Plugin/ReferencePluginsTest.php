<?php

declare(strict_types=1);

namespace Tests\Core\Plugin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Whity\Core\Plugin\ReferencePlugins;
use Whity\Core\PluginLoader;
use Whity\Core\Router;

/**
 * #1831: the release image carries HelloWorld and ExamplePlugin as examples,
 * and WHITY_REFERENCE_PLUGINS decides whether they run. Unset or `on` (dev, CI,
 * e2e) they load; `off` (the release image) discovery skips them, and only them.
 */
final class ReferencePluginsTest extends TestCase
{
    private const PLUGINS = __DIR__ . '/../../../plugins';

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        $this->setSwitch(null);
        foreach ($this->tempDirs as $dir) {
            @unlink($dir . '/Acme/Plugin.php');
            @rmdir($dir . '/Acme');
            @rmdir($dir);
        }
    }

    /** @return array<string, array{0: string|null, 1: bool}> */
    public static function values(): array
    {
        return [
            'unset'   => [null, true],
            'on'      => ['on', true],
            'empty'   => ['', true],
            'off'     => ['off', false],
            'OFF'     => [' OFF ', false],
            'zero'    => ['0', false],
            'false'   => ['false', false],
            'no'      => ['no', false],
        ];
    }

    #[DataProvider('values')]
    public function testOnlyAnExplicitOffTurnsThemOff(?string $value, bool $enabled): void
    {
        $this->setSwitch($value);

        self::assertSame($enabled, ReferencePlugins::enabled());
        self::assertSame(!$enabled, ReferencePlugins::skips('HelloWorld'));
        self::assertSame(!$enabled, ReferencePlugins::skips('ExamplePlugin.php'));
        self::assertFalse(ReferencePlugins::skips('Glossary'), 'other plugins never');
    }

    public function testOffSkipsTheReferencePluginsAndNothingElse(): void
    {
        $this->setSwitch('off');
        $discovered = array_keys((new PluginLoader(self::PLUGINS, new Router('')))->discover());

        self::assertNotContains('HelloWorld\\HelloWorldPlugin', $discovered);
        self::assertNotContains('Whity\\Plugins\\ExamplePlugin', $discovered);
        self::assertNotSame([], array_filter($discovered, static fn (string $c): bool => str_starts_with($c, 'Glossary\\')));
    }

    public function testOnLoadsThemAsBefore(): void
    {
        $this->setSwitch('on');
        $discovered = array_keys((new PluginLoader(self::PLUGINS, new Router('')))->discover());

        self::assertContains('HelloWorld\\HelloWorldPlugin', $discovered);
        self::assertContains('Whity\\Plugins\\ExamplePlugin', $discovered);
    }

    public function testFlippingTheSwitchChangesTheFingerprintSoCachesAndReloadsConverge(): void
    {
        $dir = sys_get_temp_dir() . '/whity_reference_' . uniqid();
        mkdir($dir . '/Acme', 0755, true);
        file_put_contents($dir . '/Acme/Plugin.php', "<?php\n");
        $this->tempDirs[] = $dir;
        $loader = new PluginLoader($dir, new Router(''));

        $this->setSwitch('on');
        $on = $loader->getFingerprint();
        $this->setSwitch('off');
        $off = $loader->getFingerprint();

        self::assertNotSame($on, $off);
    }

    public function testTheReleaseImageTurnsThemOffAndTheDevStackOn(): void
    {
        $dockerfile = (string) file_get_contents(__DIR__ . '/../../../Dockerfile');
        $release = substr($dockerfile, (int) strpos($dockerfile, 'AS release'));
        self::assertMatchesRegularExpression('/^ENV WHITY_REFERENCE_PLUGINS=off\r?$/m', $release);
        self::assertStringNotContainsString(
            'WHITY_REFERENCE_PLUGINS',
            substr($dockerfile, 0, (int) strpos($dockerfile, 'AS release')),
            'the base stage (dev, CI, e2e) leaves it on'
        );

        $compose = (string) file_get_contents(__DIR__ . '/../../../docker-compose.yml');
        self::assertStringContainsString('WHITY_REFERENCE_PLUGINS: ${WHITY_REFERENCE_PLUGINS:-on}', $compose);
    }

    private function setSwitch(?string $value): void
    {
        if ($value === null) {
            putenv(ReferencePlugins::ENV);
            unset($_ENV[ReferencePlugins::ENV]);

            return;
        }
        putenv(ReferencePlugins::ENV . '=' . $value);
        $_ENV[ReferencePlugins::ENV] = $value;
    }
}
