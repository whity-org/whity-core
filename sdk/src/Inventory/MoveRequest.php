<?php

declare(strict_types=1);

namespace Whity\Sdk\Inventory;

/**
 * Stock moves to post together, in one transaction, in the workspace the call
 * runs in (SDK 1.127, WHIT-1232).
 *
 * `sourceType` says what caused them, in the plugin's own words: the host
 * prefixes it with the plugin's name (`sale` from the `pos` plugin is stored
 * as `pos.sale`), so a plugin can never post as core or as another plugin.
 * `sourceId` is the plugin's own record. `reasonCode` is for adjustments
 * (`damaged`, `expired`). `occurredAt` is when it really happened (ISO 8601),
 * now when null; it may be in the past.
 *
 * `idempotencyKey`, when set, makes the post happen once: posting the same
 * key again returns the first post's moves and changes nothing. Use it for
 * anything that can be retried (a queue, a scanner's offline list).
 *
 * @see Stock::move()
 */
final class MoveRequest
{
    /** @param list<MoveLine> $lines */
    public function __construct(
        public readonly int $actingProfileId,
        public readonly array $lines,
        public readonly string $sourceType,
        public readonly ?int $sourceId = null,
        public readonly string $reasonCode = '',
        public readonly string $note = '',
        public readonly ?string $occurredAt = null,
        public readonly ?string $idempotencyKey = null,
    ) {
    }
}
