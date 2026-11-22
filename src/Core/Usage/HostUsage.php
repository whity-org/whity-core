<?php

declare(strict_types=1);

namespace Whity\Core\Usage;

use Whity\Sdk\Usage\Usage;
use Whity\Sdk\Usage\UsageMeter;

/** The host's {@see Usage}: the same report Billing > Usage shows (section 33 §5). */
final class HostUsage implements Usage
{
    public function __construct(private readonly UsageReport $report)
    {
    }

    public function meters(int $tenantId, ?string $period = null): array
    {
        if ($period !== null && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
            throw new \InvalidArgumentException('A month is written YYYY-MM.');
        }
        if ($tenantId <= 0) {
            return [];
        }

        $out = [];
        foreach ($this->report->forTenant($tenantId, $period)['meters'] as $row) {
            $out[] = UsageMeter::fromArray($row);
        }

        return $out;
    }

    public function meter(int $tenantId, string $meter): ?UsageMeter
    {
        foreach ($this->meters($tenantId) as $row) {
            if ($row->meter === $meter) {
                return $row;
            }
        }

        return null;
    }
}
