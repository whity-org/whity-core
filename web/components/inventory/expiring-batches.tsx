'use client';

/**
 * Inventory's Batches tab (WHIT-1235, `TplInventory` "Expiring soon"): the
 * batches with stock that are expiring within the workspace's warning window
 * or have expired, soonest first, so they're sold or used first. "Further
 * ahead" widens it to 90 days. A row opens its item.
 */

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import { ExpiryBadge } from '@/components/inventory/item-batches';
import { DataTable, type DataTableColumn } from '@/components/ui/data-table';
import { LoadProblem } from '@/components/load-problem';
import { useFetch } from '@/hooks/useFetch';
import { useDateDisplay } from '@whity/features/datetime';
import { Switch } from '@whity/ui/switch';
import { useLanguageCode, useTranslation } from '@whity/features/i18n';
import { inventoryApi, nameIn, quantityText, type InventoryLocation, type InventoryLot } from '@/lib/inventory/api';

export function ExpiringBatches({ places, windowDays }: { places: InventoryLocation[]; windowDays: number }) {
  const t = useTranslation('admin');
  const language = useLanguageCode();
  const dates = useDateDisplay();
  const router = useRouter();
  const [ahead, setAhead] = useState(false);
  const lots = useFetch(() => inventoryApi.expiringLots(ahead ? 90 : undefined), [ahead]);

  const placeName = (id: number) => {
    const place = places.find((p) => p.id === id);
    return place ? nameIn(place.names, place.name, language) : `#${id}`;
  };

  const columns: DataTableColumn<InventoryLot>[] = [
    {
      id: 'item',
      header: t('inventory.column.item', 'Item'),
      cell: (row) => (
        <div>
          <div className="font-medium">{nameIn(row.item_names, row.item_name, language)}</div>
          <bdi dir="ltr" className="font-mono text-xs text-muted-foreground">
            {row.sku}
          </bdi>
        </div>
      ),
    },
    {
      id: 'code',
      header: t('inventory.lots.code', 'Batch'),
      cell: (row) => (
        <bdi dir="ltr" className="font-mono">
          {row.code}
        </bdi>
      ),
    },
    {
      id: 'expires',
      header: t('inventory.lots.expires', 'Expires'),
      cell: (row) => (
        <div className="flex flex-wrap items-center gap-2">
          <span>{row.expires_on ? (dates.date(row.expires_on) ?? '—') : '—'}</span>
          {/* @date-display-ignore: the badge says Expired or Expiring soon; it never shows the date */}
          <ExpiryBadge expiresOn={row.expires_on} windowDays={windowDays} />
        </div>
      ),
    },
    {
      id: 'where',
      header: t('inventory.lots.where', 'Where'),
      cell: (row) => (
        <span className="text-muted-foreground">
          {row.places.map((p) => `${placeName(p.location_id)} ${quantityText(p.on_hand)}`).join(' · ')}
        </span>
      ),
    },
    {
      id: 'on_hand',
      header: t('inventory.column.total', 'All places'),
      className: 'text-end',
      cell: (row) => (
        <bdi dir="ltr" className="font-mono tabular-nums">
          {quantityText(row.on_hand)} <span className="text-xs text-muted-foreground">{row.unit}</span>
        </bdi>
      ),
    },
  ];

  if (lots.error) {
    return (
      <LoadProblem
        forbidden={lots.forbidden}
        message={t('inventory.lots.expiringProblem', 'What is expiring could not be loaded.')}
        onRetry={lots.refetch}
        area={t('inventory.area', 'inventory')}
      />
    );
  }

  return (
    <div className="space-y-3" data-testid="inventory-batches">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-sm text-muted-foreground">
          {ahead
            ? t('inventory.lots.aheadHint', 'Batches expiring in the next 90 days, soonest first.')
            : t('inventory.lots.expiringHint', 'Batches expiring in the next {days} days, or already expired, soonest first. Sell or use these first.', {
                days: String(windowDays),
              })}
        </p>
        <label className="flex items-center gap-2 text-sm" htmlFor="batches-ahead">
          <Switch id="batches-ahead" checked={ahead} onCheckedChange={setAhead} />
          {t('inventory.lots.ahead', 'Further ahead')}
        </label>
      </div>
      <DataTable
        columns={columns}
        data={lots.data ?? []}
        isLoading={lots.loading && lots.data === null}
        getRowId={(row) => String(row.id)}
        onRowClick={(row) => router.push(`/inventory/items/${row.item_id}`)}
        emptyState={{
          title: t('inventory.lots.emptyTitle', 'Nothing expiring soon'),
          description: t('inventory.lots.emptyHint', 'Items that keep batches show here as their expiry dates come near.'),
        }}
      />
    </div>
  );
}
