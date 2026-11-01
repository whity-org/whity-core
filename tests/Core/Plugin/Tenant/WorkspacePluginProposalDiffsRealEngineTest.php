<?php

declare(strict_types=1);

namespace Tests\Core\Plugin\Tenant;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Sdk\Manifest\RecordTypeValidatorTest;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\RoleChecker;
use Whity\Core\Plugin\Tenant\ManifestImpact;
use Whity\Core\Plugin\Tenant\ManifestTestRunner;
use Whity\Core\Plugin\Tenant\RecordWriter;
use Whity\Core\Plugin\Tenant\TenantManifestPublisher;
use Whity\Core\Plugin\Tenant\TenantPluginRepository;
use Whity\Core\Plugin\Tenant\TenantRecordRepository;
use Whity\Core\Plugin\Tenant\WorkspacePluginProposalDiffs;
use Whity\Core\PluginRoleSeeder;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\Tools\ProposalDiffRegistry;

/**
 * WHIT-764: the diff a person sees on a proposal to publish or roll back a workspace plugin.
 */
final class WorkspacePluginProposalDiffsRealEngineTest extends TestCase
{
    private const A = 1;

    private PDO $pdo;
    private TenantPluginRepository $plugins;
    private TenantRecordRepository $records;
    private TenantManifestPublisher $publisher;

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (0, 'system')");
        $this->pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 'example-bakery')");
        $this->plugins = new TenantPluginRepository($this->pdo);
        $this->records = new TenantRecordRepository($this->pdo);
        $this->publisher = new TenantManifestPublisher($this->pdo, $this->plugins, new PluginRoleSeeder($this->pdo), []);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    private function diffs(): WorkspacePluginProposalDiffs
    {
        return new WorkspacePluginProposalDiffs(
            $this->plugins,
            $this->publisher,
            new ManifestImpact($this->records),
            new ManifestTestRunner($this->pdo, $this->records, new RecordWriter($this->pdo, $this->records, $this->createMock(RoleChecker::class))),
        );
    }

    public function testAPublishProposalCarriesItsImpactAndTests(): void
    {
        $v1 = RecordTypeValidatorTest::manifest();
        $first = $this->publisher->publish(self::A, $v1);
        $this->records->create(self::A, 'CakeOrders', 'order', 'draft', ['customer' => 'Example Bakery', 'paid' => true, 'pickup_on' => '2026-11-03'], null);

        $v2 = $v1;
        $v2['plugin']['version'] = '1.1.0';
        unset($v2['records']['order']['fields']['paid']);
        $diff = $this->diffs()->forPublish(['manifest' => $v2, 'confirmDestructive' => true], self::A);

        self::assertNotNull($diff);
        self::assertSame(['CakeOrders', '1.0.0', '1.1.0', 'destructive'], [$diff['plugin'], $diff['from'], $diff['to'], $diff['level']]);
        $change = array_column($diff['changes'], null, 'path')['records.order.fields.paid'];
        self::assertSame(['destructive', 1], [$change['effect'], $change['records']]);
        self::assertSame(['passed' => 0, 'failed' => 0], $diff['tests']);
        self::assertSame($first['version_id'], $this->plugins->liveVersionId(self::A, 'CakeOrders'), 'nothing was published');

        // Rolling back to v1 from a live v2 is the same plan the other way.
        $second = $this->publisher->publish(self::A, $v2);
        $back = $this->diffs()->forActivate(['plugin' => 'cakeorders', 'id' => $first['version_id']], self::A);
        self::assertNotNull($back);
        self::assertSame(['1.1.0', '1.0.0', 'safe'], [$back['from'], $back['to'], $back['level']]);
        self::assertSame($second['version_id'], $this->plugins->liveVersionId(self::A, 'CakeOrders'));
    }

    public function testTheRegistryGivesThePlanForThePublishTool(): void
    {
        $registry = new ProposalDiffRegistry();
        $this->diffs()->registerOn($registry);

        $diff = $registry->diffFor(WorkspacePluginProposalDiffs::PUBLISH, ['manifest' => RecordTypeValidatorTest::manifest()], self::A);
        self::assertSame(['safe', ['new_plugin']], [$diff['level'] ?? null, array_column($diff['changes'] ?? [], 'change')]);
        self::assertNull($registry->diffFor(WorkspacePluginProposalDiffs::ACTIVATE, ['plugin' => 'nothing', 'id' => 1], self::A));
    }

    public function testArgumentsThatDontMakeAPlanGiveNoDiff(): void
    {
        $diffs = $this->diffs();

        self::assertNull($diffs->forPublish(['manifest' => ['manifest' => 'nonsense']], self::A));
        self::assertNull($diffs->forPublish([], self::A));
        self::assertNull($diffs->forActivate(['plugin' => 'cakeorders', 'id' => 999], self::A));
    }
}
