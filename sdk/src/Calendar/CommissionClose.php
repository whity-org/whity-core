<?php

declare(strict_types=1);

namespace Whity\Sdk\Calendar;

/**
 * A closed commission period, frozen (SDK 1.141, WHIT-1334, design section 35
 * §4.3): from and to are the workspace's dates, both included; each line is
 * one member of staff in one currency.
 */
final class CommissionClose
{
    /**
     * @param list<CommissionCloseLine> $lines
     */
    public function __construct(
        public readonly int $id,
        public readonly int $workspaceId,
        public readonly string $from,
        public readonly string $to,
        public readonly array $lines,
        public readonly ?int $closedByProfileId,
    ) {
    }
}
