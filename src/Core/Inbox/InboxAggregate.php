<?php

declare(strict_types=1);

namespace Whity\Core\Inbox;

use Throwable;
use Whity\Sdk\Inbox\InboxCursor;
use Whity\Sdk\Inbox\InboxItem;
use Whity\Sdk\Inbox\InboxOrder;
use Whity\Sdk\Inbox\InboxSource;
use Whity\Sdk\Inbox\KeysetInboxSource;

/**
 * Every inbox source as one list, one page at a time (#881, ADR 0016 §7).
 *
 * WHY THIS IS CORRECT FOR ANY NUMBER OF SOURCES
 * ---------------------------------------------
 * A page asks every source for up to `limit` items strictly after the cursor,
 * merges them in {@see InboxOrder}, keeps the first `limit`, and returns the
 * last kept item as the next cursor. Every item of the true next page is among
 * what was fetched: if one were missing, its source would have returned `limit`
 * items before it, all of which come first, so it would not be on this page.
 * Concatenating page 1 of each source (#867 one level up) cannot happen.
 *
 * Positions, not page numbers: an item arriving between two requests lands
 * before the cursor and never pushes a later item onto a page already read.
 *
 * SOURCES THAT CANNOT SEEK
 * ------------------------
 * A {@see KeysetInboxSource} is asked directly. Any other source is read from
 * its start in chunks and filtered here, scanning at most {@see MAX_SCAN}
 * items. Past that depth such a source is reported in `truncated`, so a client
 * can say "older items from this source are on its own tab" instead of
 * silently ending the list.
 *
 * FAILURE ISOLATION
 * -----------------
 * A source that throws is named in `unavailable`; the rest of the page is
 * still served.
 */
final class InboxAggregate
{
    public const MAX_LIMIT = 100;
    private const MAX_SCAN = 1000;
    private const CHUNK = 100;

    public function __construct(private readonly InboxSourceRegistry $sources)
    {
    }

    /**
     * @return array{
     *     items: list<array{source: string, item: InboxItem}>,
     *     next: ?string,
     *     unavailable: list<string>,
     *     truncated: list<string>
     * }
     */
    public function page(int $tenantId, int $profileId, bool $openOnly, ?InboxCursor $after, int $limit): array
    {
        $limit = max(1, min($limit, self::MAX_LIMIT));

        $candidates = [];
        $unavailable = [];
        $truncated = [];

        foreach ($this->sources->keys() as $key) {
            $source = $this->sources->get($key);
            if ($source === null) {
                continue;
            }
            try {
                [$items, $cut] = $this->after($source, $key, $tenantId, $profileId, $openOnly, $after, $limit);
            } catch (Throwable $e) {
                error_log("[InboxAggregate] inbox source {$key} failed: " . $e->getMessage());
                $unavailable[] = $key;
                continue;
            }
            if ($cut) {
                $truncated[] = $key;
            }
            foreach ($items as $item) {
                $candidates[] = ['source' => $key, 'item' => $item];
            }
        }

        usort(
            $candidates,
            static fn (array $a, array $b): int => InboxOrder::compare($a['source'], $a['item'], $b['source'], $b['item'])
        );

        $page = array_slice($candidates, 0, $limit);
        $next = null;
        if (count($candidates) > $limit && $page !== []) {
            $last = $page[count($page) - 1];
            $next = InboxCursor::fromItem($last['source'], $last['item'])->encode();
        }

        return ['items' => $page, 'next' => $next, 'unavailable' => $unavailable, 'truncated' => $truncated];
    }

    /**
     * Up to `limit + 1` of one source's items after the cursor, in order (one
     * extra so the page knows whether anything follows). The flag says the scan
     * stopped at {@see MAX_SCAN} before the source ran out.
     *
     * @return array{0: list<InboxItem>, 1: bool}
     */
    private function after(
        InboxSource $source,
        string $key,
        int $tenantId,
        int $profileId,
        bool $openOnly,
        ?InboxCursor $after,
        int $limit,
    ): array {
        $want = $limit + 1;

        if ($source instanceof KeysetInboxSource) {
            $items = $source->listAfter($tenantId, $profileId, $openOnly, $after, $want);

            return [self::onlyAfter($key, self::items($items), $after, $want), false];
        }

        $kept = [];
        $offset = 0;
        while ($offset < self::MAX_SCAN) {
            $chunk = self::items($source->list($tenantId, $profileId, $openOnly, self::CHUNK, $offset));
            foreach (self::onlyAfter($key, $chunk, $after, PHP_INT_MAX) as $item) {
                $kept[] = $item;
            }
            if (count($kept) >= $want || count($chunk) < self::CHUNK) {
                return [self::onlyAfter($key, $kept, null, $want), false];
            }
            $offset += self::CHUNK;
        }

        return [self::onlyAfter($key, $kept, null, $want), true];
    }

    /**
     * The items strictly after the cursor, sorted, at most `$max`. Sorting here
     * as well means a source that returns its items slightly out of order costs
     * nothing within a page.
     *
     * @param list<InboxItem> $items
     *
     * @return list<InboxItem>
     */
    private static function onlyAfter(string $key, array $items, ?InboxCursor $after, int $max): array
    {
        if ($after !== null) {
            $items = array_values(array_filter(
                $items,
                static fn (InboxItem $i): bool => InboxOrder::isAfter($key, $i, $after)
            ));
        }
        usort($items, static fn (InboxItem $a, InboxItem $b): int => InboxOrder::compare($key, $a, $key, $b));

        return array_slice($items, 0, $max);
    }

    /**
     * Drop anything a plugin returned that is not an item, rather than let one
     * stray value break the whole page.
     *
     * @param array<mixed> $items
     *
     * @return list<InboxItem>
     */
    private static function items(array $items): array
    {
        return array_values(array_filter($items, static fn (mixed $i): bool => $i instanceof InboxItem));
    }
}
