<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Inbox;

use PHPUnit\Framework\TestCase;
use Whity\Core\Inbox\InboxAggregate;
use Whity\Core\Inbox\InboxSourceRegistry;
use Whity\Core\Inbox\InvalidInboxSourceException;
use Whity\Sdk\Inbox\InboxCursor;
use Whity\Sdk\Inbox\InboxItem;
use Whity\Sdk\Inbox\InboxOrder;
use Whity\Sdk\Inbox\InboxSource;
use Whity\Sdk\Inbox\KeysetInboxSource;

/**
 * The merged inbox (#881, ADR 0016 §7): every item exactly once across pages,
 * in one order, whatever the sources and page size; a failing source is named,
 * not fatal.
 */
final class InboxAggregateTest extends TestCase
{
    /** @param list<InboxItem> $items in InboxOrder */
    private static function offsetSource(array $items): InboxSource
    {
        return new class ($items) implements InboxSource {
            /** @param list<InboxItem> $items */
            public function __construct(private array $items)
            {
            }

            public function label(): string
            {
                return 'Offset';
            }

            public function list(int $tenantId, int $profileId, bool $openOnly, int $limit, int $offset): array
            {
                return array_slice($this->items, $offset, $limit);
            }

            public function count(int $tenantId, int $profileId, bool $openOnly): int
            {
                return count($this->items);
            }
        };
    }

    /** @param list<InboxItem> $items */
    private static function keysetSource(string $key, array $items): KeysetInboxSource
    {
        return new class ($key, $items) implements KeysetInboxSource {
            public int $keysetCalls = 0;

            /** @param list<InboxItem> $items */
            public function __construct(private string $key, private array $items)
            {
            }

            public function label(): string
            {
                return 'Keyset';
            }

            public function list(int $tenantId, int $profileId, bool $openOnly, int $limit, int $offset): array
            {
                throw new \LogicException('the aggregate must seek a keyset source');
            }

            public function listAfter(int $tenantId, int $profileId, bool $openOnly, ?InboxCursor $after, int $limit): array
            {
                $this->keysetCalls++;
                $out = [];
                foreach ($this->items as $item) {
                    if ($after === null || InboxOrder::isAfter($this->key, $item, $after)) {
                        $out[] = $item;
                    }
                }

                return array_slice($out, 0, $limit);
            }

            public function count(int $tenantId, int $profileId, bool $openOnly): int
            {
                return count($this->items);
            }
        };
    }

    /** @return list<InboxItem> newest first */
    private static function items(string $prefix, int $n, int $startMinute, bool $urgentEvery3 = false): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $minute = $startMinute - $i * 2;
            $at = gmdate('Y-m-d H:i:s', 1_790_000_000 + $minute * 60);
            $out[] = new InboxItem($prefix . $i, $prefix . $i, null, $at, null, null, null, [], null, $urgentEvery3 && $i % 3 === 0);
        }
        // Keep each source's own list in the global order (urgent first).
        usort($out, static fn (InboxItem $a, InboxItem $b): int => InboxOrder::compare('x', $a, 'x', $b));

        return $out;
    }

    public function testPagingVisitsEveryItemOnceInOrderForEveryPageSize(): void
    {
        $registry = new InboxSourceRegistry();
        $registry->registerCoreSource('alpha', self::offsetSource(self::items('a', 137, 10_000)));
        $registry->register('Acme', ['tasks' => self::keysetSource('acme:tasks', self::items('t', 61, 9_999, true))]);
        $aggregate = new InboxAggregate($registry);

        foreach ([1, 7, 25, 100] as $size) {
            $seen = [];
            $cursor = null;
            $previous = null;
            do {
                $page = $aggregate->page(1, 1, true, $cursor, $size);
                self::assertLessThanOrEqual($size, count($page['items']));
                foreach ($page['items'] as $row) {
                    $key = $row['source'] . '/' . $row['item']->id;
                    self::assertArrayNotHasKey($key, $seen, "size {$size}: {$key} repeated");
                    $seen[$key] = true;
                    if ($previous !== null) {
                        self::assertLessThan(0, InboxOrder::compare($previous['source'], $previous['item'], $row['source'], $row['item']));
                    }
                    $previous = $row;
                }
                $cursor = $page['next'] === null ? null : InboxCursor::decode($page['next']);
            } while ($cursor !== null);

            self::assertCount(198, $seen, "size {$size}: every item exactly once");
        }
    }

    public function testUrgentItemsLeadTheFirstPage(): void
    {
        $registry = new InboxSourceRegistry();
        $registry->registerCoreSource('alpha', self::offsetSource(self::items('a', 5, 10_000)));
        $registry->register('Acme', ['tasks' => self::keysetSource('acme:tasks', self::items('t', 4, 100, true))]);

        $page = (new InboxAggregate($registry))->page(1, 1, true, null, 3);

        self::assertTrue($page['items'][0]['item']->urgent);
        self::assertTrue($page['items'][1]['item']->urgent, 't0 and t3 are urgent and older than every alpha item');
        self::assertSame('acme:tasks', $page['items'][0]['source']);
    }

    public function testAFailingSourceIsNamedAndTheRestIsServed(): void
    {
        $registry = new InboxSourceRegistry();
        $registry->registerCoreSource('alpha', self::offsetSource(self::items('a', 3, 10_000)));
        $registry->registerCoreSource('broken', new class () implements InboxSource {
            public function label(): string
            {
                return 'Broken';
            }

            public function list(int $tenantId, int $profileId, bool $openOnly, int $limit, int $offset): array
            {
                throw new \RuntimeException('down');
            }

            public function count(int $tenantId, int $profileId, bool $openOnly): int
            {
                return 0;
            }
        });

        $page = (new InboxAggregate($registry))->page(1, 1, true, null, 10);

        self::assertSame(['broken'], $page['unavailable']);
        self::assertCount(3, $page['items']);
        self::assertNull($page['next']);
    }

    public function testAnOffsetSourceDeeperThanTheScanLimitIsReportedTruncated(): void
    {
        $registry = new InboxSourceRegistry();
        $registry->registerCoreSource('deep', self::offsetSource(self::items('d', 1_200, 100_000)));
        $aggregate = new InboxAggregate($registry);

        $last = self::items('d', 1_200, 100_000)[1_050];
        $page = $aggregate->page(1, 1, true, InboxCursor::fromItem('deep', $last), 10);

        self::assertSame(['deep'], $page['truncated']);
    }

    public function testPluginSourcesAreNamespacedAndAReloadReplacesOnlyItsOwn(): void
    {
        $registry = new InboxSourceRegistry();
        $registry->register('Acme Tasks', ['tasks' => self::offsetSource([]), 'reviews' => self::offsetSource([])]);
        $registry->register('Other', ['tasks' => self::offsetSource([])]);

        self::assertTrue($registry->has('acme_tasks:tasks'));
        self::assertTrue($registry->has('other:tasks'));

        $registry->register('Acme Tasks', ['tasks' => self::offsetSource([])]);
        self::assertFalse($registry->has('acme_tasks:reviews'));
        self::assertTrue($registry->has('other:tasks'));
        self::assertSame('Acme Tasks', $registry->catalogue()[0]['origin']);
    }

    public function testAPluginCannotRegisterAsCoreOrWithABadKey(): void
    {
        $registry = new InboxSourceRegistry();
        foreach ([['core', ['x' => self::offsetSource([])]], ['Acme', ['Bad-Key' => self::offsetSource([])]], ['Acme', ['ok' => 'not a source']]] as [$name, $sources]) {
            try {
                $registry->register($name, $sources);
                self::fail("{$name} should have been refused");
            } catch (InvalidInboxSourceException) {
                self::assertSame([], $registry->keys());
            }
        }
    }
}
