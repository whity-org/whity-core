<?php

declare(strict_types=1);

namespace Whity\Core\Inventory\Host;

use Whity\Core\Inventory\LotService;
use Whity\Sdk\Inventory\Lot;
use Whity\Sdk\Inventory\Lots;

/** Core's batches and serial numbers for plugins (SDK 1.137, WHIT-1235), in the workspace the call runs in. Reads only. */
final class HostLots implements Lots
{
    public function __construct(
        private readonly LotService $lots,
        private readonly HostInventoryGuard $guard,
    ) {
    }

    public function get(int $lotId): Lot
    {
        $tenantId = $this->guard->workspace();

        return Lot::fromArray(HostInventoryGuard::run(fn (): array => $this->lots->get($tenantId, $lotId)));
    }

    public function findByCode(int $variantId, string $code): ?Lot
    {
        $tenantId = $this->guard->workspace();
        $row = $this->lots->findByCode($tenantId, $variantId, $code);

        return $row === null ? null : Lot::fromArray($row);
    }

    public function expiring(?int $days = null): array
    {
        $tenantId = $this->guard->workspace();
        if ($days !== null && ($days < 0 || $days > 3650)) {
            return HostInventoryGuard::run(static fn (): array => throw new \Whity\Core\Inventory\InventoryRejectedException('days is 0 to 3650', 422));
        }

        return array_map(static fn (array $row): Lot => Lot::fromArray($row), $this->lots->expiring($tenantId, $days));
    }
}
