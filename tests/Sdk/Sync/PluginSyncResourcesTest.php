<?php

declare(strict_types=1);

namespace Tests\Sdk\Sync;

use DemoCatalog\DemoCatalogPlugin;
use PHPUnit\Framework\TestCase;
use Whity\Sdk\Contribute\PluginSyncResourcesInterface;

require_once dirname(__DIR__, 3) . '/plugins/DemoCatalog/DemoCatalogPlugin.php';

/**
 * SDK 1.173 (WHIT-1422): a plugin names the routes Whity Desktop may relay.
 * DemoCatalog is the first consumer, and what it names must be real: each
 * path is the base of a SyncController, so the plugin registers a GET (the
 * changes feed) and a POST (idempotent create) exactly there, and a PATCH
 * and DELETE on `{path}/{id}`.
 */
final class PluginSyncResourcesTest extends TestCase
{
    public function testDemoCatalogDeclaresItsItemsAndEveryDeclaredPathIsServed(): void
    {
        $plugin = new DemoCatalogPlugin();
        $this->assertInstanceOf(PluginSyncResourcesInterface::class, $plugin);

        $declared = $plugin->getSyncResources();
        $this->assertSame(['/api/demo-catalog/items'], $declared);

        $registered = [];
        foreach ($plugin->getRoutes() as $route) {
            $registered[$route['method'] . ' ' . $route['path']] = true;
        }
        foreach ($declared as $path) {
            $this->assertMatchesRegularExpression('#^/api/[a-z0-9][a-z0-9/_-]*$#', $path);
            $this->assertArrayHasKey('GET ' . $path, $registered, "$path has no changes feed");
            $this->assertArrayHasKey('POST ' . $path, $registered, "$path has no create");
            $this->assertArrayHasKey('PATCH ' . $path . '/{id:\d+}', $registered, "$path has no update");
            $this->assertArrayHasKey('DELETE ' . $path . '/{id:\d+}', $registered, "$path has no delete");
        }
    }
}
