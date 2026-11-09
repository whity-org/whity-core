<?php

declare(strict_types=1);

namespace Tests\Database;

use Database\Migrations\KeepRenderOffForExistingRenderers;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Database\Database;

/**
 * Migration 238 (WHIT-1018): an install that upgrades with a renderer stays off
 * until someone turns printing on, and a new install still prints by default.
 *
 * Real migrated schema, on SQLite or Postgres as the job selects.
 */
final class KeepRenderOffForExistingRenderersRealEngineTest extends TestCase
{
    private const KEY = 'documents.render_enabled';
    private const SECRET = 'abcdefghijklmnopqrstuvwxyz0123456789';
    private const URL = 'http://render:8130';
    private const VARS = ['RENDER_SERVICE_URL', 'RENDER_SHARED_SECRET', 'COMPOSE_PROFILES'];

    private PDO $pdo;
    private Database $db;
    private GlobalSettingsRepository $settings;

    /** @var array<string, array{0: string|null, 1: string|false}> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        foreach (self::VARS as $name) {
            $value = $_ENV[$name] ?? null;
            $this->savedEnv[$name] = [is_string($value) ? $value : null, getenv($name)];
        }
        $this->pdo = SchemaFromMigrations::make(true);
        $this->db = Database::withFactory(fn (): PDO => $this->pdo);
        $this->settings = new GlobalSettingsRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => [$env, $process]) {
            if ($env === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $env;
            }
            putenv($process === false ? $name : "{$name}={$process}");
        }
    }

    /** @param array<string, string> $values a variable left out is unset */
    private function environment(array $values): void
    {
        foreach (self::VARS as $name) {
            if (isset($values[$name])) {
                $_ENV[$name] = $values[$name];
                putenv("{$name}={$values[$name]}");
            } else {
                unset($_ENV[$name]);
                putenv($name);
            }
        }
    }

    private function aRenderer(): void
    {
        $this->environment([
            'RENDER_SERVICE_URL' => self::URL,
            'RENDER_SHARED_SECRET' => self::SECRET,
            'COMPOSE_PROFILES' => 'render',
        ]);
    }

    private function anExistingInstall(): void
    {
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (4242, 'Example Bakery', 'example-bakery')");
    }

    public function testAFreshInstallStaysOnByDefaultEvenWithARendererConfigured(): void
    {
        // Migrations run before the seed, so tenant 0 is all a new install has.
        $this->aRenderer();
        $tenants = $this->pdo->query('SELECT id FROM tenants');
        self::assertNotFalse($tenants);
        self::assertSame(['0'], array_map('strval', $tenants->fetchAll(PDO::FETCH_COLUMN)));

        KeepRenderOffForExistingRenderers::up($this->db);

        self::assertNull($this->settings->get(self::KEY));
        self::assertSame('true', SettingsRegistry::defaultFor(self::KEY));
    }

    public function testAnExistingInstallWithARendererStaysOff(): void
    {
        $this->aRenderer();
        $this->anExistingInstall();

        KeepRenderOffForExistingRenderers::up($this->db);

        self::assertSame('false', $this->settings->get(self::KEY));
    }

    /** @return array<string, array{0: string}> */
    public static function savedValues(): array
    {
        return ['saved true' => ['true'], 'saved false' => ['false']];
    }

    #[DataProvider('savedValues')]
    public function testASavedValueIsNeverReplaced(string $saved): void
    {
        $this->aRenderer();
        $this->anExistingInstall();
        $this->settings->set(self::KEY, $saved);

        KeepRenderOffForExistingRenderers::up($this->db);

        self::assertSame($saved, $this->settings->get(self::KEY));
    }

    public function testAnExistingInstallWithNoRendererSettingsIsLeftAlone(): void
    {
        $this->environment([]);
        $this->anExistingInstall();

        KeepRenderOffForExistingRenderers::up($this->db);

        self::assertNull($this->settings->get(self::KEY));
    }

    /** @return array<string, array{0: array<string, string>}> */
    public static function notARenderer(): array
    {
        return [
            'the development secret' => [['RENDER_SERVICE_URL' => self::URL, 'RENDER_SHARED_SECRET' => 'dev_render_shared_secret_change_in_production']],
            'a short secret' => [['RENDER_SERVICE_URL' => self::URL, 'RENDER_SHARED_SECRET' => 'too-short']],
            'an empty URL' => [['RENDER_SERVICE_URL' => '', 'RENDER_SHARED_SECRET' => self::SECRET]],
            'profiles without render' => [['RENDER_SERVICE_URL' => self::URL, 'RENDER_SHARED_SECRET' => self::SECRET, 'COMPOSE_PROFILES' => 'web,cron']],
        ];
    }

    /** @param array<string, string> $environment */
    #[DataProvider('notARenderer')]
    public function testSettingsThatAreNotARendererLeaveTheDefault(array $environment): void
    {
        $this->environment($environment);
        $this->anExistingInstall();

        KeepRenderOffForExistingRenderers::up($this->db);

        self::assertNull($this->settings->get(self::KEY));
    }

    /** @return array<string, array{0: array<string, string>}> */
    public static function renderers(): array
    {
        return [
            // Deployments using --profile flags have no COMPOSE_PROFILES at all.
            'no profiles variable' => [['RENDER_SERVICE_URL' => self::URL, 'RENDER_SHARED_SECRET' => self::SECRET]],
            'render among others' => [['RENDER_SERVICE_URL' => self::URL, 'RENDER_SHARED_SECRET' => self::SECRET, 'COMPOSE_PROFILES' => 'web, render']],
            'the default address' => [['RENDER_SHARED_SECRET' => self::SECRET]],
        ];
    }

    /** @param array<string, string> $environment */
    #[DataProvider('renderers')]
    public function testEveryWayOfHavingARendererStaysOff(array $environment): void
    {
        $this->environment($environment);
        $this->anExistingInstall();

        KeepRenderOffForExistingRenderers::up($this->db);

        self::assertSame('false', $this->settings->get(self::KEY));
    }

    public function testRunningItAgainOrRollingBackKeepsWhatAPersonChose(): void
    {
        $this->aRenderer();
        $this->anExistingInstall();

        KeepRenderOffForExistingRenderers::up($this->db);
        $this->settings->set(self::KEY, 'true');
        KeepRenderOffForExistingRenderers::up($this->db);
        self::assertSame('true', $this->settings->get(self::KEY));

        KeepRenderOffForExistingRenderers::down($this->db);
        self::assertSame('true', (new GlobalSettingsRepository($this->pdo))->get(self::KEY));
    }
}
