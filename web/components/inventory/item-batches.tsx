'use client';

/**
 * An item's batches or serial numbers (WHIT-1235, design 31 §3.5,
 * `TmInventoryMobile`): each with its expiry and what it holds in each place,
 * soonest expiring first, so the one to sell or use first is at the top. A
 * batch past its date is red; one inside the warning window (7 days unless the
 * workspace changed it) is amber. Batch labels print from here.
 */

import { useState } from 'react';
import { PrintMenu } from '@/components/inventory/print-menu';
import { useFetch } from '@/hooks/useFetch';
import { useDateDisplay } from '@whity/features/datetime';
import { Badge } from '@whity/ui/badge';
import { Switch } from '@whity/ui/switch';
import { useLanguageCode, useTranslation } from '@whity/features/i18n';
import {
  expiryState,
  inventoryApi,
  nameIn,
  quantityText,
  type InventoryItem,
  type InventoryLocation,
  type InventoryLot,
} from '@/lib/inventory/api';

export function ExpiryBadge({ expiresOn, windowDays }: { expiresOn: string | null | undefined; windowDays: number }) {
  const t = useTranslation('admin');
  const state = expiryState(expiresOn, windowDays);
  if (state === 'expired') return <Badge variant="destructive">{t('inventory.lots.expired', 'Expired')}</Badge>;
  if (state === 'soon') return <Badge variant="warning">{t('inventory.lots.soon', 'Expiring soon')}</Badge>;
  return null;
}

export function ItemBatches({
  item,
  places,
  windowDays,
  canPrint,
}: {
  item: InventoryItem;
  places: InventoryLocation[];
  windowDays: number;
  canPrint: boolean;
}) {
  const t = useTranslation('admin');
  const language = useLanguageCode();
  const dates = useDateDisplay();
  const [withEmpty, setWithEmpty] = useState(false);
  const { data, loading, error } = useFetch(() => inventoryApi.lots(item.id, withEmpty), [item.id, withEmpty]);
  const serial = item.tracking === 'serial';
  const unit = item.base_unit.code;
  const lots: InventoryLot[] = data ?? [];

  const placeName = (id: number) => {
    const place = places.find((p) => p.id === id);
    return place ? nameIn(place.names, place.name, language) : `#${id}`;
  };

  return (
    <section className="space-y-3 rounded-lg border border-border p-5" data-testid="item-batches">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-sm font-semibold text-muted-foreground">
          {serial ? t('inventory.lots.serials', 'Serial numbers') : t('inventory.lots.title', 'Batches')}
        </h2>
        <div className="flex flex-wrap items-center gap-3">
          <label className="flex items-center gap-2 text-sm" htmlFor="batches-with-empty">
            <Switch id="batches-with-empty" checked={withEmpty} onCheckedChange={setWithEmpty} />
            {t('inventory.lots.withEmpty', 'Show used up')}
          </label>
          {canPrint && lots.length > 0 && (
            <PrintMenu
              size="sm"
              print={(lang) => inventoryApi.printBatchLabels(lots.map((l) => l.id), lang)}
              fileName={`batch-labels-${item.variants[0]?.sku ?? item.id}`}
              label={t('inventory.lots.printLabels', 'Print batch labels')}
              testId="item-print-batch-labels"
            />
          )}
        </div>
      </div>
      {loading && !data ? (
        <p className="text-sm text-muted-foreground">{t('common.loading', 'Loading…')}</p>
      ) : error ? (
        <p className="text-sm text-destructive">{t('inventory.lots.loadProblem', 'The batches could not be loaded. Try again.')}</p>
      ) : lots.length === 0 ? (
        <p className="text-sm text-muted-foreground">
          {serial
            ? t('inventory.lots.noneSerial', 'No serial numbers in stock. Each piece gets one when it is received.')
            : t('inventory.lots.none', 'No batches in stock. Stock that comes in is given a batch code.')}
        </p>
      ) : (
        <ul className="divide-y divide-border">
          {lots.map((lot) => (
            <li key={lot.id} className="flex flex-wrap items-start justify-between gap-3 py-3 text-sm" data-testid={`batch-${lot.code}`}>
              <div className="space-y-1">
                <div className="flex flex-wrap items-center gap-2">
                  <bdi dir="ltr" className="font-mono font-semibold">
                    {lot.code}
                  </bdi>
                  {/* @date-display-ignore: the badge says Expired or Expiring soon; it never shows the date */}
                  <ExpiryBadge expiresOn={lot.expires_on} windowDays={windowDays} />
                </div>
                <p className="text-muted-foreground">
                  {lot.expires_on
                    ? t('inventory.lots.expiresOn', 'Expires {date}', { date: dates.date(lot.expires_on) ?? '—' })
                    : t('inventory.lots.noExpiry', 'No expiry date')}
                  {lot.places.length > 0 && ' · ' + lot.places.map((p) => `${placeName(p.location_id)} ${quantityText(p.on_hand)}`).join(' · ')}
                </p>
              </div>
              <bdi dir="ltr" className={`font-mono tabular-nums ${Number(lot.on_hand) < 0 ? 'text-destructive' : ''}`}>
                {quantityText(lot.on_hand)} <span className="text-xs text-muted-foreground">{unit}</span>
              </bdi>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
