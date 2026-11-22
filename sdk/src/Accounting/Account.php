<?php

declare(strict_types=1);

namespace Whity\Sdk\Accounting;

/**
 * One account in the workspace's chart (SDK 1.154, WHIT-1318, register AC.8).
 *
 * `type` is `asset`, `liability`, `equity`, `income` or `expense`. A heading
 * (`isGroup`) holds no lines. `role` names what the books use it for
 * (`receivable`, `sales_tax_output`, `inventory`...), null for an ordinary
 * account. `balanceMinor` is debits minus credits to date, in the books' base
 * currency's minor units; a heading carries its children's.
 */
final class Account
{
    /** @param array<string, string> $names every language, `{en, ar}` */
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly string $name,
        public readonly array $names,
        public readonly string $type,
        public readonly string $subtype,
        public readonly bool $isGroup,
        public readonly ?int $parentId,
        public readonly ?string $role,
        public readonly bool $allowsManual,
        public readonly int $balanceMinor,
        public readonly bool $archived,
        public readonly ?string $ownerPlugin = null,
    ) {
    }

    /** @param array<string, mixed> $data the API's `AccountingAccount` */
    public static function fromArray(array $data): self
    {
        $int = static fn (string $key): ?int => is_int($data[$key] ?? null) ? $data[$key] : null;
        $text = static fn (string $key): string => is_scalar($data[$key] ?? null) ? (string) $data[$key] : '';
        $names = [];
        foreach (is_array($data['names'] ?? null) ? $data['names'] : [] as $lang => $value) {
            if (is_string($lang) && is_string($value)) {
                $names[$lang] = $value;
            }
        }

        return new self(
            id: $int('id') ?? 0,
            code: $text('code'),
            name: $text('name'),
            names: $names,
            type: $text('type'),
            subtype: $text('subtype'),
            isGroup: ($data['is_group'] ?? false) === true,
            parentId: $int('parent_id'),
            role: is_string($data['role'] ?? null) ? $data['role'] : null,
            allowsManual: ($data['allows_manual'] ?? true) === true,
            balanceMinor: $int('balance_minor') ?? 0,
            archived: ($data['archived_at'] ?? null) !== null,
            ownerPlugin: is_string($data['owner_plugin'] ?? null) ? $data['owner_plugin'] : null,
        );
    }
}
