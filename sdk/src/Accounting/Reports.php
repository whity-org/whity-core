<?php

declare(strict_types=1);

namespace Whity\Sdk\Accounting;

/**
 * Reports read from the ledger, for a plugin (SDK 1.132, WHIT-1318, register
 * AC.23). The trial balance arrives first; profit and loss, the balance sheet
 * and the aged reports arrive with phase A5.
 */
interface Reports
{
    /**
     * Every account's opening balance, debits, credits and closing balance
     * for a date range, `YYYY-MM-DD` to `YYYY-MM-DD`. `ouId` limits it to one
     * branch.
     *
     * @throws AccountingRejected
     */
    public function trialBalance(string $from, string $to, ?int $ouId = null): TrialBalance;
}
