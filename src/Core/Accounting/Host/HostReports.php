<?php

declare(strict_types=1);

namespace Whity\Core\Accounting\Host;

use Whity\Core\Accounting\AccountingReports;
use Whity\Sdk\Accounting\Reports;
use Whity\Sdk\Accounting\TrialBalance;

/** Reports from the ledger for plugins, read-only (SDK 1.132, WHIT-1318). */
final class HostReports implements Reports
{
    public function __construct(
        private readonly AccountingReports $reports,
        private readonly HostAccountingGuard $guard,
    ) {
    }

    public function trialBalance(string $from, string $to, ?int $ouId = null): TrialBalance
    {
        $tenantId = $this->guard->workspace();

        return TrialBalance::fromArray(HostAccountingGuard::run(fn (): array => $this->reports->trialBalance($tenantId, $from, $to, $ouId)));
    }
}
