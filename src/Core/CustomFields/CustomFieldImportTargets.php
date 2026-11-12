<?php

declare(strict_types=1);

namespace Whity\Core\CustomFields;

use Whity\Core\Contacts\Import\ImportTargets;

/**
 * The workspace's own fields as places a spreadsheet column can go
 * (WHIT-954, `TplImport` "Your field"). Targets are `field:<key>`.
 */
final class CustomFieldImportTargets implements ImportTargets
{
    public const PREFIX = 'field:';

    /**
     * Definitions per tenant, kept for a few seconds so an import of thousands
     * of rows reads them once. Short-lived on purpose: this object lives as
     * long as the worker, and a field added a moment ago must be offered.
     *
     * @var array<int, array{at: float, rows: list<array<string, mixed>>}>
     */
    private array $cache = [];

    public function __construct(
        private readonly CustomFieldService $fields,
        private readonly CustomFieldRepository $repository,
        private readonly string $recordType,
    ) {
    }

    public function targets(int $tenantId): array
    {
        $out = [];
        foreach ($this->definitions($tenantId) as $def) {
            $out[self::PREFIX . $def['field_key']] = [
                'label' => CustomFieldService::labelText($def),
                'names' => array_values(array_filter([
                    CustomFieldService::labelText($def, 'en'),
                    CustomFieldService::labelText($def, 'ar'),
                    str_replace('_', ' ', (string) $def['field_key']),
                ])),
            ];
        }

        return $out;
    }

    public function check(int $tenantId, string $target, string $raw): array
    {
        $def = $this->definition($tenantId, $target);
        if ($def === null) {
            return ['error' => 'That field is no longer there'];
        }
        $checked = $this->fields->check($tenantId, $def, $raw);

        return isset($checked['error']) ? ['error' => $checked['error']] : ['value' => $checked['value']];
    }

    public function current(int $tenantId, int $contactId, array $targets): array
    {
        $values = $this->repository->values($tenantId, $this->recordType, [$contactId])[$contactId] ?? [];
        $out = [];
        foreach ($targets as $target) {
            $out[$target] = $values[substr($target, strlen(self::PREFIX))] ?? null;
        }

        return $out;
    }

    public function write(int $tenantId, int $contactId, array $values): void
    {
        $byKey = [];
        foreach ($values as $target => $value) {
            if (str_starts_with($target, self::PREFIX)) {
                $byKey[substr($target, strlen(self::PREFIX))] = $value;
            }
        }
        $this->fields->writeChecked($tenantId, $this->recordType, $contactId, $byKey);
    }

    /** @return array<string, mixed>|null */
    private function definition(int $tenantId, string $target): ?array
    {
        foreach ($this->definitions($tenantId) as $def) {
            if (self::PREFIX . $def['field_key'] === $target) {
                return $def;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private function definitions(int $tenantId): array
    {
        $hit = $this->cache[$tenantId] ?? null;
        if ($hit !== null && microtime(true) - $hit['at'] < 5.0) {
            return $hit['rows'];
        }
        $rows = $this->repository->definitions($tenantId, $this->recordType);
        $this->cache = [$tenantId => ['at' => microtime(true), 'rows' => $rows]];

        return $rows;
    }
}
