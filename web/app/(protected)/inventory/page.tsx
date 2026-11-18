'use client';

/**
 * /inventory (WHIT-1232, design section 31, `TplInventory`): stock in every
 * place. Three tabs for now (Batches, Recipes, Counts and Transfers join with
 * their phases):
 *
 * - Stock: each item against each place, as on the card; a figure below zero
 *   is red (register IV.8).
 * - Items: the list, with search over names in both languages, SKUs and
 *   barcodes, and a kind filter.
 * - Places: warehouses, shops and vans.
 *
 * New item is the one main button (New place on the Places tab). Reorder
 * levels, expiry and stock value join the tiles in P2, P4 and P7.
 */

import { useEffect, useMemo, useState } from 'react';
import { useRouter } from 'next/navigation';
import { AdminHeader } from '@/components/admin/admin-header';
import { DataTable, type DataTableColumn } from '@/components/ui/data-table';
import { LoadProblem } from '@/components/load-problem';
import { NoAccess } from '@/components/no-access';
import { ItemFormDialog, kindLabel } from '@/components/inventory/item-form-dialog';
import { PlaceFormDialog } from '@/components/inventory/place-form-dialog';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useFetch } from '@/hooks/useFetch';
import { Badge } from '@whity/ui/badge';
import { Button } from '@whity/ui/button';
import { Icon } from '@whity/ui/icon';
import { KpiStrip } from '@whity/ui/kpi-strip';
import { Money } from '@whity/ui/money/money';
import { EmptyFiltered } from '@whity/ui/page-states';
import { SearchField } from '@whity/ui/search-field';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@whity/ui/select';
import { Tabs, TabsList, TabsTrigger } from '@whity/ui/tabs';
import { useLanguageCode, useTranslation } from '@whity/features/i18n';
import {
  INVENTORY_READ,
  INVENTORY_WRITE,
  ITEM_KINDS,
  inventoryApi,
  nameIn,
  quantityText,
  type InventoryItem,
  type InventoryLocation,
  type ItemKind,
} from '@/lib/inventory/api';

type Tab = 'stock' | 'items' | 'places';
const PER_PAGE = 50;

export default function InventoryPage() {
  const t = useTranslation('admin');
  const language = useLanguageCode();
  const router = useRouter();
  const { has, loading: accessLoading } = useCapabilities();
  const canRead = has(INVENTORY_READ);
  const canWrite = has(INVENTORY_WRITE);

  const [tab, setTab] = useState<Tab>('stock');
  const [search, setSearch] = useState('');
  const [query, setQuery] = useState('');
  const [kind, setKind] = useState<ItemKind | null>(null);
  const [page, setPage] = useState(1);
  const [addingItem, setAddingItem] = useState(false);
  const [editingPlace, setEditingPlace] = useState<InventoryLocation | 'new' | null>(null);

  useEffect(() => {
    const id = setTimeout(() => {
      setQuery(search.trim());
      setPage(1);
    }, 300);
    return () => clearTimeout(id);
  }, [search]);

  const meta = useFetch(async () => {
    const [units, places, levels, firstItems] = await Promise.all([
      inventoryApi.units(),
      inventoryApi.locations(),
      inventoryApi.levels({ nonzero: true }),
      inventoryApi.items({ perPage: 200 }),
    ]);
    return { units, places, levels, stockItems: firstItems.data, itemTotal: firstItems.pagination.total };
  }, []);

  const list = useFetch(
    () => inventoryApi.items({ q: query, kind, page, perPage: PER_PAGE }),
    [query, kind, page]
  );

  const places = meta.data?.places ?? [];
  const onHand = useMemo(() => {
    const map = new Map<string, number>();
    for (const level of meta.data?.levels ?? []) {
      const key = `${level.item_id}:${level.location_id}`;
      map.set(key, (map.get(key) ?? 0) + Number(level.on_hand));
    }
    return map;
  }, [meta.data]);
  const belowZero = (meta.data?.levels ?? []).filter((l) => Number(l.on_hand) < 0).length;

  if (!accessLoading && !canRead) {
    return <NoAccess area={t('inventory.area', 'inventory')} permission={INVENTORY_READ} />;
  }

  const itemName = (item: InventoryItem) => nameIn(item.names, item.name, language);
  const skuText = (item: InventoryItem) =>
    item.variants.length === 1
      ? item.variants[0].sku
      : t('inventory.variantCount', '{count} variants', { count: String(item.variants.filter((v) => !v.archived).length) });

  const nameCell = (row: InventoryItem) => (
    <div className="flex items-center gap-3">
      <span className="flex size-8 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
        <Icon name="package" size={16} />
      </span>
      <div className="min-w-0">
        <div className="truncate font-medium">{itemName(row)}</div>
        <div className="text-xs text-muted-foreground">
          <bdi dir="ltr" className="font-mono">
            {skuText(row)}
          </bdi>{' '}
          · {kindLabel(t, row.kind)}
        </div>
      </div>
    </div>
  );

  const quantityCell = (value: number | null, unit: string) =>
    value === null ? (
      <span className="text-muted-foreground">–</span>
    ) : (
      <bdi dir="ltr" className={`font-mono tabular-nums ${value < 0 ? 'text-destructive' : ''}`}>
        {quantityText(String(Math.round(value * 1000) / 1000))} <span className="text-xs text-muted-foreground">{unit}</span>
      </bdi>
    );

  const stockColumns: DataTableColumn<InventoryItem>[] = [
    { id: 'item', header: t('inventory.column.item', 'Item'), cell: nameCell },
    ...places.map(
      (place): DataTableColumn<InventoryItem> => ({
        id: `place-${place.id}`,
        header: nameIn(place.names, place.name, language),
        cell: (row) => {
          const value = onHand.get(`${row.id}:${place.id}`);
          return quantityCell(value === undefined ? null : value, row.base_unit.code);
        },
      })
    ),
    {
      id: 'total',
      header: t('inventory.column.total', 'All places'),
      cell: (row) => quantityCell(Number(row.on_hand), row.base_unit.code),
    },
  ];

  const itemColumns: DataTableColumn<InventoryItem>[] = [
    { id: 'item', header: t('inventory.column.item', 'Item'), cell: nameCell },
    {
      id: 'onHand',
      header: t('inventory.column.onHand', 'In stock'),
      cell: (row) => (row.kind === 'service' ? <span className="text-muted-foreground">–</span> : quantityCell(Number(row.on_hand), row.base_unit.code)),
    },
    {
      id: 'price',
      header: t('inventory.column.price', 'Selling price'),
      cell: (row) =>
        row.sale_price_minor != null && row.currency ? (
          <Money minor={row.sale_price_minor} currency={row.currency} />
        ) : (
          <span className="text-muted-foreground">–</span>
        ),
    },
    {
      id: 'state',
      header: '',
      cell: (row) => (row.archived ? <Badge variant="secondary">{t('inventory.archived', 'Archived')}</Badge> : null),
    },
  ];

  const placeColumns: DataTableColumn<InventoryLocation>[] = [
    {
      id: 'name',
      header: t('inventory.column.place', 'Place'),
      cell: (row) => (
        <div className="flex items-center gap-3">
          <span className="flex size-8 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
            <Icon name="store" size={16} />
          </span>
          <div className="min-w-0">
            <div className="truncate font-medium">{nameIn(row.names, row.name, language)}</div>
            {row.address !== '' && <div className="truncate text-xs text-muted-foreground">{row.address}</div>}
          </div>
        </div>
      ),
    },
    {
      id: 'code',
      header: t('inventory.column.code', 'Code'),
      cell: (row) => (
        <bdi dir="ltr" className="font-mono text-sm">
          {row.code}
        </bdi>
      ),
    },
    {
      id: 'items',
      header: t('inventory.column.itemsHere', 'Items here'),
      cell: (row) => (
        <span className="font-mono tabular-nums">
          {(meta.data?.levels ?? []).filter((l) => l.location_id === row.id && Number(l.on_hand) !== 0).length}
        </span>
      ),
    },
  ];

  const pagination = list.data?.pagination;
  const filtered = query !== '' || kind !== null;

  const mainAction =
    tab === 'places'
      ? canWrite && (
          <Button onClick={() => setEditingPlace('new')} data-testid="inventory-new-place">
            <Icon name="plus" />
            {t('inventory.place.new', 'New place')}
          </Button>
        )
      : canWrite && (
          <Button onClick={() => setAddingItem(true)} data-testid="inventory-new-item">
            <Icon name="plus" />
            {t('inventory.item.new', 'New item')}
          </Button>
        );

  return (
    <div className="space-y-4">
      <AdminHeader
        title={t('inventory.title', 'Inventory')}
        description={t('inventory.description', 'What you keep, in every place')}
        action={mainAction || undefined}
      />

      {meta.data && (
        <KpiStrip
          items={[
            { label: t('inventory.kpi.items', 'Items'), value: String(meta.data.itemTotal) },
            { label: t('inventory.kpi.places', 'Places'), value: String(places.length) },
            {
              label: t('inventory.kpi.belowZero', 'Below zero'),
              value: String(belowZero),
              note: belowZero > 0 ? t('inventory.kpi.belowZeroNote', 'Count these to set them right') : undefined,
              tone: belowZero > 0 ? 'bad' : undefined,
            },
          ]}
        />
      )}

      <Tabs value={tab} onValueChange={(v) => setTab(v as Tab)}>
        <TabsList>
          <TabsTrigger value="stock">{t('inventory.tab.stock', 'Stock')}</TabsTrigger>
          <TabsTrigger value="items">
            {t('inventory.tab.items', 'Items')}
            {meta.data && <span className="ms-2 font-mono text-xs">{meta.data.itemTotal}</span>}
          </TabsTrigger>
          <TabsTrigger value="places">
            {t('inventory.tab.places', 'Places')}
            {meta.data && <span className="ms-2 font-mono text-xs">{places.length}</span>}
          </TabsTrigger>
        </TabsList>
      </Tabs>

      {meta.error ? (
        <LoadProblem
          forbidden={meta.forbidden}
          message={t('inventory.error.load', 'The inventory could not be loaded.')}
          onRetry={meta.refetch}
          area={t('inventory.area', 'inventory')}
        />
      ) : tab === 'stock' ? (
        <DataTable
          columns={stockColumns}
          data={(meta.data?.stockItems ?? []).filter((item) => item.kind !== 'service')}
          isLoading={meta.loading && meta.data === null}
          getRowId={(row) => String(row.id)}
          onRowClick={(row) => router.push(`/inventory/items/${row.id}`)}
          emptyState={{
            title: t('inventory.empty.title', 'No items yet'),
            description: canWrite
              ? places.length === 0
                ? t('inventory.empty.noPlaces', 'Add the places you keep stock, then your items.')
                : t('inventory.empty.write', 'Add what you buy, sell, make or use, with the stock you have now.')
              : t('inventory.empty.read', 'Someone who keeps the inventory adds items here.'),
          }}
        />
      ) : tab === 'items' ? (
        <>
          <div className="flex flex-wrap items-end gap-3">
            <div className="w-full max-w-sm">
              <SearchField
                value={search}
                onValueChange={setSearch}
                placeholder={t('inventory.search', 'Search by name, SKU or barcode')}
                label={t('inventory.search.label', 'Search items')}
                clearLabel={t('inventory.search.clear', 'Clear search')}
              />
            </div>
            <div className="w-56">
              <Select
                value={kind ?? 'any'}
                onValueChange={(v) => {
                  setKind(v === 'any' ? null : (v as ItemKind));
                  setPage(1);
                }}
              >
                <SelectTrigger aria-label={t('inventory.filter.kind', 'Kind')}>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="any">{t('inventory.filter.kindAny', 'Every kind')}</SelectItem>
                  {ITEM_KINDS.map((k) => (
                    <SelectItem key={k} value={k}>
                      {kindLabel(t, k)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </div>
          {list.error ? (
            <LoadProblem
              forbidden={list.forbidden}
              message={t('inventory.error.items', 'The items could not be loaded.')}
              onRetry={list.refetch}
              area={t('inventory.area', 'inventory')}
            />
          ) : filtered && list.data && list.data.data.length === 0 ? (
            <EmptyFiltered
              title={t('inventory.filtered', 'No item matches these filters')}
              clearLabel={t('inventory.filtered.clear', 'Clear filters')}
              onClear={() => {
                setSearch('');
                setKind(null);
              }}
            />
          ) : (
            <DataTable
              columns={itemColumns}
              data={list.data?.data ?? []}
              isLoading={list.loading && list.data === null}
              getRowId={(row) => String(row.id)}
              onRowClick={(row) => router.push(`/inventory/items/${row.id}`)}
              pagination={
                pagination
                  ? {
                      pageIndex: pagination.page - 1,
                      pageSize: pagination.perPage,
                      pageCount: Math.max(1, pagination.totalPages),
                      total: pagination.total,
                      onPaginationChange: (index) => setPage(index + 1),
                    }
                  : undefined
              }
              emptyState={{
                title: t('inventory.empty.title', 'No items yet'),
                description: canWrite
                  ? t('inventory.empty.write', 'Add what you buy, sell, make or use, with the stock you have now.')
                  : t('inventory.empty.read', 'Someone who keeps the inventory adds items here.'),
              }}
            />
          )}
        </>
      ) : (
        <DataTable
          columns={placeColumns}
          data={places}
          isLoading={meta.loading && meta.data === null}
          getRowId={(row) => String(row.id)}
          onRowClick={canWrite ? (row) => setEditingPlace(row) : undefined}
          emptyState={{
            title: t('inventory.places.empty', 'No places yet'),
            description: t('inventory.places.emptyHint', 'Add each warehouse, shop or van where you keep stock.'),
          }}
        />
      )}

      {addingItem && meta.data && (
        <ItemFormDialog
          open={addingItem}
          onOpenChange={setAddingItem}
          item={null}
          units={meta.data.units}
          places={places}
          onSaved={(item) => router.push(`/inventory/items/${item.id}`)}
        />
      )}
      {editingPlace !== null && (
        <PlaceFormDialog
          open
          onOpenChange={(open) => {
            if (!open) setEditingPlace(null);
          }}
          place={editingPlace === 'new' ? null : editingPlace}
          onSaved={() => {
            setEditingPlace(null);
            meta.refetch();
          }}
        />
      )}
    </div>
  );
}
