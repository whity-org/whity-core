<?php

declare(strict_types=1);

namespace Whity\Sdk\Documents;

/**
 * Many documents from one template, issued in the background (SDK 1.83,
 * WHIT-997). Pass it to {@see Documents::issueMany()}.
 *
 * - `batchKey`: your own name for this batch, e.g. `payroll:2026-11`. Sending
 *   the same key again returns the same batch instead of starting a second
 *   one, so a retried request can't issue twice. The same key with different
 *   items is refused (`batch_key_conflict`).
 * - Each item is one document. An item with no `originKey` gets
 *   `batch:<batchKey>:<index>`, so a batch that is interrupted and resumed
 *   never issues an item twice.
 * - At most {@see MAX_ITEMS} items, each with at most {@see MAX_ITEM_BYTES} of
 *   values, and {@see MAX_BYTES} for the whole batch.
 */
final class BulkIssueRequest
{
    public const MAX_ITEMS = 500;
    /** The values of one item, as JSON. */
    public const MAX_ITEM_BYTES = 16384;
    /** Every item's record, values and title together, as JSON. */
    public const MAX_BYTES = 1048576;

    /** @var list<BulkIssueItem> */
    public readonly array $items;

    /** @param array<mixed> $items BulkIssueItems, checked here since a plugin may build the list from anything */
    public function __construct(
        public readonly TemplateRef $template,
        array $items,
        public readonly string $batchKey,
        public readonly ?bool $render = null,
    ) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,99}$/', $batchKey) !== 1) {
            throw new \InvalidArgumentException('A batch key is 1 to 100 letters, digits and . _ : / -, starting with a letter or digit');
        }
        if ($items === [] || !array_is_list($items)) {
            throw new \InvalidArgumentException('A batch has at least one item');
        }
        if (count($items) > self::MAX_ITEMS) {
            throw new \InvalidArgumentException('A batch has at most ' . self::MAX_ITEMS . ' items; split it into several batches');
        }
        $bytes = 0;
        $checked = [];
        foreach ($items as $i => $item) {
            if (!$item instanceof BulkIssueItem) {
                throw new \InvalidArgumentException('Each item is a BulkIssueItem');
            }
            $checked[] = $item;
            $values = strlen((string) json_encode($item->values, JSON_UNESCAPED_UNICODE));
            if ($values > self::MAX_ITEM_BYTES) {
                throw new \InvalidArgumentException("Item {$i}'s values are over " . self::MAX_ITEM_BYTES . ' bytes');
            }
            $bytes += $values + strlen((string) $item->record) + strlen((string) $item->title);
        }
        if ($bytes > self::MAX_BYTES) {
            throw new \InvalidArgumentException('A batch is at most ' . self::MAX_BYTES . ' bytes of records, values and titles; split it');
        }
        $this->items = $checked;
    }
}
