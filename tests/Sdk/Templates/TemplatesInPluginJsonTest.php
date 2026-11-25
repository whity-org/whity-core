<?php

declare(strict_types=1);

namespace Tests\Sdk\Templates;

use PHPUnit\Framework\TestCase;
use Tests\Sdk\Manifest\ManifestValidatorTest;
use Tests\Support\ExampleClinicTemplates as Clinic;
use Whity\Core\Plugin\PluginManifestCheck;
use Whity\Sdk\Manifest\ManifestPlugin;
use Whity\Sdk\Manifest\ManifestValidator;

/**
 * WHIT-1413 (SDK 1.170): a template plugin is a plugin.json and JSON files,
 * with no PHP of its own.
 */
final class TemplatesInPluginJsonTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/whity_tpl_json_' . uniqid();
        mkdir($this->dir . '/templates', 0755, true);
        file_put_contents($this->dir . '/templates/clinic.json', json_encode(Clinic::clinic(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        file_put_contents($this->dir . '/templates/patient-file.json', json_encode(Clinic::patientFile(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/templates/*.json') ?: [] as $file) {
            unlink($file);
        }
        if (is_file($this->dir . '/plugin.json')) {
            unlink($this->dir . '/plugin.json');
        }
        rmdir($this->dir . '/templates');
        rmdir($this->dir);
    }

    public function testAManifestPluginServesItsTemplatesAndExtensions(): void
    {
        $plugin = $this->pluginFrom(self::manifest());

        self::assertSame(Clinic::clinic(), $plugin->getWorkspaceTemplates()[0]);
        self::assertSame(Clinic::patientFile(), $plugin->getWorkspaceTemplateExtensions()[0]);
        self::assertSame([], $this->pluginFrom(ManifestValidatorTest::bakeryManifest())->getWorkspaceTemplates(), 'Nothing declared, nothing served');
    }

    public function testTheManifestAndTheCliCheckTheFiles(): void
    {
        self::assertTrue(ManifestValidator::validate(self::manifest())['ok']);
        self::assertContains('workspaceTemplates[0]: a path inside the plugin ending in .json, such as templates/clinic.json', ManifestValidator::validate(['workspaceTemplates' => ['../clinic.json']] + self::manifest())['errors']);

        file_put_contents($this->dir . '/plugin.json', json_encode(self::manifest(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        self::assertSame([], PluginManifestCheck::run($this->dir)['errors']);

        $broken = Clinic::clinic();
        unset($broken['name']);
        file_put_contents($this->dir . '/templates/broken.json', json_encode($broken, JSON_THROW_ON_ERROR));
        $manifest = self::manifest();
        $manifest['workspaceTemplates'][] = 'templates/broken.json';
        $manifest['workspaceTemplateExtensions'][] = 'templates/missing.json';
        file_put_contents($this->dir . '/plugin.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $joined = implode("\n", PluginManifestCheck::run($this->dir)['errors']);

        self::assertStringContainsString('workspaceTemplates[1] (templates/broken.json): template.name', $joined);
        self::assertStringContainsString("workspaceTemplateExtensions[1]: templates/missing.json doesn't exist", $joined);
        self::assertStringNotContainsString('workspaceTemplates[0]', $joined);
    }

    /** @return array<string, mixed> */
    private static function manifest(): array
    {
        return ManifestValidatorTest::bakeryManifest() + [
            'workspaceTemplates' => ['templates/clinic.json'],
            'workspaceTemplateExtensions' => ['templates/patient-file.json'],
        ];
    }

    /** @param array<string, mixed> $manifest */
    private function pluginFrom(array $manifest): ManifestPlugin
    {
        file_put_contents($this->dir . '/plugin.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $dir = $this->dir;

        return new class ($dir) extends ManifestPlugin {
            public function __construct(private readonly string $base)
            {
                parent::__construct();
            }

            protected function manifestPath(): string
            {
                return $this->base . '/plugin.json';
            }
        };
    }
}
