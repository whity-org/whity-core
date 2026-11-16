<?php

declare(strict_types=1);

namespace Whity\Sdk\Inbox;

/**
 * A position in the merged inbox (SDK 1.97): the last item a page ended on.
 *
 * It carries the item's whole sort key, so the next page can start right after
 * it in any source, whatever was added in between. Clients treat the encoded
 * form as opaque.
 */
final class InboxCursor
{
    /**
     * @param bool   $urgent    Whether the item was urgent.
     * @param string $timestamp When it arrived, as {@see InboxOrder::epochMicros()} reads it.
     * @param string $source    The canonical key of the source it came from.
     * @param string $id        Its id within that source.
     */
    public function __construct(
        public readonly bool $urgent,
        public readonly string $timestamp,
        public readonly string $source,
        public readonly string $id,
    ) {
    }

    public static function fromItem(string $source, InboxItem $item): self
    {
        return new self($item->urgent, $item->timestamp, $source, $item->id);
    }

    /** URL-safe and opaque to clients. */
    public function encode(): string
    {
        $json = json_encode([$this->urgent ? 1 : 0, $this->timestamp, $this->source, $this->id], JSON_UNESCAPED_SLASHES);

        return rtrim(strtr(base64_encode((string) $json), '+/', '-_'), '=');
    }

    /** Null for anything that is not a cursor this class encoded. */
    public static function decode(string $encoded): ?self
    {
        if ($encoded === '' || strlen($encoded) > 1024) {
            return null;
        }
        $raw = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($raw === false) {
            return null;
        }
        $parts = json_decode($raw, true);
        if (
            !is_array($parts) || count($parts) !== 4
            || !in_array($parts[0] ?? null, [0, 1], true)
            || !is_string($parts[1]) || !is_string($parts[2]) || !is_string($parts[3])
            || InboxOrder::epochMicros($parts[1]) === null
        ) {
            return null;
        }

        return new self($parts[0] === 1, $parts[1], $parts[2], $parts[3]);
    }
}
