<?php

declare(strict_types=1);

namespace Whity\Core\Inventory\Host;

use Whity\Core\Inventory\InventoryRejectedException;
use Whity\Core\Inventory\LocationService;
use Whity\Core\RBAC\CorePermissions;
use Whity\Sdk\Inventory\Location;
use Whity\Sdk\Inventory\Locations;

/** Core's places for plugins (SDK 1.127, WHIT-1232), in the workspace the call runs in. */
final class HostLocations implements Locations
{
    public function __construct(
        private readonly LocationService $locations,
        private readonly HostInventoryGuard $guard,
    ) {
    }

    public function list(bool $includeVirtual = false): array
    {
        $tenantId = $this->guard->workspace();

        return array_map(static fn (array $row): Location => Location::fromArray($row), $this->locations->list($tenantId, $includeVirtual));
    }

    public function get(int $locationId): ?Location
    {
        $tenantId = $this->guard->workspace();
        if ($locationId <= 0) {
            return null;
        }
        try {
            return Location::fromArray($this->locations->show($tenantId, $locationId));
        } catch (InventoryRejectedException $e) {
            if ($e->status === 404) {
                return null;
            }

            return HostInventoryGuard::run(static fn (): Location => throw $e);
        }
    }

    public function virtual(string $role): Location
    {
        $tenantId = $this->guard->workspace();

        return Location::fromArray(HostInventoryGuard::run(fn (): array => $this->locations->virtual($tenantId, $role)));
    }

    public function create(array $data, int $actingProfileId): Location
    {
        $tenantId = $this->guard->workspace();
        $this->guard->writer($tenantId, $actingProfileId, CorePermissions::INVENTORY_WRITE);

        return Location::fromArray(HostInventoryGuard::run(fn (): array => $this->locations->create($tenantId, $data)));
    }
}
