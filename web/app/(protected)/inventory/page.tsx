'use client';

/**
 * /inventory (WHIT-1232, WHIT-1233, design section 31, `TplInventory`): stock
 * in every place. As on the card, the tabs sit under the header and the
 * numbers under the tabs:
 *
 * - Stock: each item against each place, with its reorder level; a figure
 *   below zero or under its place's level is red, with "Low at <place>" or
 *   "Below reorder" beside it (IV.8, IV.11). Items and Below reorder lead the
 *   numbers; Expiring and Stock value join with lots (P4) and cost (P7).
 * - Items: the list, with search over names in both languages, SKUs and
 *   barcodes, and a kind filter.
 * - Places: warehouses, shops and vans.
 * - Counts and Transfers (P2): newest first; a count opens its page, a
 *   transfer on the way opens to be received.
 * - Purchases (P3, D10: a tab here until Money arrives): purchase orders;
 *   New purchase order is the main button on it.
 *
 * Count stock and Transfer are outline buttons beside the one main button,
 * New item (New place on the Places tab). Waste and adjustments has its own
 * page, linked beside the tabs.
 */

import { useEffect, useMemo, useState } from 'react';
import Link from 'next/link';
import { useRouter, useSearchParams } from 'next/navigation';
import { AdminHeader } from '@/components/admin/admin-header';
import { DataTable, type DataTableColumn } from '@/components/ui/data-table';
import { LoadProblem } from '@/components/load-problem';
import { NoAccess } from '@/components/no-access';
import { UserText } from '@/components/ui/user-text';
import { ItemFormDialog, kindLabel } from '@/components/inventory/item-form-dialog';
import { PlaceFormDialog } from '@/components/inventory/place-form-dialog';
import { CountStartDialog } from '@/components/inventory/count-start-dialog';
import { CountsTab, TransfersTab } from '@/components/inventory/operations-tabs';
import { ReceiveTransferDialog } from '@/components/inventory/receive-transfer-dialog';
import { TransferDialog } from '@/components/inventory/transfer-dialog';
import { PurchaseOrderDialog } from '@/components/inventory/purchase-order-dialog';
import { PurchaseOrdersTab } from '@/components/inventory/purchase-orders-tab';
import { useWorkspaceCurrency } from '@/lib/branding-context';
import { useToast } from '@/lib/toast-context';
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
  PURCHASES_READ,
  PURCHASES_WRITE,
  STOCK_COUNT,
  STOCK_TRANSFER,
  inventoryApi,
  inventoryErrorText,
  nameIn,
  quantityText,
  type InventoryItem,
  type InventoryLocation,
  type InventoryOperation,
  type InventoryReorderRule,
  type ItemKind,
} from '@/lib/inventory/api';

type Tab = 'stock' | 'items' | 'places' | 'counts' | 'transfers' | 'purchases';
const TABS: Tab[] = ['stock', 'items', 'places', 'counts', 'transfers', 'purchases'];
const PER_PAGE = 50;

export default function InventoryPage() {
  const t = useTranslation('admin');
  const language = useLanguageCode();
  const router = useRouter();
  const { has, loading: accessLoading } = useCapabilities();
  const canRead = has(INVENTORY_READ);
  const canWrite = has(INVENTORY_WRITE);
  const canCount = has(STOCK_COUNT);
  const canTransfer = has(STOCK_TRANSFER);
  const canSeePurchases = has(PURCHASES_READ);
  const canPurchase = has(PURCHASES_WRITE);
  const currency = useWorkspaceCurrency();
  const toast = useToast();

  // A tab can be linked to (?tab=counts), as the count page's way back does.
  const wanted = useSearchParams().get('tab');
  const [tab, setTab] = useState<Tab>(wanted !== null && (TABS as string[]).includes(wanted) ? (wanted as Tab) : 'stock');
  const [counting, setCounting] = useState(false);
  const [transferring, setTransferring] = useState(false);
  const [receiving, setReceiving] = useState<InventoryOperation | null>(null);
  const [ordering, setOrdering] = useState(false);

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
    const [units, places, levels, firstItems, rules, categories, settings] = await Promise.all([
      inventoryApi.units(),
      inventoryApi.locations(),
      inventoryApi.levels({ nonzero: true }),
      inventoryApi.items({ perPage: 200 }),
      inventoryApi.reorderRules(),
      inventoryApi.categories(),
      inventoryApi.settings().catch(() => null),
    ]);
    return {
      units,
      places,
      levels,
      rules,
      categories,
      settings,
      stockItems: firstItems.data,
      itemTotal: firstItems.pagination.total,
    };
  }, []);
  const counts = useFetch(() => (tab === 'counts' ? inventoryApi.operations({ kind: 'count' }) : Promise.resolve(null)), [tab]);
  const transfers = useFetch(
    () => (tab === 'transfers' ? inventoryApi.operations({ kind: 'transfer' }) : Promise.resolve(null)),
    [tab]
  );
  const purchases = useFetch(
    () => (tab === 'purchases' && canSeePurchases ? inventoryApi.purchaseOrders() : Promise.resolve(null)),
    [tab, canSeePurchases]
  );

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
  // Reorder levels by item and place, and the items under one now (IV.11).
  const rules = useMemo(() => {
    const byItemPlace = new Map<string, InventoryReorderRule>();
    for (const rule of meta.data?.rules ?? []) {
      if (rule.is_active) byItemPlace.set(`${rule.item_id}:${rule.location_id}`, rule);
    }
    return byItemPlace;
  }, [meta.data]);
  const belowRules = (meta.data?.rules ?? []).filter((r) => r.is_active && r.is_below);
  const belowItems = [...new Map(belowRules.map((r) => [r.item_id, r])).values()];

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
        <UserText className="block truncate font-medium">{itemName(row)}</UserText>
        <div className="text-xs text-muted-foreground">
          <bdi dir="ltr" className="font-mono">
            {skuText(row)}
          </bdi>{' '}
          · {kindLabel(t, row.kind)}
        </div>
      </div>
    </div>
  );

  const quantityCell = (value: number | null, unit: string, low = false) =>
    value === null ? (
      <span className={low ? 'text-destructive' : 'text-muted-foreground'}>–</span>
    ) : (
      <bdi dir="ltr" className={`font-mono tabular-nums ${value < 0 || low ? 'text-destructive' : ''}`}>
        {quantityText(String(Math.round(value * 1000) / 1000))} <span className="text-xs text-muted-foreground">{unit}</span>
      </bdi>
    );
  const placeName = (id: number) => {
    const place = places.find((p) => p.id === id);
    return place ? nameIn(place.names, place.name, language) : '';
  };
  const itemRules = (item: InventoryItem) => places.map((p) => rules.get(`${item.id}:${p.id}`)).filter((r) => r !== undefined);

  const stockColumns: DataTableColumn<InventoryItem>[] = [
    { id: 'item', header: t('inventory.column.item', 'Item'), cell: nameCell },
    ...places.map(
      (place): DataTableColumn<InventoryItem> => ({
        id: `place-${place.id}`,
        header: nameIn(place.names, place.name, language),
        cell: (row) => {
          const value = onHand.get(`${row.id}:${place.id}`);
          return quantityCell(value === undefined ? null : value, row.base_unit.code, rules.get(`${row.id}:${place.id}`)?.is_below === true);
        },
      })
    ),
    {
      id: 'reorder',
      header: t('inventory.column.reorderAt', 'Reorder at'),
      cell: (row) => {
        // The low place's level when one place is low (as on the card), else each level once.
        const ruled = itemRules(row);
        const low = ruled.filter((r) => r.is_below);
        const levels = [...new Set((low.length === 1 ? low : ruled).map((r) => quantityText(r.min_quantity)))];
        if (levels.length === 0) return <span className="text-muted-foreground">–</span>;
        return (
          <bdi dir="ltr" className="font-mono tabular-nums">
            {levels.join(' / ')} <span className="text-xs text-muted-foreground">{row.base_unit.code}</span>
          </bdi>
        );
      },
    },
    {
      id: 'badge',
      header: '',
      cell: (row) => {
        const ruled = itemRules(row);
        const low = ruled.filter((r) => r.is_below);
        if (low.length === 0) return null;
        return (
          <Badge variant="destructive">
            {low.length === 1 && ruled.length > 1
              ? t('inventory.badge.lowAt', 'Low at {place}', { place: placeName(low[0].location_id) })
              : t('inventory.badge.belowReorder', 'Below reorder')}
          </Badge>
        );
      },
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
            <UserText className="block truncate font-medium">{nameIn(row.names, row.name, language)}</UserText>
            {row.address !== '' && (
              <div className="truncate text-xs text-muted-foreground">
                <UserText>{row.address}</UserText>
              </div>
            )}
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
    tab === 'purchases'
      ? canPurchase && (
          <Button onClick={() => setOrdering(true)} data-testid="inventory-new-po">
            <Icon name="plus" />
            {t('inventory.po.new', 'New purchase order')}
          </Button>
        )
      : tab === 'places'
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
  const actions = (
    <div className="flex flex-wrap items-center gap-2">
      {canCount && places.length > 0 && (
        <Button variant="outline" onClick={() => setCounting(true)} data-testid="inventory-count">
          <Icon name="checkbox" />
          {t('inventory.count.title', 'Count stock')}
        </Button>
      )}
      {canWrite && (
        <Button variant="outline" asChild data-testid="inventory-import">
          <Link href={tab === 'stock' ? '/inventory/import?kind=inventory.opening_stock' : '/inventory/import?kind=inventory.items'}>
            <Icon name="upload" />
            {t('inventory.import', 'Import')}
          </Link>
        </Button>
      )}
      {canTransfer && places.length > 1 && (
        <Button variant="outline" onClick={() => setTransferring(true)} data-testid="inventory-transfer">
          <Icon name="transfer" />
          {t('inventory.transfer.button', 'Transfer')}
        </Button>
      )}
      {mainAction}
    </div>
  );

  const openTransfer = async (row: InventoryOperation) => {
    try {
      setReceiving(await inventoryApi.operation(row.id));
    } catch (error) {
      toast.addToast(inventoryErrorText(error), 'error');
    }
  };

  return (
    <div className="space-y-4">
      <AdminHeader
        title={t('inventory.title', 'Inventory')}
        description={
          places.length > 0
            ? t('inventory.descriptionPlaces', 'Stock in {count} places', { count: String(places.length) })
            : t('inventory.description', 'What you keep, in every place')
        }
        action={actions}
      />

      <div className="flex flex-wrap items-end justify-between gap-3">
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
            <TabsTrigger value="counts">{t('inventory.tab.counts', 'Counts')}</TabsTrigger>
            <TabsTrigger value="transfers">{t('inventory.tab.transfers', 'Transfers')}</TabsTrigger>
            {canSeePurchases && <TabsTrigger value="purchases">{t('inventory.tab.purchases', 'Purchases')}</TabsTrigger>}
          </TabsList>
        </Tabs>
        <Link href="/inventory/adjustments" className="text-sm font-medium text-primary hover:underline" data-testid="inventory-waste-link">
          {t('inventory.waste.link', 'Waste and adjustments')}
        </Link>
      </div>

      {meta.data && tab === 'stock' && (
        <KpiStrip
          className="pb-4"
          items={[
            { label: t('inventory.kpi.items', 'Items'), value: String(meta.data.itemTotal) },
            {
              label: t('inventory.kpi.belowReorder', 'Below reorder'),
              value: String(belowItems.length),
              note:
                belowItems.length > 0
                  ? belowItems
                      .slice(0, 3)
                      .map((r) => nameIn(r.item_names, r.item_name ?? '', language))
                      .join(language === 'ar' ? '، ' : ', ') + (belowItems.length > 3 ? '…' : '')
                  : meta.data.rules.length === 0
                    ? t('inventory.kpi.noReorder', 'Set reorder levels on an item’s page')
                    : t('inventory.kpi.allAbove', 'Everything is above its level'),
              tone: belowItems.length > 0 ? 'bad' : undefined,
            },
          ]}
        />
      )}

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
      ) : tab === 'counts' ? (
        <CountsTab counts={counts} places={places} />
      ) : tab === 'transfers' ? (
        <TransfersTab transfers={transfers} places={places} onOpen={(row) => void openTransfer(row)} />
      ) : tab === 'purchases' ? (
        <PurchaseOrdersTab orders={purchases} places={places} />
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
      {ordering && meta.data && (
        <PurchaseOrderDialog
          open={ordering}
          onOpenChange={setOrdering}
          places={places}
          currency={currency}
          onSaved={(order) => router.push(`/inventory/purchase-orders/${order.id}`)}
        />
      )}
      {counting && meta.data && (
        <CountStartDialog
          open={counting}
          onOpenChange={setCounting}
          places={places}
          categories={meta.data.categories}
          onStarted={(count) => router.push(`/inventory/counts/${count.id}`)}
        />
      )}
      {transferring && meta.data && (
        <TransferDialog
          open={transferring}
          onOpenChange={setTransferring}
          places={places}
          oneStepByDefault={meta.data.settings?.transfers_one_step === true}
          onDone={(transfer) => {
            setTransferring(false);
            toast.addToast(
              transfer.status === 'in_transit'
                ? t('inventory.transfer.sent', '{number} is on the way. Receive it when it arrives.', { number: transfer.number })
                : t('inventory.transfer.moved', '{number}: the stock has moved.', { number: transfer.number }),
              'success'
            );
            meta.refetch();
            if (tab === 'transfers') transfers.refetch();
          }}
        />
      )}
      {receiving !== null && (
        <ReceiveTransferDialog
          transfer={receiving}
          route={`${placeName(receiving.from_location_id ?? 0)} → ${placeName(receiving.to_location_id ?? 0)}`}
          onOpenChange={(open) => {
            if (!open) setReceiving(null);
          }}
          onDone={(transfer) => {
            setReceiving(null);
            toast.addToast(
              transfer.status === 'cancelled'
                ? t('inventory.receive.sentBack', '{number} went back to where it left.', { number: transfer.number })
                : t('inventory.receive.done', '{number} received.', { number: transfer.number }),
              'success'
            );
            meta.refetch();
            transfers.refetch();
          }}
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
