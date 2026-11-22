<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Whity\Api\ThemesApiHandler;
use Whity\Core\PluginInstaller;
use Whity\Core\Request;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Tenant\TenantContext;
use Whity\Core\Theme\ThemePackage;
use Whity\Core\Theme\ThemeRepository;
use Whity\Core\Theme\ThemeValidator;
use ZipArchive;

/**
 * Publishing (TH.25, 30-themes 11): a theme becomes a store-ready plugin zip
 * that the real plugin installer accepts, whose only job is to return the
 * theme, and that never carries what a person typed into its code.
 */
final class ThemePackageTest extends TestCase
{
    private string $pluginDir;
    private string $extractDir;

    protected function setUp(): void
    {
        $this->pluginDir = sys_get_temp_dir() . '/whity_theme_pkg_plugins_' . uniqid();
        $this->extractDir = sys_get_temp_dir() . '/whity_theme_pkg_x_' . uniqid();
        mkdir($this->pluginDir, 0775, true);
        mkdir($this->extractDir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->pluginDir, $this->extractDir] as $dir) {
            $this->remove($dir);
        }
        TenantContext::reset();
    }

    /** @return array<string, mixed> */
    private static function theme(): array
    {
        return [
            'schemaVersion' => 1, 'id' => 'acme.harbour-blue', 'version' => '1.2.0',
            // A name that would be code if it were ever written into PHP.
            'name' => ['en' => "Harbour'; system('x'); //", 'ar' => 'المرفأ'],
            'colour' => ['seeds' => ['primary' => '#0b6e74']],
            'shape' => ['preset' => 'round', 'components' => ['cards' => 'crisp']],
        ];
    }

    public function testTheSameThemeAlwaysMakesTheSameBytesWithAManifestOfHashes(): void
    {
        $a = ThemePackage::build(self::theme());
        $b = ThemePackage::build(self::theme());
        self::assertSame($a['sha256'], $b['sha256']);
        self::assertSame(hash('sha256', $a['content']), $a['sha256']);
        self::assertSame('AcmeHarbourBlueTheme', $a['plugin']);
        self::assertSame('AcmeHarbourBlueTheme-1.2.0.zip', $a['filename']);

        $files = $this->extract($a['content']);
        $root = 'AcmeHarbourBlueTheme/';
        $manifest = json_decode($files[$root . 'manifest.json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['kind' => 'theme', 'plugin' => 'AcmeHarbourBlueTheme', 'theme' => 'acme.harbour-blue', 'version' => '1.2.0'], array_intersect_key($manifest, array_flip(['kind', 'plugin', 'theme', 'version'])));
        self::assertSame('^1.125', $manifest['sdk']);
        foreach ($manifest['files'] as $path => $sha) {
            self::assertSame(hash('sha256', $files[$root . $path]), $sha, $path);
        }
        self::assertSame(self::theme(), json_decode($files[$root . 'src/themes/acme.harbour-blue.json'], true));
        // Nothing the person typed is in the code; the name is read at runtime.
        self::assertStringNotContainsString('system(', $files[$root . 'src/AcmeHarbourBlueThemePlugin.php']);
        self::assertStringNotContainsString('1.2.0', $files[$root . 'src/AcmeHarbourBlueThemePlugin.php']);
    }

    /** The installer's own checks: one top-level folder, one plugin class, its name, the SDK gate. */
    public function testThePluginInstallerAcceptsIt(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('The installer inspects packages in a child process through sh; CI runs this on Linux.');
        }
        $package = ThemePackage::build(self::theme());
        $entry = (new PluginInstaller($this->pluginDir))->installFromBytes($package['content']);
        self::assertSame('AcmeHarbourBlueTheme', $entry['name']);
        self::assertSame('1.2.0', $entry['version']);
    }

    public function testThePluginReturnsExactlyTheTheme(): void
    {
        $package = ThemePackage::build(self::theme());
        // The class, loaded as the host would, gives back exactly the theme.
        $this->extract($package['content']);
        require_once $this->extractDir . '/AcmeHarbourBlueTheme/src/AcmeHarbourBlueThemePlugin.php';
        // Built at runtime: the class only exists once the package is unpacked.
        $class = 'WhityTheme\\' . substr($package['plugin'], 0, -strlen('Theme')) . '\\' . $package['plugin'] . 'Plugin';
        if (!class_exists($class)) {
            self::fail('The packaged plugin class did not load.');
        }
        $plugin = (new \ReflectionClass($class))->newInstance();
        self::assertSame('WhityTheme\\AcmeHarbourBlue\\AcmeHarbourBlueThemePlugin', $plugin::class);
        self::assertInstanceOf(\Whity\Sdk\Contribute\PluginThemesInterface::class, $plugin);
        self::assertInstanceOf(\Whity\Sdk\PluginDisplayNameInterface::class, $plugin);
        self::assertInstanceOf(\Whity\Sdk\PluginInterface::class, $plugin);
        self::assertSame('AcmeHarbourBlueTheme', $plugin->getName());
        self::assertSame('1.2.0', $plugin->getVersion());
        self::assertSame(['en' => "Harbour'; system('x'); //", 'ar' => 'المرفأ'], $plugin->getDisplayName());
        self::assertSame([self::theme()], $plugin->getThemes());
        self::assertSame([], ThemeValidator::validate($plugin->getThemes()[0]));
        self::assertSame([], $plugin->getRoutes());
    }

    public function testTheApiPackagesTheWorkspacesThemeButNotWhitysOwn(): void
    {
        $repository = (new ReflectionClass(ThemeRepository::class))->newInstanceWithoutConstructor();
        $settings = (new ReflectionClass(SettingsService::class))->newInstanceWithoutConstructor();
        $handler = new ThemesApiHandler($repository, $settings);
        TenantContext::reset();
        TenantContext::setTenantId(7);

        $response = $handler->package(new Request('POST', '/api/themes/package', [], (string) json_encode(['theme' => self::theme()])));
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true)['data'];
        self::assertSame('application/zip', $data['media_type']);
        self::assertSame($data['sha256'], hash('sha256', (string) base64_decode($data['content_base64'], true)));

        $whity = self::theme();
        $whity['id'] = 'whity.harbour';
        $refused = $handler->package(new Request('POST', '/api/themes/package', [], (string) json_encode(['theme' => $whity])));
        self::assertSame(422, $refused->getStatusCode());
        self::assertStringContainsString('Make a copy', $refused->getBody());
    }

    /** @return array<string, string> */
    private function extract(string $content): array
    {
        $file = $this->extractDir . '/package.zip';
        file_put_contents($file, $content);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($file));
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $files[$name] = (string) $zip->getFromIndex($i);
        }
        $zip->extractTo($this->extractDir);
        $zip->close();

        return $files;
    }

    private function remove(string $path): void
    {
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $item) {
                if ($item !== '.' && $item !== '..') {
                    $this->remove($path . '/' . $item);
                }
            }
            @rmdir($path);
        } elseif (file_exists($path)) {
            @unlink($path);
        }
    }
}
