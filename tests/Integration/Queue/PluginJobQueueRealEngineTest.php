<?php

declare(strict_types=1);

namespace Tests\Integration\Queue;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Plugin\PluginIdentity;
use Whity\Core\Queue\HostPluginJobQueue;
use Whity\Core\Queue\JobRepository;
use Whity\Core\Sandbox\SideEffectBlockedException;
use Whity\Core\Sandbox\SideEffectGuard;
use Whity\Sdk\JobInterface;
use Whity\Sdk\Jobs\JobQueue;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginJobsInterface;

/**
 * A plugin's own jobs (WHIT-1031), against the migration-built schema: enqueued
 * only under the plugin's own declared names in the current workspace, and
 * readable only by that plugin in that workspace.
 */
final class PluginJobQueueRealEngineTest extends TestCase
{
    private PDO $pdo;
    private JobRepository $jobs;
    private ?int $tenant = 1;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Example Cafe', 'example-cafe') ON CONFLICT DO NOTHING");
        $this->jobs = new JobRepository($this->pdo);
    }

    public function testAJobIsEnqueuedUnderThePluginsNameInTheCurrentWorkspace(): void
    {
        $id = $this->queue('jobs-acme')->enqueue('catalog.sync', ['page' => 1]);
        self::assertIsInt($id);

        $row = $this->jobs->find(1, $id);
        self::assertNotNull($row);
        self::assertSame('jobs_acme:catalog.sync', $row['name']);
        self::assertSame(['page' => 1], $row['payload']);
    }

    /** @dataProvider notItsOwn */
    public function testOnlyThePluginsOwnDeclaredJobsCanBeEnqueued(string $name): void
    {
        try {
            $this->queue('jobs-acme')->enqueue($name);
            self::fail("{$name} was accepted.");
        } catch (\InvalidArgumentException) {
            self::assertSame(0, $this->jobs->countForTenant(1, null, null));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function notItsOwn(): iterable
    {
        yield 'never declared' => ['catalog.delete'];
        yield 'a core job' => ['core.notifications.deliver'];
        yield 'another plugin, by full name' => ['jobs_other:catalog.sync'];
        yield 'its own, by full name' => ['jobs_acme:catalog.sync'];
    }

    public function testAPluginSeesOnlyItsOwnJobs(): void
    {
        $mine = $this->queue('jobs-acme')->enqueue('catalog.sync');
        $theirs = $this->queue('jobs-other')->enqueue('catalog.sync');
        self::assertIsInt($mine);
        self::assertIsInt($theirs);

        $queue = $this->queue('jobs-acme');
        self::assertNull($queue->status($theirs));
        self::assertSame('catalog.sync', $queue->status($mine)?->job);
        self::assertSame([$mine], array_map(static fn ($s): int => $s->id, $queue->recent()));
    }

    public function testAnUnderscoreInASlugDoesNotMatchALookalikePlugin(): void
    {
        $this->queue('jobs-acme')->enqueue('catalog.sync');
        $this->jobs->enqueue(1, 'jobsxacme:catalog.sync', []);

        self::assertCount(1, $this->queue('jobs-acme')->recent());
    }

    public function testAWorkspaceSeesOnlyItsOwnJobs(): void
    {
        $queue = $this->queue('jobs-acme');
        $id = $queue->enqueue('catalog.sync');
        self::assertIsInt($id);

        $this->tenant = 2;

        self::assertNull($queue->status($id));
        self::assertSame([], $queue->recent());
    }

    public function testAnIdempotencyKeyIsThePluginsOwn(): void
    {
        self::assertNotNull($this->queue('jobs-acme')->enqueue('catalog.sync', [], 0, 'k'));
        self::assertNull($this->queue('jobs-acme')->enqueue('catalog.sync', [], 0, 'k'));
        self::assertNotNull($this->queue('jobs-other')->enqueue('catalog.sync', [], 0, 'k'));
    }

    public function testAFinishedJobStaysReadableWithItsResult(): void
    {
        $queue = $this->queue('jobs-acme');
        $id = $queue->enqueue('catalog.sync');
        self::assertIsInt($id);

        $this->jobs->reserve();
        $this->jobs->markCompleted($id, ['done' => 3]);

        $status = $queue->status($id);
        self::assertSame('completed', $status?->status);
        self::assertSame(['done' => 3], $status->result);
    }

    /** @dataProvider noWorkspace */
    public function testNoWorkspaceIsRefused(?int $tenant): void
    {
        $queue = $this->queue('jobs-acme');
        $this->tenant = $tenant;

        $this->expectException(\RuntimeException::class);
        $queue->enqueue('catalog.sync');
    }

    /** @return iterable<string, array{?int}> */
    public static function noWorkspace(): iterable
    {
        yield 'none' => [null];
        yield 'the system tenant' => [0];
    }

    public function testAStubCarryingALoadedPluginsNameIsRefused(): void
    {
        $this->queue('jobs-acme');

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->for(self::plugin('jobs-acme'));
    }

    public function testAProposalPreviewEnqueuesNothing(): void
    {
        $queue = $this->queue('jobs-acme');

        $this->expectException(SideEffectBlockedException::class);
        SideEffectGuard::blocking('a proposal preview', fn () => $queue->enqueue('catalog.sync'));
    }

    private function queue(string $name): JobQueue
    {
        $plugin = self::plugin($name);
        PluginIdentity::bind($plugin, $name);

        return $this->service()->for($plugin);
    }

    private function service(): HostPluginJobQueue
    {
        return new HostPluginJobQueue($this->jobs, fn (): ?int => $this->tenant);
    }

    private static function plugin(string $name): PluginInterface&PluginJobsInterface
    {
        return new class ($name) implements PluginInterface, PluginJobsInterface {
            public function __construct(private string $name)
            {
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function getVersion(): string
            {
                return '1.0.0';
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

            public function getJobs(): array
            {
                return ['catalog.sync' => new class implements JobInterface {
                    public function handle(array $payload): array
                    {
                        return [];
                    }
                }];
            }

            public function getSubmittableJobs(): array
            {
                return [];
            }
        };
    }
}
