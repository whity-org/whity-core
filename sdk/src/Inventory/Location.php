<?php

declare(strict_types=1);

namespace Whity\Sdk\Inventory;

/**
 * A place stock is kept (SDK 1.127, WHIT-1232, register IV.7): a warehouse,
 * shop or van (`kind` `warehouse`), a shelf or area inside one (`area`), or a
 * virtual place at the other end of stock coming in or going out (`virtual`,
 * with `virtualRole` one of {@see self::VIRTUAL_ROLES}). Virtual places never
 * hold stock.
 */
final class Location
{
    public const VIRTUAL_ROLES = ['supplier', 'customer', 'loss', 'found', 'production', 'consumed', 'opening', 'transit'];

    /** @param array<string, string> $names */
    public function __construct(
        public readonly int $id,
        public readonly string $kind,
        public readonly string $name,
        public readonly array $names = [],
        public readonly ?string $virtualRole = null,
        public readonly ?int $parentId = null,
        public readonly string $code = '',
        public readonly ?int $ouId = null,
        public readonly bool $active = true,
    ) {
    }

    public function isVirtual(): bool
    {
        return $this->kind === 'virtual';
    }

    /** @param array<string, mixed> $data the API's `InventoryLocation` */
    public static function fromArray(array $data): self
    {
        return new self(
            id: is_int($data['id'] ?? null) ? $data['id'] : 0,
            kind: is_string($data['kind'] ?? null) ? $data['kind'] : 'warehouse',
            name: is_string($data['name'] ?? null) ? $data['name'] : '',
            names: Item::stringMap($data['names'] ?? []),
            virtualRole: is_string($data['virtual_role'] ?? null) ? $data['virtual_role'] : null,
            parentId: is_int($data['parent_id'] ?? null) ? $data['parent_id'] : null,
            code: is_string($data['code'] ?? null) ? $data['code'] : '',
            ouId: is_int($data['ou_id'] ?? null) ? $data['ou_id'] : null,
            active: (bool) ($data['is_active'] ?? true),
        );
    }
}
