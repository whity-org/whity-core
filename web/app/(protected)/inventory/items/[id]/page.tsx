'use client';

/**
 * /inventory/items/[id] (WHIT-1232, design section 31; the card is a brand
 * ask, built from section 21's record page meanwhile): one item.
 *
 * Stock by place (red below zero), the variants with their SKUs and barcodes,
 * the units it is bought or sold in, and the history: every move, newest
 * first, with who made it and why. A stock change can be undone from the
 * history, which posts it the other way; nothing is ever edited. Change is the
 * main button; Change stock is outline. Archiving can be undone, so it asks
 * nothing and offers Restore.
 */

import { use, useState } from 'react';
import Link from 'next/link';
import { AdminHeader } from '@/components/admin/admin-header';
import { LoadProblem } from '@/components/load-problem';
import { NoAccess } from '@/components/no-access';
import { AdjustStockDialog, reasonLabel } from '@/components/inventory/adjust-stock-dialog';
import { ItemFormDialog, kindLabel } from '@/components/inventory/item-form-dialog';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useDateDisplay } from '@whity/features/datetime';
import { useFetch } from '@/hooks/useFetch';
import { useToast } from '@/lib/toast-context';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Badge } from '@whity/ui/badge';
import { Button } from '@whity/ui/button';
import { Icon } from '@whity/ui/icon';
import { Money } from '@whity/ui/money/money';
import { useLanguageCode, useTranslation } from '@whity/features/i18n';
import {
  INVENTORY_READ,
  INVENTORY_WRITE,
  STOCK_ADJUST,
  inventoryApi,
  inventoryErrorText,
  nameIn,
  quantityText,
  type InventoryMove,
} from '@/lib/inventory/api';

export default function InventoryItemPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const itemId = Number(id);
  const t = useTranslation('admin');
  const dates = useDateDisplay();
  const language = useLanguageCode();
  const toast = useToast();
  const { has, loading: accessLoading } = useCapabilities();
  const canWrite = has(INVENTORY_WRITE);
  const canAdjust = has(STOCK_ADJUST);
  const [editing, setEditing] = useState(false);
  const [adjusting, setAdjusting] = useState(false);
  const [busy, setBusy] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);

  const { data, loading, error, forbidden, refetch } = useFetch(async () => {
    const [item, levels, moves, places, allPlaces, units] = await Promise.all([
      inventoryApi.item(itemId),
      inventoryApi.levels({ itemId }),
      inventoryApi.moves({ itemId, limit: 50 }),
      inventoryApi.locations(),
      inventoryApi.locations({ virtual: true, inactive: true }),
      inventoryApi.units(),
    ]);
    return { item, levels, moves: moves.data, places, allPlaces, units };
  }, [itemId]);

  if (!accessLoading && !has(INVENTORY_READ)) {
    return <NoAccess area={t('inventory.area', 'inventory')} permission={INVENTORY_READ} />;
  }
  if (error || (!loading && data === null)) {
    return (
      <LoadProblem
        forbidden={forbidden}
        message={t('inventory.error.item', 'The item could not be loaded.')}
        onRetry={refetch}
        area={t('inventory.area', 'inventory')}
      />
    );
  }
  if (data === null) {
    return <div className="h-48 animate-pulse rounded-lg bg-muted" aria-busy="true" />;
  }

  const { item, levels, moves, places, allPlaces, units } = data;
  const unit = item.base_unit.code;
  const placeName = (placeId: number) => {
    const place = allPlaces.find((p) => p.id === placeId);
    return place ? nameIn(place.names, place.name, language) : `#${placeId}`;
  };
  const variantName = (variantId: number) => {
    const v = item.variants.find((x) => x.id === variantId);
    const options = Object.values(v?.options ?? {});
    return options.length > 0 ? options.join(' · ') : null;
  };
  const reversed = new Set(moves.map((m) => m.reverses_move_id).filter((x): x is number => x != null));

  const archive = async (archived: boolean) => {
    setBusy(true);
    setProblem(null);
    try {
      await (archived ? inventoryApi.archiveItem(item.id) : inventoryApi.restoreItem(item.id));
      refetch();
    } catch (e) {
      setProblem(inventoryErrorText(e));
    } finally {
      setBusy(false);
    }
  };

  const undo = async (move: InventoryMove) => {
    setBusy(true);
    setProblem(null);
    try {
      await inventoryApi.undo(move.batch_key);
      toast.addToast(t('inventory.undone', 'Undone. The stock is back as it was.'), 'success');
      refetch();
    } catch (e) {
      setProblem(inventoryErrorText(e));
    } finally {
      setBusy(false);
    }
  };

  const qty = (value: string) => (
    <bdi dir="ltr" className={`font-mono tabular-nums ${Number(value) < 0 ? 'text-destructive' : ''}`}>
      {quantityText(value)} <span className="text-xs text-muted-foreground">{unit}</span>
    </bdi>
  );

  const why = (move: InventoryMove) => {
    if (move.reverses_move_id != null) return t('inventory.history.undo', 'Undo');
    if (move.source_type === 'opening') return t('inventory.history.opening', 'Opening stock');
    if (move.source_type === 'adjustment') return reasonLabel(t, move.reason_code ?? '');
    return move.source_type;
  };

  return (
    <div className="space-y-6">
      <AdminHeader
        breadcrumb={
          <Link href="/inventory" className="hover:underline">
            {t('inventory.title', 'Inventory')}
          </Link>
        }
        title={nameIn(item.names, item.name, language)}
        description={`${kindLabel(t, item.kind)} · ${t('inventory.countedIn', 'counted in {unit}', { unit })}`}
        action={
          <div className="flex gap-2">
            {canAdjust && item.kind !== 'service' && !item.archived && places.length > 0 && (
              <Button variant="outline" onClick={() => setAdjusting(true)} data-testid="item-adjust">
                <Icon name="transfer" />
                {t('inventory.adjust.title', 'Change stock')}
              </Button>
            )}
            {canWrite && !item.archived && (
              <Button onClick={() => setEditing(true)} data-testid="item-edit">
                <Icon name="edit" />
                {t('inventory.item.changeShort', 'Change')}
              </Button>
            )}
          </div>
        }
      />

      {item.archived && (
        <Alert>
          <AlertDescription className="flex flex-wrap items-center justify-between gap-3">
            {t('inventory.archived.notice', 'This item is archived. Its history is kept, but it is left out of lists and can\'t be moved.')}
            {canWrite && (
              <Button variant="outline" loading={busy} onClick={() => void archive(false)}>
                <Icon name="undo" />
                {t('inventory.restore', 'Restore')}
              </Button>
            )}
          </AlertDescription>
        </Alert>
      )}
      {problem && (
        <Alert variant="destructive">
          <AlertDescription>{problem}</AlertDescription>
        </Alert>
      )}

      <div className="grid gap-6 lg:grid-cols-2">
        <section className="space-y-3 rounded-lg border border-border p-5" data-testid="item-stock">
          <h2 className="text-sm font-semibold text-muted-foreground">{t('inventory.byPlace', 'Stock by place')}</h2>
          {item.kind === 'service' ? (
            <p className="text-sm text-muted-foreground">{t('inventory.service.noStock', 'A service has no stock.')}</p>
          ) : levels.length === 0 ? (
            <p className="text-sm text-muted-foreground">{t('inventory.byPlace.none', 'No stock anywhere yet.')}</p>
          ) : (
            <dl className="divide-y divide-border">
              {levels.map((level) => (
                <div key={`${level.variant_id}-${level.location_id}`} className="flex items-center justify-between gap-3 py-2.5 text-sm">
                  <dt>
                    {placeName(level.location_id)}
                    {variantName(level.variant_id) && (
                      <span className="text-muted-foreground"> · {variantName(level.variant_id)}</span>
                    )}
                  </dt>
                  <dd>{qty(level.on_hand)}</dd>
                </div>
              ))}
              <div className="flex items-center justify-between gap-3 py-2.5 text-sm font-medium">
                <dt>{t('inventory.column.total', 'All places')}</dt>
                <dd>{qty(item.on_hand)}</dd>
              </div>
            </dl>
          )}
        </section>

        <section className="space-y-3 rounded-lg border border-border p-5">
          <h2 className="text-sm font-semibold text-muted-foreground">{t('inventory.details', 'Details')}</h2>
          <dl className="divide-y divide-border text-sm">
            {item.sale_price_minor != null && item.currency && (
              <div className="grid grid-cols-[10rem_1fr] gap-3 py-2.5">
                <dt className="text-muted-foreground">{t('inventory.field.salePrice', 'Selling price')}</dt>
                <dd>
                  <Money minor={item.sale_price_minor} currency={item.currency} />
                </dd>
              </div>
            )}
            {item.purchase_price_minor != null && item.currency && (
              <div className="grid grid-cols-[10rem_1fr] gap-3 py-2.5">
                <dt className="text-muted-foreground">{t('inventory.field.purchasePrice', 'Buying price')}</dt>
                <dd>
                  <Money minor={item.purchase_price_minor} currency={item.currency} />
                </dd>
              </div>
            )}
            {item.units.map((u) => (
              <div key={u.unit} className="grid grid-cols-[10rem_1fr] gap-3 py-2.5">
                <dt className="text-muted-foreground">
                  {t('inventory.oneUnit', 'One {unit}', {
                    unit: nameIn(units.find((x) => x.code === u.unit)?.names, u.unit, language),
                  })}
                </dt>
                <dd>
                  <bdi dir="ltr" className="font-mono">
                    {u.per} {unit}
                  </bdi>
                </dd>
              </div>
            ))}
          </dl>
          <h3 className="pt-2 text-sm font-semibold text-muted-foreground">
            {item.variants.length > 1 ? t('inventory.variants', 'Variants') : t('inventory.codes', 'Codes')}
          </h3>
          <ul className="divide-y divide-border text-sm" data-testid="item-variants">
            {item.variants.map((v) => (
              <li key={v.id} className="flex flex-wrap items-center justify-between gap-2 py-2.5">
                <span className="flex items-center gap-2">
                  {variantName(v.id) && <span>{variantName(v.id)}</span>}
                  <bdi dir="ltr" className="font-mono">
                    {v.sku}
                  </bdi>
                  {v.archived && <Badge variant="secondary">{t('inventory.archived', 'Archived')}</Badge>}
                </span>
                <span className="flex flex-wrap gap-1">
                  {v.barcodes.map((b) => (
                    <Badge key={b.code} variant="outline">
                      <Icon name="barcode" size={14} />
                      <bdi dir="ltr" className="font-mono">
                        {b.code}
                      </bdi>
                    </Badge>
                  ))}
                </span>
              </li>
            ))}
          </ul>
        </section>
      </div>

      {item.kind !== 'service' && (
        <section className="space-y-3 rounded-lg border border-border p-5" data-testid="item-history">
          <h2 className="text-sm font-semibold text-muted-foreground">{t('inventory.history', 'History')}</h2>
          {moves.length === 0 ? (
            <p className="text-sm text-muted-foreground">{t('inventory.history.none', 'Nothing has moved yet.')}</p>
          ) : (
            <ul className="divide-y divide-border text-sm">
              {moves.map((move) => {
                const canUndo =
                  canAdjust &&
                  move.reverses_move_id == null &&
                  !reversed.has(move.id) &&
                  (move.source_type === 'adjustment' || move.source_type === 'opening');
                return (
                  <li key={move.id} className="flex flex-wrap items-center justify-between gap-3 py-2.5">
                    <div className="min-w-0 space-y-0.5">
                      <div>
                        {placeName(move.from_location_id)} → {placeName(move.to_location_id)}
                        <span className="text-muted-foreground"> · {why(move)}</span>
                        {reversed.has(move.id) && (
                          <Badge variant="secondary" className="ms-2">
                            {t('inventory.history.undone', 'Undone')}
                          </Badge>
                        )}
                      </div>
                      <div className="text-xs text-muted-foreground">
                        {dates.dateTime(move.occurred_at) ?? ''}
                        {move.posted_by_name ? ` · ${move.posted_by_name}` : ''}
                        {move.note ? ` · ${move.note}` : ''}
                      </div>
                    </div>
                    <div className="flex items-center gap-3">
                      {qty(move.quantity)}
                      {canUndo && (
                        <Button variant="outline" size="sm" loading={busy} onClick={() => void undo(move)}>
                          <Icon name="undo" />
                          {t('inventory.undo', 'Undo')}
                        </Button>
                      )}
                    </div>
                  </li>
                );
              })}
            </ul>
          )}
        </section>
      )}

      {canWrite && !item.archived && (
        <div>
          <Button variant="outline" loading={busy} onClick={() => void archive(true)} data-testid="item-archive">
            <Icon name="archive" />
            {t('inventory.archive', 'Archive')}
          </Button>
        </div>
      )}

      {editing && (
        <ItemFormDialog
          open={editing}
          onOpenChange={setEditing}
          item={item}
          units={units}
          places={places}
          onSaved={() => {
            setEditing(false);
            refetch();
          }}
        />
      )}
      {adjusting && (
        <AdjustStockDialog
          open={adjusting}
          onOpenChange={setAdjusting}
          item={item}
          places={places}
          onDone={(batch) => {
            setAdjusting(false);
            // WHIT-1233: a change worth more than the workspace's value waits
            // for approval, and the stock moves only once it is approved.
            toast.addToast(
              batch.operation?.status === 'waiting_approval'
                ? t('inventory.adjustWaiting', 'Sent for approval. The stock changes once it is approved.')
                : t('inventory.adjusted', 'Stock changed.'),
              'success'
            );
            refetch();
          }}
        />
      )}
    </div>
  );
}
