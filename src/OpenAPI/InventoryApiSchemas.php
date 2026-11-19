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
        $operation = self::data(SchemaBuilder::ref('InventoryOperation'));
        $order = self::data(SchemaBuilder::ref('InventoryPurchaseOrder'));
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
                'description' => '`kind: opening` adds stock to a place. `kind: adjustment` changes it by a signed amount with a `reason_code` from the workspace\'s reasons, at one place, '
                    . 'and waits for approval when a line is worth more than the workspace\'s value (then `batch_key` is null and `operation.status` is `waiting_approval`). '
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
            self::route('GET', '/api/inventory/operations', 'inventory:read', [
                'summary' => 'List adjustments, counts and transfers, newest first',
                'tags' => $tags,
                'parameters' => [
                    self::param('kind', 'string', '`adjustment`, `count`, `transfer` or `receipt`.'),
                    self::param('status', 'string', '`draft`, `waiting_approval`, `in_transit`, `done` or `cancelled`.'),
                    self::param('location_id', 'integer', 'Only those at, from or to this place.'),
                    self::param('limit', 'integer', 'At most 200. Default 50.'),
                    self::param('offset', 'integer', 'How many to skip.'),
                    self::param('lines', 'string', '`true` to include each one\'s lines.'),
                    self::param('order_id', 'integer', 'Only the receipts of this purchase order.'),
                ],
                'responses' => [200 => self::json('Operations, with their lines when asked', self::list('InventoryOperation'))] + self::auth(),
            ]),
            self::route('GET', '/api/inventory/operations/{id:\d+}', 'inventory:read', [
                'summary' => 'An adjustment, count or transfer with its lines',
                'description' => 'In a blind count still being counted, people who can\'t adjust stock don\'t see what was expected.',
                'tags' => $tags,
                'responses' => [200 => self::json('The operation', $operation)] + $notFound + self::auth(),
            ]),
            self::route('POST', '/api/inventory/adjustments', 'stock:adjust', [
                'summary' => 'Adjust stock at a place, with a reason',
                'description' => 'Posts at once, unless a line is worth more than the workspace\'s value at its buying price (25 by default): '
                    . 'then it waits for approval (`status: waiting_approval`) and posts when approved. '
                    . 'With an `idempotency_key`, sending the same key again changes nothing and answers the first result (200).',
                'tags' => $tags,
                'request' => 'InventoryOperationAdjustmentRequest',
                'responses' => [
                    201 => self::json('The adjustment', $operation),
                    200 => self::json('The same key was sent before; nothing changed', $operation),
                ] + $refused + self::auth(),
            ]),
            self::route('POST', '/api/inventory/adjustments/{id:\d+}/submit', 'stock:adjust', [
                'summary' => 'Send a draft adjustment again',
                'description' => 'For an adjustment turned down and back in draft: posts, or goes for approval again.',
                'tags' => $tags,
                'responses' => [200 => self::json('The adjustment', $operation), 409 => self::error('Not a draft')] + $notFound + self::auth(),
            ]),
            self::route('POST', '/api/inventory/adjustments/{id:\d+}/cancel', 'stock:adjust', [
                'summary' => 'Cancel an adjustment that has not posted',
                'tags' => $tags,
                'responses' => [200 => self::json('The adjustment', $operation), 409 => self::error('Already done or cancelled')] + $notFound + self::auth(),
            ]),
            self::route('POST', '/api/inventory/counts', 'stock:count', [
                'summary' => 'Start a stock count at a place',
                'description' => 'Lists what is at the place now, or a category, or chosen items. Nothing is counted yet; expected is taken as each line is counted.',
                'tags' => $tags,
                'request' => 'InventoryCountRequest',
                'responses' => [201 => self::json('The count', $operation), 200 => self::json('The same key was sent before', $operation)] + $refused + self::auth(),
            ]),
            self::route('PUT', '/api/inventory/counts/{id:\d+}/lines', 'stock:count', [
                'summary' => 'Record what was counted',
                'description' => 'Each line\'s expected quantity is taken now, so sales during a long count are not differences. Counting a line again replaces it.',
                'tags' => $tags,
                'request' => 'InventoryCountLinesRequest',
                'responses' => [200 => self::json('The count', $operation), 409 => self::error('The count is finished')] + $refused + $notFound + self::auth(),
            ]),
            self::route('POST', '/api/inventory/counts/{id:\d+}/finish', 'stock:count', [
                'summary' => 'Finish a count and post its differences',
                'description' => 'Lines not counted are left out. Differences post as count corrections, after approval when one is worth more than the workspace\'s value.',
                'tags' => $tags,
                'responses' => [200 => self::json('The count', $operation), 409 => self::error('Already finished')] + $refused + $notFound + self::auth(),
            ]),
            self::route('POST', '/api/inventory/counts/{id:\d+}/cancel', 'stock:count', [
                'summary' => 'Cancel a count that has not posted',
                'tags' => $tags,
                'responses' => [200 => self::json('The count', $operation), 409 => self::error('Already done or cancelled')] + $notFound + self::auth(),
            ]),
            self::route('POST', '/api/inventory/transfers', 'stock:transfer', [
                'summary' => 'Move stock from one place to another',
                'description' => 'Sent at once unless `send` is false. It waits "on the way" until received, or arrives at once with `one_step` or the workspace\'s setting.',
                'tags' => $tags,
                'request' => 'InventoryTransferRequest',
                'responses' => [201 => self::json('The transfer', $operation), 200 => self::json('The same key was sent before', $operation)] + $refused + self::auth(),
            ]),
            self::route('POST', '/api/inventory/transfers/{id:\d+}/send', 'stock:transfer', [
                'summary' => 'Send a draft transfer',
                'tags' => $tags,
                'request' => 'InventoryTransferSendRequest',
                'responses' => [200 => self::json('The transfer', $operation), 409 => self::error('Not a draft')] + $refused + $notFound + self::auth(),
            ]),
            self::route('POST', '/api/inventory/transfers/{id:\d+}/receive', 'stock:transfer', [
                'summary' => 'Receive a transfer on the way',
                'description' => 'Everything arrives unless a line says less; what did not arrive is written off as lost on the way.',
                'tags' => $tags,
                'request' => 'InventoryReceiveRequest',
                'responses' => [200 => self::json('The transfer', $operation), 409 => self::error('Not on the way')] + $refused + $notFound + self::auth(),
            ]),
            self::route('POST', '/api/inventory/transfers/{id:\d+}/cancel', 'stock:transfer', [
                'summary' => 'Cancel a transfer',
                'description' => 'A transfer on the way goes back to the place it left.',
                'tags' => $tags,
                'responses' => [200 => self::json('The transfer', $operation), 409 => self::error('Already done or cancelled')] + $notFound + self::auth(),
            ]),
            self::route('GET', '/api/inventory/reasons', 'inventory:read', [
                'summary' => 'Reasons stock changes, such as expired or found',
                'tags' => $tags,
                'parameters' => [self::param('archived', 'string', '`true` to include archived reasons.')],
                'responses' => [200 => self::json('Reasons', self::list('InventoryReason'))] + self::auth(),
            ]),
            self::route('POST', '/api/inventory/reasons', 'inventory:settings', [
                'summary' => 'Add a reason',
                'tags' => $tags,
                'request' => 'InventoryReasonRequest',
                'responses' => [201 => self::json('The reason', self::data(SchemaBuilder::ref('InventoryReason')))] + $refused + self::auth(),
            ]),
            self::route('PATCH', '/api/inventory/reasons/{id:\d+}', 'inventory:settings', [
                'summary' => 'Rename, change or archive a reason',
                'tags' => $tags,
                'request' => 'InventoryReasonUpdateRequest',
                'responses' => [200 => self::json('The reason', self::data(SchemaBuilder::ref('InventoryReason')))] + $refused + $notFound + self::auth(),
            ]),
            self::route('GET', '/api/inventory/reorder-rules', 'inventory:read', [
                'summary' => 'Reorder levels, with what is available now',
                'tags' => $tags,
                'parameters' => [
                    self::param('variant_id', 'integer', 'Only this variant.'),
                    self::param('location_id', 'integer', 'Only this place.'),
                    self::param('below', 'string', '`true` for only those under their level now, with how much to order.'),
                ],
                'responses' => [200 => self::json('Reorder levels', self::list('InventoryReorderRule'))] + self::auth(),
            ]),
            self::route('PUT', '/api/inventory/reorder-rules', 'inventory:write', [
                'summary' => 'Set the reorder level for an item at a place',
                'description' => 'Adds it or changes it. Going under it tells the Stock assistant once, until stock comes back.',
                'tags' => $tags,
                'request' => 'InventoryReorderRuleRequest',
                'responses' => [200 => self::json('The reorder level', self::data(SchemaBuilder::ref('InventoryReorderRule')))] + $refused + self::auth(),
            ]),
            self::route('DELETE', '/api/inventory/reorder-rules/{id:\d+}', 'inventory:write', [
                'summary' => 'Remove a reorder level',
                'tags' => $tags,
                'responses' => [200 => self::json('Removed', self::data(self::object(['deleted' => self::bool()], ['deleted'])))] + $notFound + self::auth(),
            ]),
            self::route('GET', '/api/inventory/settings', 'inventory:read', [
                'summary' => 'The workspace\'s inventory settings',
                'tags' => $tags,
                'responses' => [200 => self::json('Settings', self::data(SchemaBuilder::ref('InventorySettings')))] + self::auth(),
            ]),
            self::route('PATCH', '/api/inventory/settings', 'inventory:settings', [
                'summary' => 'Change the workspace\'s inventory settings',
                'tags' => $tags,
                'request' => 'InventorySettingsRequest',
                'responses' => [200 => self::json('Settings', self::data(SchemaBuilder::ref('InventorySettings')))] + $refused + self::auth(),
            ]),
            self::route('GET', '/api/inventory/purchase-orders', 'purchases:read', [
                'summary' => 'List purchase orders, newest first',
                'tags' => $tags,
                'parameters' => [
                    self::param('status', 'string', '`draft`, `waiting_approval`, `approved`, `partly_done`, `done` or `cancelled`.'),
                    self::param('contact_id', 'integer', 'Only this supplier.'),
                    self::param('location_id', 'integer', 'Only those received into this place.'),
                    self::param('limit', 'integer', 'At most 200. Default 50.'),
                    self::param('offset', 'integer', 'How many to skip.'),
                ],
                'responses' => [200 => self::json('Purchase orders, without their lines', self::list('InventoryPurchaseOrder'))] + self::auth(),
            ]),
            self::route('POST', '/api/inventory/purchase-orders', 'purchases:write', [
                'summary' => 'Draft a purchase order',
                'description' => 'A supplier (a contact marked as one), the place it is received into, and lines in the unit they are bought in. '
                    . 'A line without a price takes what the supplier sells it for in that unit. It stays a draft until sent.',
                'tags' => $tags,
                'request' => 'InventoryPurchaseOrderRequest',
                'responses' => [201 => self::json('The purchase order', $order), 200 => self::json('The same key was sent before', $order)] + $refused + self::auth(),
            ]),
            self::route('GET', '/api/inventory/purchase-orders/{id:\d+}', 'purchases:read', [
                'summary' => 'A purchase order with its lines and receipts',
                'tags' => $tags,
                'responses' => [200 => self::json('The purchase order', $order)] + $notFound + self::auth(),
            ]),
            self::route('PATCH', '/api/inventory/purchase-orders/{id:\d+}', 'purchases:write', [
                'summary' => 'Change a draft purchase order',
                'description' => 'Any field; `lines` replaces every line. Only a draft can change.',
                'tags' => $tags,
                'request' => 'InventoryPurchaseOrderUpdateRequest',
                'responses' => [200 => self::json('The purchase order', $order)] + $refused + $notFound + self::auth(),
            ]),
            self::route('POST', '/api/inventory/purchase-orders/{id:\d+}/submit', 'purchases:write', [
                'summary' => 'Send a purchase order for approval',
                'description' => 'It travels the workspace\'s purchase route when one is chosen (`status: waiting_approval`); with none it is approved at once.',
                'tags' => $tags,
                'responses' => [200 => self::json('The purchase order', $order), 409 => self::error('Not a draft, or a rule refused it')] + $notFound + self::auth(),
            ]),
            self::route('POST', '/api/inventory/purchase-orders/{id:\d+}/cancel', 'purchases:write', [
                'summary' => 'Cancel a purchase order nothing has arrived against',
                'tags' => $tags,
                'responses' => [200 => self::json('The purchase order', $order), 409 => self::error('Part of it arrived, or it is done')] + $notFound + self::auth(),
            ]),
            self::route('POST', '/api/inventory/purchase-orders/{id:\d+}/receive', 'purchases:write', [
                'summary' => 'Receive what arrived against a purchase order',
                'description' => 'Posts the stock into the order\'s place at the order\'s cost, as a receipt (`GRN-0001`). '
                    . 'More than ordered past the workspace\'s tolerance is refused (409, `over_receipt`) unless `allow_over` is true.',
                'tags' => $tags,
                'request' => 'InventoryReceiveOrderRequest',
                'responses' => [
                    201 => self::json('The purchase order, and the receipt', self::object(['data' => SchemaBuilder::ref('InventoryPurchaseOrder'), 'receipt' => SchemaBuilder::ref('InventoryOperation')], ['data', 'receipt'])),
                    200 => self::json('The same key was sent before', self::object(['data' => SchemaBuilder::ref('InventoryPurchaseOrder'), 'receipt' => SchemaBuilder::ref('InventoryOperation')], ['data', 'receipt'])),
                ] + $refused + $notFound + self::auth(),
            ]),
            self::route('POST', '/api/inventory/purchase-orders/{id:\d+}/print', 'purchases:read', [
                'summary' => 'Print a purchase order',
                'description' => 'Issues the purchase order from the workspace\'s template in that language (seeded and published the first time) '
                    . 'and answers with its PDF; the document\'s id is in `X-Document-Id`. Printing again returns the same document until the '
                    . 'order or the template changes. When this instance can\'t make PDFs, the document is still issued and the answer is 202.',
                'tags' => $tags,
                'request' => 'InventoryPrintRequest',
                'responses' => [
                    200 => ['description' => 'The PDF', 'content' => ['application/pdf' => ['schema' => ['type' => 'string', 'format' => 'binary']]]],
                    202 => self::json('Issued, with no PDF on this instance', self::object(['data' => SchemaBuilder::ref('InventoryPrinted')], ['data'])),
                ] + $refused + $notFound + self::auth(),
            ]),
            self::route('POST', '/api/inventory/receipts/{id:\d+}/print', 'purchases:read', [
                'summary' => 'Print a goods received note',
                'description' => 'Issues the goods received note for one receipt (`GRN-0001`) and answers with its PDF, as purchase orders print.',
                'tags' => $tags,
                'request' => 'InventoryPrintRequest',
                'responses' => [
                    200 => ['description' => 'The PDF', 'content' => ['application/pdf' => ['schema' => ['type' => 'string', 'format' => 'binary']]]],
                    202 => self::json('Issued, with no PDF on this instance', self::object(['data' => SchemaBuilder::ref('InventoryPrinted')], ['data'])),
                ] + $refused + $notFound + self::auth(),
            ]),
            self::route('GET', '/api/inventory/print-templates', 'inventory:read', [
                'summary' => 'The templates inventory prints from',
                'description' => 'By document (`inventory.purchase-order`, `inventory.goods-received`, `inventory.item-labels`) and language, '
                    . 'seeding any the workspace lacks. Item labels print through `POST /api/document-sources/{id}/print-labels` '
                    . 'with records `inventory.variant:<id>`.',
                'tags' => $tags,
                'responses' => [200 => self::json('Template ids', self::object(['data' => [
                    'type' => 'object',
                    'additionalProperties' => self::object(['en' => self::int(), 'ar' => self::int()], ['en', 'ar']),
                ]], ['data']))] + self::auth(),
            ]),
            self::route('POST', '/api/inventory/purchase-orders/{id:\d+}/lines/{line:\d+}/close', 'purchases:write', [
                'summary' => 'Close a line that won\'t come',
                'description' => 'What has not arrived of it is no longer expected. When every line is received or closed, the order is done.',
                'tags' => $tags,
                'responses' => [200 => self::json('The purchase order', $order), 409 => self::error('The order is not approved')] + $notFound + self::auth(),
            ]),
            self::route('GET', '/api/inventory/supplier-items', 'purchases:read', [
                'summary' => 'What suppliers sell, and at what price',
                'tags' => $tags,
                'parameters' => [
                    self::param('variant_id', 'integer', 'Only this variant.'),
                    self::param('item_id', 'integer', 'Only this item\'s variants.'),
                    self::param('contact_id', 'integer', 'Only this supplier.'),
                ],
                'responses' => [200 => self::json('Supplier prices', self::list('InventorySupplierItem'))] + self::auth(),
            ]),
            self::route('PUT', '/api/inventory/supplier-items', 'inventory:write', [
                'summary' => 'Set what a supplier sells an item for',
                'description' => 'Adds it or changes it; one per item variant and supplier. The preferred one fills new purchase orders.',
                'tags' => $tags,
                'request' => 'InventorySupplierItemRequest',
                'responses' => [200 => self::json('The supplier price', self::data(SchemaBuilder::ref('InventorySupplierItem')))] + $refused + self::auth(),
            ]),
            self::route('DELETE', '/api/inventory/supplier-items/{id:\d+}', 'inventory:write', [
                'summary' => 'Remove a supplier price',
                'tags' => $tags,
                'responses' => [200 => self::json('Removed', self::data(self::object(['deleted' => self::bool()], ['deleted'])))] + $notFound + self::auth(),
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
                'source_line_id' => self::int(true),
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
                'batch_key' => self::str(true) + ['description' => 'Null while an adjustment waits for approval.'],
                'moves' => ['type' => 'array', 'items' => SchemaBuilder::ref('InventoryMove')],
                'repeated' => self::bool(),
                'operation' => SchemaBuilder::ref('InventoryOperation'),
            ], ['batch_key', 'moves', 'repeated']),
            'InventoryOperationLine' => self::object([
                'id' => self::int(),
                'variant_id' => self::int(),
                'item_id' => self::int(),
                'item_name' => self::str(),
                'item_names' => $names,
                'sku' => self::str(),
                'unit' => self::str(),
                'decimals' => self::int(),
                'entered_unit' => self::str(true),
                'entered_quantity' => self::str(true),
                'reason_code' => self::str(),
                'note' => self::str(),
                'value_minor' => self::int(true) + ['description' => 'Worth at the buying price, in `currency`; for a count, the difference.'],
                'currency' => self::str(true),
                'change' => $decimal('Adjustments: the signed change.') + ['nullable' => true],
                'quantity' => $decimal('Transfers: the amount sent.') + ['nullable' => true],
                'received' => $decimal('Transfers: the amount that arrived.') + ['nullable' => true],
                'counted' => $decimal('Counts: what was counted, null until counted.') + ['nullable' => true],
                'expected' => $decimal('Counts: what the stock said when the line was counted.') + ['nullable' => true],
                'difference' => $decimal('Counts: counted minus expected.') + ['nullable' => true],
                'counted_at' => self::str(true),
                'counted_by_profile_id' => self::int(true),
                'order_line_id' => self::int(true),
            ], ['id', 'variant_id', 'item_id', 'item_name', 'sku', 'unit', 'decimals']),
            'InventoryOperation' => self::object([
                'id' => self::int(),
                'kind' => self::str() + ['enum' => ['adjustment', 'count', 'transfer', 'receipt']],
                'number' => self::str(),
                'order_id' => self::int(true),
                'status' => self::str() + ['enum' => ['draft', 'waiting_approval', 'in_transit', 'done', 'cancelled']],
                'location_id' => self::int(true),
                'from_location_id' => self::int(true),
                'to_location_id' => self::int(true),
                'category_id' => self::int(true),
                'reason_code' => self::str(),
                'note' => self::str(),
                'is_blind' => self::bool(),
                'value_minor' => self::int(true),
                'currency' => self::str(true),
                'route_id' => self::int(true),
                'batch_key' => self::str(true),
                'sent_batch_key' => self::str(true),
                'source_plugin' => self::str(true),
                'created_by_profile_id' => self::int(true),
                'created_by_name' => self::str(true),
                'agent_id' => self::int(true),
                'on_behalf_of_profile_id' => self::int(true),
                'done_by_profile_id' => self::int(true),
                'done_by_name' => self::str(true),
                'occurred_at' => self::str(true),
                'submitted_at' => self::str(true),
                'sent_at' => self::str(true),
                'done_at' => self::str(true),
                'cancelled_at' => self::str(true),
                'created_at' => self::str(),
                'updated_at' => self::str(),
                'line_count' => self::int(),
                'counted_count' => self::int() + ['description' => 'Counts: lines counted so far.'],
                'lines' => ['type' => 'array', 'items' => SchemaBuilder::ref('InventoryOperationLine')],
            ], ['id', 'kind', 'number', 'status', 'created_at', 'updated_at']),
            'InventoryOperationAdjustmentRequest' => self::object([
                'location_id' => self::int() + ['description' => 'The place whose stock changes.'],
                'reason_code' => self::str() + ['description' => 'Why, from the workspace\'s reasons (GET /api/inventory/reasons), going the way the line goes.'],
                'note' => self::str() + ['description' => 'A note kept with the change.'],
                'occurred_at' => self::str() + ['description' => 'When it happened, if earlier than now (ISO 8601).'],
                'lines' => ['description' => 'One line per item: `{variant_id, change, unit, note, reason_code}`; a negative change takes stock out.', 'type' => 'array', 'minItems' => 1, 'maxItems' => 500, 'items' => self::object([
                    'variant_id' => self::int(),
                    'change' => $decimal('Signed: -2 takes two out.'),
                    'unit' => self::str() + ['description' => 'A unit code; the item\'s base unit when left out.'],
                    'note' => self::str(),
                    'reason_code' => self::str() + ['description' => 'This line\'s own reason, when it differs.'],
                ], ['variant_id', 'change'])],
                'idempotency_key' => self::str() + ['maxLength' => 128] + ['description' => 'Any unique text; sending the same key again changes nothing and answers the first result.'],
            ], ['location_id', 'reason_code', 'lines']),
            'InventoryCountRequest' => self::object([
                'location_id' => self::int() + ['description' => 'The place to count.'],
                'category_id' => self::int(true) + ['description' => 'Count only this category\'s items.'],
                'variant_ids' => ['type' => 'array', 'items' => self::int(), 'description' => 'Count only these items\' variants.'],
                'is_blind' => self::bool() + ['description' => 'True to hide what is expected from the people counting.'],
                'note' => self::str() + ['description' => 'A note kept with the count.'],
                'idempotency_key' => self::str() + ['maxLength' => 128] + ['description' => 'Any unique text; sending the same key again starts nothing new.'],
            ], ['location_id']),
            'InventoryCountLinesRequest' => self::object([
                'lines' => ['description' => 'What was counted: `{variant_id, counted, unit, note}`. An item not on the count is added.', 'type' => 'array', 'minItems' => 1, 'maxItems' => 500, 'items' => self::object([
                    'variant_id' => self::int(),
                    'counted' => $decimal('0 or more.'),
                    'unit' => self::str() + ['description' => 'A unit code; the item\'s base unit when left out.'],
                    'note' => self::str(),
                ], ['variant_id', 'counted'])],
            ], ['lines']),
            'InventoryTransferRequest' => self::object([
                'from_location_id' => self::int() + ['description' => 'The place it leaves.'],
                'to_location_id' => self::int() + ['description' => 'The place it goes to.'],
                'lines' => ['description' => 'What moves: `{variant_id, quantity, unit, note}`.', 'type' => 'array', 'minItems' => 1, 'maxItems' => 500, 'items' => self::object([
                    'variant_id' => self::int(),
                    'quantity' => $decimal('More than 0.'),
                    'unit' => self::str() + ['description' => 'A unit code; the item\'s base unit when left out.'],
                    'note' => self::str(),
                ], ['variant_id', 'quantity'])],
                'note' => self::str() + ['description' => 'A note kept with the transfer.'],
                'occurred_at' => self::str() + ['description' => 'When it left, if earlier than now (ISO 8601).'],
                'send' => self::bool() + ['description' => 'False to keep it as a draft; true (the default) sends it now.'],
                'one_step' => self::bool() + ['description' => 'True to have it arrive as it is sent, with no time on the way.'],
                'idempotency_key' => self::str() + ['maxLength' => 128] + ['description' => 'Any unique text; sending the same key again changes nothing and answers the first result.'],
            ], ['from_location_id', 'to_location_id', 'lines']),
            'InventoryTransferSendRequest' => self::object([
                'one_step' => self::bool() + ['description' => 'True to have it arrive as it is sent; the workspace\'s setting when left out.'],
            ], []),
            'InventoryReceiveRequest' => self::object([
                'lines' => ['description' => 'Only lines that arrived short: `{line_id, received}`. Every other line arrives in full.', 'type' => 'array', 'items' => self::object([
                    'line_id' => self::int(),
                    'received' => $decimal('Between 0 and what was sent, in the item\'s base unit.'),
                ], ['line_id', 'received'])],
            ], []),
            'InventoryReason' => self::object([
                'id' => self::int(),
                'code' => self::str(),
                'name' => self::str(),
                'names' => $names,
                'direction' => self::str() + ['enum' => ['out', 'in', 'both']],
                'is_waste' => self::bool(),
                'is_seeded' => self::bool(),
                'archived' => self::bool(),
            ], ['id', 'code', 'name', 'names', 'direction', 'is_waste', 'is_seeded', 'archived']),
            'InventoryReasonRequest' => self::object([
                'code' => self::str() + ['pattern' => '^[a-z][a-z0-9_]{0,39}$', 'description' => 'A short lower-case code, such as `tasting`; unique in the workspace.'],
                'name' => $name,
                'direction' => self::str() + ['enum' => ['out', 'in', 'both'], 'description' => 'Whether it takes stock out, brings it in, or both.'],
                'is_waste' => self::bool() + ['description' => 'True when it counts as waste in the waste figures.'],
            ], ['code', 'name', 'direction']),
            'InventoryReasonUpdateRequest' => self::object([
                'name' => $name,
                'direction' => self::str() + ['enum' => ['out', 'in', 'both'], 'description' => 'Your own reasons only; the ones Whity added keep theirs.'],
                'is_waste' => self::bool() + ['description' => 'True when it counts as waste.'],
                'archived' => self::bool() + ['description' => 'True to stop offering it; old changes keep it.'],
            ], []),
            'InventoryReorderRule' => self::object([
                'id' => self::int(),
                'item_id' => self::int(),
                'item_name' => self::str(),
                'item_names' => $names,
                'variant_id' => self::int(),
                'sku' => self::str(),
                'location_id' => self::int(),
                'unit' => self::str(),
                'decimals' => self::int(),
                'min_quantity' => $decimal('The least to keep.'),
                'order_up_to' => $decimal('What to order up to.') + ['nullable' => true],
                'supplier_contact_id' => self::int(true),
                'lead_days' => self::int(true),
                'is_active' => self::bool(),
                'is_below' => self::bool(),
                'below_since' => self::str(true),
                'available' => $decimal('Available now at the place.'),
                'suggested_quantity' => $decimal('How much to order, while under the level.') + ['nullable' => true],
            ], ['id', 'item_id', 'variant_id', 'location_id', 'min_quantity', 'is_active', 'is_below', 'available']),
            'InventoryReorderRuleRequest' => self::object([
                'variant_id' => self::int() + ['description' => 'The item variant.'],
                'location_id' => self::int() + ['description' => 'The place.'],
                'min_quantity' => $decimal('The reorder level: the least to keep, in the item\'s base unit.'),
                'order_up_to' => $decimal('What to order up to, or null to order back to the level.') + ['nullable' => true],
                'supplier_contact_id' => self::int(true) + ['description' => 'The usual supplier, a contact.'],
                'lead_days' => self::int(true) + ['description' => 'Days the supplier takes to deliver.'],
                'is_active' => self::bool() + ['description' => 'False to keep the level without watching it.'],
            ], ['variant_id', 'location_id', 'min_quantity']),
            'InventorySettings' => self::object([
                'approve_adjustments_above' => self::str(true) + ['description' => 'An adjustment line worth more than this waits for approval; null never asks.'],
                'approve_counts_above' => self::str(true) + ['description' => 'The same for a count\'s differences.'],
                'adjustment_route_template_id' => self::int(true),
                'count_route_template_id' => self::int(true),
                'transfers_one_step' => self::bool(),
                'purchase_route_template_id' => self::int(true),
                'over_receipt_percent' => self::int(),
            ], ['approve_adjustments_above', 'approve_counts_above', 'adjustment_route_template_id', 'count_route_template_id', 'transfers_one_step', 'purchase_route_template_id', 'over_receipt_percent']),
            'InventorySettingsRequest' => self::object([
                'approve_adjustments_above' => self::str(true) + ['description' => 'An amount such as 25, in each item\'s currency; null never asks.'],
                'approve_counts_above' => self::str(true) + ['description' => 'An amount for a count\'s differences; null never asks.'],
                'adjustment_route_template_id' => self::int(true) + ['description' => 'The route template adjustments travel; null for one step to everyone who runs inventory.'],
                'count_route_template_id' => self::int(true) + ['description' => 'The route template counts travel; null for the default.'],
                'transfers_one_step' => self::bool() + ['description' => 'True to have transfers arrive as they are sent.'],
                'purchase_route_template_id' => self::int(true) + ['description' => 'The route template purchase orders travel; null approves them on sending.'],
                'over_receipt_percent' => self::int() + ['description' => 'How far past the order a receipt may go before it asks, 0 to 100 (0: anything over asks).'],
            ], []),
            'InventoryPurchaseOrderLine' => self::object([
                'id' => self::int(),
                'variant_id' => self::int(),
                'item_id' => self::int(),
                'item_name' => self::str(),
                'item_names' => $names,
                'sku' => self::str(),
                'unit' => self::str(),
                'quantity' => $decimal('Ordered, in `unit`.'),
                'received' => $decimal('Received so far, in `unit`.'),
                'base_unit' => self::str(),
                'base_quantity' => $decimal('Ordered, in the item\'s base unit.'),
                'price_minor' => self::int(),
                'discount_bp' => self::int(),
                'tax_bp' => self::int(),
                'line_total_minor' => self::int() + ['description' => 'Quantity times price, less the discount; tax not included.'],
                'is_closed' => self::bool(),
                'is_over' => self::bool() + ['description' => 'More arrived than ordered.'],
                'note' => self::str(),
            ], ['id', 'variant_id', 'item_id', 'item_name', 'unit', 'quantity', 'received', 'price_minor', 'line_total_minor', 'is_closed']),
            'InventoryPurchaseOrder' => self::object([
                'id' => self::int(),
                'kind' => self::str(),
                'number' => self::str(),
                'status' => self::str() + ['enum' => ['draft', 'waiting_approval', 'approved', 'partly_done', 'done', 'cancelled']],
                'contact_id' => self::int(),
                'contact_name' => self::str(),
                'location_id' => self::int(),
                'expected_on' => self::str(true),
                'currency' => self::str(),
                'subtotal_minor' => self::int(),
                'tax_minor' => self::int(),
                'total_minor' => self::int(),
                'notes' => self::str(),
                'external_ref' => self::str(),
                'route_id' => self::int(true),
                'document_id' => self::int(true),
                'source_plugin' => self::str(true),
                'created_by_profile_id' => self::int(true),
                'created_by_name' => self::str(true),
                'agent_id' => self::int(true),
                'on_behalf_of_profile_id' => self::int(true),
                'approved_by_profile_id' => self::int(true),
                'approved_by_name' => self::str(true),
                'submitted_at' => self::str(true),
                'approved_at' => self::str(true),
                'done_at' => self::str(true),
                'cancelled_at' => self::str(true),
                'created_at' => self::str(),
                'updated_at' => self::str(),
                'line_count' => self::int(),
                'received_percent' => self::int(),
                'lines' => ['type' => 'array', 'items' => SchemaBuilder::ref('InventoryPurchaseOrderLine')],
                'receipts' => ['type' => 'array', 'items' => self::object([
                    'id' => self::int(), 'number' => self::str(), 'status' => self::str(), 'batch_key' => self::str(true),
                    'done_at' => self::str(true), 'created_by_name' => self::str(true), 'line_count' => self::int(),
                ], ['id', 'number'])],
            ], ['id', 'kind', 'number', 'status', 'contact_id', 'contact_name', 'location_id', 'currency', 'subtotal_minor', 'tax_minor', 'total_minor', 'notes', 'created_at', 'updated_at']),
            'InventoryPurchaseOrderRequest' => self::object([
                'contact_id' => self::int() + ['description' => 'The supplier: a contact marked as a supplier.'],
                'location_id' => self::int() + ['description' => 'The place it is received into.'],
                'expected_on' => self::str(true) + ['description' => 'When it should arrive, such as 2026-11-26.'],
                'currency' => self::str() + ['description' => 'A 3-letter code; the workspace\'s currency when left out.'],
                'notes' => self::str() + ['description' => 'Notes for the supplier and the people receiving it.'],
                'external_ref' => self::str() + ['description' => 'The supplier\'s or another system\'s reference.'],
                'lines' => ['description' => 'What is ordered: `{variant_id, quantity, unit, price_minor, discount_bp, tax_bp}`.', 'type' => 'array', 'minItems' => 1, 'maxItems' => 500, 'items' => self::object([
                    'variant_id' => self::int(),
                    'quantity' => $decimal('How many, in `unit`.'),
                    'unit' => self::str() + ['description' => 'The unit it is bought in, such as `bag`; the item\'s base unit when left out.'],
                    'price_minor' => self::int() + ['description' => 'The price of one `unit` in minor units; what the supplier sells it for when left out.'],
                    'discount_bp' => self::int() + ['description' => 'Discount in basis points (500 is 5%).'],
                    'tax_bp' => self::int() + ['description' => 'Tax in basis points, on top.'],
                    'note' => self::str(),
                ], ['variant_id', 'quantity'])],
                'idempotency_key' => self::str() + ['maxLength' => 128] + ['description' => 'Any unique text; sending the same key again drafts nothing new.'],
            ], ['contact_id', 'location_id', 'lines']),
            'InventoryPurchaseOrderUpdateRequest' => self::object([
                'contact_id' => self::int() + ['description' => 'The supplier: a contact marked as a supplier.'],
                'location_id' => self::int() + ['description' => 'The place it is received into.'],
                'expected_on' => self::str(true) + ['description' => 'When it should arrive.'],
                'currency' => self::str() + ['description' => 'A 3-letter code.'],
                'notes' => self::str() + ['description' => 'Notes for the supplier and the people receiving it.'],
                'external_ref' => self::str() + ['description' => 'The supplier\'s or another system\'s reference.'],
                'lines' => ['description' => 'Every line, replacing the old ones.', 'type' => 'array', 'minItems' => 1, 'maxItems' => 500, 'items' => self::object([
                    'variant_id' => self::int(),
                    'quantity' => $decimal('How many, in `unit`.'),
                    'unit' => self::str() + ['description' => 'The unit it is bought in, such as `bag`; the item\'s base unit when left out.'],
                    'price_minor' => self::int() + ['description' => 'The price of one `unit` in minor units; what the supplier sells it for when left out.'],
                    'discount_bp' => self::int() + ['description' => 'Discount in basis points (500 is 5%).'],
                    'tax_bp' => self::int() + ['description' => 'Tax in basis points, on top.'],
                    'note' => self::str(),
                ], ['variant_id', 'quantity'])],
            ], []),
            'InventoryPrintRequest' => self::object([
                'lang' => ['type' => 'string', 'enum' => ['en', 'ar'], 'description' => 'The template\'s language. English when left out.'],
            ], []),
            'InventoryPrinted' => self::object([
                'document_id' => self::int(),
                'number' => self::str() + ['nullable' => true],
                'title' => self::str(),
                'pdf' => self::bool(),
            ], ['document_id', 'number', 'title', 'pdf']),
            'InventoryReceiveOrderRequest' => self::object([
                'lines' => ['description' => 'What arrived: `{line_id, quantity, unit, note}`, in the line\'s unit unless `unit` says.', 'type' => 'array', 'minItems' => 1, 'maxItems' => 500, 'items' => self::object([
                    'line_id' => self::int(),
                    'quantity' => $decimal('How many arrived.'),
                    'unit' => self::str() + ['description' => 'The unit counted in; the line\'s unit when left out.'],
                    'note' => self::str(),
                ], ['line_id', 'quantity'])],
                'allow_over' => self::bool() + ['description' => 'True to receive more than ordered past the workspace\'s tolerance.'],
                'note' => self::str() + ['description' => 'A note kept with the receipt, such as the delivery note number.'],
                'occurred_at' => self::str() + ['description' => 'When it arrived, if earlier than now (ISO 8601).'],
                'idempotency_key' => self::str() + ['maxLength' => 128] + ['description' => 'Any unique text; sending the same key again receives nothing twice.'],
            ], ['lines']),
            'InventorySupplierItem' => self::object([
                'id' => self::int(),
                'variant_id' => self::int(),
                'item_id' => self::int(),
                'item_name' => self::str(),
                'item_names' => $names,
                'sku' => self::str(),
                'contact_id' => self::int(),
                'contact_name' => self::str(),
                'supplier_sku' => self::str(),
                'unit' => self::str(),
                'price_minor' => self::int(),
                'currency' => self::str(),
                'min_quantity' => self::str(true),
                'lead_days' => self::int(true),
                'is_preferred' => self::bool(),
                'updated_at' => self::str(),
            ], ['id', 'variant_id', 'item_id', 'item_name', 'item_names', 'sku', 'contact_id', 'contact_name', 'supplier_sku', 'unit', 'price_minor', 'currency', 'is_preferred', 'updated_at']),
            'InventorySupplierItemRequest' => self::object([
                'variant_id' => self::int() + ['description' => 'The item variant.'],
                'contact_id' => self::int() + ['description' => 'The supplier: a contact marked as a supplier.'],
                'unit' => self::str() + ['description' => 'The unit they sell it in, such as `bag`; the item\'s base unit when left out.'],
                'price_minor' => self::int() + ['description' => 'The price of one `unit`, in minor units.'],
                'currency' => self::str() + ['description' => 'A 3-letter code; the workspace\'s currency when left out.'],
                'supplier_sku' => self::str() + ['description' => 'The supplier\'s own code for it.'],
                'min_quantity' => $decimal('The least they take, in `unit`.') + ['nullable' => true],
                'lead_days' => self::int(true) + ['description' => 'Days they take to deliver.'],
                'is_preferred' => self::bool() + ['description' => 'True to have new purchase orders use this supplier\'s price.'],
            ], ['variant_id', 'contact_id', 'price_minor']),
            'InventoryAdjustmentRequest' => self::object([
                'kind' => self::str() + ['enum' => ['opening', 'adjustment']] + ['description' => '`opening` to set starting stock; `adjustment` (the default) to change stock with a reason.'],
                'lines' => ['description' => 'One line per item and place: `{variant_id, location_id, change, unit}`.', 'type' => 'array', 'minItems' => 1, 'maxItems' => 500, 'items' => self::object([
                    'variant_id' => self::int(),
                    'location_id' => self::int(),
                    'change' => $decimal('Opening: a positive amount. Adjustment: signed, -2 takes two out.'),
                    'unit' => self::str() + ['description' => 'A unit code; the item\'s base unit when left out.'],
                    'note' => self::str(),
                ], ['variant_id', 'location_id', 'change'])],
                'reason_code' => self::str() + ['description' => 'For an adjustment: why, from the workspace\'s reasons (GET /api/inventory/reasons), such as expired, damaged, spoiled, unsold or found. One place per adjustment.'],
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
