<?php

declare(strict_types=1);

namespace Whity\Sdk\Inbox;

/**
 * An inbox source that can start reading right after a position (SDK 1.97).
 *
 * Optional. The merged inbox pages by position, not by page number, so that
 * adding items between two requests never skips or repeats one (#867 one level
 * up). A source that only implements {@see InboxSource::list()} is read from
 * its start and filtered by the host, which costs more the deeper a person
 * scrolls. Implement this when the source can turn the cursor into a `WHERE`
 * clause.
 */
interface KeysetInboxSource extends InboxSource
{
    /**
     * Up to `$limit` items that come strictly after `$after` in
     * {@see InboxOrder}, in that order. A null cursor means from the start.
     *
     * The cursor may come from another source: compare with
     * {@see InboxOrder::isAfter()} rather than assuming the ids are yours.
     *
     * @return list<InboxItem>
     */
    public function listAfter(int $tenantId, int $profileId, bool $openOnly, ?InboxCursor $after, int $limit): array;
}
