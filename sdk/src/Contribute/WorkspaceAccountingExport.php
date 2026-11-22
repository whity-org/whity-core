<?php

declare(strict_types=1);

namespace Whity\Sdk\Contribute;

use Whity\Sdk\Inventory\StockPosting;

/**
 * Turns stock moves into journal lines for a set of books (SDK 1.159,
 * WHIT-1318, design section 31 §6.3, section 32 §6, register IV.22, AC.18).
 *
 * Core gives the moves and what they were worth (its moving average cost,
 * IV.18); the export owns the accounts and the posting. Core accounting is the
 * default: it posts each batch to the workspace's own books. A plugin that
 * exports to an outside system (Odoo, QuickBooks, a local package) implements
 * the same interface. Core binds at most one, through the host's service
 * container, keyed by this interface.
 *
 * {@see record()} runs inside the stock posting's transaction, after the moves
 * are written: throw to refuse the batch, and the stock move is refused with
 * it (a closed month in the books is a closed month for stock). Answer a
 * reference people can read (the journal entry's number), or null when
 * nothing needed posting. The workspace comes from the posting.
 */
interface WorkspaceAccountingExport
{
    public function record(StockPosting $posting): ?string;
}
