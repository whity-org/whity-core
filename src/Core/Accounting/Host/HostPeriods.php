<?php

declare(strict_types=1);

namespace Whity\Core\Accounting\Host;

use Whity\Core\Accounting\AccountingWorkspace;
use Whity\Core\Accounting\Periods as CorePeriods;
use Whity\Sdk\Accounting\Period;
use Whity\Sdk\Accounting\Periods;

/** The months of the books for plugins, read-only (SDK 1.131, WHIT-1318). */
final class HostPeriods implements Periods
{
    public function __construct(
        private readonly CorePeriods $periods,
        private readonly AccountingWorkspace $workspace,
        private readonly HostAccountingGuard $guard,
    ) {
    }

    public function periodFor(string $date): Period
    {
        $tenantId = $this->guard->workspace();

        return Period::fromArray(HostAccountingGuard::run(fn (): array => $this->periods->periodFor($tenantId, $date)));
    }

    public function isOpen(string $date): bool
    {
        $tenantId = $this->guard->workspace();

        return HostAccountingGuard::run(function () use ($tenantId, $date): bool {
            CorePeriods::date($date);

            return $this->periods->isOpen($tenantId, $date);
        });
    }

    public function lockDate(): ?string
    {
        $tenantId = $this->guard->workspace();

        return $this->workspace->books($tenantId)['lock_date'];
    }
}
