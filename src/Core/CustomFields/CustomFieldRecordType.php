<?php

declare(strict_types=1);

namespace Whity\Core\CustomFields;

/**
 * A kind of record the workspace can add its own fields to (WHIT-954).
 *
 * Core registers `contact`. A plugin registers its own types the same way
 * ({@see CustomFieldRecordTypes::register()}); its key must start with its
 * own name and a dot (`front-office.item`), so it can never take core's.
 */
final class CustomFieldRecordType
{
    /**
     * @param callable(int, int): bool $exists whether a record with this id is in the tenant
     * @param list<string> $reservedKeys
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $labelAr,
        public readonly string $readPermission,
        public readonly string $writePermission,
        private readonly mixed $exists,
        /** @var list<string> Keys the record already uses, which a field may not take. */
        public readonly array $reservedKeys = [],
    ) {
        if (preg_match('/^[a-z][a-z0-9_-]*(\.[a-z][a-z0-9_]*)?$/', $key) !== 1) {
            throw new \InvalidArgumentException('A record type key is lower-case letters, digits, _ and -, with at most one dot');
        }
    }

    public function exists(int $tenantId, int $recordId): bool
    {
        return (bool) ($this->exists)($tenantId, $recordId);
    }

    /** @return array{key: string, label: string, label_ar: string} */
    public function toApi(): array
    {
        return ['key' => $this->key, 'label' => $this->label, 'label_ar' => $this->labelAr];
    }
}
