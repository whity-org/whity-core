<?php

declare(strict_types=1);

namespace Whity\Core\Accounting\Host;

use Whity\Core\Accounting\Taxes as CoreTaxes;
use Whity\Sdk\Accounting\AccountingRejected;
use Whity\Sdk\Accounting\TaxCode;
use Whity\Sdk\Accounting\Taxes;

/**
 * Core's tax codes for plugins (SDK 1.155, WHIT-1318), read-only, in the
 * workspace the call runs in.
 */
final class HostTaxes implements Taxes
{
    public function __construct(
        private readonly CoreTaxes $taxes,
        private readonly HostAccountingGuard $guard,
    ) {
    }

    public function codes(?string $direction = null): array
    {
        $tenantId = $this->guard->workspace();
        $rows = HostAccountingGuard::run(fn (): array => $this->taxes->list($tenantId, $direction === null ? [] : ['direction' => $direction]));

        return array_map(static fn (array $row): TaxCode => TaxCode::fromArray($row), $rows);
    }

    public function codeFor(string $taxCategory, string $direction, string $date): ?TaxCode
    {
        if (!in_array($direction, CoreTaxes::DIRECTIONS, true)) {
            throw new AccountingRejected('direction is output or input', 422, 'direction', AccountingRejected::INVALID);
        }
        $tenantId = $this->guard->workspace();
        $row = HostAccountingGuard::run(fn (): ?array => $this->taxes->codeFor($tenantId, $taxCategory, $direction, $date));

        return $row === null ? null : TaxCode::fromArray($row);
    }

    public function compute(int $amountMinor, int $rateBp, bool $inclusive = false): array
    {
        if ($amountMinor < 0 || $rateBp < 0 || $rateBp > 10000) {
            throw new AccountingRejected('amountMinor is 0 or more and rateBp 0 to 10000', 422, 'rateBp', AccountingRejected::INVALID);
        }

        return CoreTaxes::split($amountMinor, $rateBp, $inclusive);
    }
}
