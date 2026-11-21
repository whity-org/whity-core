<?php

declare(strict_types=1);

namespace Whity\Core\Inventory;

use Whity\Core\Contacts\ContactRejectedException;
use Whity\Core\CustomFields\CustomFieldService;
use Whity\Core\Db\DbBool;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Text\SearchText;
use Whity\Sdk\Hooks\Events;
use Whity\Sdk\Inventory\Quantity;

/**
 * Items, their variants, units and barcodes, and categories (WHIT-1232,
 * register IV.4–IV.6, IV.24).
 *
 * TENANT-OWNED: every statement binds `tenant_id`; an id from another
 * workspace finds nothing and answers 404.
 *
 * The plan's item limit (`inventory.items.max`, Free: 100) counts live
 * variants, so a T-shirt in six sizes is six. It only warns (register IV.24,
 * SC.14): items are always added, and Billing and the plan banner show the
 * count past the limit ({@see \Whity\Core\Usage\PlanLimitsReport}). Nothing
 * here reads the limit.
 */
final class ItemService
{
    public const RECORD_TYPE = 'inventory.item';
    public const KINDS = ['stocked', 'consumable', 'service', 'made'];
    public const MAX_VARIANTS = 100;
    public const MAX_OPTION_AXES = 3;
    public const MAX_BARCODES = 20;
    public const MAX_PER_PAGE = 200;

    /** The keys an item already uses, which the workspace's own fields may not take. */
    public const RESERVED_KEYS = ['id', 'name', 'names', 'description', 'kind', 'category', 'category_id', 'unit', 'base_unit',
        'sku', 'barcode', 'barcodes', 'variants', 'units', 'tracking', 'expires', 'price', 'sale_price', 'purchase_price',
        'currency', 'tax_category', 'stock', 'fields', 'archived'];

    private const ITEM_COLUMNS = 'id, name, names, description, kind, category_id, base_unit_id, tracking, expires, shelf_life_days,
        sale_price_minor, purchase_price_minor, currency, tax_category, image_file_id, owner_plugin, archived_at, created_at, updated_at';

    /**
     * @param \Closure(int): string $defaultCurrency the workspace's currency
     */
    public function __construct(
        private readonly InventoryDb $db,
        private readonly InventoryWorkspace $workspace,
        private readonly UnitCatalog $units,
        private readonly \Closure $defaultCurrency,
        private readonly ?CustomFieldService $customFields = null,
        private readonly ?HookManager $hooks = null,
    ) {
    }

    /** @var (\Closure(int, int, ?int): void)|null puts an item's stock without a batch into the batch OPENING */
    private ?\Closure $openingLots = null;

    /**
     * How turning batches on for an item with stock moves that stock into
     * the batch OPENING (WHIT-1235, design 31 §3.5); the ledger provides it.
     *
     * @param \Closure(int, int, ?int): void $openingLots (tenant, item, actor)
     */
    public function useOpeningLots(\Closure $openingLots): void
    {
        $this->openingLots = $openingLots;
    }

    // ── reading ──────────────────────────────────────────────────────────

    /**
     * @param array{q?: ?string, kind?: ?string, category_id?: ?int, archived?: bool} $filter
     * @return array{data: list<array<string, mixed>>, total: int}
     */
    public function list(int $tenantId, array $filter, int $limit, int $offset): array
    {
        $this->workspace->ensure($tenantId);
        $where = [($filter['archived'] ?? false) ? 'i.archived_at IS NOT NULL' : 'i.archived_at IS NULL'];
        $params = [':tenant_id' => $tenantId];
        $kind = $filter['kind'] ?? null;
        if ($kind !== null) {
            if (!in_array($kind, self::KINDS, true)) {
                throw InventoryRejectedException::field('kind', 'kind must be one of ' . implode(', ', self::KINDS));
            }
            $where[] = 'i.kind = :kind';
            $params[':kind'] = $kind;
        }
        if (($filter['category_id'] ?? null) !== null) {
            $where[] = 'i.category_id = :category_id';
            $params[':category_id'] = (int) $filter['category_id'];
        }
        $q = trim((string) ($filter['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(' . SearchText::sqlFold('i.names') . " LIKE :pattern ESCAPE '\\'
                OR EXISTS (SELECT 1 FROM inv_variants v WHERE v.tenant_id = i.tenant_id AND v.item_id = i.id
                           AND (LOWER(v.sku) LIKE :pattern ESCAPE '\\'
                                OR EXISTS (SELECT 1 FROM inv_barcodes b WHERE b.tenant_id = v.tenant_id AND b.variant_id = v.id AND b.code = :code))))";
            $params[':pattern'] = SearchText::containsPattern(SearchText::fold($q));
            $params[':code'] = self::barcodeText($q);
        }
        $sqlWhere = implode(' AND ', $where);
        $total = (int) $this->db->run('SELECT COUNT(*) FROM inv_items i WHERE i.tenant_id = :tenant_id AND ' . $sqlWhere, $params)->fetchColumn();
        $rows = $this->db->all(
            'SELECT ' . self::prefixed('i') . ' FROM inv_items i WHERE i.tenant_id = :tenant_id AND ' . $sqlWhere . ' ORDER BY i.name, i.id LIMIT :limit OFFSET :offset',
            $params + [':limit' => max(1, min(self::MAX_PER_PAGE, $limit)), ':offset' => max(0, $offset)]
        );

        return ['data' => $this->presentMany($tenantId, $rows), 'total' => $total];
    }

    /** @return array<string, mixed> */
    public function show(int $tenantId, int $itemId): array
    {
        $row = $this->row($tenantId, $itemId) ?? throw InventoryRejectedException::notFound('Item');

        return $this->presentMany($tenantId, [$row])[0];
    }

    /** The workspace's currency, for prices given without one (CU.1). */
    public function currencyFor(int $tenantId): string
    {
        return ($this->defaultCurrency)($tenantId);
    }

    /** @return array<string, mixed>|null */
    public function row(int $tenantId, int $itemId): ?array
    {
        return $this->db->one(
            'SELECT ' . self::ITEM_COLUMNS . ' FROM inv_items WHERE tenant_id = :tenant_id AND id = :id',
            [':tenant_id' => $tenantId, ':id' => $itemId]
        );
    }

    /**
     * The variant, its item and the item's base unit, for posting stock.
     *
     * @return array{variant: array<string, mixed>, item: array<string, mixed>, unit: array<string, mixed>}|null
     */
    public function variantForStock(int $tenantId, int $variantId): ?array
    {
        $variant = $this->db->one(
            'SELECT id, item_id, sku, archived_at FROM inv_variants WHERE tenant_id = :tenant_id AND id = :id',
            [':tenant_id' => $tenantId, ':id' => $variantId]
        );
        if ($variant === null) {
            return null;
        }
        $item = $this->row($tenantId, (int) $variant['item_id']);
        $unit = $item === null ? null : $this->units->row($tenantId, (int) $item['base_unit_id']);

        return $item === null || $unit === null ? null : ['variant' => $variant, 'item' => $item, 'unit' => $unit];
    }

    /** The item id one of whose variants has this SKU. */
    public function itemIdBySku(int $tenantId, string $sku): ?int
    {
        return InventoryDb::int($this->db->one(
            'SELECT item_id FROM inv_variants WHERE tenant_id = :tenant_id AND sku = :sku',
            [':tenant_id' => $tenantId, ':sku' => trim($sku)]
        )['item_id'] ?? null);
    }

    /**
     * What a scanned code means: a barcode first, then a SKU.
     *
     * @return array{item: array<string, mixed>, variant_id: int, unit: string}|null
     */
    public function lookup(int $tenantId, string $code): ?array
    {
        $code = self::barcodeText($code);
        if ($code === '') {
            return null;
        }
        $hit = $this->db->one(
            'SELECT v.id AS variant_id, v.item_id, u.code AS unit_code
               FROM inv_barcodes b
               JOIN inv_variants v ON v.tenant_id = b.tenant_id AND v.id = b.variant_id
               LEFT JOIN inv_units u ON u.tenant_id = b.tenant_id AND u.id = b.unit_id
              WHERE b.tenant_id = :tenant_id AND b.code = :code',
            [':tenant_id' => $tenantId, ':code' => $code]
        ) ?? $this->db->one(
            'SELECT v.id AS variant_id, v.item_id, NULL AS unit_code FROM inv_variants v WHERE v.tenant_id = :tenant_id AND v.sku = :code',
            [':tenant_id' => $tenantId, ':code' => $code]
        );
        if ($hit === null) {
            return null;
        }
        $item = $this->show($tenantId, (int) $hit['item_id']);

        return [
            'item' => $item,
            'variant_id' => (int) $hit['variant_id'],
            'unit' => is_string($hit['unit_code']) ? $hit['unit_code'] : (string) $item['base_unit']['code'],
        ];
    }

    /** Live variants in the workspace, what `inventory.items.max` counts. */
    public function countLiveVariants(int $tenantId): int
    {
        return (int) $this->db->run(
            'SELECT COUNT(*) FROM inv_variants v
               JOIN inv_items i ON i.tenant_id = v.tenant_id AND i.id = v.item_id
              WHERE v.tenant_id = :tenant_id AND v.archived_at IS NULL AND i.archived_at IS NULL',
            [':tenant_id' => $tenantId]
        )->fetchColumn();
    }

    // ── writing ──────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $input
     * @param (callable(string): bool)|null $hasPermission the caller's permissions, for fields with their own
     * @return array<string, mixed>
     */
    public function create(int $tenantId, array $input, ?int $actor, ?callable $hasPermission = null, ?string $ownerPlugin = null): array
    {
        $this->workspace->ensure($tenantId);
        $names = InventoryNames::normalize($input['name'] ?? null, 'name', 200);
        $kind = $this->kind($input['kind'] ?? 'stocked');
        $unitCode = is_string($input['unit'] ?? null) && $input['unit'] !== '' ? $input['unit'] : 'pc';
        $unit = $this->units->rowByCode($tenantId, $unitCode);
        if ($unit === null || $unit['archived_at'] !== null) {
            throw InventoryRejectedException::field('unit', 'There is no unit "' . $unitCode . '"');
        }
        $common = $this->commonFields($tenantId, $input, null);
        $variants = $this->variantInputs($input, null);

        $id = $this->db->transaction(function () use ($tenantId, $input, $actor, $names, $kind, $unit, $common, $variants, $hasPermission, $ownerPlugin): int {
            $id = $this->db->insert(
                'INSERT INTO inv_items (tenant_id, name, names, description, kind, category_id, base_unit_id, tracking, expires,
                        shelf_life_days, sale_price_minor, purchase_price_minor, currency, tax_category, owner_plugin, created_by_profile_id)
                 VALUES (:tenant_id, :name, :names, :description, :kind, :category_id, :unit_id, :tracking, :expires,
                        :shelf_life_days, :sale_price, :purchase_price, :currency, :tax_category, :owner_plugin, :actor)',
                [
                    ':tenant_id' => $tenantId, ':name' => $names['name'], ':names' => $names['names'],
                    ':description' => $common['description'] ?? '', ':kind' => $kind, ':category_id' => $common['category_id'] ?? null,
                    ':unit_id' => (int) $unit['id'], ':tracking' => $common['tracking'] ?? 'none', ':expires' => DbBool::of($common['expires'] ?? false),
                    ':shelf_life_days' => $common['shelf_life_days'] ?? null, ':sale_price' => $common['sale_price_minor'] ?? null,
                    ':purchase_price' => $common['purchase_price_minor'] ?? null, ':currency' => $common['currency'] ?? null,
                    ':tax_category' => $common['tax_category'] ?? '', ':owner_plugin' => $ownerPlugin, ':actor' => $actor,
                ]
            );
            foreach ($variants as $index => $variant) {
                $this->insertVariant($tenantId, $id, $variant, $index, count($variants));
            }
            if (array_key_exists('units', $input)) {
                $this->replaceItemUnits($tenantId, $id, $unit, $input['units']);
            }
            $this->customFields?->write($tenantId, self::RECORD_TYPE, $id, $input['fields'] ?? [], $hasPermission ?? static fn (): bool => false, true);

            return $id;
        });

        $item = $this->show($tenantId, $id);
        $this->dispatch(Events::INVENTORY_ITEM_CREATED, [
            'tenant_id' => $tenantId, 'item_id' => $id,
            'variant_ids' => array_map(static fn (array $v): int => (int) $v['id'], $item['variants']),
            'acting_profile_id' => $actor,
        ]);

        return $item;
    }

    /**
     * Fields left out keep their value. `variants`, when sent, is the whole
     * list: entries with an `id` change that variant, entries without one are
     * added, and variants left out are archived (their history stays).
     *
     * @param array<string, mixed> $input
     * @param (callable(string): bool)|null $hasPermission
     * @return array<string, mixed>
     */
    public function update(int $tenantId, int $itemId, array $input, ?int $actor, ?callable $hasPermission = null): array
    {
        $row = $this->row($tenantId, $itemId) ?? throw InventoryRejectedException::notFound('Item');
        $sets = [];
        $params = [':tenant_id' => $tenantId, ':id' => $itemId];
        if (array_key_exists('name', $input)) {
            $names = InventoryNames::normalize($input['name'], 'name', 200);
            $sets[] = 'name = :name';
            $sets[] = 'names = :names';
            $params[':name'] = $names['name'];
            $params[':names'] = $names['names'];
        }
        if (array_key_exists('kind', $input)) {
            $sets[] = 'kind = :kind';
            $params[':kind'] = $this->kind($input['kind']);
        }
        if (array_key_exists('unit', $input)) {
            $unit = is_string($input['unit']) ? $this->units->rowByCode($tenantId, $input['unit']) : null;
            if ($unit === null || $unit['archived_at'] !== null) {
                throw InventoryRejectedException::field('unit', 'There is no unit "' . (is_string($input['unit']) ? $input['unit'] : '') . '"');
            }
            if ((int) $unit['id'] !== (int) $row['base_unit_id']) {
                if ($this->hasMoves($tenantId, $itemId)) {
                    throw new InventoryRejectedException('The unit can\'t change once the item has stock history. Add the new unit under units instead.', 409, ['field' => 'unit']);
                }
                $sets[] = 'base_unit_id = :unit_id';
                $params[':unit_id'] = (int) $unit['id'];
            }
        }
        foreach ($this->commonFields($tenantId, $input, $row) as $column => $value) {
            $sets[] = $column . ' = :' . $column;
            $params[':' . $column] = $value;
        }
        $variants = array_key_exists('variants', $input) ? $this->variantInputs($input, $itemId) : null;

        $this->db->transaction(function () use ($tenantId, $itemId, $sets, $params, $variants, $input, $hasPermission, $row, $actor): void {
            if ($sets !== []) {
                $sets[] = 'updated_at = CURRENT_TIMESTAMP';
                $this->db->run('UPDATE inv_items SET ' . implode(', ', $sets) . ' WHERE tenant_id = :tenant_id AND id = :id', $params);
            }
            if (($params[':tracking'] ?? null) === 'lot' && $row['tracking'] === 'none' && $this->hasStock($tenantId, $itemId)) {
                // Stock already here goes into the batch OPENING, now that the item keeps batches.
                ($this->openingLots ?? throw new \LogicException('Opening lots need the ledger'))($tenantId, $itemId, $actor);
            }
            if ($variants !== null) {
                $this->syncVariants($tenantId, $itemId, $variants);
            }
            if (array_key_exists('units', $input)) {
                $item = $this->row($tenantId, $itemId);
                $unit = $item === null ? null : $this->units->row($tenantId, (int) $item['base_unit_id']);
                if ($unit !== null) {
                    $this->replaceItemUnits($tenantId, $itemId, $unit, $input['units']);
                }
            }
            if (array_key_exists('fields', $input)) {
                $this->customFields?->write($tenantId, self::RECORD_TYPE, $itemId, $input['fields'], $hasPermission ?? static fn (): bool => false, false);
            }
        });

        $this->dispatch(Events::INVENTORY_ITEM_UPDATED, [
            'tenant_id' => $tenantId, 'item_id' => $itemId,
            'changed' => array_values(array_intersect(array_keys($input), ['name', 'kind', 'unit', 'description', 'category_id', 'expires',
                'shelf_life_days', 'sale_price_minor', 'purchase_price_minor', 'currency', 'tax_category', 'variants', 'units', 'fields'])),
            'acting_profile_id' => $actor,
        ]);

        return $this->show($tenantId, $itemId);
    }

    /** @return array<string, mixed> */
    public function archive(int $tenantId, int $itemId, bool $archived, ?int $actor): array
    {
        $row = $this->row($tenantId, $itemId) ?? throw InventoryRejectedException::notFound('Item');
        $this->db->run(
            'UPDATE inv_items SET archived_at = ' . ($archived ? 'CURRENT_TIMESTAMP' : 'NULL') . ', updated_at = CURRENT_TIMESTAMP
              WHERE tenant_id = :tenant_id AND id = :id',
            [':tenant_id' => $tenantId, ':id' => $itemId]
        );
        if ($archived) {
            $this->dispatch(Events::INVENTORY_ITEM_ARCHIVED, ['tenant_id' => $tenantId, 'item_id' => $itemId, 'acting_profile_id' => $actor]);
        }

        return $this->show($tenantId, $itemId);
    }

    // ── categories ───────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    public function categories(int $tenantId): array
    {
        $rows = $this->db->all(
            'SELECT c.id, c.parent_id, c.name, c.names,
                    (SELECT COUNT(*) FROM inv_items i WHERE i.tenant_id = c.tenant_id AND i.category_id = c.id AND i.archived_at IS NULL) AS item_count
               FROM inv_categories c WHERE c.tenant_id = :tenant_id AND c.archived_at IS NULL ORDER BY c.name, c.id',
            [':tenant_id' => $tenantId]
        );

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'parent_id' => InventoryDb::int($r['parent_id']),
            'name' => (string) $r['name'],
            'names' => InventoryNames::decode($r['names'], (string) $r['name']),
            'item_count' => (int) $r['item_count'],
        ], $rows);
    }

    /**
     * @param array<string, mixed> $input `name`, `parent_id`
     * @return array<string, mixed>
     */
    public function createCategory(int $tenantId, array $input): array
    {
        $names = InventoryNames::normalize($input['name'] ?? null, 'name', 100);
        $parent = $input['parent_id'] ?? null;
        if ($parent !== null && (!is_int($parent) || !$this->categoryExists($tenantId, $parent))) {
            throw InventoryRejectedException::field('parent_id', 'parent_id is not a category here');
        }
        $id = $this->db->insert(
            'INSERT INTO inv_categories (tenant_id, parent_id, name, names) VALUES (:tenant_id, :parent_id, :name, :names)',
            [':tenant_id' => $tenantId, ':parent_id' => $parent, ':name' => $names['name'], ':names' => $names['names']]
        );

        foreach ($this->categories($tenantId) as $category) {
            if ($category['id'] === $id) {
                return $category;
            }
        }

        throw new \RuntimeException('Category ' . $id . ' vanished');
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function presentMany(int $tenantId, array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $in = self::inList($ids, 'i');
        $variants = [];
        foreach ($this->db->all(
            'SELECT id, item_id, sku, options, sale_price_minor, purchase_price_minor, archived_at
               FROM inv_variants WHERE tenant_id = :tenant_id AND item_id IN (' . $in['sql'] . ') ORDER BY sort_order, id',
            [':tenant_id' => $tenantId] + $in['params']
        ) as $v) {
            $variants[(int) $v['item_id']][(int) $v['id']] = $v;
        }
        $barcodes = [];
        foreach ($this->db->all(
            'SELECT b.variant_id, b.code, b.symbology, u.code AS unit_code
               FROM inv_barcodes b
               JOIN inv_variants v ON v.tenant_id = b.tenant_id AND v.id = b.variant_id
               LEFT JOIN inv_units u ON u.tenant_id = b.tenant_id AND u.id = b.unit_id
              WHERE b.tenant_id = :tenant_id AND v.item_id IN (' . $in['sql'] . ') ORDER BY b.id',
            [':tenant_id' => $tenantId] + $in['params']
        ) as $b) {
            $barcodes[(int) $b['variant_id']][] = ['code' => (string) $b['code'], 'unit' => is_string($b['unit_code']) ? $b['unit_code'] : null, 'symbology' => (string) $b['symbology']];
        }
        $itemUnits = [];
        foreach ($this->db->all(
            'SELECT iu.item_id, iu.num, iu.den, iu.purpose, iu.is_default, u.code
               FROM inv_item_units iu JOIN inv_units u ON u.tenant_id = iu.tenant_id AND u.id = iu.unit_id
              WHERE iu.tenant_id = :tenant_id AND iu.item_id IN (' . $in['sql'] . ') ORDER BY iu.id',
            [':tenant_id' => $tenantId] + $in['params']
        ) as $u) {
            $itemUnits[(int) $u['item_id']][] = [
                'unit' => (string) $u['code'],
                'per' => UnitCatalog::fractionText((int) $u['num'], (int) $u['den']),
                'purpose' => (string) $u['purpose'],
                'is_default' => DbBool::of($u['is_default']),
            ];
        }
        $onHand = [];
        foreach ($this->db->all(
            "SELECT v.item_id, l.variant_id, SUM(l.on_hand) AS on_hand
               FROM inv_levels l
               JOIN inv_variants v ON v.tenant_id = l.tenant_id AND v.id = l.variant_id
              WHERE l.tenant_id = :tenant_id AND v.item_id IN (" . $in['sql'] . ')
              GROUP BY v.item_id, l.variant_id',
            [':tenant_id' => $tenantId] + $in['params']
        ) as $l) {
            $onHand[(int) $l['item_id']][(int) $l['variant_id']] = (int) $l['on_hand'];
        }
        $units = [];
        foreach ($this->units->list($tenantId, true) as $unit) {
            $units[$unit['id']] = $unit;
        }
        $fields = $this->customFields?->values($tenantId, self::RECORD_TYPE, $ids) ?? [];

        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $unit = $units[(int) $row['base_unit_id']] ?? UnitCatalog::present([]);
            $decimals = (int) $unit['decimals'];
            $total = 0;
            $variantList = [];
            foreach ($variants[$id] ?? [] as $variantId => $v) {
                $steps = $onHand[$id][$variantId] ?? 0;
                if ($v['archived_at'] === null) {
                    $total += $steps;
                }
                $variantList[] = [
                    'id' => $variantId,
                    'item_id' => $id,
                    'sku' => (string) $v['sku'],
                    'options' => self::decodeMap($v['options']),
                    'barcodes' => $barcodes[$variantId] ?? [],
                    'sale_price_minor' => InventoryDb::int($v['sale_price_minor']),
                    'purchase_price_minor' => InventoryDb::int($v['purchase_price_minor']),
                    'on_hand' => (new Quantity($steps, $decimals))->toDecimal(),
                    'archived' => $v['archived_at'] !== null,
                ];
            }
            $out[] = [
                'id' => $id,
                'name' => (string) $row['name'],
                'names' => InventoryNames::decode($row['names'], (string) $row['name']),
                'description' => (string) $row['description'],
                'kind' => (string) $row['kind'],
                'category_id' => InventoryDb::int($row['category_id']),
                'base_unit' => $unit,
                'tracking' => (string) $row['tracking'],
                'expires' => DbBool::of($row['expires']),
                'shelf_life_days' => InventoryDb::int($row['shelf_life_days']),
                'sale_price_minor' => InventoryDb::int($row['sale_price_minor']),
                'purchase_price_minor' => InventoryDb::int($row['purchase_price_minor']),
                'currency' => is_string($row['currency']) ? $row['currency'] : null,
                'tax_category' => (string) $row['tax_category'],
                'owner_plugin' => is_string($row['owner_plugin']) ? $row['owner_plugin'] : null,
                'variants' => $variantList,
                'units' => $itemUnits[$id] ?? [],
                'on_hand' => (new Quantity($total, $decimals))->toDecimal(),
                'fields' => (object) ($fields[$id] ?? []),
                'archived' => $row['archived_at'] !== null,
                'created_at' => (string) $row['created_at'],
                'updated_at' => (string) $row['updated_at'],
            ];
        }

        return $out;
    }

    /**
     * The validated values every item may carry, keyed by column.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $row the item, when updating
     * @return array<string, mixed>
     */
    private function commonFields(int $tenantId, array $input, ?array $row): array
    {
        $out = [];
        if (array_key_exists('description', $input)) {
            $text = is_string($input['description']) ? trim($input['description']) : '';
            if (mb_strlen($text) > 4000) {
                throw InventoryRejectedException::field('description', 'description must be 4000 characters or fewer');
            }
            $out['description'] = $text;
        }
        if (array_key_exists('category_id', $input)) {
            $category = $input['category_id'];
            if ($category !== null && (!is_int($category) || !$this->categoryExists($tenantId, $category))) {
                throw InventoryRejectedException::field('category_id', 'category_id is not a category here');
            }
            $out['category_id'] = $category;
        }
        if (array_key_exists('tracking', $input)) {
            if (!in_array($input['tracking'], ['none', 'lot', 'serial'], true)) {
                throw InventoryRejectedException::field('tracking', 'tracking is none, lot (batches) or serial (serial numbers)');
            }
            if ($row !== null && $input['tracking'] !== $row['tracking']) {
                $this->trackingCanChange($tenantId, $row, (string) $input['tracking']);
            }
            $out['tracking'] = $input['tracking'];
        }
        if (array_key_exists('expires', $input)) {
            $out['expires'] = self::flag($input['expires'], 'expires');
        }
        if (array_key_exists('shelf_life_days', $input)) {
            $days = $input['shelf_life_days'];
            if ($days !== null && (!is_int($days) || $days < 1 || $days > 36500)) {
                throw InventoryRejectedException::field('shelf_life_days', 'shelf_life_days must be a whole number of days from 1');
            }
            $out['shelf_life_days'] = $days;
        }
        $priced = false;
        foreach (['sale_price_minor', 'purchase_price_minor'] as $key) {
            if (array_key_exists($key, $input)) {
                $out[$key] = self::price($input[$key], $key);
                $priced = $priced || $out[$key] !== null;
            }
        }
        if (array_key_exists('currency', $input) || ($priced && ($row['currency'] ?? null) === null)) {
            $currency = $input['currency'] ?? null;
            if ($currency === null && $priced) {
                $currency = ($this->defaultCurrency)($tenantId);
            }
            if ($currency !== null && (!is_string($currency) || preg_match('/^[A-Z]{3}$/', $currency) !== 1)) {
                throw InventoryRejectedException::field('currency', 'currency is a 3-letter code such as USD or JOD');
            }
            $out['currency'] = $currency;
        }
        if (array_key_exists('tax_category', $input)) {
            $tax = is_string($input['tax_category']) ? trim($input['tax_category']) : '';
            if (mb_strlen($tax) > 40) {
                throw InventoryRejectedException::field('tax_category', 'tax_category must be 40 characters or fewer');
            }
            $out['tax_category'] = $tax;
        }

        return $out;
    }

    /**
     * The variants an item is created or updated with.
     *
     * @param array<string, mixed> $input
     * @return list<array{id: ?int, sku: ?string, options: array<string, string>, barcodes: list<array{code: string, unit: ?string}>, sale_price_minor: ?int, purchase_price_minor: ?int}>
     */
    private function variantInputs(array $input, ?int $itemId): array
    {
        $raw = $input['variants'] ?? null;
        if ($raw === null) {
            if ($itemId !== null) {
                return [];
            }
            $raw = [['sku' => $input['sku'] ?? null, 'barcodes' => $input['barcodes'] ?? ($input['barcode'] ?? null)]];
        }
        if (!is_array($raw) || !array_is_list($raw) || $raw === []) {
            throw InventoryRejectedException::field('variants', 'variants must be a list with at least one variant');
        }
        if (count($raw) > self::MAX_VARIANTS) {
            throw InventoryRejectedException::field('variants', 'An item can have at most ' . self::MAX_VARIANTS . ' variants');
        }
        $out = [];
        $skus = [];
        $axes = null;
        foreach ($raw as $i => $v) {
            if (!is_array($v)) {
                throw InventoryRejectedException::field('variants', 'variants[' . $i . '] must be an object');
            }
            $sku = $v['sku'] ?? null;
            if ($sku !== null) {
                $sku = is_string($sku) ? trim($sku) : '';
                if (preg_match('/^[\p{L}\p{N}][\p{L}\p{N}._\-\/]{0,63}$/u', $sku) !== 1) {
                    throw InventoryRejectedException::field('variants', 'variants[' . $i . '].sku is up to 64 letters, digits and . _ - /');
                }
                if (isset($skus[$sku])) {
                    throw InventoryRejectedException::field('variants', 'SKU "' . $sku . '" is used twice');
                }
                $skus[$sku] = true;
            }
            $options = [];
            foreach (is_array($v['options'] ?? null) ? $v['options'] : [] as $axis => $value) {
                if (!is_string($axis) || !is_scalar($value) || trim($axis) === '' || trim((string) $value) === '' || mb_strlen($axis) > 40 || mb_strlen((string) $value) > 60) {
                    throw InventoryRejectedException::field('variants', 'variants[' . $i . '].options maps a name (size) to a value (M)');
                }
                $options[trim($axis)] = trim((string) $value);
            }
            if (count($options) > self::MAX_OPTION_AXES) {
                throw InventoryRejectedException::field('variants', 'A variant has at most ' . self::MAX_OPTION_AXES . ' options');
            }
            $keys = array_keys($options);
            sort($keys);
            if ($axes !== null && $axes !== $keys) {
                throw InventoryRejectedException::field('variants', 'Every variant needs the same options (for example size and colour)');
            }
            $axes = $keys;
            if (count($raw) > 1 && $options === []) {
                throw InventoryRejectedException::field('variants', 'With more than one variant, each needs its options (size, colour)');
            }
            $out[] = [
                'id' => is_int($v['id'] ?? null) ? $v['id'] : null,
                'sku' => $sku,
                'options' => $options,
                'barcodes' => self::barcodeInputs($v['barcodes'] ?? null, 'variants[' . $i . '].barcodes'),
                'sale_price_minor' => array_key_exists('sale_price_minor', $v) ? self::price($v['sale_price_minor'], 'sale_price_minor') : null,
                'purchase_price_minor' => array_key_exists('purchase_price_minor', $v) ? self::price($v['purchase_price_minor'], 'purchase_price_minor') : null,
            ];
        }

        return $out;
    }

    /** @param array{id: ?int, sku: ?string, options: array<string, string>, barcodes: list<array{code: string, unit: ?string}>, sale_price_minor: ?int, purchase_price_minor: ?int} $variant */
    private function insertVariant(int $tenantId, int $itemId, array $variant, int $index, int $count): int
    {
        $sku = $variant['sku'] ?? ('ITEM-' . $itemId . ($count > 1 || $index > 0 ? '-' . ($index + 1) : ''));
        try {
            $id = $this->db->insert(
                'INSERT INTO inv_variants (tenant_id, item_id, sku, options, sale_price_minor, purchase_price_minor, sort_order)
                 VALUES (:tenant_id, :item_id, :sku, :options, :sale_price, :purchase_price, :sort_order)',
                [
                    ':tenant_id' => $tenantId, ':item_id' => $itemId, ':sku' => $sku,
                    ':options' => self::encodeMap($variant['options']),
                    ':sale_price' => $variant['sale_price_minor'], ':purchase_price' => $variant['purchase_price_minor'],
                    ':sort_order' => $index,
                ]
            );
        } catch (\PDOException $e) {
            if (InventoryDb::isUniqueViolation($e)) {
                throw new InventoryRejectedException('SKU "' . $sku . '" is already used by another item', 409, ['field' => 'sku']);
            }
            throw $e;
        }
        $this->replaceBarcodes($tenantId, $id, $variant['barcodes']);

        return $id;
    }

    /** @param list<array{id: ?int, sku: ?string, options: array<string, string>, barcodes: list<array{code: string, unit: ?string}>, sale_price_minor: ?int, purchase_price_minor: ?int}> $variants */
    private function syncVariants(int $tenantId, int $itemId, array $variants): void
    {
        $existing = [];
        foreach ($this->db->all(
            'SELECT id FROM inv_variants WHERE tenant_id = :tenant_id AND item_id = :item_id',
            [':tenant_id' => $tenantId, ':item_id' => $itemId]
        ) as $r) {
            $existing[(int) $r['id']] = true;
        }
        $kept = [];
        foreach ($variants as $index => $variant) {
            if ($variant['id'] === null) {
                $kept[$this->insertVariant($tenantId, $itemId, $variant, $index + count($existing), count($variants))] = true;
                continue;
            }
            if (!isset($existing[$variant['id']])) {
                throw InventoryRejectedException::field('variants', 'Variant ' . $variant['id'] . ' is not part of this item');
            }
            $kept[$variant['id']] = true;
            try {
                $this->db->run(
                    'UPDATE inv_variants SET options = :options, sale_price_minor = :sale_price, purchase_price_minor = :purchase_price,
                            sort_order = :sort_order, archived_at = NULL, updated_at = CURRENT_TIMESTAMP'
                        . ($variant['sku'] !== null ? ', sku = :sku' : '') . '
                      WHERE tenant_id = :tenant_id AND id = :id',
                    [
                        ':tenant_id' => $tenantId, ':id' => $variant['id'], ':options' => self::encodeMap($variant['options']),
                        ':sale_price' => $variant['sale_price_minor'], ':purchase_price' => $variant['purchase_price_minor'],
                        ':sort_order' => $index,
                    ] + ($variant['sku'] !== null ? [':sku' => $variant['sku']] : [])
                );
            } catch (\PDOException $e) {
                if (InventoryDb::isUniqueViolation($e)) {
                    throw new InventoryRejectedException('SKU "' . $variant['sku'] . '" is already used by another item', 409, ['field' => 'sku']);
                }
                throw $e;
            }
            $this->replaceBarcodes($tenantId, $variant['id'], $variant['barcodes']);
        }
        foreach (array_keys($existing) as $id) {
            if (!isset($kept[$id])) {
                $this->db->run(
                    'UPDATE inv_variants SET archived_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
                      WHERE tenant_id = :tenant_id AND id = :id AND archived_at IS NULL',
                    [':tenant_id' => $tenantId, ':id' => $id]
                );
            }
        }
    }

    /** @param list<array{code: string, unit: ?string}> $barcodes */
    private function replaceBarcodes(int $tenantId, int $variantId, array $barcodes): void
    {
        $this->db->run('DELETE FROM inv_barcodes WHERE tenant_id = :tenant_id AND variant_id = :variant_id', [':tenant_id' => $tenantId, ':variant_id' => $variantId]);
        foreach ($barcodes as $barcode) {
            $unitId = null;
            if ($barcode['unit'] !== null) {
                $unit = $this->units->rowByCode($tenantId, $barcode['unit']);
                if ($unit === null) {
                    throw InventoryRejectedException::field('barcodes', 'There is no unit "' . $barcode['unit'] . '"');
                }
                $unitId = (int) $unit['id'];
            }
            try {
                $this->db->run(
                    'INSERT INTO inv_barcodes (tenant_id, variant_id, unit_id, code, symbology) VALUES (:tenant_id, :variant_id, :unit_id, :code, :symbology)',
                    [':tenant_id' => $tenantId, ':variant_id' => $variantId, ':unit_id' => $unitId, ':code' => $barcode['code'], ':symbology' => self::symbology($barcode['code'])]
                );
            } catch (\PDOException $e) {
                if (InventoryDb::isUniqueViolation($e)) {
                    throw new InventoryRejectedException('Barcode ' . $barcode['code'] . ' is already used', 409, ['field' => 'barcodes']);
                }
                throw $e;
            }
        }
    }

    /** @param array<string, mixed> $baseUnit */
    private function replaceItemUnits(int $tenantId, int $itemId, array $baseUnit, mixed $raw): void
    {
        if (!is_array($raw) || !array_is_list($raw) || count($raw) > 10) {
            throw InventoryRejectedException::field('units', 'units is a list of at most 10 {unit, per}');
        }
        $this->db->run('DELETE FROM inv_item_units WHERE tenant_id = :tenant_id AND item_id = :item_id', [':tenant_id' => $tenantId, ':item_id' => $itemId]);
        foreach ($raw as $i => $entry) {
            $code = is_array($entry) && is_string($entry['unit'] ?? null) ? $entry['unit'] : '';
            $unit = $this->units->rowByCode($tenantId, $code);
            if ($unit === null || (int) $unit['id'] === (int) $baseUnit['id']) {
                throw InventoryRejectedException::field('units', 'units[' . $i . '].unit must be another unit of this workspace');
            }
            [$num, $den] = UnitCatalog::fraction(is_array($entry) ? ($entry['per'] ?? null) : null, 'units[' . $i . '].per');
            $purpose = is_array($entry) && in_array($entry['purpose'] ?? 'both', ['purchase', 'sale', 'both'], true) ? (string) ($entry['purpose'] ?? 'both') : 'both';
            try {
                $this->db->run(
                    'INSERT INTO inv_item_units (tenant_id, item_id, unit_id, num, den, purpose, is_default)
                     VALUES (:tenant_id, :item_id, :unit_id, :num, :den, :purpose, :is_default)',
                    [':tenant_id' => $tenantId, ':item_id' => $itemId, ':unit_id' => (int) $unit['id'], ':num' => $num, ':den' => $den,
                        ':purpose' => $purpose, ':is_default' => is_array($entry) && self::flag($entry['is_default'] ?? false, 'units')]
                );
            } catch (\PDOException $e) {
                if (InventoryDb::isUniqueViolation($e)) {
                    throw InventoryRejectedException::field('units', 'units lists "' . $code . '" twice');
                }
                throw $e;
            }
        }
    }


    /**
     * Batches can be turned on while an item has stock (that stock goes into
     * the batch OPENING); serial numbers, and turning batches off, only while
     * it has none.
     *
     * @param array<string, mixed> $row
     */
    private function trackingCanChange(int $tenantId, array $row, string $to): void
    {
        if (!$this->hasStock($tenantId, (int) $row['id'])) {
            return;
        }
        if ($row['tracking'] === 'none' && $to === 'lot' && $this->openingLots !== null) {
            return;
        }
        $message = match (true) {
            $to === 'serial' => $row['name'] . ' has stock, so serial numbers can\'t be turned on: each piece would need its own. Bring its stock to 0 first.',
            $to === 'none' => $row['name'] . ' has stock in batches, so batches can\'t be turned off. Bring its stock to 0 first.',
            default => $row['name'] . ' has stock, so this can\'t change now. Bring its stock to 0 first.',
        };
        throw new InventoryRejectedException($message, 409, ['field' => 'tracking']);
    }

    private function hasStock(int $tenantId, int $itemId): bool
    {
        return $this->db->one(
            'SELECT 1 AS hit FROM inv_levels l JOIN inv_variants v ON v.tenant_id = l.tenant_id AND v.id = l.variant_id
              WHERE l.tenant_id = :tenant_id AND v.item_id = :item_id AND l.on_hand <> 0 LIMIT 1',
            [':tenant_id' => $tenantId, ':item_id' => $itemId]
        ) !== null;
    }

    private function hasMoves(int $tenantId, int $itemId): bool
    {
        return $this->db->one(
            'SELECT 1 AS hit FROM inv_moves m JOIN inv_variants v ON v.tenant_id = m.tenant_id AND v.id = m.variant_id
              WHERE m.tenant_id = :tenant_id AND v.item_id = :item_id LIMIT 1',
            [':tenant_id' => $tenantId, ':item_id' => $itemId]
        ) !== null;
    }

    private function categoryExists(int $tenantId, int $id): bool
    {
        return $this->db->one('SELECT id FROM inv_categories WHERE tenant_id = :tenant_id AND id = :id', [':tenant_id' => $tenantId, ':id' => $id]) !== null;
    }

    private function kind(mixed $kind): string
    {
        if (!is_string($kind) || !in_array($kind, self::KINDS, true)) {
            throw InventoryRejectedException::field('kind', 'kind must be one of ' . implode(', ', self::KINDS));
        }

        return $kind;
    }

    /** @param array<string, mixed> $payload */
    private function dispatch(string $event, array $payload): void
    {
        if ($this->hooks === null) {
            return;
        }
        try {
            $this->hooks->dispatch($event, $payload);
            $this->hooks->dispatchAsync($event . '.async', $payload);
        } catch (\Throwable $e) {
            error_log('[inventory] ' . $event . ' listeners failed: ' . $e->getMessage());
        }
    }

    private static function flag(mixed $value, string $field): bool
    {
        if (!is_bool($value) && !is_int($value) && $value !== null) {
            throw InventoryRejectedException::field($field, $field . ' is true or false');
        }

        return DbBool::of($value);
    }

    private static function price(mixed $value, string $field): ?int
    {
        if ($value === null) {
            return null;
        }
        if (!is_int($value) || $value < 0) {
            throw InventoryRejectedException::field($field, $field . ' is a whole number of minor units (cents), 0 or more');
        }

        return $value;
    }

    /** @return list<array{code: string, unit: ?string}> */
    private static function barcodeInputs(mixed $raw, string $field): array
    {
        if ($raw === null) {
            return [];
        }
        if (is_string($raw)) {
            $raw = [$raw];
        }
        if (!is_array($raw) || !array_is_list($raw) || count($raw) > self::MAX_BARCODES) {
            throw InventoryRejectedException::field('barcodes', $field . ' is a list of at most ' . self::MAX_BARCODES . ' barcodes');
        }
        $out = [];
        foreach ($raw as $entry) {
            $code = self::barcodeText(is_array($entry) ? ($entry['code'] ?? '') : $entry);
            if (preg_match('/^[A-Za-z0-9.\-\/+]{1,64}$/', $code) !== 1) {
                throw InventoryRejectedException::field('barcodes', 'A barcode is up to 64 letters and digits');
            }
            $out[] = ['code' => $code, 'unit' => is_array($entry) && is_string($entry['unit'] ?? null) ? $entry['unit'] : null];
        }

        return $out;
    }

    /** A scanned or typed code with spaces dropped and Arabic-Indic digits read as Western ones. */
    public static function barcodeText(mixed $raw): string
    {
        if (!is_scalar($raw)) {
            return '';
        }

        return preg_replace('/\s+/u', '', strtr((string) $raw, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ])) ?? '';
    }

    private static function symbology(string $code): string
    {
        if (preg_match('/^\d+$/', $code) !== 1) {
            return 'other';
        }

        return match (strlen($code)) {
            13 => 'ean13',
            8 => 'ean8',
            12 => 'upca',
            default => 'other',
        };
    }

    /** @param array<string, string> $map */
    private static function encodeMap(array $map): string
    {
        $json = json_encode($map === [] ? new \stdClass() : $map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? '{}' : $json;
    }

    /** @return array<string, string> */
    private static function decodeMap(mixed $stored): array
    {
        $decoded = is_string($stored) ? json_decode($stored, true) : null;
        $out = [];
        foreach (is_array($decoded) ? $decoded : [] as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $out[$key] = (string) $value;
            }
        }

        return $out;
    }

    private static function prefixed(string $alias): string
    {
        return implode(', ', array_map(static fn (string $c): string => $alias . '.' . trim($c), explode(',', self::ITEM_COLUMNS)));
    }

    /**
     * @param list<int> $ids
     * @return array{sql: string, params: array<string, int>}
     */
    private static function inList(array $ids, string $prefix): array
    {
        $names = [];
        $params = [];
        foreach ($ids as $i => $id) {
            $names[] = ':' . $prefix . $i;
            $params[':' . $prefix . $i] = $id;
        }

        return ['sql' => implode(', ', $names), 'params' => $params];
    }
}
