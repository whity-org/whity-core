<?php

declare(strict_types=1);

namespace Whity\Sdk\Inbox;

/**
 * One contributor of items to a person's inbox (SDK 1.97, #881).
 *
 * A plugin returns these from {@see \Whity\Sdk\PluginInboxSourcesInterface}
 * under bare keys; the host namespaces each key as `<plugin>:<key>` and reads
 * every source through one registry, so a person with items from several
 * plugins has one inbox, not one per plugin.
 *
 * SELF-SCOPED, ALWAYS. Every method takes `(tenantId, profileId)` and answers
 * only for that person in that workspace. There is no "everyone's items" here:
 * an inbox is the caller's own, which is why the inbox routes need no
 * permission.
 *
 * ORDER. {@see list()} returns items in {@see InboxOrder}: urgent first, then
 * newest first, then by id descending. The merged inbox relies on each source
 * keeping that order; a source that returns an urgent item after ordinary ones
 * will have it shown late. Sources that can seek to a position cheaply should
 * also implement {@see KeysetInboxSource}.
 */
interface InboxSource
{
    /** A short name for the source, shown where items are grouped by origin. */
    public function label(): string;

    /**
     * A page of the caller's items, in {@see InboxOrder}.
     *
     * @param bool $openOnly True for the ordinary inbox (items still awaiting the
     *        caller); false to include their history. A source with no notion of
     *        a closed item may ignore it.
     *
     * @return list<InboxItem>
     */
    public function list(int $tenantId, int $profileId, bool $openOnly, int $limit, int $offset): array;

    /**
     * How many items the same predicate as {@see list()} matches: the real
     * total, never the size of a page.
     */
    public function count(int $tenantId, int $profileId, bool $openOnly): int;
}
