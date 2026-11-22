<?php

declare(strict_types=1);

namespace Whity\Sdk\Inventory;

/**
 * One batch of stock moves, as the books see it (SDK 1.159, WHIT-1318, design
 * section 31 §6.3, section 32 §6): what core hands
 * {@see \Whity\Sdk\Contribute\WorkspaceAccountingExport::record()}.
 *
 * `sourceType` is what posted it (`receipt`, `adjustment`, `count`,
 * `transfer`, `opening`, `reversal`, or a plugin's `<plugin>.<kind>`), with
 * `sourceId`. `occurredOn` is the day it happened (`YYYY-MM-DD`). Values are
 * in `currency`, the workspace's, from core's moving average cost.
 */
final class StockPosting
{
    /** @param list<StockPostingMove> $moves */
    public function __construct(
        public readonly int $workspaceId,
        public readonly string $batchKey,
        public readonly string $sourceType,
        public readonly ?int $sourceId,
        public readonly string $occurredOn,
        public readonly ?int $actingProfileId,
        public readonly string $currency,
        public readonly array $moves,
    ) {
    }
}
