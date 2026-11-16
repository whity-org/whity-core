<?php

declare(strict_types=1);

namespace Whity\Core\Mention;

/**
 * One way to name someone (merge map N4, WHIT-1120), shared by chat and
 * document comments.
 *
 * A mention is `{type: person|agent|everyone, id}` (UC.10). Document comments
 * used to send bare profile ids, and still may: a bare int reads as a person.
 * Who a mention may name is the caller's to decide: a chat's members, or the
 * people who can read the document. This only reads the shape, removes
 * duplicates and caps the list, so the two can't drift apart.
 *
 * A mention reaches the person as a notice in the Inbox's Mentions tab
 * (`NotificationTabs::MENTION_TYPES`), with push to their devices.
 */
final class Mentions
{
    public const PERSON = 'person';
    public const AGENT = 'agent';
    public const EVERYONE = 'everyone';

    /**
     * The mentions in a request, in order, without duplicates, at most $max.
     * Null when the value isn't a list of mentions at all.
     *
     * @return list<array{type: string, id: int}>|null
     */
    public static function parse(mixed $raw, int $max): ?array
    {
        if ($raw === null) {
            return [];
        }
        if (!is_array($raw) || !array_is_list($raw)) {
            return null;
        }
        $kept = [];
        foreach ($raw as $m) {
            if (is_int($m)) {
                $m = ['type' => self::PERSON, 'id' => $m];
            }
            if (!is_array($m)) {
                return null;
            }
            $type = $m['type'] ?? null;
            $id = $m['id'] ?? ($type === self::EVERYONE ? 0 : null);
            if (!in_array($type, [self::PERSON, self::AGENT, self::EVERYONE], true) || !is_int($id) || ($type !== self::EVERYONE && $id <= 0)) {
                return null;
            }
            $kept[$type . ':' . $id] = ['type' => $type, 'id' => $type === self::EVERYONE ? 0 : $id];
            if (count($kept) > $max) {
                return null;
            }
        }

        return array_values($kept);
    }

    /**
     * The people named, as profile ids.
     *
     * @param list<array{type: string, id: int}> $mentions
     * @return list<int>
     */
    public static function people(array $mentions): array
    {
        return array_values(array_map(
            static fn (array $m): int => $m['id'],
            array_filter($mentions, static fn (array $m): bool => $m['type'] === self::PERSON),
        ));
    }
}
