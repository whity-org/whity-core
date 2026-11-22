<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Whity\Core\Audit\AuditContext;
use Whity\Core\Observability\ErrorTracker;
use Whity\Core\Plugin\HostPluginLog;
use Whity\Core\Plugin\PluginRouteLimits;
use Whity\Core\RateLimit\RateLimitMiddleware;
use Whity\Core\RateLimit\SharedStoreRateLimitStore;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Router;
use Whity\Core\Store\ArraySharedStore;
use Whity\Sdk\Http\Response as SdkResponse;

/**
 * A plugin's log and error reports pass the core scrubber and carry the plugin
 * and workspace the host says, and a plugin route's own rate limit is applied
 * per person, to that route only (#1686).
 */
final class PluginLogAndRouteLimitsTest extends TestCase
{
    /** @var \ArrayObject<int, array{level: mixed, message: string, context: array<array-key, mixed>}> */
    private \ArrayObject $records;

    protected function setUp(): void
    {
        PluginRouteLimits::reset();
        AuditContext::reset();
        $this->records = new \ArrayObject();
    }

    protected function tearDown(): void
    {
        PluginRouteLimits::reset();
        AuditContext::reset();
    }

    /** @return array{level: mixed, message: string, context: array<array-key, mixed>} */
    private function record(int $i): array
    {
        $record = $this->records[$i] ?? null;
        self::assertNotNull($record);

        return $record;
    }

    private function log(?ErrorTracker $tracker = null, ?int $workspace = 4): HostPluginLog
    {
        $logger = new class ($this->records) extends AbstractLogger {
            /** @param \ArrayObject<int, array{level: mixed, message: string, context: array<array-key, mixed>}> $records */
            public function __construct(private \ArrayObject $records)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        return new HostPluginLog('KeyHub', $logger, $tracker, static fn (): ?int => $workspace);
    }

    public function testRecordsAreScrubbedAndTaggedByTheHost(): void
    {
        $this->log()->warning('Sync failed for amro@example.com with token=abc123', [
            'api_key' => 'sk_live_123',
            'order' => 'A-1',
            'nested' => ['password' => 'hunter2', 'note' => 'mail ahmad@example.com'],
            'plugin' => 'SomeoneElse',
            'tenant_id' => 99,
            'obj' => new \stdClass(),
        ]);

        self::assertCount(1, $this->records);
        $record = $this->record(0);
        self::assertSame('warning', $record['level']);
        self::assertStringStartsWith('[plugin:KeyHub] ', $record['message']);
        self::assertStringNotContainsString('amro@example.com', $record['message']);
        self::assertStringNotContainsString('abc123', $record['message']);
        self::assertSame('[redacted]', $record['context']['api_key']);
        self::assertSame('A-1', $record['context']['order']);
        self::assertSame('[redacted]', $record['context']['nested']['password']);
        self::assertStringNotContainsString('ahmad@example.com', $record['context']['nested']['note']);
        self::assertSame('[object]', $record['context']['obj']);
        self::assertSame('KeyHub', $record['context']['plugin']);
        self::assertSame(4, $record['context']['tenant_id']);
    }

    public function testErrorsGoToTheTrackerTagged(): void
    {
        $tracker = new class implements ErrorTracker {
            /** @var list<array{\Throwable, array<string, mixed>}> */
            public array $captured = [];

            public function captureException(\Throwable $e, array $context = []): void
            {
                $this->captured[] = [$e, $context];
            }
        };
        $this->log($tracker)->report(new \RuntimeException('bad answer'), ['provider' => 'polar', 'secret' => 'x']);

        self::assertCount(1, $tracker->captured);
        self::assertSame('bad answer', $tracker->captured[0][0]->getMessage());
        self::assertSame(['provider' => 'polar', 'secret' => '[redacted]', 'plugin' => 'KeyHub', 'tenant_id' => 4], $tracker->captured[0][1]);
        self::assertCount(0, $this->records);
    }

    public function testWithoutATrackerOrWhenItFailsTheReportIsLogged(): void
    {
        $this->log(null, null)->report(new \LogicException('token=abc'));
        $broken = new class implements ErrorTracker {
            public function captureException(\Throwable $e, array $context = []): void
            {
                throw new \RuntimeException('tracker down');
            }
        };
        $this->log($broken)->report(new \LogicException('second'));

        self::assertCount(2, $this->records);
        self::assertSame('error', $this->record(0)['level']);
        self::assertStringContainsString('LogicException', $this->record(0)['message']);
        self::assertStringNotContainsString('abc', $this->record(0)['message']);
        self::assertNull($this->record(0)['context']['tenant_id']);
        self::assertStringContainsString('second', $this->record(1)['message']);
    }

    public function testTheRefusalsOfADeclaredLimit(): void
    {
        self::assertNull(PluginRouteLimits::refusal(30));
        self::assertNotNull(PluginRouteLimits::refusal(0));
        self::assertNotNull(PluginRouteLimits::refusal(10001));
        self::assertNotNull(PluginRouteLimits::refusal('30'));
        self::assertNotNull(PluginRouteLimits::refusal(2.5));
    }

    public function testARouteLimitAppliesPerPersonToThatRouteOnly(): void
    {
        $ok = static fn (): Response => Response::json(['ok' => true], 200);
        $router = new Router();
        $router->register('POST', '/api/keyhub/sync/{id}', $ok, null, 'KeyHub\\', 'keyhub:write');
        $router->register('GET', '/api/keyhub/orders', $ok, null, 'KeyHub\\', 'keyhub:read');
        $router->register('GET', '/api/other/x', $ok, null, 'Other\\');
        PluginRouteLimits::add('KeyHub\\', 'POST', '/api/v1/keyhub/sync/{id}', 2);
        // Someone else's entry on another plugin's path is never applied.
        PluginRouteLimits::add('KeyHub\\', 'GET', '/api/v1/other/x', 1);

        $limiter = new RateLimitMiddleware(
            new SharedStoreRateLimitStore(new ArraySharedStore()),
            [PluginRouteLimits::rule($router)],
        );
        $send = static function (string $method, string $path) use ($limiter): SdkResponse {
            return $limiter->handle(new Request($method, $path, [], ''), static fn (): Response => Response::json([], 200));
        };

        AuditContext::set(10, '203.0.113.5');
        self::assertSame(200, $send('POST', '/api/v1/keyhub/sync/1')->getStatusCode());
        self::assertSame(200, $send('POST', '/api/v1/keyhub/sync/2')->getStatusCode());
        $third = $send('POST', '/api/v1/keyhub/sync/3');
        self::assertSame(429, $third->getStatusCode());
        self::assertArrayHasKey('retry-after', array_change_key_case($third->getHeaders()));

        // Another route of the plugin, and another plugin's route, are untouched.
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(200, $send('GET', '/api/v1/keyhub/orders')->getStatusCode());
            self::assertSame(200, $send('GET', '/api/v1/other/x')->getStatusCode());
        }

        // Another person has their own budget.
        AuditContext::set(11, '203.0.113.5');
        self::assertSame(200, $send('POST', '/api/v1/keyhub/sync/1')->getStatusCode());

        // Removed with the plugin.
        PluginRouteLimits::removeOwner('KeyHub\\');
        AuditContext::set(10, '203.0.113.5');
        self::assertSame(200, $send('POST', '/api/v1/keyhub/sync/9')->getStatusCode());
    }
}
