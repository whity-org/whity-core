<?php

declare(strict_types=1);

namespace Whity\OpenAPI;

/**
 * Route and component schemas for importing items and opening stock
 * (WHIT-1322, design section 34 §6). Kept apart from {@see CoreApiSchemas},
 * which merges these in.
 *
 * MCP: every route is a tool in the `core.inventory` toolset. Running an
 * import declares no undo of its own (its Undo is a person's, for 24 hours),
 * so an agent's run is a proposal its person approves.
 */
final class ImportApiSchemas
{
    /** @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}> */
    public static function routes(): array
    {
        $tags = ['inventory'];
        $base = '/api/inventory/imports/{kind:items|opening_stock|supplier_prices}';
        $kind = ['name' => 'kind', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'enum' => ['items', 'opening_stock', 'supplier_prices']], 'description' => '`items`, `opening_stock` or `supplier_prices`. Opening stock also needs `stock:adjust`.'];
        $import = self::object(['data' => SchemaBuilder::ref('InventoryImport')], ['data']);
        $notFound = [404 => self::error('Not found')];
        $refused = [409 => self::error('Not possible in the import\'s state'), 422 => self::error('A value that doesn\'t look right')];

        return [
            self::route('GET', $base, [
                'summary' => 'Recent imports of items, opening stock or supplier prices',
                'tags' => $tags,
                'parameters' => [$kind],
                'responses' => [200 => self::json('The last 20, newest first', self::object(['data' => ['type' => 'array', 'items' => SchemaBuilder::ref('InventoryImportListItem')]], ['data']))] + self::auth(),
            ]),
            self::route('POST', $base, [
                'summary' => 'Upload a spreadsheet of items, opening stock or supplier prices',
                'description' => 'CSV or Excel, as a `file` upload or as `text` or `content_base64` with `file_name`. Up to 5,000 rows. '
                    . 'Answers the import with each column, its examples and its suggested target. Nothing changes yet.',
                'tags' => $tags,
                'parameters' => [$kind],
                'request' => 'InventoryImportUploadRequest',
                'responses' => [201 => self::json('The import', $import)] + $refused + self::auth(),
            ]),
            self::route('GET', $base . '/{id:\d+}', [
                'summary' => 'An import of items, opening stock or supplier prices',
                'tags' => $tags,
                'parameters' => [$kind],
                'responses' => [200 => self::json('The import', $import)] + $notFound + self::auth(),
            ]),
            self::route('POST', $base . '/{id:\d+}/check', [
                'summary' => 'Work out what an import will do',
                'description' => 'With the columns matched, every row is created, updated (items by SKU, supplier prices by supplier and SKU) or skipped with a reason. '
                    . 'Items take a row per variant: the option columns (`option1`-`option3`) are named by their headings, and rows share an item by `group` or by name. Nothing changes.',
                'tags' => $tags,
                'parameters' => [$kind],
                'request' => 'InventoryImportCheckRequest',
                'responses' => [200 => self::json('The import with its summary', $import)] + $refused + $notFound + self::auth(),
            ]),
            self::route('POST', $base . '/{id:\d+}/run', [
                'summary' => 'Run a checked import',
                'description' => 'Works the plan out again against the data as it is now and applies it in one transaction. It can be undone for 24 hours.',
                'tags' => $tags,
                'parameters' => [$kind],
                'responses' => [200 => self::json('The import', $import)] + $refused + $notFound + self::auth(),
            ]),
            self::route('POST', $base . '/{id:\d+}/undo', [
                'summary' => 'Undo an import, within 24 hours',
                'description' => 'Items it made are archived and items it changed get their values back, variants included; supplier prices it made are removed and changed ones put back; '
                    . 'opening stock is reversed, unless that stock has moved since (409).',
                'tags' => $tags,
                'parameters' => [$kind],
                'responses' => [200 => self::json('The import', $import)] + $refused + $notFound + self::auth(),
            ]),
            self::route('GET', $base . '/{id:\d+}/skipped.csv', [
                'summary' => 'The skipped rows as a CSV, with their reasons',
                'tags' => $tags,
                'parameters' => [$kind],
                'responses' => [200 => ['description' => 'A CSV file', 'content' => ['text/csv' => ['schema' => ['type' => 'string']]]]] + $refused + $notFound + self::auth(),
            ]),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function components(): array
    {
        $counts = self::object(['create' => self::int(), 'update' => self::int(), 'skip' => self::int()], ['create', 'update', 'skip']);

        return [
            'InventoryImportUploadRequest' => self::object([
                'file_name' => self::str() + ['description' => 'The file\'s name, such as items.csv; .xlsx is read as Excel.'],
                'text' => self::str() + ['description' => 'The CSV itself.'],
                'content_base64' => self::str() + ['description' => 'The file, base64.'],
            ], []),
            'InventoryImportCheckRequest' => self::object([
                'mapping' => ['type' => 'object', 'additionalProperties' => ['type' => 'string', 'nullable' => true], 'description' => 'Column index => target key from `targets`, such as `{"0": "sku", "1": "name"}`. Items need `sku`; opening stock needs `sku` and `quantity`; supplier prices need `sku` and `price`.'],
                'location_id' => self::int(true) + ['description' => 'Opening stock: the place for rows that name none.'],
                'contact_id' => self::int(true) + ['description' => 'Supplier prices: the supplier for rows that name none.'],
            ], ['mapping']),
            'InventoryImportListItem' => self::object([
                'id' => self::int(), 'kind' => self::str(), 'file_name' => self::str(), 'status' => self::str(),
                'summary' => ['type' => 'object', 'nullable' => true] + $counts,
                'created_by_profile_id' => self::int(true), 'imported_at' => self::str(true), 'undo_until' => self::str(true),
                'can_undo' => self::bool(), 'created_at' => self::str(),
            ], ['id', 'kind', 'status']),
            'InventoryImport' => self::object([
                'id' => self::int(),
                'kind' => self::str() + ['enum' => ['items', 'opening_stock', 'supplier_prices']],
                'file_name' => self::str(),
                'status' => self::str() + ['enum' => ['uploaded', 'checked', 'imported', 'undone']],
                'row_count' => self::int(),
                'columns' => ['type' => 'array', 'items' => self::object([
                    'index' => self::int(), 'header' => self::str(), 'examples' => ['type' => 'array', 'items' => self::str()], 'empty' => self::bool(),
                    'suggestion' => ['type' => 'object', 'nullable' => true, 'properties' => ['target' => self::str(), 'confidence' => self::str()]],
                ], ['index', 'header'])],
                'targets' => ['type' => 'array', 'items' => self::object(['key' => self::str(), 'label' => self::str()], ['key', 'label'])],
                'options' => ['type' => 'object', 'nullable' => true],
                'summary' => ['type' => 'object', 'nullable' => true, 'description' => 'Counts, and up to 200 rows with what each does and why.'],
                'imported_at' => self::str(true),
                'undone_at' => self::str(true),
                'undo_until' => self::str(true),
                'can_undo' => self::bool(),
                'created_at' => self::str(),
            ], ['id', 'kind', 'file_name', 'status', 'row_count', 'columns', 'targets', 'can_undo']),
        ];
    }

    /**
     * @param array<string, mixed> $schema
     * @return array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}
     */
    private static function route(string $method, string $path, array $schema): array
    {
        return ['method' => $method, 'path' => $path, 'requiredRole' => null, 'requiredPermission' => 'inventory:write', 'schema' => $schema];
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private static function json(string $description, array $schema): array
    {
        return ['description' => $description, 'content' => ['application/json' => ['schema' => $schema]]];
    }

    /** @return array<string, mixed> */
    private static function error(string $description): array
    {
        return ['description' => $description, 'content' => ['application/json' => ['schema' => SchemaBuilder::ref('Error')]]];
    }

    /** @return array<int, array<string, mixed>> */
    private static function auth(): array
    {
        return [401 => self::error('Missing or invalid authentication'), 403 => self::error('Insufficient permissions')];
    }

    /**
     * @param array<string, mixed> $properties
     * @param list<string> $required
     * @return array<string, mixed>
     */
    private static function object(array $properties, array $required): array
    {
        $schema = ['type' => 'object', 'properties' => $properties];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /** @return array<string, mixed> */
    private static function int(bool $nullable = false): array
    {
        return $nullable ? ['type' => 'integer', 'nullable' => true] : ['type' => 'integer'];
    }

    /** @return array<string, mixed> */
    private static function str(bool $nullable = false): array
    {
        return $nullable ? ['type' => 'string', 'nullable' => true] : ['type' => 'string'];
    }

    /** @return array<string, mixed> */
    private static function bool(): array
    {
        return ['type' => 'boolean'];
    }
}
