<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\InstallFromStoreApiHandler;
use Whity\Api\PluginsApiHandler;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Plugin\PluginRecords;
use Whity\Core\Plugin\Trust\PluginTrust;
use Whity\Core\Plugin\Trust\TrustedKeys;
use Whity\Core\PluginLoader;
use Whity\Core\Queue\Jobs\PurgeRemovedPluginsJob;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Router;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\TenantContext;

/**
 * S7, WHIT-870, on the real schema (SQLite, or Postgres with PHPUNIT_PG_DSN):
 * the 30-day keep (S.7), update approval (S.6), and trust a store can't set
 * (S.4). Operators only throughout (WHIT-869).
 */
final class PluginStoreLifecycleRealEngineTest extends TestCase
{
    private const OPERATOR = 0;
    private const WORKSPACE = 1;
    private const STORE = 'https://store.example.com';

    private PDO $pdo;
    private string $pluginDir;
    private string $workDir;
    private string $plugin;
    private PluginLoader $loader;
    private PluginsApiHandler $plugins;
    private PluginRecords $records;

    /** @var array<int, array<string, mixed>> */
    private array $catalogue = [];
    private ?string $package = null;
    /** What the store answers for a version's /attestation, if anything. */
    private ?string $attestation = null;

    protected function setUp(): void
    {
        TenantContext::reset();
        TenantContext::setTenantId(self::OPERATOR);

        $this->pdo = SchemaFromMigrations::make();
        $this->records = new PluginRecords($this->pdo);
        $this->pluginDir = sys_get_temp_dir() . '/whity_s7_plugins_' . uniqid();
        $this->workDir = sys_get_temp_dir() . '/whity_s7_work_' . uniqid();
        mkdir($this->pluginDir, 0775, true);
        mkdir($this->workDir, 0775, true);

        // A fresh class per test: a class can't be redefined in one PHP process.
        $this->plugin = 'Acme' . str_replace('.', '', uniqid('', true));

        $this->loader = new PluginLoader($this->pluginDir, new Router(''));
        $this->loader->load();
        $this->plugins = new PluginsApiHandler($this->pluginDir, $this->loader, $this->pdo);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        $this->pdo->exec('DROP TABLE IF EXISTS ' . $this->table());
        $this->removeRecursive($this->pluginDir);
        $this->removeRecursive($this->workDir);
    }

    public function testAStoreCannotClaimAMarkInItsCatalogue(): void
    {
        $this->catalogue = [[
            'slug' => 'acme',
            'name' => 'Acme',
            'verified' => true,
            'trust' => 'verified',
            'mark' => 'by_whity',
            'used_by' => 12,
            'price' => ['amount' => '49.000', 'currency' => 'JOD', 'interval' => 'month'],
            'outside_domains' => ['api.example.com', 'not a domain', 'javascript:alert(1)'],
            'support_url' => 'javascript:alert(1)',
            'unknown_field' => 'x',
        ]];

        $_GET = ['store_url' => self::STORE];
        $entry = $this->data($this->store()->browseCatalog(new Request('GET', '/api/plugins/store/catalog')))[0];
        $_GET = [];

        self::assertSame('unverified', $entry['trust']);
        self::assertArrayNotHasKey('verified', $entry);
        self::assertArrayNotHasKey('mark', $entry);
        self::assertArrayNotHasKey('unknown_field', $entry);
        self::assertSame(12, $entry['used_by']);
        self::assertSame('49.000', $entry['price']['amount']);
        self::assertSame(['api.example.com'], $entry['outside_domains']);
        self::assertNull($entry['support_url']);
    }

    public function testInstallingRecordsThePersonAccessAndSourceNotAMark(): void
    {
        $this->installFromStore('1.0.0', ['acme:read']);

        $row = $this->install();
        self::assertSame('store', $row['source']);
        self::assertSame(['acme:read'], PluginRecords::decodeList($row['permissions']));
        self::assertArrayNotHasKey('trust', $row);
        self::assertArrayNotHasKey('verified', $row);

        $listed = $this->mustBeListed();
        self::assertSame('unverified', $listed['trust']);
        self::assertSame(['acme:read'], $listed['install']['permissions']);
    }

    public function testUninstallKeepsEverythingAndHidesThePlugin(): void
    {
        $this->installFromStore('1.0.0', []);
        $this->plugins->enable(new Request('POST', '/x'), ['name' => $this->plugin]);
        self::assertSame(1, $this->tableRows());

        $data = $this->data($this->plugins->uninstall(new Request('POST', '/x', [], '{}'), ['id' => $this->plugin]));

        self::assertSame(30, $data['kept_days']);
        self::assertDirectoryExists($this->pluginDir . '/' . $this->plugin);
        self::assertSame(1, $this->tableRows(), 'the data stays');
        self::assertNull($this->listed(), 'hidden from Installed');

        $removed = $this->data($this->plugins->removed(new Request('GET', '/x')));
        self::assertSame($this->plugin, $removed[0]['name']);
        self::assertGreaterThan(gmdate('Y-m-d', time() + 29 * 86400), $removed[0]['purge_after']);

        $enable = $this->plugins->enable(new Request('POST', '/x'), ['name' => $this->plugin]);
        self::assertSame(409, $enable->getStatusCode(), 'turning on would bypass the removal');
    }

    public function testReinstallRestoresThePluginAsItWas(): void
    {
        $this->installFromStore('1.0.0', []);
        $this->plugins->enable(new Request('POST', '/x'), ['name' => $this->plugin]);
        $this->plugins->uninstall(new Request('POST', '/x', [], '{}'), ['id' => $this->plugin]);

        $response = $this->plugins->reinstall(new Request('POST', '/x'), ['name' => $this->plugin]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $this->records->removals());
        self::assertSame('active', $this->mustBeListed()['status']);
        self::assertSame(1, $this->tableRows());
    }

    public function testPurgeWaitsThirtyDaysThenRunsDownAndRemovesTheFiles(): void
    {
        $this->installFromStore('1.0.0', []);
        $this->plugins->enable(new Request('POST', '/x'), ['name' => $this->plugin]);
        $this->plugins->uninstall(new Request('POST', '/x', [], '{}'), ['id' => $this->plugin]);

        $job = new PurgeRemovedPluginsJob($this->pdo, fn (): PluginLoader => $this->loader);

        self::assertSame([], $job->purge($this->loader, gmdate('Y-m-d H:i:s', time() + 29 * 86400))['purged']);
        self::assertSame(1, $this->tableRows(), 'kept inside the 30 days');

        $result = $job->purge($this->loader, gmdate('Y-m-d H:i:s', time() + 31 * 86400));

        self::assertSame([$this->plugin], $result['purged']);
        self::assertFalse($this->tableExists(), "the plugin's down() ran");
        self::assertDirectoryDoesNotExist($this->pluginDir . '/' . $this->plugin);
        self::assertSame([], $this->records->removals());
        self::assertNull($this->records->install($this->plugin));
    }

    public function testAFailedDownKeepsTheCodeAndTheRowToTryAgain(): void
    {
        $this->installFromStore('1.0.0', [], failingDown: true);
        $this->plugins->enable(new Request('POST', '/x'), ['name' => $this->plugin]);
        $this->plugins->uninstall(new Request('POST', '/x', [], '{}'), ['id' => $this->plugin]);

        $job = new PurgeRemovedPluginsJob($this->pdo, fn (): PluginLoader => $this->loader);
        $result = $job->purge($this->loader, gmdate('Y-m-d H:i:s', time() + 31 * 86400));

        self::assertSame([$this->plugin], $result['failed']);
        self::assertDirectoryExists($this->pluginDir . '/' . $this->plugin);
        self::assertTrue($this->data($this->plugins->removed(new Request('GET', '/x')))[0]['purge_failed']);
    }

    /**
     * WHIT-1308 (#2189): a code package with no signature from Whity is refused
     * before anything in it is unpacked, and the refusal is kept.
     */
    public function testAnUnsignedStorePackageIsRefusedBeforeAnythingIsUnpacked(): void
    {
        $response = $this->installAs('1.0.0', allowUnsigned: false);

        self::assertSame(422, $response->getStatusCode(), $response->getBody());
        self::assertStringContainsString("isn't signed by Whity", $response->getBody());
        self::assertSame([], array_values(array_diff((array) scandir($this->pluginDir), ['.', '..'])));
        self::assertNull($this->records->install($this->plugin));
        self::assertSame(['plugin.store_unsigned.refused'], $this->unsignedAudit());
    }

    /** WHIT-1308: the operator's temporary override lets it in, and says so in the audit log. */
    public function testTheOperatorsOverrideLetsAnUnsignedPackageInAndItIsAudited(): void
    {
        $response = $this->installAs('1.0.0', allowUnsigned: true);

        self::assertSame(201, $response->getStatusCode(), $response->getBody());
        self::assertSame(['plugin.store_unsigned.installed'], $this->unsignedAudit());
        $stmt = $this->pdo->prepare("SELECT metadata FROM audit_log WHERE action = 'plugin.store_unsigned.installed'");
        $stmt->execute();
        $metadata = json_decode((string) $stmt->fetchColumn(), true);
        self::assertIsArray($metadata);
        self::assertSame(hash('sha256', (string) $this->package), $metadata['sha256'] ?? null);
        self::assertSame('install', $metadata['during'] ?? null);
    }

    /** WHIT-1308: a package Whity signed installs with the override off. */
    public function testAPackageWhitySignedInstallsWithoutTheOverride(): void
    {
        [$trust, $sign] = $this->trust();
        $this->package = $this->zip('1.0.0', ['acme:read']);
        $this->attestation = $sign(PluginTrust::ATTESTATION, [
            'plugin' => $this->plugin,
            'version' => '1.0.0',
            'sha256' => hash('sha256', $this->package),
            'issued_at' => time(),
            'expires_at' => time() + 86400,
        ]);

        $response = $this->installAs('1.0.0', allowUnsigned: false, trust: $trust, keepPackage: true);

        self::assertSame(201, $response->getStatusCode(), $response->getBody());
        self::assertSame([], $this->unsignedAudit());
    }

    /**
     * WHIT-1308: a signature that doesn't hold for these bytes (another package,
     * a forged key) is refused even with the override on: the override is for
     * packages Whity hasn't signed yet, not for ones whose signature fails.
     */
    public function testASignatureThatDoesNotHoldIsRefusedEvenWithTheOverride(): void
    {
        [$trust, $sign] = $this->trust();
        $this->package = $this->zip('1.0.0', ['acme:read']);
        $this->attestation = $sign(PluginTrust::ATTESTATION, [
            'plugin' => $this->plugin,
            'version' => '1.0.0',
            'sha256' => hash('sha256', 'some other package'),
            'issued_at' => time(),
            'expires_at' => time() + 86400,
        ]);

        $response = $this->installAs('1.0.0', allowUnsigned: true, trust: $trust, keepPackage: true);

        self::assertSame(422, $response->getStatusCode(), $response->getBody());
        self::assertStringContainsString("doesn't match this package", $response->getBody());
        self::assertSame([], array_values(array_diff((array) scandir($this->pluginDir), ['.', '..'])));
        self::assertSame(['plugin.store_unsigned.refused'], $this->unsignedAudit());
    }

    /** WHIT-1308: an update is new code too; unsigned, it's refused and the installed version stays. */
    public function testAnUnsignedUpdateIsRefusedAndTheInstalledVersionStays(): void
    {
        $this->installFromStore('1.0.0', ['acme:read']);
        $this->catalogue = [['slug' => 'acme', 'name' => 'Acme', 'latest_version' => '1.1.0']];
        $this->package = $this->zip('1.1.0', ['acme:read']);

        $response = $this->store(allowUnsigned: false)->update(new Request('POST', '/x', [], '{}'), ['name' => $this->plugin]);

        self::assertSame(422, $response->getStatusCode(), $response->getBody());
        self::assertSame('1.0.0', $this->install()['version']);
        self::assertStringContainsString("'1.0.0'", $this->liveSource());
    }

    public function testAnUpdateWithNoNewAccessAppliesAndLandsTurnedOff(): void
    {
        $this->installFromStore('1.0.0', ['acme:read']);

        $data = $this->update('1.1.0', ['acme:read']);

        self::assertSame('updated', $data['status']);
        self::assertSame('1.1.0', $data['version']);
        self::assertSame('1.1.0', $this->install()['version']);
        self::assertStringContainsString("'1.1.0'", $this->liveSource());
        self::assertFileExists($this->pluginDir . '/' . $this->plugin . '/' . PluginLoader::DIR_DISABLED_SENTINEL);
    }

    public function testAnUpdateAskingForANewPermissionWaitsAndTheOldVersionStays(): void
    {
        $this->installFromStore('1.0.0', ['acme:read']);

        $data = $this->update('2.0.0', ['acme:read', 'acme:delete']);

        self::assertSame('waiting_approval', $data['status']);
        self::assertSame(['acme:delete'], $data['new_permissions']);
        self::assertStringContainsString("'1.0.0'", $this->liveSource(), 'old version keeps running');
        self::assertSame('1.0.0', $this->install()['version']);
        self::assertSame('2.0.0', $this->mustBeListed()['install']['update_waiting']['version']);
        self::assertSame(['acme:delete'], $this->mustBeListed()['install']['update_waiting']['new_permissions']);

        $approved = $this->data($this->store()->approveUpdate(new Request('POST', '/x'), ['name' => $this->plugin]));

        self::assertSame('updated', $approved['status']);
        self::assertStringContainsString("'2.0.0'", $this->liveSource());
        self::assertSame(['acme:read', 'acme:delete'], PluginRecords::decodeList($this->install()['permissions']));
        self::assertNull($this->install()['pending_version']);
    }

    public function testAnUpdateAskingForANewOutsideDomainWaits(): void
    {
        $this->installFromStore('1.0.0', []);

        $data = $this->update('2.0.0', [], ['outside_domains' => ['api.example.com']]);

        self::assertSame('waiting_approval', $data['status']);
        self::assertSame(['api.example.com'], $data['new_domains']);
    }

    public function testRejectingDropsTheUpdateAndStaysOnTheVersion(): void
    {
        $this->installFromStore('1.0.0', []);
        $this->update('2.0.0', ['acme:delete']);

        $this->store()->rejectUpdate(new Request('POST', '/x'), ['name' => $this->plugin]);

        self::assertNull($this->install()['pending_version']);
        self::assertStringContainsString("'1.0.0'", $this->liveSource());
        self::assertSame(404, $this->store()->approveUpdate(new Request('POST', '/x'), ['name' => $this->plugin])->getStatusCode());
    }

    public function testThePackageDecidesTheVersionAndCannotGoBackwards(): void
    {
        $this->installFromStore('2.0.0', []);
        $this->catalogue = [['slug' => 'acme', 'name' => 'Acme', 'latest_version' => '3.0.0']];
        $this->package = $this->zip('1.0.0', []);

        $response = $this->store()->update(new Request('POST', '/x', [], '{}'), ['name' => $this->plugin]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString("'2.0.0'", $this->liveSource());
    }

    public function testAnUpdateMustBeTheSamePlugin(): void
    {
        $this->installFromStore('1.0.0', []);
        $this->catalogue = [['slug' => 'acme', 'name' => 'Acme', 'latest_version' => '2.0.0']];
        $this->package = $this->zip('2.0.0', [], name: 'Other' . str_replace('.', '', uniqid('', true)));

        $response = $this->store()->update(new Request('POST', '/x', [], '{}'), ['name' => $this->plugin]);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString("'1.0.0'", $this->liveSource());
    }

    public function testAWorkspaceCannotReinstallUpdateOrSeeTheOperatorsLists(): void
    {
        $this->installFromStore('1.0.0', []);
        $this->update('2.0.0', ['acme:delete']);
        $this->plugins->uninstall(new Request('POST', '/x', [], '{}'), ['id' => $this->plugin]);

        TenantContext::reset();
        TenantContext::setTenantId(self::WORKSPACE);
        $params = ['name' => $this->plugin];

        self::assertSame(403, $this->plugins->reinstall(new Request('POST', '/x'), $params)->getStatusCode());
        self::assertSame(403, $this->store()->update(new Request('POST', '/x', [], '{}'), $params)->getStatusCode());
        self::assertSame(403, $this->store()->approveUpdate(new Request('POST', '/x'), $params)->getStatusCode());
        self::assertSame(403, $this->store()->rejectUpdate(new Request('POST', '/x'), $params)->getStatusCode());
        self::assertSame([], $this->data($this->plugins->removed(new Request('GET', '/x'))));
        self::assertSame([], $this->data($this->store()->availableUpdates(new Request('GET', '/x'))));
        self::assertNotNull($this->records->removal($this->plugin), 'nothing changed');
    }

    public function testAWorkspaceDoesNotSeeInstallRecords(): void
    {
        $this->installFromStore('1.0.0', ['acme:read']);

        TenantContext::reset();
        TenantContext::setTenantId(self::WORKSPACE);
        $listed = $this->mustBeListed();

        self::assertSame('unverified', $listed['trust']);
        self::assertArrayNotHasKey('install', $listed);
    }

    public function testNewerVersionsAreListedForTheOperator(): void
    {
        $this->installFromStore('1.0.0', []);
        $this->catalogue = [['slug' => 'acme', 'name' => 'Acme', 'latest_version' => '1.2.0']];

        $updates = $this->data($this->store()->availableUpdates(new Request('GET', '/x')));

        self::assertSame([['plugin' => $this->plugin, 'installed_version' => '1.0.0', 'latest_version' => '1.2.0']], $updates);
    }

    /** @param list<string> $permissions */
    private function installFromStore(string $version, array $permissions, bool $failingDown = false): void
    {
        $this->catalogue = [['slug' => 'acme', 'name' => 'Acme', 'latest_version' => $version]];
        $this->package = $this->zip($version, $permissions, failingDown: $failingDown);

        $response = $this->store()->install(new Request('POST', '/x', [], (string) json_encode([
            'store_url' => self::STORE,
            'slug' => 'acme',
            'version' => $version,
        ])));
        self::assertSame(201, $response->getStatusCode(), $response->getBody());
    }

    /**
     * @param list<string> $permissions
     * @param array<string, mixed> $listing
     * @return array<string, mixed>
     */
    private function update(string $version, array $permissions, array $listing = []): array
    {
        $this->catalogue = [['slug' => 'acme', 'name' => 'Acme', 'latest_version' => $version] + $listing];
        $this->package = $this->zip($version, $permissions);

        $response = $this->store()->update(new Request('POST', '/x', [], '{}'), ['name' => $this->plugin]);
        self::assertSame(200, $response->getStatusCode(), $response->getBody());

        return $this->data($response);
    }

    /**
     * The lifecycle tests above are about what happens once a package is in, so
     * they run with the operator's unsigned-package override on (WHIT-1308).
     */
    private function store(bool $allowUnsigned = true, ?PluginTrust $trust = null): InstallFromStoreApiHandler
    {
        $settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        $settings->setGlobal(SettingsRegistry::PLUGINS_STORE_ALLOWED_HOSTS, 'store.example.com');
        $settings->setGlobal(SettingsRegistry::PLUGINS_STORE_ALLOW_UNSIGNED, $allowUnsigned ? 'true' : 'false');

        return new InstallFromStoreApiHandler(
            $this->pluginDir,
            $settings,
            $this->loader,
            new AuditLogger($this->pdo),
            fn (string $url, array $headers): ?string => str_ends_with($url, '/attestation') ? $this->attestation : $this->package,
            fn (string $url): array => ['data' => $this->catalogue],
            $this->records,
            null,
            null,
            $trust,
        );
    }

    private function installAs(string $version, bool $allowUnsigned, ?PluginTrust $trust = null, bool $keepPackage = false): Response
    {
        $this->catalogue = [['slug' => 'acme', 'name' => 'Acme', 'latest_version' => $version]];
        if (!$keepPackage) {
            $this->package = $this->zip($version, ['acme:read']);
        }

        return $this->store($allowUnsigned, $trust)->install(new Request('POST', '/x', [], (string) json_encode([
            'store_url' => self::STORE,
            'slug' => 'acme',
            'version' => $version,
        ])));
    }

    /**
     * A key of the test's own, with a fresh revocation list signed by it.
     *
     * @return array{0: PluginTrust, 1: \Closure(string, array<string, mixed>): string}
     */
    private function trust(): array
    {
        $pair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($pair);
        $public = base64_encode(sodium_crypto_sign_publickey($pair));
        $trust = new PluginTrust($this->pdo, new TrustedKeys([$public]));
        $sign = static function (string $format, array $body) use ($secret, $public): string {
            $payload = (string) json_encode(['format' => $format] + $body);

            return (string) json_encode([
                'key' => $public,
                'payload' => base64_encode($payload),
                'signature' => base64_encode(sodium_crypto_sign_detached($format . "\n" . $payload, $secret)),
            ]);
        };
        self::assertTrue($trust->applyRevocationList($sign(PluginTrust::REVOCATIONS, [
            'sequence' => 1,
            'issued_at' => time(),
            'expires_at' => time() + 7 * 86400,
            'revoked' => [],
        ]))['ok']);

        return [$trust, $sign];
    }

    /** @return list<string> */
    private function unsignedAudit(): array
    {
        $stmt = $this->pdo->prepare("SELECT action FROM audit_log WHERE action LIKE 'plugin.store_unsigned.%' ORDER BY id");
        $stmt->execute();

        /** @var list<string> */
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return array<string, mixed> */
    private function install(): array
    {
        $row = $this->records->install($this->plugin);
        self::assertNotNull($row);

        return $row;
    }

    /** @return array<string, mixed> */
    private function mustBeListed(): array
    {
        $plugin = $this->listed();
        self::assertNotNull($plugin);

        return $plugin;
    }

    /** @return array<string, mixed>|null */
    private function listed(): ?array
    {
        foreach ($this->data($this->plugins->list(new Request('GET', '/api/plugins'))) as $plugin) {
            if ($plugin['id'] === $this->plugin || $plugin['name'] === $this->plugin) {
                return $plugin;
            }
        }

        return null;
    }

    /** @return array<mixed> */
    private function data(Response $response): array
    {
        $body = json_decode($response->getBody(), true);
        self::assertIsArray($body, $response->getBody());
        self::assertArrayHasKey('data', $body, $response->getBody());

        return $body['data'];
    }

    private function liveSource(): string
    {
        return (string) file_get_contents($this->pluginDir . '/' . $this->plugin . '/' . $this->plugin . 'Plugin.php');
    }

    private function table(): string
    {
        return strtolower($this->plugin) . '_items';
    }

    private function tableExists(): bool
    {
        try {
            $this->pdo->query('SELECT 1 FROM ' . $this->table());

            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    private function tableRows(): int
    {
        $rows = $this->pdo->prepare('SELECT COUNT(*) FROM ' . $this->table());
        $rows->execute();

        return (int) $rows->fetchColumn();
    }

    /** @param list<string> $permissions */
    private function zip(string $version, array $permissions, bool $failingDown = false, ?string $name = null): string
    {
        $name ??= $this->plugin;
        $table = $this->table();
        $class = $name . 'Plugin';
        $migration = 'Create' . $name . 'Items';
        $permissionList = var_export($permissions, true);
        $down = $failingDown
            ? "throw new \\RuntimeException('no');"
            : "\$pdo->exec('DROP TABLE IF EXISTS {$table}');";

        $plugin = <<<PHP
<?php

declare(strict_types=1);

namespace {$name};

use Whity\\Sdk\\PluginInterface;

final class {$class} implements PluginInterface
{
    public function getName(): string { return '{$name}'; }
    public function getVersion(): string { return '{$version}'; }
    public function getRoutes(): array { return []; }
    public function getPermissions(): array { return {$permissionList}; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array { return [\\{$name}\\Migrations\\{$migration}::class]; }
}
PHP;
        $migrationSource = <<<PHP
<?php

declare(strict_types=1);

namespace {$name}\\Migrations;

use Whity\\Sdk\\MigrationInterface;

final class {$migration} implements MigrationInterface
{
    public function up(\\PDO \$pdo): void
    {
        \$pdo->exec('CREATE TABLE IF NOT EXISTS {$table} (id INTEGER)');
        \$pdo->exec('INSERT INTO {$table} (id) VALUES (1)');
    }

    public function down(\\PDO \$pdo): void
    {
        {$down}
    }
}
PHP;

        $path = $this->workDir . '/' . uniqid('pkg_') . '.zip';
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE));
        $zip->addFromString($name . '/' . $class . '.php', $plugin);
        $zip->addFromString($name . '/Migrations/' . $migration . '.php', $migrationSource);
        $zip->close();

        return (string) file_get_contents($path);
    }

    private function removeRecursive(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            $this->removeRecursive($path . '/' . $entry);
        }
        @rmdir($path);
    }
}
