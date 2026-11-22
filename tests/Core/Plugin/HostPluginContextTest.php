<?php

declare(strict_types=1);

namespace Tests\Core\Plugin;

use PHPUnit\Framework\TestCase;
use Whity\Core\Plugin\HostPluginContext;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Context\ServiceUnavailable;
use Whity\Sdk\Http\Outbound\AddressPolicy;
use Whity\Sdk\Http\Outbound\HttpFailure;
use Whity\Sdk\Jobs\JobQueue;
use Whity\Sdk\Jobs\JobStatus;
use Whity\Sdk\Jobs\PluginJobQueue;
use Whity\Sdk\PluginContext;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\Secrets\PluginSecrets;
use Whity\Sdk\Secrets\SecretStore;

/** PluginContext (SDK 1.124, D3): one entry point over the factories each entry point binds. */
final class HostPluginContextTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $saved = [];

    protected function setUp(): void
    {
        $this->saved = $GLOBALS['whity_services'] ?? [];
        TenantContext::reset();
    }

    protected function tearDown(): void
    {
        $GLOBALS['whity_services'] = $this->saved;
        TenantContext::reset();
    }

    public function testItHandsOutTheStoreTheFactoryGivesThisPluginOnce(): void
    {
        $queue = new RecordingJobQueue();
        $factory = new class ($queue) implements PluginJobQueue {
            /** @var list<string> */
            public array $asked = [];

            public function __construct(private readonly JobQueue $queue)
            {
            }

            public function for(PluginInterface $plugin): JobQueue
            {
                $this->asked[] = $plugin->getName();

                return $this->queue;
            }
        };
        \Whity\register_service(PluginJobQueue::class, $factory);

        $scope = (new HostPluginContext())->for(self::plugin('Bakery'));

        $scope->jobs()->enqueue('catalog.sync');
        $scope->jobs()->enqueue('catalog.sync');
        self::assertSame(2, $queue->calls, 'both calls reached the store the factory gave');
        self::assertSame(['Bakery'], $factory->asked, 'asked once, for this plugin');
        self::assertSame('Bakery', $scope->pluginName());
    }

    public function testSecretsUseTheWorkspaceTheCallRunsIn(): void
    {
        $store = new MemorySecretStore();
        \Whity\register_service(PluginSecrets::class, new class ($store) implements PluginSecrets {
            public function __construct(private readonly SecretStore $store)
            {
            }

            public function for(PluginInterface $plugin): SecretStore
            {
                return $this->store;
            }
        });
        $secrets = (new HostPluginContext())->for(self::plugin('Bakery'))->secrets();

        self::inWorkspace(7);
        $secrets->set('api_key', 'sk_test_7');
        self::inWorkspace(8);
        self::assertNull($secrets->get('api_key'), 'another workspace sees nothing');
        $secrets->set('api_key', 'sk_test_8');
        self::inWorkspace(7);
        self::assertSame('sk_test_7', $secrets->get('api_key'));
        self::assertSame([7 => ['api_key' => 'sk_test_7'], 8 => ['api_key' => 'sk_test_8']], $store->values);

        TenantContext::reset();
        $this->expectException(\RuntimeException::class);
        $secrets->get('api_key');
    }

    public function testTheWorkspaceIsNullOutsideOneAndForTheSystem(): void
    {
        $scope = (new HostPluginContext())->for(self::plugin('Bakery'));

        self::assertNull($scope->workspaceId());
        self::inWorkspace(0);
        self::assertNull($scope->workspaceId());
        self::inWorkspace(12);
        self::assertSame(12, $scope->workspaceId());
    }

    public function testAServiceThisHostDoesntBindIsUnavailableWhenAskedForNotBefore(): void
    {
        unset($GLOBALS['whity_services'][PluginJobQueue::class]);
        $scope = (new HostPluginContext())->for(self::plugin('Bakery'));

        $this->expectException(ServiceUnavailable::class);
        $this->expectExceptionMessage(PluginJobQueue::class);
        $scope->jobs();
    }

    public function testHttpTakesAnAddressPolicy(): void
    {
        $http = (new HostPluginContext())->for(self::plugin('Bakery'))
            ->http(AddressPolicy::publicOnly(static fn (): array => ['10.0.0.1']));

        $this->expectException(HttpFailure::class);
        $http->send('GET', 'https://intranet.example.com/x', []);
    }

    public function testAPluginWithNoUsableNameIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new HostPluginContext())->for(self::plugin('!!!'));
    }

    public function testEveryEntryPointRegistersIt(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Core/Plugin/HostPluginServices.php');

        self::assertStringContainsString('register_service(\Whity\Sdk\PluginContext::class, new HostPluginContext())', $source);
        self::assertInstanceOf(PluginContext::class, new HostPluginContext());
    }

    /** The context locks once set, as a request's does; a new "request" resets it. */
    private static function inWorkspace(int $workspace): void
    {
        TenantContext::reset();
        TenantContext::setTenantId($workspace);
    }

    private static function plugin(string $name): PluginInterface
    {
        return new class ($name) implements PluginInterface {
            public function __construct(private readonly string $name)
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
        };
    }
}

final class RecordingJobQueue implements JobQueue
{
    public int $calls = 0;

    public function enqueue(string $job, array $payload = [], int $delaySeconds = 0, ?string $idempotencyKey = null): ?int
    {
        $this->calls++;

        return $idempotencyKey === 'taken' ? null : 1;
    }

    public function status(int $id): ?JobStatus
    {
        return null;
    }

    public function recent(int $limit = 50): array
    {
        return [];
    }
}

final class MemorySecretStore implements SecretStore
{
    /** @var array<int, array<string, string>> */
    public array $values = [];

    public function set(int $tenantId, string $key, string $value): void
    {
        $this->values[$tenantId][$key] = $value;
    }

    public function get(int $tenantId, string $key): ?string
    {
        return $this->values[$tenantId][$key] ?? null;
    }

    public function delete(int $tenantId, string $key): void
    {
        unset($this->values[$tenantId][$key]);
    }
}
