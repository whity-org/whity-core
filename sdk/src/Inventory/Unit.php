<?php

declare(strict_types=1);

namespace Whity\Sdk\Inventory;

/**
 * A unit of measure in a workspace (SDK 1.126, WHIT-1232): piece, kg, box.
 * `toBaseNum / toBaseDen` is how many of its dimension's base unit one of it
 * is (g: 1 / 1000 of a kg).
 */
final class Unit
{
    /** @param array<string, string> $names */
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly string $name,
        public readonly array $names,
        public readonly string $dimension,
        public readonly int $decimals,
        public readonly int $toBaseNum = 1,
        public readonly int $toBaseDen = 1,
        public readonly bool $archived = false,
    ) {
    }

    /** @param array<string, mixed> $data the API's `InventoryUnit` */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) ($data['id'] ?? 0),
            code: (string) ($data['code'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            names: Item::stringMap($data['names'] ?? []),
            dimension: (string) ($data['dimension'] ?? 'count'),
            decimals: (int) ($data['decimals'] ?? 0),
            toBaseNum: (int) ($data['to_base_num'] ?? 1),
            toBaseDen: (int) ($data['to_base_den'] ?? 1),
            archived: (bool) ($data['archived'] ?? false),
        );
    }
}
