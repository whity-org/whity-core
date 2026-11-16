<?php

declare(strict_types=1);

namespace Tests\Core\Document;

use PHPUnit\Framework\TestCase;
use Tests\Sdk\Manifest\DocumentBlockValidatorTest;
use Whity\Core\Document\Blocks\DocumentBlockRegistry;
use Whity\Core\Document\Blocks\PluginBlockPrinter;
use Whity\Core\Document\Fields\DocumentFieldRegistry;
use Whity\Core\Document\Slots\TemplateSlotRegistry;
use Whity\Core\PluginLoader;
use Whity\Core\Router;

/**
 * Editor M7 (E.17–E.20, SDK 1.103): plugins add document blocks as
 * declarations. Code plugins register theirs at load, a workspace's plugins
 * per request; Verified comes from Whity's marks; a block prints from its
 * print view as Whity's own blocks, and one whose plugin is off stays a
 * plugin block for the publish check to name.
 */
final class PluginDocumentBlocksTest extends TestCase
{
    private static int $seq = 0;

    private ?string $tempDir = null;

    /** @var list<array{plugin: string, label: array{en: string, ar: string}, verified: bool, blocks: array<string, array<string, mixed>>}> */
    private array $workspace = [];

    /** @var list<array{plugin: string, label: array{en: string, ar: string}}> */
    private array $off = [];

    /** @var list<string> */
    private array $verifiedCode = [];

    protected function tearDown(): void
    {
        if ($this->tempDir !== null) {
            foreach ((array) scandir($this->tempDir) as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    @unlink($this->tempDir . '/' . $entry);
                }
            }
            @rmdir($this->tempDir);
        }
    }

    private function registry(): DocumentBlockRegistry
    {
        return new DocumentBlockRegistry(
            fn (int $tenantId): array => $tenantId === 1 ? $this->workspace : [],
            fn (int $tenantId): array => $tenantId === 1 ? $this->off : [],
            fn (string $plugin): bool => in_array($plugin, $this->verifiedCode, true),
        );
    }

    public function testCodeAndWorkspaceBlocksAreListedPerWorkspaceWithWhitysMark(): void
    {
        $registry = $this->registry();
        $registry->register('Shipping', ['en' => 'Shipping', 'ar' => 'Shipping'], ['delivery_status' => DocumentBlockValidatorTest::delivery()]);
        $this->verifiedCode = ['Shipping'];
        $noPrint = DocumentBlockValidatorTest::delivery();
        unset($noPrint['print'], $noPrint['allowedIn']);
        $this->workspace = [
            ['plugin' => 'RouteNotes', 'label' => ['en' => 'Route notes', 'ar' => 'ملاحظات المسار'], 'verified' => false, 'blocks' => ['stop' => $noPrint]],
            // A workspace plugin can't stand in for a code plugin of the same name.
            ['plugin' => 'Shipping', 'label' => ['en' => 'Fake', 'ar' => 'Fake'], 'verified' => true, 'blocks' => ['x' => $noPrint]],
        ];
        $this->off = [['plugin' => 'DeliveryTracker', 'label' => ['en' => 'DeliveryTracker', 'ar' => 'DeliveryTracker']]];

        $listed = $registry->forTenant(1);
        self::assertSame(['Shipping', 'RouteNotes'], array_column($listed['plugins'], 'plugin'));
        self::assertSame([true, false], array_column($listed['plugins'], 'verified'));
        $shipping = $listed['plugins'][0]['blocks'][0];
        self::assertSame('Shipping/delivery_status', $shipping['name']);
        self::assertSame(['note', 'template'], $shipping['allowedIn']);
        $stop = $listed['plugins'][1]['blocks'][0];
        self::assertNull($stop['print']);
        self::assertSame(['note', 'wiki'], $stop['allowedIn'], 'without a print view: notes and wiki pages only (E.18)');
        self::assertSame('DeliveryTracker', $listed['off'][0]['plugin']);

        self::assertSame(['Shipping'], array_column($registry->forTenant(2)['plugins'], 'plugin'), 'another workspace sees the code plugins only');
        self::assertNull($registry->definition(2, 'RouteNotes', 'stop'));
    }

    public function testAPrintViewPrintsAsWhitysOwnBlocksWithTheBlocksData(): void
    {
        $registry = $this->registry();
        $definition = DocumentBlockValidatorTest::delivery();
        $definition['print'] = [
            ['type' => 'heading', 'level' => 2, 'text' => 'Order {{data.order}}'],
            ['type' => 'keyValue', 'items' => [['label' => 'Stage', 'value' => '{{data.stage}}']]],
            ['type' => 'list', 'items' => ['Signed for at the door']],
            ['type' => 'table', 'columns' => [['key' => 'box', 'label' => 'Box']], 'rows' => [['box' => '1 of 3']]],
            ['type' => 'divider'],
        ];
        $registry->register('Shipping', ['en' => 'Shipping', 'ar' => 'Shipping'], ['delivery_status' => $definition]);
        $printer = new PluginBlockPrinter($registry);
        $block = ['id' => 'd1', 'type' => 'plugin', 'plugin' => 'Shipping', 'blockType' => 'delivery_status', 'schemaVersion' => 1, 'data' => ['order' => 'SO-2207', 'stage' => 'out']];
        $gone = ['id' => 'g1', 'type' => 'plugin', 'plugin' => 'DeliveryTracker', 'blockType' => 'x', 'schemaVersion' => 1, 'data' => []];

        $out = $printer->lower(1, [$block, ['id' => 't1', 'type' => 'toggle', 'open' => true, 'summary' => [], 'children' => [$gone]]], 'ar');

        $t = static fn (string $s): array => [['type' => 'text', 'text' => $s]];
        self::assertSame([
            ['id' => 'd1-p0', 'type' => 'heading', 'level' => 1, 'content' => $t('Order SO-2207')],
            // A select prints its option's label, in the document's language.
            ['id' => 'd1-p1', 'type' => 'table', 'headerRow' => false, 'columns' => [['align' => 'start'], ['align' => 'start']], 'rows' => [[$t('Stage'), $t('في الطريق')]]],
            ['id' => 'd1-p2-0', 'type' => 'bullet', 'level' => 0, 'content' => $t('Signed for at the door')],
            ['id' => 'd1-p3', 'type' => 'table', 'headerRow' => true, 'columns' => [['align' => 'start']], 'rows' => [[$t('Box')], [$t('1 of 3')]]],
            ['id' => 'd1-p4', 'type' => 'divider'],
        ], array_slice($out, 0, 5));
        self::assertSame($gone, $out[5]['children'][0], "a block whose plugin is gone stays, for the publish check to name");
        self::assertSame(['g1' => 'DeliveryTracker'], $printer->missing(1, $out));
    }

    public function testACodePluginRegistersItsBlocksAtLoadAndTheyGoWhenItUnloads(): void
    {
        $registry = $this->registry();
        $this->tempDir = sys_get_temp_dir() . '/whity_plugin_blocks_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        $class = 'ShippingBlocksFixture' . (++self::$seq) . 'Plugin';
        $definition = var_export(DocumentBlockValidatorTest::delivery(), true);
        file_put_contents($this->tempDir . '/' . $class . '.php', <<<PHP
        <?php

        namespace Whity\\Plugins;

        use Whity\\Sdk\\Documents\\DocumentBlock;
        use Whity\\Sdk\\PluginDocumentBlocksInterface;
        use Whity\\Sdk\\PluginInterface;

        class {$class} implements PluginInterface, PluginDocumentBlocksInterface
        {
            public function getName(): string { return 'Shipping'; }
            public function getVersion(): string { return '1.0.0'; }
            public function getRoutes(): array { return []; }
            public function getPermissions(): array { return []; }
            public function getHooks(): array { return []; }
            public function getMigrations(): array { return []; }
            public function getDocumentBlocks(): array
            {
                return [new DocumentBlock('delivery_status', {$definition}), new DocumentBlock('delivery_status', {$definition})];
            }
        }
        PHP);

        $logger = new BlocksLoaderSpyLogger();
        $loader = new PluginLoader($this->tempDir, new Router(''), null, null, $logger);
        $loader->setDocumentRegistries(new TemplateSlotRegistry(), new DocumentFieldRegistry(), $registry);
        $loader->load();

        self::assertSame(['Shipping/delivery_status'], array_column($registry->forTenant(1)['plugins'][0]['blocks'] ?? [], 'name'));
        self::assertTrue($logger->has("declares document block 'delivery_status' twice"));
        self::assertSame('active', array_column($loader->getPluginStatuses(), 'state', 'name')['Shipping'] ?? null);

        // Removed and reloaded: its blocks go with it.
        unlink($this->tempDir . '/' . $class . '.php');
        $loader->reload();
        self::assertSame([], $registry->forTenant(1)['plugins']);
    }
}

final class BlocksLoaderSpyLogger extends \Psr\Log\AbstractLogger
{
    /** @var list<string> */
    public array $messages = [];

    /**
     * @param mixed $level
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->messages[] = (string) $message;
    }

    public function has(string $needle): bool
    {
        foreach ($this->messages as $message) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }
}
