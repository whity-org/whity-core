<?php

declare(strict_types=1);

namespace Whity\OpenAPI;

/**
 * Route and component schemas for inventory (WHIT-1232, design section 31).
 * Kept apart from {@see CoreApiSchemas}, which merges these in, so the lane
 * adds two lines there and nothing else.
 *
 * MCP: every route is a tool in the `core.inventory` toolset. No write
 * declares an undo, so an agent's change is always a proposal its person
 * approves (register IV.19).
 */
final class InventoryApiSchemas
{
    /** @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}> */
    public static function routes(): array
    {
        $tags = ['inventory'];
        $item = self::data(SchemaBuilder::ref('InventoryItem'));
        $location = self::data(SchemaBuilder::ref('InventoryLocation'));
        $notFound = [404 => self::error('Not found')];
        $refused = [409 => self::error('Refused: a duplicate SKU or barcode, or a rule'), 422 => self::error('A value that doesn\'t look right')];

        return [
            self::route('GET', '/api/inventory/units', 'inventory:read', [
                'summary' => 'List units of measure',
                'description' => 'Every workspace starts with piece (pc), kg, g, l, ml, m, box, pack and bag. Quantities are decimals with the unit\'s own decimals.',
                'tags' => $tags,
                'responses' => [200 => self::json('Units', self::list('InventoryUnit'))] + self::auth(),
            ]),
            self::route('POST', '/api/inventory/units', 'inventory:write', [
                'summary' => 'Add a unit of measure',
                'tags' => $tags,
                'request' => 'InventoryUnitRequest',
                'responses' => [201 => self::json('The unit', self::data(SchemaBuilder::ref('InventoryUnit')))] + $refused + self::auth(),
            ]),
            self::route('GET', '/api/inventory/categories', 'inventory:read', [
                'summary' => 'List item categories, with how many items each has',
                'tags' => $tags,
                'responses' => [200 => self::json('Categories', self::list('InventoryCategory'))] + self::auth(),
            ]),
            self::route('POST', '/api/inventory/categories', 'inventory:write', [
                'summary' => 'Add an item category',
                'tags' => $tags,
                'request' => 'InventoryCategoryRequest',
                'responses' => [201 => self::json('The category', self::data(SchemaBuilder::ref('InventoryCategory')))] + $refused + self::auth(),
            ]),
            self::route('GET', '/api/inventory/items', 'inventory:read', [
                'summary' => 'List items',
                'description' => 'Sorted by name. Search matches names in every language, SKUs, and barcodes exactly.',
                'tags' => $tags,
                'parameters' => [
                    self::param('q', 'string', 'Search text, a SKU or a barcode.'),
                    self::param('kind', 'string', '`stocked`, `consumable`, `service` or `made`.'),
                    self::param('category_id', 'integer', 'Only items in this category.'),
                    self::param('archived', 'string', '`true` for archived items instead.'),
                    self::param('page', 'integer', 'Page, from 1.'),
                    self::param('per_page', 'integer', 'Rows per page, at most 200. Default 50.'),
                ],
                'responses' => [200 => self::json('Items', self::object([
                    'data' => ['type' => 'array', 'items' => SchemaBuilder::ref('InventoryItem')],
                    'pagination' => SchemaBuilder::ref('Pagination'),
                ], ['data', 'pagination']))] + self::auth(),
            ]),
            self::route('POST', '/api/inventory/items', 'inventory:write', [
                'summary' => 'Add an item',
                'description' => 'Needs a name. Counted in `unit` (pc when left out). For sizes or colours send `variants`; otherwise one variant is made, '
                    . 'with `sku` and `barcodes` if given. Each variant counts toward the plan\'s item limit, which only warns: the item is always added.',
                'tags' => $tags,
                'request' => 'InventoryItemRequest',
                'responses' => [201 => self::json('The item', $item)] + $refused + self::auth(),
            ]),
            self::route('GET', '/api/inventory/items/lookup', 'inventory:read', [
                'summary' => 'Find the item a scanned barcode or SKU belongs to',
                'description' => 'Answers the item, the variant, and the unit the barcode stands for (a box barcode means a box).',
                'tags' => $tags,
                'parameters' => [self::param('code', 'string', 'The barcode or SKU. Spaces are ignored and Arabic-Indic digits read as Western ones.')],
                'responses' => [200 => self::json('The match', self::data(self::object([
                    'item' => SchemaBuilder::ref('InventoryItem'),
                    'variant_id' => self::int(),
                    'unit' => self::str(),
                ], ['item', 'variant_id', 'unit'])))] + $notFound + self::auth(),
            ]),
            self::route('GET', '/api/inventory/items/{id:\d+}', 'inventory:read', [
                'summary' => 'Get one item, its variants, units and stock',
                'tags' => $tags,
                'responses' => [200 => self::json('The item', $item)] + $notFound + self::auth(),
            ]),
            self::route('PATCH', '/api/inventory/items/{id:\d+}', 'inventory:write', [
                'summary' => 'Change an item; fields left out keep their value',
                'description' => '`variants`, when sent, is the whole list: entries with an `id` change that variant, entries without one are added, '
                    . 'and variants left out are archived. The unit can\'t change once the item has stock history.',
                'tags' => $tags,
                'request' => 'InventoryItemRequest',
                'responses' => [200 => self::json('The item', $item)] + $refused + $notFound + self::auth(),
            ]),
            self::route('POST', '/api/inventory/items/{id:\d+}/archive', 'inventory:write', [
                'summary' => 'Archive an item',
                'description' => 'Archived items leave the lists and can\'t be moved; their history stays and they can be restored.',
                'tags' => $tags,
                'responses' => [200 => self::json('The item', $item)] + $notFound + self::auth(),
            ]),
            self::route('POST', '/api/inventory/items/{id:\d+}/restore', 'inventory:write', [
                'summary' => 'Bring an archived item back',
                'tags' => $tags,
                'responses' => [200 => self::json('The item', $item)] + $refused + $notFound + self::auth(),
            ]),
            self::route('GET', '/api/inventory/locations', 'inventory:read', [
                'summary' => 'List places stock is kept',
                'tags' => $tags,
                'parameters' => [
                    self::param('virtual', 'string', '`true` to include virtual places (supplier, customer, loss, found, production, consumed, opening, transit).'),
                    self::param('inactive', 'string', '`true` to include places no longer in use.'),
                ],
                'responses' => [200 => self::json('Places', self::list('InventoryLocation'))] + self::auth(),
            ]),
            self::route('POST', '/api/inventory/locations', 'inventory:write', [
                'summary' => 'Add a place (a warehouse, shop or van, or an area inside one)',
                'tags' => $tags,
                'request' => 'InventoryLocationRequest',
                'responses' => [201 => self::json('The place', $location)] + $refused + self::auth(),
            ]),
            self::route('GET', '/api/inventory/locations/{id:\d+}', 'inventory:read', [
                'summary' => 'Get one place',
                'tags' => $tags,
                'responses' => [200 => self::json('The place', $location)] + $notFound + self::auth(),
            ]),
            self::route('PATCH', '/api/inventory/locations/{id:\d+}', 'inventory:write', [
                'summary' => 'Change a place; `is_active: false` stops using it',
                'tags' => $tags,
                'request' => 'InventoryLocationRequest',
                'responses' => [200 => self::json('The place', $location)] + $refused + $notFound + self::auth(),
            ]),
            self::route('GET', '/api/inventory/stock', 'inventory:read', [
                'summary' => 'Stock now, per variant and place, in each item\'s base unit',
                'tags' => $tags,
                'parameters' => [
                    self::param('item_id', 'integer', 'Only this item.'),
                    self::param('variant_id', 'integer', 'Only this variant.'),
                    self::param('location_id', 'integer', 'Only this place.'),
                    self::param('nonzero', 'string', '`true` to leave out levels at zero.'),
                ],
                'responses' => [200 => self::json('Levels', self::list('InventoryLevel'))] + self::auth(),
            ]),
            self::route('GET', '/api/inventory/stock/moves', 'inventory:read', [
                'summary' => 'Stock history: every move, newest first',
                'description' => 'Moves are never changed; an undo is another move the other way, with `reverses_move_id`. Page back with `before_id`.',
                'tags' => $tags,
                'parameters' => [
                    self::param('item_id', 'integer', 'Only this item.'),
                    self::param('variant_id', 'integer', 'Only this variant.'),
                    self::param('location_id', 'integer', 'Moves into or out of this place.'),
                    self::param('source_type', 'string', 'Only moves from this source, such as `adjustment` or a plugin\'s `pos.sale`.'),
                    self::param('batch_key', 'string', 'Only the moves posted together under this key.'),
                    self::param('before_id', 'integer', 'Moves older than this one.'),
                    self::param('limit', 'integer', 'At most 200. Default 50.'),
                ],
                'responses' => [200 => self::json('Moves', self::object([
                    'data' => ['type' => 'array', 'items' => SchemaBuilder::ref('InventoryMove')],
                    'next_before_id' => self::int(true),
                ], ['data']))] + self::auth(),
            ]),
            self::route('POST', '/api/inventory/stock/adjustments', 'stock:adjust', [
                'summary' => 'Set opening stock, or adjust stock with a reason',
                'description' => '`kind: opening` adds stock to a place. `kind: adjustment` changes it by a signed amount with a `reason_code`: '
                    . 'going out expired, damaged, spoiled, used_in_house, sample, theft, count_correction or other; coming in found, count_correction, returned or other. '
                    . 'With an `idempotency_key`, sending the same key again changes nothing and answers the first result (200).',
                'tags' => $tags,
                'request' => 'InventoryAdjustmentRequest',
                'responses' => [
                    201 => self::json('The stock change', self::data(SchemaBuilder::ref('InventoryBatch'))),
                    200 => self::json('The same key was sent before; nothing changed', self::data(SchemaBuilder::ref('InventoryBatch'))),
                ] + $refused + self::auth(),
            ]),
            self::route('POST', '/api/inventory/stock/batches/{key:[a-f0-9]+}/undo', 'stock:adjust', [
                'summary' => 'Undo a stock change',
                'description' => 'Posts the same moves the other way, linked to the originals. A change can be undone once.',
                'tags' => $tags,
                'responses' => [201 => self::json('The undo', self::data(SchemaBuilder::ref('InventoryBatch'))), 409 => self::error('Already undone')] + $notFound + self::auth(),
            ]),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function components(): array
    {
        $names = ['type' => 'object', 'additionalProperties' => ['type' => 'string'], 'description' => 'The name in each language, such as `{"en": "Flour", "ar": "طحين"}`.'];
        $name = ['oneOf' => [self::str(), self::object(['en' => self::str(), 'ar' => self::str()], [])], 'description' => 'Text, or `{en, ar}`.'];
        $decimal = static fn (string $what): array => ['type' => 'string', 'pattern' => '^-?\d+(\.\d+)?$', 'description' => $what];
        $unit = SchemaBuilder::ref('InventoryUnit');

        return [
            'InventoryUnit' => self::object([
                'id' => self::int(),
                'code' => self::str(),
                'name' => self::str(),
                'names' => $names,
                'dimension' => self::str() + ['enum' => ['count', 'mass', 'volume', 'length', 'area', 'time', 'package']],
                'decimals' => self::int(),
                'to_base_num' => self::int(),
                'to_base_den' => self::int(),
                'seeded' => self::bool(),
                'archived' => self::bool(),
            ], ['id', 'code', 'name', 'names', 'dimension', 'decimals', 'to_base_num', 'to_base_den', 'seeded', 'archived']),
            'InventoryUnitRequest' => self::object([
                'code' => self::str() + ['pattern' => '^[a-z][a-z0-9_]{0,19}$'] + ['description' => 'A short lower-case code for the unit, such as `tray` or `sack`; unique in the workspace.'],
                'name' => $name,
                'dimension' => self::str() + ['enum' => ['count', 'mass', 'volume', 'length', 'area', 'time', 'package']] + ['description' => 'What it measures: `count` for pieces, `mass`, `volume`, `length`, `area`, `time`, or `package` for a box or pack.'],
                'decimals' => self::int() + ['minimum' => 0, 'maximum' => 6] + ['description' => 'Decimal places a quantity in it may have: 0 for pieces, 3 for kg.'],
                'to_base' => $decimal('How many of the dimension\'s base unit (kg, l, m) one of it is, such as "0.001" for g.'),
            ], ['code']),
            'InventoryCategory' => self::object([
                'id' => self::int(),
                'parent_id' => self::int(true),
                'name' => self::str(),
                'names' => $names,
                'item_count' => self::int(),
            ], ['id', 'parent_id', 'name', 'names', 'item_count']),
            'InventoryCategoryRequest' => self::object(['name' => $name, 'parent_id' => self::int(true) + ['description' => 'The category it sits under, or null for a top-level one.']], ['name']),
            'InventoryVariant' => self::object([
                'id' => self::int(),
                'item_id' => self::int(),
                'sku' => self::str(),
                'options' => ['type' => 'object', 'additionalProperties' => ['type' => 'string'], 'description' => 'Such as `{"size": "M", "colour": "red"}`; empty for an item without variants.'],
                'barcodes' => ['type' => 'array', 'items' => self::object(['code' => self::str(), 'unit' => self::str(true), 'symbology' => self::str()], ['code', 'unit', 'symbology'])],
                'sale_price_minor' => self::int(true),
                'purchase_price_minor' => self::int(true),
                'on_hand' => $decimal('Stock at every real place, in the item\'s base unit.'),
                'archived' => self::bool(),
            ], ['id', 'item_id', 'sku', 'options', 'barcodes', 'sale_price_minor', 'purchase_price_minor', 'on_hand', 'archived']),
            'InventoryItem' => self::object([
                'id' => self::int(),
                'name' => self::str() + ['description' => 'As first written; `names` has every language.'],
                'names' => $names,
                'description' => self::str(),
                'kind' => self::str() + ['enum' => ['stocked', 'consumable', 'service', 'made']],
                'category_id' => self::int(true),
                'base_unit' => $unit,
                'tracking' => self::str() + ['enum' => ['none', 'lot', 'serial']],
                'expires' => self::bool(),
                'shelf_life_days' => self::int(true),
                'sale_price_minor' => self::int(true) + ['description' => 'In the currency\'s minor units.'],
                'purchase_price_minor' => self::int(true),
                'currency' => self::str(true),
                'tax_category' => self::str(),
                'owner_plugin' => self::str(true),
                'variants' => ['type' => 'array', 'items' => SchemaBuilder::ref('InventoryVariant')],
                'units' => ['type' => 'array', 'items' => self::object([
                    'unit' => self::str(),
                    'per' => self::str() + ['description' => 'How many of the base unit one of it is, such as "25".'],
                    'purpose' => self::str() + ['enum' => ['purchase', 'sale', 'both']],
                    'is_default' => self::bool(),
                ], ['unit', 'per', 'purpose', 'is_default'])],
                'on_hand' => $decimal('Stock of every live variant at every real place, in the base unit.'),
                'fields' => ['type' => 'object', 'additionalProperties' => true, 'description' => 'The workspace\'s own fields, by key.'],
                'archived' => self::bool(),
                'created_at' => self::str(),
                'updated_at' => self::str(),
            ], ['id', 'name', 'names', 'description', 'kind', 'category_id', 'base_unit', 'tracking', 'expires', 'variants', 'units', 'on_hand', 'archived', 'created_at', 'updated_at']),
            'InventoryItemRequest' => self::object([
                'name' => $name,
                'kind' => self::str() + ['enum' => ['stocked', 'consumable', 'service', 'made']] + ['description' => '`stocked` (kept and sold, the default), `consumable` (used up, such as cleaning supplies), `service` (no stock) or `made` (made from other items).'],
                'unit' => self::str() + ['description' => 'The unit code stock is counted in, such as `kg`. Only when adding, or before any stock moved.'],
                'sku' => self::str() + ['description' => 'For an item without variants. Made up when left out.'],
                'barcodes' => ['type' => 'array', 'items' => ['oneOf' => [self::str(), self::object(['code' => self::str(), 'unit' => self::str()], ['code'])]]] + ['description' => 'For an item without variants: barcodes, each a code or `{code, unit}` when it stands for a box or pack.'],
                'variants' => ['type' => 'array', 'maxItems' => 100, 'items' => self::object([
                    'id' => self::int(),
                    'sku' => self::str(),
                    'options' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
                    'barcodes' => ['type' => 'array', 'items' => ['oneOf' => [self::str(), self::object(['code' => self::str(), 'unit' => self::str()], ['code'])]]],
                    'sale_price_minor' => self::int(true),
                    'purchase_price_minor' => self::int(true),
                ], [])] + ['description' => 'Sizes or colours, each `{options, sku, barcodes, sale_price_minor}`; each one counts as an item. On a change, the whole list: with `id` changes that variant, without adds one, left out archives it.'],
                'units' => ['type' => 'array', 'maxItems' => 10, 'items' => self::object([
                    'unit' => self::str(),
                    'per' => $decimal('How many of the base unit one is.'),
                    'purpose' => self::str() + ['enum' => ['purchase', 'sale', 'both']],
                    'is_default' => self::bool(),
                ], ['unit', 'per'])] + ['description' => 'Other units it is bought or sold in, each `{unit, per, purpose}`, such as a 25 kg bag: `{"unit": "bag", "per": "25"}`.'],
                'description' => self::str() + ['description' => 'A longer note about the item.'],
                'category_id' => self::int(true) + ['description' => 'The category it belongs to, or null for none.'],
                'expires' => self::bool() + ['description' => 'True when the item goes off and its stock has expiry dates.'],
                'shelf_life_days' => self::int(true) + ['description' => 'Days it keeps once made or received, for working out expiry dates.'],
                'sale_price_minor' => self::int(true) + ['description' => 'Selling price in the currency\'s minor units (cents, or fils with three decimals).'],
                'purchase_price_minor' => self::int(true) + ['description' => 'Usual buying price in the currency\'s minor units.'],
                'currency' => self::str(true) + ['description' => 'The workspace\'s currency when left out and a price is set.'],
                'tax_category' => self::str() + ['description' => 'The tax category it is sold under, as the workspace names it; empty for none.'],
                'fields' => ['type' => 'object', 'additionalProperties' => true] + ['description' => 'The workspace\'s own fields for items, by key.'],
            ], []),
            'InventoryLocation' => self::object([
                'id' => self::int(),
                'parent_id' => self::int(true),
                'kind' => self::str() + ['enum' => ['warehouse', 'area', 'virtual']],
                'virtual_role' => self::str(true),
                'name' => self::str(),
                'names' => $names,
                'code' => self::str(),
                'ou_id' => self::int(true) + ['description' => 'The organisational unit (branch) it belongs to.'],
                'address' => self::str(),
                'is_active' => self::bool(),
                'created_at' => self::str(),
                'updated_at' => self::str(),
            ], ['id', 'parent_id', 'kind', 'virtual_role', 'name', 'names', 'code', 'ou_id', 'address', 'is_active', 'created_at', 'updated_at']),
            'InventoryLocationRequest' => self::object([
                'name' => $name,
                'kind' => self::str() + ['enum' => ['warehouse', 'area']] + ['description' => '`warehouse` for a building, shop or van; `area` for a shelf or room inside one.'],
                'parent_id' => self::int(true) + ['description' => 'For an area, the place it is inside.'],
                'code' => self::str() + ['description' => 'A short code for the place, such as `WH1`.'],
                'ou_id' => self::int(true) + ['description' => 'The organisational unit (branch) it belongs to, which limits who sees its stock.'],
                'address' => self::str() + ['description' => 'Where it is.'],
                'is_active' => self::bool() + ['description' => 'False to stop using the place; its history stays.'],
            ], []),
            'InventoryLevel' => self::object([
                'item_id' => self::int(),
                'variant_id' => self::int(),
                'sku' => self::str(),
                'location_id' => self::int(),
                'lot_id' => self::int(true),
                'on_hand' => $decimal('Below zero where the workspace allows it.'),
                'reserved' => $decimal('Promised to orders.'),
                'available' => $decimal('On hand minus reserved.'),
                'unit' => self::str(),
                'decimals' => self::int(),
                'updated_at' => self::str(),
            ], ['item_id', 'variant_id', 'sku', 'location_id', 'lot_id', 'on_hand', 'reserved', 'available', 'unit', 'decimals', 'updated_at']),
            'InventoryMove' => self::object([
                'id' => self::int(),
                'item_id' => self::int(),
                'variant_id' => self::int(),
                'sku' => self::str(),
                'lot_id' => self::int(true),
                'from_location_id' => self::int(),
                'to_location_id' => self::int(),
                'quantity' => $decimal('In the item\'s base unit, always positive.'),
                'unit' => self::str(),
                'decimals' => self::int(),
                'entered_quantity' => self::str(true),
                'entered_unit' => self::str(true),
                'unit_cost_minor' => self::int(true),
                'currency' => self::str(true),
                'source_type' => self::str(),
                'source_id' => self::int(true),
                'reason_code' => self::str(),
                'note' => self::str(),
                'batch_key' => self::str(),
                'reverses_move_id' => self::int(true),
                'posted_by_profile_id' => self::int(true),
                'posted_by_name' => self::str(true),
                'agent_id' => self::int(true),
                'on_behalf_of_profile_id' => self::int(true),
                'occurred_at' => self::str(),
                'posted_at' => self::str(),
            ], ['id', 'item_id', 'variant_id', 'sku', 'from_location_id', 'to_location_id', 'quantity', 'unit', 'source_type', 'batch_key', 'occurred_at', 'posted_at']),
            'InventoryBatch' => self::object([
                'batch_key' => self::str(),
                'moves' => ['type' => 'array', 'items' => SchemaBuilder::ref('InventoryMove')],
                'repeated' => self::bool(),
            ], ['batch_key', 'moves', 'repeated']),
            'InventoryAdjustmentRequest' => self::object([
                'kind' => self::str() + ['enum' => ['opening', 'adjustment']] + ['description' => '`opening` to set starting stock; `adjustment` (the default) to change stock with a reason.'],
                'lines' => ['description' => 'One line per item and place: `{variant_id, location_id, change, unit}`.', 'type' => 'array', 'minItems' => 1, 'maxItems' => 500, 'items' => self::object([
                    'variant_id' => self::int(),
                    'location_id' => self::int(),
                    'change' => $decimal('Opening: a positive amount. Adjustment: signed, -2 takes two out.'),
                    'unit' => self::str() + ['description' => 'A unit code; the item\'s base unit when left out.'],
                    'note' => self::str(),
                ], ['variant_id', 'location_id', 'change'])],
                'reason_code' => self::str() + ['description' => 'Why stock changed. Out: expired, damaged, spoiled, used_in_house, sample, theft, count_correction, other. In: found, count_correction, returned, other.'],
                'note' => self::str() + ['description' => 'A note kept with the change.'],
                'occurred_at' => self::str() + ['description' => 'When it happened, if earlier than now (ISO 8601).'],
                'idempotency_key' => self::str() + ['maxLength' => 128] + ['description' => 'Any unique text; sending the same key again changes nothing and answers the first result.'],
            ], ['lines']),
        ];
    }

    /** @return array<string, mixed> */
    private static function list(string $ref): array
    {
        return self::object(['data' => ['type' => 'array', 'items' => SchemaBuilder::ref($ref)]], ['data']);
    }

    /**
     * @param array<string, mixed> $schema
     * @return array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}
     */
    private static function route(string $method, string $path, string $permission, array $schema): array
    {
        return ['method' => $method, 'path' => $path, 'requiredRole' => null, 'requiredPermission' => $permission, 'schema' => $schema];
    }

    /** @return array<string, mixed> */
    private static function param(string $name, string $type, string $description): array
    {
        return ['name' => $name, 'in' => 'query', 'required' => false, 'description' => $description, 'schema' => ['type' => $type]];
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
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private static function data(array $item): array
    {
        return self::object(['data' => $item], ['data']);
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
