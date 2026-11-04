<?php

declare(strict_types=1);

namespace Tests\Integration\Plugin;

use InvalidArgumentException;
use LogicException;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Entitlement\EntitlementService;
use Whity\Core\Entitlement\TenantEntitlementRepository;
use Whity\Core\Plugin\HostPluginFiles;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\PluginInterface;
use Whity\Storage\LocalStorageDriver;
use Whity\Storage\TenantStorageConfigRepository;
use Whity\Storage\TenantStorageResolver;

/**
 * WHAT WAS PROVEN (WHIT-886, SDK 1.63): a plugin's files are reachable only by
 * that plugin, in the workspace that is in scope. Plugin A cannot read, list or
 * delete plugin B's files in the same workspace; tenant 1 cannot see tenant 2's;
 * and a traversal is refused rather than rewritten.
 *
 * The real resolver and the real storage driver run here, against a temporary
 * directory and the migrated schema. Nothing is mocked except the plugin, which
 * is an interface and only has to answer its name.
 */
final class PluginFilesIsolationRealEngineTest extends TestCase
{
    private string $root;
    private ?int $tenant = 1;
    private ?PDO $pdo = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/whity-plugin-files-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700, true);
        TenantContext::reset();
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        $this->removeTree($this->root);
    }

    public function testOnePluginCannotReadOrListOrDeleteAnotherPluginsFilesInTheSameWorkspace(): void
    {
        $files = $this->service();
        $reports = $files->for($this->plugin('Acme\\Reports'));
        $ledger = $files->for($this->plugin('Acme\\Ledger'));

        $reports->put('summary.pdf', 'reports-bytes', 'application/pdf');

        $this->assertNull($ledger->get('summary.pdf'));
        $this->assertFalse($ledger->exists('summary.pdf'));
        $this->assertSame([], $ledger->list());

        $ledger->delete('summary.pdf');
        $this->assertSame('reports-bytes', $reports->get('summary.pdf')?->contents);
        $this->assertSame(['summary.pdf'], $reports->list());
    }

    public function testAFileIsReadBackWithItsTypeAndItsOwnPath(): void
    {
        $store = $this->service()->for($this->plugin('Acme\\Reports'));

        $store->put('exports/march.csv', "a,b\n1,2", 'text/csv');

        $file = $store->get('exports/march.csv');
        $this->assertNotNull($file);
        $this->assertSame('exports/march.csv', $file->path);
        $this->assertSame("a,b\n1,2", $file->contents);
        // The local driver answers the type from the extension, as MimeTypes does;
        // an S3 object keeps the type it was written with. The store passes through.
        $this->assertSame(\Whity\Storage\MimeTypes::forKey('exports/march.csv'), $file->contentType);
        $this->assertSame(['exports/march.csv'], $store->list('exports'));
        $this->assertSame([], $store->list('other'));
    }

    public function testATenantCannotSeeAnotherTenantsFilesForTheSamePlugin(): void
    {
        $files = $this->service();
        $plugin = $this->plugin('Acme\\Reports');

        $this->tenant = 1;
        $files->for($plugin)->put('summary.pdf', 'tenant-one');

        $this->tenant = 2;
        $store = $files->for($plugin);
        $this->assertNull($store->get('summary.pdf'));
        $this->assertSame([], $store->list());

        $store->put('summary.pdf', 'tenant-two');

        $this->tenant = 1;
        $this->assertSame('tenant-one', $files->for($plugin)->get('summary.pdf')?->contents);
    }

    public function testTheWorkspaceComesFromTheRequestContextNotFromTheCaller(): void
    {
        TenantContext::setTenantId(1);
        $store = (new HostPluginFiles($this->resolverClosure()))->for($this->plugin('Acme\\Reports'));
        $store->put('a.txt', 'one');

        TenantContext::reset();
        TenantContext::setTenantId(2);
        $this->assertNull($store->get('a.txt'));
    }

    public function testAPathThatWouldLeaveThePluginIsRefusedAndWritesNothing(): void
    {
        $files = $this->service();
        $reports = $files->for($this->plugin('Acme\\Reports'));
        $ledger = $files->for($this->plugin('Acme\\Ledger'));
        $ledger->put('secret.txt', 'ledger-only');

        foreach (['../ledger/secret.txt', 'exports/../../ledger/secret.txt', '/etc/passwd', 'a\\b'] as $path) {
            $this->assertRefused(fn () => $reports->get($path));
            $this->assertRefused(fn () => $reports->put($path, 'x'));
            $this->assertRefused(fn () => $reports->delete($path));
        }

        $this->assertSame('ledger-only', $ledger->get('secret.txt')?->contents);
        $this->assertSame([], $reports->list());
    }

    public function testWithNoWorkspaceInScopeThereAreNoFilesToReach(): void
    {
        $this->tenant = null;
        $store = $this->service()->for($this->plugin('Acme\\Reports'));

        $this->expectException(LogicException::class);

        $store->put('a.txt', 'x');
    }

    private function assertRefused(callable $operation): void
    {
        try {
            $operation();
        } catch (InvalidArgumentException) {
            return;
        }
        $this->fail('A path that leaves the plugin was accepted.');
    }

    private function service(): HostPluginFiles
    {
        return new HostPluginFiles($this->resolverClosure(), fn (): ?int => $this->tenant);
    }

    private function resolverClosure(): \Closure
    {
        return function (): TenantStorageResolver {
            $pdo = $this->schema();

            return new TenantStorageResolver(
                new LocalStorageDriver($this->root),
                new TenantStorageConfigRepository($pdo),
                new EntitlementService(new TenantEntitlementRepository($pdo)),
                new EncryptedSecretStore(['v1' => 'plugin_files_test_key_0123456789abcdef0123'], 'v1'),
            );
        };
    }

    private function schema(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = SchemaFromMigrations::make(true);
            $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'One', 'one')");
            $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (2, 'Two', 'two')");
        }

        return $this->pdo;
    }

    private function plugin(string $name): PluginInterface
    {
        $plugin = $this->createMock(PluginInterface::class);
        $plugin->method('getName')->willReturn($name);

        return $plugin;
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
