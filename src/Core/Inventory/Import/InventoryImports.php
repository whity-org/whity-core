<?php

declare(strict_types=1);

namespace Whity\Core\Inventory\Import;

use Whity\Core\Contacts\Import\ColumnMatcher;
use Whity\Core\Contacts\Import\SpreadsheetReader;
use Whity\Core\Contacts\ContactRejectedException;
use Whity\Core\Import\ImportJobRepository;
use Whity\Core\Inventory\InventoryDb;
use Whity\Core\Inventory\InventoryNames;
use Whity\Core\Inventory\InventoryRejectedException;
use Whity\Core\Inventory\InventoryWorkspace;
use Whity\Core\Inventory\ItemService;
use Whity\Core\Inventory\StockLedger;
use Whity\Core\Inventory\SupplierItems;
use Whity\Core\Money\Currency;
use Whity\Core\Money\MoneyException;

/**
 * Importing items and opening stock from a spreadsheet (WHIT-1322, design
 * section 34 §6, register IM.1-IM.6, `TplImport`), on the same engine and in
 * the same four steps as contacts:
 *
 * 1. **Upload**: CSV or Excel; the header row is found and the rows kept on
 *    the job (`import_jobs`, kind `inventory.items` or
 *    `inventory.opening_stock`), with each column's suggested target from its
 *    English or Arabic heading.
 * 2. **Check**: every row is worked out as create, update or skip with a
 *    reason. Nothing changes.
 * 3. **Import**: worked out again against the data as it is now and applied
 *    in one transaction, each change recorded with the values before.
 * 4. **Undo**, for 24 hours: items it made are archived, items it changed get
 *    their values back; opening stock is reversed, unless that stock has moved
 *    since.
 *
 * Items are matched by SKU. A new item has one variant (its SKU); its unit
 * and barcode are set only when it is made. A row never empties a field: a
 * blank cell means "nothing to say". Prices are typed in normal units of the
 * workspace's currency (CU.1-CU.4).
 *
 * Items with variants take a row per variant (WHIT-1234 P3d, IM.3): up to
 * three option columns, each named by its own heading (Size, Colour), and the
 * rows of one item share its group column or its name. A new item is made
 * with all of them; an item that exists gains the new SKUs as variants.
 *
 * Opening stock posts one batch `opening → place` at the given unit cost, to
 * the place named in the row or the one chosen for the whole file.
 *
 * Supplier prices (`SupplierPriceRows`) set what a supplier sells, matched by
 * supplier and SKU.
 *
 * Importing never refuses because of a plan limit (IM.8): items past the
 * base only warn, on Billing and the plan banner.
 */
final class InventoryImports
{
    public const UNDO_SECONDS = 86400;
    public const MAX_LISTED = 200;

    /** Kind in the API => kind on the job. */
    public const KINDS = ['items' => 'inventory.items', 'opening_stock' => 'inventory.opening_stock', 'supplier_prices' => 'inventory.supplier_prices'];

    /** Option columns: a row per variant, the column's own heading names the option (Size, Colour). */
    private const OPTION_TARGETS = ['option1', 'option2', 'option3'];

    /** Targets, with their English label and the headings that mean them (lower case; Arabic forms read alike). */
    private const TARGETS = [
        'items' => [
            'sku' => ['SKU', ['sku', 'code', 'item code', 'item no', 'item number', 'product code', 'الرمز', 'رمز', 'رمز الصنف', 'كود', 'كود الصنف']],
            'name' => ['Name', ['name', 'item', 'item name', 'product', 'product name', 'description', 'الاسم', 'اسم الصنف', 'الصنف', 'المنتج']],
            'name_ar' => ['Arabic name', ['name ar', 'arabic name', 'name (arabic)', 'الاسم بالعربية', 'الاسم العربي']],
            'unit' => ['Unit', ['unit', 'uom', 'unit of measure', 'الوحدة', 'وحدة']],
            'kind' => ['Kind', ['kind', 'type', 'item type', 'النوع']],
            'sale_price' => ['Sale price', ['sale price', 'selling price', 'price', 'سعر البيع', 'السعر']],
            'purchase_price' => ['Purchase price', ['purchase price', 'cost price', 'buying price', 'سعر الشراء', 'سعر الكلفة']],
            'tax_category' => ['Tax category', ['tax category', 'tax', 'vat', 'tax class', 'فئة الضريبة', 'الضريبة']],
            'barcode' => ['Barcode', ['barcode', 'ean', 'upc', 'gtin', 'الباركود', 'باركود']],
            'description' => ['Description', ['details', 'notes', 'long description', 'الوصف', 'التفاصيل']],
            'group' => ['Item group', ['item group', 'group', 'parent sku', 'parent code', 'parent', 'model', 'المجموعة', 'الصنف الرئيسي']],
            'option1' => ['Option 1', ['option 1', 'option1', 'size', 'المقاس', 'الحجم']],
            'option2' => ['Option 2', ['option 2', 'option2', 'colour', 'color', 'اللون']],
            'option3' => ['Option 3', ['option 3', 'option3', 'material', 'flavour', 'flavor', 'المادة', 'النكهة']],
        ],
        'opening_stock' => [
            'sku' => ['SKU', ['sku', 'code', 'item code', 'item no', 'الرمز', 'رمز', 'رمز الصنف', 'كود']],
            'quantity' => ['Quantity', ['quantity', 'qty', 'on hand', 'stock', 'count', 'الكمية', 'الرصيد']],
            'unit' => ['Unit', ['unit', 'uom', 'الوحدة']],
            'place' => ['Place', ['place', 'location', 'warehouse', 'store', 'branch', 'المكان', 'المستودع', 'الموقع', 'الفرع']],
            'unit_cost' => ['Unit cost', ['unit cost', 'cost', 'cost price', 'التكلفة', 'كلفة الوحدة', 'سعر الكلفة']],
        ],
        'supplier_prices' => SupplierPriceRows::TARGETS,
    ];

    private const KIND_WORDS = [
        'stocked' => ['stocked', 'stock', 'product', 'goods', 'مخزون', 'منتج', 'سلعة'],
        'consumable' => ['consumable', 'supplies', 'مستهلك', 'مستهلكات'],
        'service' => ['service', 'خدمة'],
    ];

    /** @var array<string, ImportJobRepository> */
    private array $jobs = [];

    private readonly ?SupplierPriceRows $supplierPrices;

    /** @param \Closure(int): string $currency the workspace's currency */
    public function __construct(
        private readonly InventoryDb $db,
        private readonly InventoryWorkspace $workspace,
        private readonly ItemService $items,
        private readonly StockLedger $ledger,
        private readonly \Closure $currency,
        private readonly ?SupplierItems $supplierItems = null,
    ) {
        $this->supplierPrices = $supplierItems === null ? null : new SupplierPriceRows($db, $items, $supplierItems, $currency);
        foreach (self::KINDS as $api => $kind) {
            $this->jobs[$api] = new ImportJobRepository($db->pdo, $kind);
        }
    }

    /** @return array<string, mixed> the import, API shape, with its columns */
    public function upload(int $tenantId, string $kind, ?int $actor, string $fileName, string $bytes): array
    {
        $jobs = $this->jobs($kind);
        $jobs->sweepAbandoned($tenantId);
        try {
            $rows = SpreadsheetReader::read($bytes, $fileName);
        } catch (ContactRejectedException $e) {
            throw new InventoryRejectedException($e->clientMessage, $e->status, $e->extra);
        }
        $headerAt = SpreadsheetReader::headerIndex($rows);
        $data = array_slice($rows, $headerAt + 1);
        if ($data === []) {
            throw new InventoryRejectedException('The file has a header row but no rows under it');
        }
        if (count($data) > SpreadsheetReader::MAX_ROWS) {
            throw new InventoryRejectedException('The file has more than ' . SpreadsheetReader::MAX_ROWS . ' rows; import it in parts');
        }

        return $this->show($tenantId, $kind, $jobs->create($tenantId, $actor, $fileName, $rows[$headerAt], $data));
    }

    /** @return array<string, mixed> */
    public function show(int $tenantId, string $kind, int $id): array
    {
        $job = $this->jobs($kind)->find($tenantId, $id) ?? throw InventoryRejectedException::notFound('Import');

        return $this->toApi($kind, $job);
    }

    /**
     * Works out what the import will do. Nothing changes.
     *
     * @param array<string, mixed> $input `mapping` (column index => target), and for opening stock `location_id` (the place for rows that name none)
     * @return array<string, mixed>
     */
    public function check(int $tenantId, string $kind, int $id, array $input): array
    {
        $jobs = $this->jobs($kind);
        $job = $jobs->find($tenantId, $id) ?? throw InventoryRejectedException::notFound('Import');
        if (!in_array($job['status'], ['uploaded', 'checked'], true)) {
            throw new InventoryRejectedException('This import has already run', 409);
        }
        $this->workspace->ensure($tenantId);
        $options = $this->options($tenantId, $kind, $job, $input);
        $jobs->saveCheck($tenantId, $id, $options, self::summary($this->plan($tenantId, $kind, $job['rows'], $options)));

        return $this->show($tenantId, $kind, $id);
    }

    /** @return array<string, mixed> */
    public function run(int $tenantId, string $kind, int $id, ?int $actor): array
    {
        $jobs = $this->jobs($kind);
        $this->db->transaction(function () use ($tenantId, $kind, $id, $actor, $jobs): void {
            $job = $jobs->find($tenantId, $id, true) ?? throw InventoryRejectedException::notFound('Import');
            if ($job['status'] !== 'checked') {
                throw new InventoryRejectedException($job['status'] === 'uploaded' ? 'Check the import before running it' : 'This import has already run', 409);
            }
            $plan = $this->plan($tenantId, $kind, $job['rows'], $job['options']);
            if ($kind === 'items') {
                $this->applyItems($tenantId, $id, $plan, $actor, $jobs);
            } elseif ($kind === 'supplier_prices') {
                $this->applySupplierPrices($tenantId, $id, $plan, $jobs);
            } else {
                $this->applyStock($tenantId, $id, $plan, $actor, $jobs, (string) ($job['file_name']));
            }
            $jobs->markImported($tenantId, $id, self::summary($plan));
        });

        return $this->show($tenantId, $kind, $id);
    }

    /**
     * Undoes an import within 24 hours of it running.
     *
     * @return array<string, mixed>
     */
    public function undo(int $tenantId, string $kind, int $id, ?int $actor): array
    {
        $jobs = $this->jobs($kind);
        $this->db->transaction(function () use ($tenantId, $id, $actor, $jobs): void {
            $job = $jobs->find($tenantId, $id, true) ?? throw InventoryRejectedException::notFound('Import');
            if ($job['status'] !== 'imported' || $job['imported_at'] === null) {
                throw new InventoryRejectedException('Only an import that ran can be undone', 409);
            }
            if (!$this->undoable($job['imported_at'], $jobs->now())) {
                throw new InventoryRejectedException('An import can be undone for 24 hours after it runs; this one ran longer ago', 409);
            }
            foreach ($jobs->changes($tenantId, $id) as $change) {
                if ($change['entity_type'] === 'inventory.supplier_item') {
                    $this->supplierPrices()->undo($tenantId, $change);
                } elseif ($change['entity_type'] === 'inventory.stock') {
                    $this->undoStock($tenantId, (string) ($change['extra']['batch_key'] ?? ''), $actor);
                } elseif ($change['action'] === 'created') {
                    $this->items->archive($tenantId, $change['entity_id'], true, $actor);
                } elseif ($this->items->row($tenantId, $change['entity_id']) !== null) {
                    $this->items->update($tenantId, $change['entity_id'], $change['before'] ?? [], $actor);
                }
            }
            $jobs->markUndone($tenantId, $id);
        });

        return $this->show($tenantId, $kind, $id);
    }

    /** The skipped rows as a CSV, each with its reason, to fix and import again. */
    public function skippedCsv(int $tenantId, string $kind, int $id): string
    {
        $job = $this->jobs($kind)->find($tenantId, $id) ?? throw InventoryRejectedException::notFound('Import');
        if ($job['options'] === []) {
            throw new InventoryRejectedException('Check the import first', 409);
        }
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('temp stream');
        }
        fwrite($stream, "\u{FEFF}");
        \Whity\Http\CsvCell::writeRow($stream, array_merge(['Row', 'Reason'], $job['header']));
        foreach ($this->plan($tenantId, $kind, $job['rows'], $job['options']) as $row) {
            if ($row['action'] === 'skip') {
                \Whity\Http\CsvCell::writeRow($stream, array_merge([(string) $row['row'], (string) ($row['reason'] ?? '')], $job['rows'][$row['row'] - 2] ?? []));
            }
        }
        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    /** @return list<array<string, mixed>> */
    public function recent(int $tenantId, string $kind): array
    {
        $jobs = $this->jobs($kind);
        $now = $jobs->now();

        return array_map(fn (array $r): array => [
            'id' => (int) $r['id'],
            'kind' => $kind,
            'file_name' => (string) $r['file_name'],
            'status' => (string) $r['status'],
            'summary' => self::counts(json_decode((string) $r['summary_json'], true)),
            'created_by_profile_id' => $r['created_by_profile_id'] === null ? null : (int) $r['created_by_profile_id'],
            'imported_at' => $r['imported_at'],
            'undo_until' => $r['imported_at'] !== null && $r['status'] === 'imported' ? self::undoUntil((string) $r['imported_at']) : null,
            'can_undo' => $r['status'] === 'imported' && $r['imported_at'] !== null && $this->undoable((string) $r['imported_at'], $now),
            'created_at' => (string) $r['created_at'],
        ], $jobs->recent($tenantId, 20));
    }

    /** @return list<array{key: string, label: string}> */
    public static function targets(string $kind): array
    {
        $out = [];
        foreach (self::TARGETS[$kind] ?? [] as $key => [$label]) {
            $out[] = ['key' => $key, 'label' => $label];
        }

        return $out;
    }

    // ── planning ─────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function options(int $tenantId, string $kind, array $job, array $input): array
    {
        $mapping = $input['mapping'] ?? null;
        if (!is_array($mapping) || $mapping === []) {
            throw InventoryRejectedException::field('mapping', 'Match at least one column');
        }
        $width = count($job['header']);
        foreach ($job['rows'] as $row) {
            $width = max($width, count($row));
        }
        $clean = [];
        $used = [];
        foreach ($mapping as $index => $target) {
            if ($target === null || $target === '') {
                continue;
            }
            if (!is_numeric($index) || (int) $index < 0 || (int) $index >= $width) {
                throw InventoryRejectedException::field('mapping', 'Column ' . $index . ' is not in the file');
            }
            if (!is_string($target) || !isset(self::TARGETS[$kind][$target])) {
                throw InventoryRejectedException::field('mapping', 'Column ' . $index . ' goes to a field Whity doesn\'t know');
            }
            if (isset($used[$target])) {
                throw InventoryRejectedException::field('mapping', 'Two columns go to ' . self::TARGETS[$kind][$target][0] . '; choose one');
            }
            $used[$target] = true;
            $clean[(string) (int) $index] = $target;
        }
        $required = match ($kind) {
            'items' => ['sku' => 'SKU'],
            'supplier_prices' => ['sku' => 'SKU', 'price' => 'price'],
            default => ['sku' => 'SKU', 'quantity' => 'quantity'],
        };
        foreach ($required as $target => $label) {
            if (!isset($used[$target])) {
                throw InventoryRejectedException::field('mapping', 'Choose the column with the ' . $label);
            }
        }
        $options = ['mapping' => $clean];
        if ($kind === 'opening_stock') {
            $place = $input['location_id'] ?? null;
            if ($place !== null) {
                if (!is_int($place) || $this->place($tenantId, (string) $place, true) === null) {
                    throw InventoryRejectedException::field('location_id', 'location_id is a place here that holds stock');
                }
            } elseif (!isset($used['place'])) {
                throw InventoryRejectedException::field('location_id', 'Choose the place the stock is in, or the column that names it');
            }
            $options['location_id'] = $place;
        }
        if ($kind === 'supplier_prices') {
            $supplier = $input['contact_id'] ?? null;
            if ($supplier !== null) {
                if (!is_int($supplier)) {
                    throw InventoryRejectedException::field('contact_id', 'contact_id is a supplier here');
                }
                $this->supplierItemsFor()->supplier($tenantId, $supplier, 'contact_id');
            } elseif (!isset($used['supplier'])) {
                throw InventoryRejectedException::field('contact_id', 'Choose the supplier, or the column that names them');
            }
            $options['contact_id'] = $supplier;
        }
        if ($kind === 'items') {
            // The option's name is the column's own heading (Size, Colour).
            $axes = [];
            foreach ($clean as $index => $target) {
                if (in_array($target, self::OPTION_TARGETS, true)) {
                    $axis = trim((string) ($job['header'][(int) $index] ?? ''));
                    if ($axis === '' || mb_strlen($axis) > 40) {
                        throw InventoryRejectedException::field('mapping', 'Column ' . $index . ' needs a heading of up to 40 characters: it names the option (Size, Colour)');
                    }
                    if (in_array(mb_strtolower($axis), array_map('mb_strtolower', $axes), true)) {
                        throw InventoryRejectedException::field('mapping', 'Two option columns are called "' . $axis . '"; rename one');
                    }
                    $axes[$target] = $axis;
                }
            }
            if ($axes !== []) {
                $options['axes'] = $axes;
            }
        }

        return $options;
    }

    /**
     * What each row will do.
     *
     * @param list<list<string>> $rows
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    private function plan(int $tenantId, string $kind, array $rows, array $options): array
    {
        /** @var array<string, string> $mapping */
        $mapping = $options['mapping'] ?? [];
        /** @var array<string, string> $axes */
        $axes = is_array($options['axes'] ?? null) ? $options['axes'] : [];
        $currency = ($this->currency)($tenantId);
        $plan = [];
        $seen = [];
        $groups = [];
        foreach ($rows as $i => $cells) {
            $rowNumber = $i + 2;
            $values = [];
            foreach ($mapping as $index => $target) {
                $values[$target] = trim(InventoryNames::westernDigits((string) ($cells[(int) $index] ?? '')));
            }
            $sku = $values['sku'] ?? '';
            $base = ['row' => $rowNumber, 'name' => $sku, 'changes' => [], 'before' => null];
            if ($sku === '') {
                $plan[$i] = ['action' => 'skip', 'reason' => 'No SKU'] + $base;
                continue;
            }
            if (mb_strlen($sku) > 64) {
                $plan[$i] = ['action' => 'skip', 'reason' => 'The SKU is longer than 64 characters'] + $base;
                continue;
            }
            $key = mb_strtolower($sku) . '|' . match ($kind) {
                'opening_stock' => mb_strtolower($values['place'] ?? ''),
                'supplier_prices' => mb_strtolower($values['supplier'] ?? ''),
                default => '',
            };
            if (isset($seen[$key])) {
                $plan[$i] = ['action' => 'skip', 'reason' => 'Same ' . match ($kind) {
                    'items' => 'item',
                    'supplier_prices' => 'item and supplier',
                    default => 'item and place',
                } . ' as row ' . $seen[$key]] + $base;
                continue;
            }
            $seen[$key] = $rowNumber;
            if ($kind === 'items' && $axes !== [] && self::hasOptions($values, $axes)) {
                // A row per variant: rows of one item share its group, or its name.
                $group = mb_strtolower(($values['group'] ?? '') !== '' ? $values['group'] : ($values['name'] ?? ''));
                if ($group === '') {
                    $plan[$i] = ['action' => 'skip', 'reason' => 'A variant needs its item\'s name or group, to know which item it belongs to'] + $base;
                    continue;
                }
                $groups[$group][$i] = ['values' => $values, 'base' => $base];
                continue;
            }
            $plan[$i] = match ($kind) {
                'items' => $this->planItem($tenantId, $values, $currency, $base),
                'supplier_prices' => $this->supplierPrices()->plan($tenantId, $values, $options, $base),
                default => $this->planStock($tenantId, $values, $options, $currency, $base),
            };
        }
        foreach ($groups as $members) {
            $plan = $this->planVariants($tenantId, $members, $axes, $currency) + $plan;
        }
        ksort($plan);

        return array_values($plan);
    }

    /**
     * @param array<string, string> $values
     * @param array<string, string> $axes
     */
    private static function hasOptions(array $values, array $axes): bool
    {
        foreach (array_keys($axes) as $target) {
            if (($values[$target] ?? '') !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * The rows that are variants of one item (IM.3: one row per variant, the
     * option columns name the axes). A new item is made with all of them; an
     * existing one, found by any row's SKU, gains the new SKUs as variants
     * and keeps the ones it has.
     *
     * @param array<int, array{values: array<string, string>, base: array<string, mixed>}> $members by row index
     * @param array<string, string> $axes option target => its name
     * @return array<int, array<string, mixed>> by row index
     */
    private function planVariants(int $tenantId, array $members, array $axes, string $currency): array
    {
        $out = [];
        $valid = [];
        $combos = [];
        foreach ($members as $i => ['values' => $v, 'base' => $base]) {
            $options = [];
            foreach ($axes as $target => $axis) {
                if (($v[$target] ?? '') === '') {
                    $out[$i] = ['action' => 'skip', 'reason' => 'Every variant needs its ' . $axis] + $base;
                    continue 2;
                }
                if (mb_strlen($v[$target]) > 60) {
                    $out[$i] = ['action' => 'skip', 'reason' => $axis . ' is longer than 60 characters'] + $base;
                    continue 2;
                }
                $options[$axis] = $v[$target];
            }
            $combo = mb_strtolower(implode('|', $options));
            if (isset($combos[$combo])) {
                $out[$i] = ['action' => 'skip', 'reason' => 'Same ' . implode(' and ', array_keys($options)) . ' as row ' . $combos[$combo]] + $base;
                continue;
            }
            $parsed = $this->itemFields($v, $currency, $base);
            if (isset($parsed['action'])) {
                $out[$i] = $parsed;
                continue;
            }
            $combos[$combo] = $base['row'];
            $valid[$i] = ['values' => $v, 'base' => $base, 'options' => $options] + $parsed;
        }
        if ($valid === []) {
            return $out;
        }
        $skipAll = static function (string $reason) use ($valid, $out): array {
            foreach ($valid as $i => $m) {
                $out[$i] = ['action' => 'skip', 'reason' => $reason] + $m['base'];
            }

            return $out;
        };

        $itemIds = [];
        foreach ($valid as $m) {
            $id = $this->items->itemIdBySku($tenantId, $m['values']['sku']);
            if ($id !== null) {
                $itemIds[$id] = true;
            }
        }
        if (count($itemIds) > 1) {
            return $skipAll('These rows have the same item name or group, but their SKUs belong to different items');
        }
        $leaderIndex = array_key_first($valid);
        $leader = $valid[$leaderIndex];

        if ($itemIds === []) {
            if (count($valid) > ItemService::MAX_VARIANTS) {
                return $skipAll('An item can have at most ' . ItemService::MAX_VARIANTS . ' variants');
            }
            $names = [];
            foreach ($valid as $m) {
                if ($m['names'] !== []) {
                    $names = $m['names'];
                    break;
                }
            }
            if ($names === []) {
                return $skipAll('No name for a new item');
            }
            $unit = ($leader['values']['unit'] ?? '') !== '' ? $leader['values']['unit'] : 'pc';
            if ($this->unitId($tenantId, $unit) === null) {
                return $skipAll('There is no unit "' . $unit . '"; add it under Units first');
            }
            $variants = [];
            foreach ($valid as $i => $m) {
                $barcode = $m['values']['barcode'] ?? '';
                if ($barcode !== '' && $this->barcodeTaken($tenantId, $barcode)) {
                    $out[$i] = ['action' => 'skip', 'reason' => 'The barcode belongs to another item'] + $m['base'];
                    continue;
                }
                $variants[] = self::variantInput(null, $m, $leader['fields']);
                $out[$i] = ['action' => 'create', 'group' => true] + $m['base'];
            }
            if ($variants === []) {
                return $out;
            }
            // The item is made once, from the first row that isn't skipped.
            foreach ($out as $i => $row) {
                if (($row['group'] ?? false) === true) {
                    $out[$i]['op'] = ['create', $leader['fields'] + ['name' => $names, 'unit' => $unit, 'variants' => $variants]];
                    break;
                }
            }

            return $out;
        }

        $itemId = (int) array_key_first($itemIds);
        $item = $this->items->show($tenantId, $itemId);
        if ($item['archived']) {
            return $skipAll('The item with this SKU is archived');
        }
        $live = array_values(array_filter($item['variants'], static fn (array $x): bool => !$x['archived']));
        $wanted = array_values($axes);
        sort($wanted);
        $bySku = [];
        foreach ($live as $x) {
            $bySku[mb_strtolower((string) $x['sku'])] = $x;
            $has = array_keys($x['options']);
            sort($has);
            if (($has !== [] || count($live) > 1) && $has !== $wanted) {
                return $skipAll($item['name'] . '\'s variants are by ' . ($has === [] ? 'nothing yet' : implode(' and ', $has)) . '; this file has ' . implode(' and ', $wanted));
            }
        }
        $list = array_map(static fn (array $x): array => self::variantFromShown($x), $live);
        $before = $list;
        $variantsChanged = false;
        $rowsSeen = [];
        foreach ($valid as $i => $m) {
            $existing = $bySku[mb_strtolower($m['values']['sku'])] ?? null;
            if ($existing !== null) {
                $rowsSeen[mb_strtolower((string) $existing['sku'])] = true;
                if ($existing['options'] != $m['options']) {
                    foreach ($list as $k => $entry) {
                        if ($entry['id'] === $existing['id']) {
                            $list[$k]['options'] = $m['options'];
                        }
                    }
                    $variantsChanged = true;
                    $out[$i] = ['action' => 'update', 'group' => true, 'changes' => ['options']] + $m['base'];
                } else {
                    $out[$i] = ['action' => 'skip', 'reason' => 'Already up to date', 'group' => true, 'item_id' => $itemId] + $m['base'];
                }
                continue;
            }
            $barcode = $m['values']['barcode'] ?? '';
            if ($barcode !== '' && $this->barcodeTaken($tenantId, $barcode)) {
                $out[$i] = ['action' => 'skip', 'reason' => 'The barcode belongs to another item'] + $m['base'];
                continue;
            }
            $list[] = self::variantInput(null, $m, $leader['fields'] + array_filter([
                'sale_price_minor' => $item['sale_price_minor'], 'purchase_price_minor' => $item['purchase_price_minor'],
            ], static fn ($p): bool => $p !== null));
            $variantsChanged = true;
            $out[$i] = ['action' => 'create', 'group' => true, 'item_id' => $itemId] + $m['base'];
        }
        // The single variant without options gets them from its row, or the item can't have more.
        if ($variantsChanged && count($live) === 1 && $live[0]['options'] === [] && !isset($rowsSeen[mb_strtolower((string) $live[0]['sku'])])) {
            return $skipAll($item['name'] . ' has one variant (' . $live[0]['sku'] . ') with no ' . implode(' or ', $wanted) . '; add a row for it too');
        }
        if (count($list) > ItemService::MAX_VARIANTS) {
            return $skipAll('An item can have at most ' . ItemService::MAX_VARIANTS . ' variants');
        }

        // The item's own fields come from the first row, as for an item without variants.
        $itemPlan = $this->itemChanges($tenantId, $itemId, $leader['fields'], $leader['names']);
        $fields = $itemPlan['fields'];
        $beforeFields = $itemPlan['before'];
        if ($variantsChanged) {
            $fields['variants'] = $list;
            $beforeFields['variants'] = $before;
        }
        if ($fields === []) {
            return $out;
        }
        if ($itemPlan['fields'] !== [] && ($out[$leaderIndex]['action'] ?? '') === 'skip' && ($out[$leaderIndex]['group'] ?? false) === true) {
            $out[$leaderIndex] = ['action' => 'update', 'group' => true, 'item_id' => $itemId, 'changes' => array_keys($itemPlan['fields'])] + $leader['base'];
        }
        // The change is made once, from the first row that isn't skipped (or the first row).
        $carrier = $leaderIndex;
        foreach ($out as $i => $row) {
            if ($row['action'] !== 'skip' && ($row['group'] ?? false) === true) {
                $carrier = $i;
                break;
            }
        }
        $out[$carrier]['op'] = ['update', $itemId, $fields, $beforeFields];

        return $out;
    }

    /**
     * @param array<string, mixed> $m a planned row: `values`, `options` and `fields`
     * @param array<string, mixed> $itemFields the item's prices, which a variant with the same one doesn't repeat
     * @return array<string, mixed> a variant as `ItemService` takes it
     */
    private static function variantInput(?int $id, array $m, array $itemFields): array
    {
        /** @var array<string, string> $values */
        $values = $m['values'];
        /** @var array<string, mixed> $fields */
        $fields = $m['fields'];
        $variant = ['id' => $id, 'sku' => $values['sku'], 'options' => $m['options']];
        if (($values['barcode'] ?? '') !== '') {
            $variant['barcodes'] = [['code' => $values['barcode']]];
        }
        foreach (['sale_price_minor', 'purchase_price_minor'] as $price) {
            if (isset($fields[$price]) && $fields[$price] !== ($itemFields[$price] ?? null)) {
                $variant[$price] = $fields[$price];
            }
        }

        return $variant;
    }

    /**
     * @param array<string, mixed> $shown a variant as `ItemService::show` gives it
     * @return array<string, mixed> the same variant as `ItemService::update` takes it
     */
    private static function variantFromShown(array $shown): array
    {
        return [
            'id' => (int) $shown['id'],
            'sku' => (string) $shown['sku'],
            'options' => $shown['options'],
            'barcodes' => array_map(static fn (array $b): array => ['code' => (string) $b['code'], 'unit' => $b['unit'] ?? null], $shown['barcodes']),
            'sale_price_minor' => $shown['sale_price_minor'],
            'purchase_price_minor' => $shown['purchase_price_minor'],
        ];
    }

    /**
     * @param array<string, string> $v
     * @param array<string, mixed> $base
     * @return array<string, mixed>
     */
    private function planItem(int $tenantId, array $v, string $currency, array $base): array
    {
        $parsed = $this->itemFields($v, $currency, $base);
        if (isset($parsed['action'])) {
            return $parsed;
        }
        ['fields' => $fields, 'names' => $names] = $parsed;

        $itemId = $this->items->itemIdBySku($tenantId, $v['sku']);
        if ($itemId === null) {
            if ($names === []) {
                return ['action' => 'skip', 'reason' => 'No name for a new item'] + $base;
            }
            $unit = ($v['unit'] ?? '') !== '' ? $v['unit'] : 'pc';
            if ($this->unitId($tenantId, $unit) === null) {
                return ['action' => 'skip', 'reason' => 'There is no unit "' . $unit . '"; add it under Units first'] + $base;
            }
            if (($v['barcode'] ?? '') !== '' && $this->barcodeTaken($tenantId, $v['barcode'])) {
                return ['action' => 'skip', 'reason' => 'The barcode belongs to another item'] + $base;
            }
            $create = $fields + ['name' => $names, 'unit' => $unit, 'sku' => $v['sku']];
            if (($v['barcode'] ?? '') !== '') {
                $create['barcodes'] = [['code' => $v['barcode']]];
            }

            return ['action' => 'create', 'fields' => $create] + $base;
        }

        $row = $this->items->row($tenantId, $itemId) ?? [];
        if (($row['archived_at'] ?? null) !== null) {
            return ['action' => 'skip', 'reason' => 'The item with this SKU is archived'] + $base;
        }
        ['fields' => $changed, 'before' => $before] = $this->itemChanges($tenantId, $itemId, $fields, $names);
        if ($changed === []) {
            return ['action' => 'skip', 'reason' => 'Already up to date', 'item_id' => $itemId] + $base;
        }

        return ['action' => 'update', 'item_id' => $itemId, 'fields' => $changed, 'before' => $before, 'changes' => array_keys($changed)] + $base;
    }

    /**
     * What an item row says about the item, or why it is skipped.
     *
     * @param array<string, string> $v
     * @param array<string, mixed> $base
     * @return array{fields: array<string, mixed>, names: array<string, string>}|array<string, mixed>
     */
    private function itemFields(array $v, string $currency, array $base): array
    {
        $fields = [];
        foreach (['sale_price' => 'sale_price_minor', 'purchase_price' => 'purchase_price_minor'] as $target => $field) {
            if (($v[$target] ?? '') === '') {
                continue;
            }
            try {
                $amount = Currency::parse(str_replace([',', ' '], '', $v[$target]), $currency);
            } catch (MoneyException) {
                return ['action' => 'skip', 'reason' => self::TARGETS['items'][$target][0] . ' is not an amount in ' . $currency] + $base;
            }
            if ($amount < 0) {
                return ['action' => 'skip', 'reason' => self::TARGETS['items'][$target][0] . ' can\'t be below zero'] + $base;
            }
            $fields[$field] = $amount;
        }
        if (($v['kind'] ?? '') !== '') {
            $kind = self::kindWord($v['kind']);
            if ($kind === null) {
                return ['action' => 'skip', 'reason' => 'The kind is not one Whity understands (stocked, consumable or service)'] + $base;
            }
            $fields['kind'] = $kind;
        }
        foreach (['tax_category' => 40, 'description' => 2000] as $target => $max) {
            if (($v[$target] ?? '') !== '') {
                if (mb_strlen($v[$target]) > $max) {
                    return ['action' => 'skip', 'reason' => self::TARGETS['items'][$target][0] . ' is longer than ' . $max . ' characters'] + $base;
                }
                $fields[$target] = $v[$target];
            }
        }
        $names = [];
        if (($v['name'] ?? '') !== '') {
            $names[\Whity\Core\i18n\LocalizedText::guessLanguage($v['name']) === 'ar' && ($v['name_ar'] ?? '') === '' ? 'ar' : 'en'] = $v['name'];
        }
        if (($v['name_ar'] ?? '') !== '') {
            $names['ar'] = $v['name_ar'];
        }
        foreach ($names as $name) {
            if (mb_strlen($name) > 200) {
                return ['action' => 'skip', 'reason' => 'The name is longer than 200 characters'] + $base;
            }
        }

        return ['fields' => $fields, 'names' => $names];
    }

    /**
     * The item's fields a row changes, with their values before.
     *
     * @param array<string, mixed> $fields
     * @param array<string, string> $names
     * @return array{fields: array<string, mixed>, before: array<string, mixed>}
     */
    private function itemChanges(int $tenantId, int $itemId, array $fields, array $names): array
    {
        $row = $this->items->row($tenantId, $itemId) ?? [];
        $changed = [];
        $before = [];
        $current = InventoryNames::decode($row['names'] ?? null, (string) ($row['name'] ?? ''));
        if ($names !== [] && array_diff_assoc($names, $current) !== []) {
            $changed['name'] = $names + $current;
            $before['name'] = $current;
        }
        foreach ($fields as $field => $value) {
            $was = $row[$field] ?? null;
            if ($field === 'sale_price_minor' || $field === 'purchase_price_minor') {
                $was = InventoryDb::int($was);
            }
            if ($was !== $value) {
                $changed[$field] = $value;
                $before[$field] = $was;
            }
        }

        return ['fields' => $changed, 'before' => $before];
    }

    /**
     * @param array<string, string> $v
     * @param array<string, mixed> $options
     * @param array<string, mixed> $base
     * @return array<string, mixed>
     */
    private function planStock(int $tenantId, array $v, array $options, string $currency, array $base): array
    {
        $itemId = $this->items->itemIdBySku($tenantId, $v['sku']);
        $variant = $itemId === null ? null : $this->db->one(
            'SELECT v.id, i.kind, v.archived_at FROM inv_variants v JOIN inv_items i ON i.tenant_id = v.tenant_id AND i.id = v.item_id
              WHERE v.tenant_id = :tenant_id AND v.sku = :sku',
            [':tenant_id' => $tenantId, ':sku' => $v['sku']]
        );
        if ($variant === null) {
            return ['action' => 'skip', 'reason' => 'No item has this SKU; import the items first'] + $base;
        }
        if ($variant['kind'] === 'service') {
            return ['action' => 'skip', 'reason' => 'A service has no stock'] + $base;
        }
        $placeId = ($v['place'] ?? '') !== '' ? $this->place($tenantId, $v['place'], false) : InventoryDb::int($options['location_id'] ?? null);
        if ($placeId === null) {
            return ['action' => 'skip', 'reason' => ($v['place'] ?? '') !== '' ? 'There is no place called "' . $v['place'] . '"' : 'No place'] + $base;
        }
        $quantity = $v['quantity'] ?? '';
        if (preg_match('/^\d{1,15}(\.\d+)?$/', $quantity) !== 1 || (float) $quantity <= 0.0) {
            return ['action' => 'skip', 'reason' => 'The quantity is not a number above zero'] + $base;
        }
        $cost = null;
        if (($v['unit_cost'] ?? '') !== '') {
            try {
                $cost = Currency::parse(str_replace([',', ' '], '', $v['unit_cost']), $currency);
            } catch (MoneyException) {
                return ['action' => 'skip', 'reason' => 'The unit cost is not an amount in ' . $currency] + $base;
            }
            if ($cost < 0) {
                return ['action' => 'skip', 'reason' => 'The unit cost can\'t be below zero'] + $base;
            }
        }

        return ['action' => 'create', 'line' => [
            'variant_id' => (int) $variant['id'], 'quantity' => $quantity, 'unit' => ($v['unit'] ?? '') !== '' ? $v['unit'] : null,
            'from_location_id' => $this->workspace->virtualId($tenantId, 'opening'), 'to_location_id' => $placeId,
            'unit_cost_minor' => $cost, 'currency' => $cost === null ? null : $currency,
        ]] + $base;
    }

    // ── applying ─────────────────────────────────────────────────────────

    /** @param list<array<string, mixed>> $plan */
    private function applyItems(int $tenantId, int $jobId, array $plan, ?int $actor, ImportJobRepository $jobs): void
    {
        foreach ($plan as $row) {
            if (($row['group'] ?? false) === true) {
                // Variants of one item: the row carrying the item's change makes it.
                $op = $row['op'] ?? null;
                if (is_array($op) && $op[0] === 'create') {
                    $item = $this->items->create($tenantId, $op[1], $actor);
                    $jobs->recordChange($tenantId, $jobId, 'inventory.item', (int) $item['id'], 'created', null);
                } elseif (is_array($op) && $op[0] === 'update') {
                    $this->items->update($tenantId, (int) $op[1], $op[2], $actor);
                    $jobs->recordChange($tenantId, $jobId, 'inventory.item', (int) $op[1], 'updated', $op[3]);
                }
                continue;
            }
            if ($row['action'] === 'create') {
                $item = $this->items->create($tenantId, $row['fields'], $actor);
                $jobs->recordChange($tenantId, $jobId, 'inventory.item', (int) $item['id'], 'created', null);
            } elseif ($row['action'] === 'update') {
                $this->items->update($tenantId, (int) $row['item_id'], $row['fields'], $actor);
                $jobs->recordChange($tenantId, $jobId, 'inventory.item', (int) $row['item_id'], 'updated', $row['before']);
            }
        }
    }

    /** @param list<array<string, mixed>> $plan */
    private function applySupplierPrices(int $tenantId, int $jobId, array $plan, ImportJobRepository $jobs): void
    {
        foreach ($plan as $row) {
            if ($row['action'] === 'create' || $row['action'] === 'update') {
                $saved = $this->supplierPrices()->apply($tenantId, $row);
                $jobs->recordChange(
                    $tenantId, $jobId, 'inventory.supplier_item', $saved['id'], $row['action'] === 'create' ? 'created' : 'updated',
                    $row['action'] === 'update' ? $row['before'] : null, ['unpreferred' => $saved['unpreferred']]
                );
            }
        }
    }

    /** @param list<array<string, mixed>> $plan */
    private function applyStock(int $tenantId, int $jobId, array $plan, ?int $actor, ImportJobRepository $jobs, string $fileName): void
    {
        $lines = [];
        foreach ($plan as $row) {
            if ($row['action'] === 'create') {
                $lines[] = $row['line'];
            }
        }
        if ($lines === []) {
            return;
        }
        // One batch per 500 lines, the ledger's most at once; undo reverses each.
        foreach (array_chunk($lines, 500) as $chunk) {
            $posted = $this->ledger->post($tenantId, $actor, $chunk, [
                'source_type' => 'opening', 'source_id' => $jobId, 'note' => mb_substr('Imported from ' . $fileName, 0, 500),
            ]);
            $first = (int) ($posted['moves'][0]['id'] ?? 0);
            $jobs->recordChange($tenantId, $jobId, 'inventory.stock', $first, 'posted', null, ['batch_key' => $posted['batch_key']]);
        }
    }

    private function undoStock(int $tenantId, string $batchKey, ?int $actor): void
    {
        if ($batchKey === '') {
            return;
        }
        $moves = $this->ledger->batch($tenantId, $batchKey);
        $last = 0;
        foreach ($moves as $m) {
            $last = max($last, (int) $m['id']);
        }
        foreach ($moves as $m) {
            $later = $this->db->one(
                'SELECT id FROM inv_moves WHERE tenant_id = :tenant_id AND variant_id = :variant_id AND id > :last
                    AND (from_location_id = :place OR to_location_id = :place) LIMIT 1',
                [':tenant_id' => $tenantId, ':variant_id' => (int) $m['variant_id'], ':last' => $last, ':place' => (int) $m['to_location_id']]
            );
            if ($later !== null) {
                throw new InventoryRejectedException('Some of this stock has moved since it was imported, so the import can\'t be undone. Adjust the stock instead.', 409);
            }
        }
        $this->ledger->reverse($tenantId, $batchKey, $actor, 'Import undone', 'opening');
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function jobs(string $kind): ImportJobRepository
    {
        return $this->jobs[$kind] ?? throw InventoryRejectedException::field('kind', 'kind is items, opening_stock or supplier_prices');
    }

    private function supplierPrices(): SupplierPriceRows
    {
        return $this->supplierPrices ?? throw new \LogicException('Supplier prices need SupplierItems');
    }

    private function supplierItemsFor(): SupplierItems
    {
        return $this->supplierItems ?? throw new \LogicException('Supplier prices need SupplierItems');
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function toApi(string $kind, array $job): array
    {
        $names = [];
        foreach (self::TARGETS[$kind] as $key => [$label, $headings]) {
            $names[$key] = array_merge([$label], $headings);
        }
        /** @var list<list<string>> $sample */
        $sample = array_slice($job['rows'], 0, 200);
        /** @var list<string> $header */
        $header = $job['header'];
        $now = $this->jobs($kind)->now();

        return [
            'id' => $job['id'],
            'kind' => $kind,
            'file_name' => $job['file_name'],
            'status' => $job['status'],
            'row_count' => count($job['rows']),
            'columns' => $job['status'] === 'undone' ? [] : ColumnMatcher::suggest($header, $sample, $names, false),
            'targets' => self::targets($kind),
            'options' => $job['options'] === [] ? null : $job['options'],
            'summary' => $job['summary'] === [] ? null : $job['summary'],
            'imported_at' => $job['imported_at'],
            'undone_at' => $job['undone_at'],
            'undo_until' => $job['imported_at'] !== null && $job['status'] === 'imported' ? self::undoUntil($job['imported_at']) : null,
            'can_undo' => $job['status'] === 'imported' && $job['imported_at'] !== null && $this->undoable($job['imported_at'], $now),
            'created_at' => $job['created_at'],
        ];
    }

    /** A place that holds stock, by id (as text), code or name in either language. */
    private function place(int $tenantId, string $ref, bool $byId): ?int
    {
        $rows = $this->db->all(
            "SELECT id, code, name, names FROM inv_locations WHERE tenant_id = :tenant_id AND kind <> 'virtual' AND is_active = TRUE",
            [':tenant_id' => $tenantId]
        );
        $want = mb_strtolower(trim($ref));
        foreach ($rows as $r) {
            if ($byId) {
                if ((string) $r['id'] === $ref) {
                    return (int) $r['id'];
                }
                continue;
            }
            $names = array_map('mb_strtolower', InventoryNames::decode($r['names'] ?? null, (string) $r['name']));
            if ($want === mb_strtolower((string) $r['code']) || $want === mb_strtolower((string) $r['name']) || in_array($want, $names, true)) {
                return (int) $r['id'];
            }
        }

        return null;
    }

    private function unitId(int $tenantId, string $code): ?int
    {
        return InventoryDb::int($this->db->one(
            'SELECT id FROM inv_units WHERE tenant_id = :tenant_id AND code = :code AND archived_at IS NULL',
            [':tenant_id' => $tenantId, ':code' => $code]
        )['id'] ?? null);
    }

    private function barcodeTaken(int $tenantId, string $code): bool
    {
        return $this->db->one('SELECT id FROM inv_barcodes WHERE tenant_id = :tenant_id AND code = :code', [':tenant_id' => $tenantId, ':code' => $code]) !== null;
    }

    private static function kindWord(string $raw): ?string
    {
        $w = mb_strtolower(trim($raw));
        foreach (self::KIND_WORDS as $kind => $words) {
            if (in_array($w, $words, true)) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $plan
     * @return array<string, mixed>
     */
    private static function summary(array $plan): array
    {
        $counts = ['create' => 0, 'update' => 0, 'skip' => 0];
        $rows = [];
        foreach ($plan as $row) {
            $counts[$row['action']]++;
            if (count($rows) < self::MAX_LISTED) {
                $rows[] = array_filter([
                    'row' => $row['row'],
                    'action' => $row['action'],
                    'name' => $row['name'],
                    'item_id' => $row['item_id'] ?? null,
                    'reason' => $row['reason'] ?? null,
                    'changes' => $row['changes'] !== [] ? $row['changes'] : null,
                ], static fn ($v): bool => $v !== null);
            }
        }

        return $counts + ['rows' => $rows, 'fixes' => [], 'tags' => []];
    }

    /** @return array{create: int, update: int, skip: int}|null */
    private static function counts(mixed $summary): ?array
    {
        if (!is_array($summary) || !isset($summary['create'])) {
            return null;
        }

        return ['create' => (int) $summary['create'], 'update' => (int) $summary['update'], 'skip' => (int) $summary['skip']];
    }

    private function undoable(string $importedAt, int $now): bool
    {
        $at = strtotime($importedAt);

        return $at !== false && $now - $at <= self::UNDO_SECONDS;
    }

    private static function undoUntil(string $importedAt): ?string
    {
        $at = strtotime($importedAt);

        return $at === false ? null : gmdate('Y-m-d\TH:i:s\Z', $at + self::UNDO_SECONDS);
    }
}
