<?php

declare(strict_types=1);

namespace Whity\Sdk\Accounting;

/**
 * Core's double-entry ledger, for a plugin (SDK 1.132, WHIT-1318, register
 * AC.3, AC.4).
 *
 * Reach it through the plugin context: `$ctx->accounting()->ledger()`. That
 * one is bound to your plugin, so the entries it posts are stored under your
 * plugin's name and no plugin can post as another. The `Ledger` in the
 * container (`\Whity\app(Ledger::class)`) reads only: its `post()` and
 * `reverse()` refuse with {@see AccountingRejected} reason `use_plugin_context`.
 *
 * It works in the workspace the call runs in; with none, it throws
 * {@see AccountingRejected} `no_workspace`.
 *
 * THE LEDGER. An entry is two or more lines whose debits equal their credits.
 * Entries are never changed: to undo one, call {@see self::reverse()}, which
 * posts its mirror. Amounts are whole minor units of the books' base currency.
 *
 * WRITES act as `EntryRequest::$actingProfileId`, who must be in the
 * workspace and hold `accounting:post`, and run the `accounting.entry.posting`
 * hook, where another plugin may refuse them. A date in a closed month is
 * refused with reason `period_closed`. After posting, `accounting.entry.posted`
 * fires, and `accounting.entry.posted.async` once the transaction has
 * committed.
 */
interface Ledger
{
    /**
     * Posts the entry, all its lines or none, under the bound plugin's name.
     *
     * @return Entry the entry posted (or, for a repeated idempotency key, posted the first time)
     * @throws AccountingRejected
     */
    public function post(EntryRequest $request): Entry;

    /**
     * Posts the mirror of an entry, linked to it. An entry is reversed once.
     * Dated `$date` when given, else the original's date while its month is
     * open, else today.
     *
     * @throws AccountingRejected
     */
    public function reverse(int $entryId, int $actingProfileId, ?string $note = null, ?string $date = null): Entry;

    /** @throws AccountingRejected `not_found` */
    public function entry(int $entryId): Entry;

    /**
     * Entries, newest first, without their lines. `before_id` pages back.
     *
     * @param array{journal_id?: int, account_id?: int, source_type?: string, source_id?: int, from?: string, to?: string, before_id?: int} $filter
     * @return list<Entry>
     * @throws AccountingRejected
     */
    public function entries(array $filter = [], int $limit = 50): array;

    /**
     * An account's balance at the end of a day: debits minus credits, in
     * minor units. `ouId` limits it to one branch.
     *
     * @throws AccountingRejected
     */
    public function balance(int $accountId, string $asOf, ?int $ouId = null): int;
}
