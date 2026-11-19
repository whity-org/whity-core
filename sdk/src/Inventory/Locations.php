<?php

declare(strict_types=1);

namespace Whity\Sdk\Inventory;

/**
 * Core's places, for a plugin (SDK 1.127, WHIT-1232, register IV.7).
 *
 * A host service: `\Whity\app(\Whity\Sdk\Inventory\Locations::class)`, null on a
 * host older than 1.127. It works in the workspace the call runs in, like
 * {@see Items}. Reads are not permission-checked; `create` acts as a person
 * who holds `inventory:write`, like `POST /api/inventory/locations`.
 *
 * Virtual places (supplier, customer, loss, found, production, consumed,
 * opening, transit) exist in every workspace; `virtual()` returns one, making
 * the workspace's set on first use.
 */
interface Locations
{
    /**
     * Real places, by name; with `$includeVirtual` the virtual ones too.
     *
     * @return list<Location>
     * @throws InventoryRejected
     */
    public function list(bool $includeVirtual = false): array;

    /** @throws InventoryRejected */
    public function get(int $locationId): ?Location;

    /**
     * `$role` is one of {@see Location::VIRTUAL_ROLES}.
     *
     * @throws InventoryRejected for an unknown role
     */
    public function virtual(string $role): Location;

    /**
     * `$data`: `name` (text or `{en, ar}`), `kind` (`warehouse` or `area`),
     * `parent_id`, `code`, `ou_id`, `address`.
     *
     * @param array<string, mixed> $data
     * @throws InventoryRejected
     */
    public function create(array $data, int $actingProfileId): Location;
}
