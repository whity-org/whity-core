<?php

declare(strict_types=1);

namespace Whity\Core\Notification;

/**
 * The Inbox's tabs (M.43, merge map group 2): Waiting for me, Mentions,
 * Updates. Waiting for me is the waiting list (`/api/me/waiting`). The other
 * two are the person's notices, split by kind:
 *
 *  - **Mentions**: someone named you, in a chat or a document comment;
 *  - **Updates**: everything else, except the notices that only say something
 *    is waiting for you. Those are already in Waiting for me with their
 *    buttons, so showing them twice would count them twice.
 */
final class NotificationTabs
{
    public const MENTIONS = 'mentions';
    public const UPDATES = 'updates';
    public const TABS = [self::MENTIONS, self::UPDATES];

    /** Someone named you. */
    public const MENTION_TYPES = ['chat.mention', 'document.comment.mention'];

    /** Notices whose item is already in Waiting for me. */
    public const WAITING_TYPES = ['document.routing.awaiting', 'agent.needs_approval'];

    /**
     * The kinds a tab shows: `[only, except]`, each null when it doesn't apply.
     *
     * @return array{0: list<string>|null, 1: list<string>|null}
     */
    public static function filter(string $tab): array
    {
        return match ($tab) {
            self::MENTIONS => [self::MENTION_TYPES, null],
            self::UPDATES => [null, [...self::MENTION_TYPES, ...self::WAITING_TYPES]],
            default => throw new \InvalidArgumentException('Unknown tab: ' . $tab),
        };
    }
}
