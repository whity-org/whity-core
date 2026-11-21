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
 * Opening stock posts one batch `opening → place` at the given unit cost, to
 * the place named in the row or the one chosen for the whole file.
 *
 * Importing never refuses because of a plan limit (IM.8): items past the
 * base only warn, on Billing and the plan banner.
 */
final class InventoryImports
{
    public const UNDO_SECONDS = 86400;
    public const MAX_LISTED = 200;

    /** Kind in the API => kind on the job. */
    public const KINDS = ['items' => 'inventory.items', 'opening_stock' => 'inventory.opening_stock'];

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
        ],
        'opening_stock' => [
            'sku' => ['SKU', ['sku', 'code', 'item code', 'item no', 'الرمز', 'رمز', 'رمز الصنف', 'كود']],
            'quantity' => ['Quantity', ['quantity', 'qty', 'on hand', 'stock', 'count', 'الكمية', 'الرصيد']],
            'unit' => ['Unit', ['unit', 'uom', 'الوحدة']],
            'place' => ['Place', ['place', 'location', 'warehouse', 'store', 'branch', 'المكان', 'المستودع', 'الموقع', 'الفرع']],
            'unit_cost' => ['Unit cost', ['unit cost', 'cost', 'cost price', 'التكلفة', 'كلفة الوحدة', 'سعر الكلفة']],
        ],
    ];

    private const KIND_WORDS = [
        'stocked' => ['stocked', 'stock', 'product', 'goods', 'مخزون', 'منتج', 'سلعة'],
        'consumable' => ['consumable', 'supplies', 'مستهلك', 'مستهلكات'],
        'service' => ['service', 'خدمة'],
    ];

    /** @var array<string, ImportJobRepository> */
    private array $jobs = [];

    /** @param \Closure(int): string $currency the workspace's currency */
    public function __construct(
        private readonly InventoryDb $db,
        private readonly InventoryWorkspace $workspace,
        private readonly ItemService $items,
        private readonly StockLedger $ledger,
        private readonly \Closure $currency,
    ) {
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
                if ($change['entity_type'] === 'inventory.stock') {
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
        $required = $kind === 'items' ? ['sku' => 'SKU'] : ['sku' => 'SKU', 'quantity' => 'quantity'];
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
        $currency = ($this->currency)($tenantId);
        $plan = [];
        $seen = [];
        foreach ($rows as $i => $cells) {
            $rowNumber = $i + 2;
            $values = [];
            foreach ($mapping as $index => $target) {
                $values[$target] = trim(InventoryNames::westernDigits((string) ($cells[(int) $index] ?? '')));
            }
            $sku = $values['sku'] ?? '';
            $base = ['row' => $rowNumber, 'name' => $sku, 'changes' => [], 'before' => null];
            if ($sku === '') {
                $plan[] = ['action' => 'skip', 'reason' => 'No SKU'] + $base;
                continue;
            }
            if (mb_strlen($sku) > 64) {
                $plan[] = ['action' => 'skip', 'reason' => 'The SKU is longer than 64 characters'] + $base;
                continue;
            }
            $key = mb_strtolower($sku) . '|' . ($kind === 'opening_stock' ? mb_strtolower($values['place'] ?? '') : '');
            if (isset($seen[$key])) {
                $plan[] = ['action' => 'skip', 'reason' => 'Same ' . ($kind === 'items' ? 'item' : 'item and place') . ' as row ' . $seen[$key]] + $base;
                continue;
            }
            $seen[$key] = $rowNumber;
            $plan[] = $kind === 'items'
                ? $this->planItem($tenantId, $values, $currency, $base)
                : $this->planStock($tenantId, $values, $options, $currency, $base);
        }

        return $plan;
    }

    /**
     * @param array<string, string> $v
     * @param array<string, mixed> $base
     * @return array<string, mixed>
     */
    private function planItem(int $tenantId, array $v, string $currency, array $base): array
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
        if ($changed === []) {
            return ['action' => 'skip', 'reason' => 'Already up to date', 'item_id' => $itemId] + $base;
        }

        return ['action' => 'update', 'item_id' => $itemId, 'fields' => $changed, 'before' => $before, 'changes' => array_keys($changed)] + $base;
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
        return $this->jobs[$kind] ?? throw InventoryRejectedException::field('kind', 'kind is items or opening_stock');
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
