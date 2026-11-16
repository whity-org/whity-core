<?php

declare(strict_types=1);

namespace Whity\Core\CustomFields;

/**
 * The record types fields can be added to (WHIT-954): core's own, and those
 * plugins register. The extension point a paid plugin uses to give its own
 * records custom fields without patching core (Front Office: items, stock).
 *
 * Built once at boot and only read after; worker-safe.
 */
final class CustomFieldRecordTypes
{
    /** @var array<string, CustomFieldRecordType> */
    private array $types = [];

    /** Core's own type. */
    public function register(CustomFieldRecordType $type): void
    {
        if (isset($this->types[$type->key])) {
            throw new \LogicException('Record type ' . $type->key . ' is registered twice');
        }
        $this->types[$type->key] = $type;
    }

    /** A plugin's type; its key must start with the plugin's own name and a dot. */
    public function registerOwned(string $owner, CustomFieldRecordType $type): void
    {
        if (!str_starts_with($type->key, strtolower($owner) . '.')) {
            throw new \LogicException('A plugin\'s record type must start with "' . strtolower($owner) . '."');
        }
        $this->register($type);
    }

    public function get(string $key): ?CustomFieldRecordType
    {
        return $this->types[$key] ?? null;
    }

    /** @return list<CustomFieldRecordType> */
    public function all(): array
    {
        return array_values($this->types);
    }
}
