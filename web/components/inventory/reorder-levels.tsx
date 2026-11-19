'use client';

/**
 * An item's reorder levels, one per variant and place (WHIT-1233, register
 * IV.11): the least to keep and, if wanted, what to order up to. Under its
 * level, a place shows red, the Stock tab says "Low at <place>", and the
 * Stock assistant is told once until stock comes back. People who can change
 * items edit them in place; everyone else reads them.
 */

import { useState } from 'react';
import { Input } from '@/components/ui/input';
import { Badge } from '@whity/ui/badge';
import { Button } from '@whity/ui/button';
import { useLanguageCode, useTranslation } from '@whity/features/i18n';
import {
  inventoryApi,
  inventoryErrorText,
  nameIn,
  quantityText,
  type InventoryItem,
  type InventoryLocation,
  type InventoryReorderRule,
} from '@/lib/inventory/api';

function Row({
  item,
  variantId,
  variantLabel,
  place,
  rule,
  canEdit,
  onSaved,
}: {
  item: InventoryItem;
  variantId: number;
  variantLabel: string | null;
  place: InventoryLocation;
  rule: InventoryReorderRule | undefined;
  canEdit: boolean;
  onSaved: () => void;
}) {
  const t = useTranslation('admin');
  const language = useLanguageCode();
  const unit = item.base_unit.code;
  const [min, setMin] = useState(rule ? quantityText(rule.min_quantity) : '');
  const [upTo, setUpTo] = useState(rule?.order_up_to ? quantityText(rule.order_up_to) : '');
  const [saving, setSaving] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const changed = min !== (rule ? quantityText(rule.min_quantity) : '') || upTo !== (rule?.order_up_to ? quantityText(rule.order_up_to) : '');
  const label = nameIn(place.names, place.name, language) + (variantLabel ? ` · ${variantLabel}` : '');

  const save = async () => {
    setSaving(true);
    setProblem(null);
    try {
      if (min.trim() === '' && rule) {
        await inventoryApi.deleteReorderRule(rule.id);
      } else if (min.trim() !== '') {
        await inventoryApi.setReorderRule({
          variant_id: variantId,
          location_id: place.id,
          min_quantity: min.trim(),
          order_up_to: upTo.trim() === '' ? null : upTo.trim(),
        });
      }
      onSaved();
    } catch (error) {
      setProblem(inventoryErrorText(error));
    } finally {
      setSaving(false);
    }
  };

  if (!canEdit) {
    return (
      <div className="flex items-center justify-between gap-3 py-2.5 text-sm">
        <span>{label}</span>
        <span className="flex items-center gap-2">
          {rule?.is_below && <Badge variant="destructive">{t('inventory.badge.belowReorder', 'Below reorder')}</Badge>}
          <bdi dir="ltr" className="font-mono tabular-nums">
            {rule ? `${quantityText(rule.min_quantity)}${rule.order_up_to ? ` → ${quantityText(rule.order_up_to)}` : ''} ${unit}` : '–'}
          </bdi>
        </span>
      </div>
    );
  }
  return (
    <div className="space-y-1 py-2.5">
      <div className="flex flex-wrap items-end gap-3">
        <div className="min-w-32 flex-1 pb-2 text-sm">
          {label}
          {rule?.is_below && (
            <Badge variant="destructive" className="ms-2">
              {t('inventory.badge.belowReorder', 'Below reorder')}
            </Badge>
          )}
        </div>
        <div className="w-28">
          <Input
            id={`reorder-min-${variantId}-${place.id}`}
            dir="ltr"
            inputMode="decimal"
            className="font-mono"
            label={t('inventory.reorder.min', 'Reorder at')}
            placeholder={unit}
            value={min}
            onChange={(e) => setMin(e.target.value)}
          />
        </div>
        <div className="w-28">
          <Input
            id={`reorder-upto-${variantId}-${place.id}`}
            dir="ltr"
            inputMode="decimal"
            className="font-mono"
            label={t('inventory.reorder.upTo', 'Order up to')}
            placeholder={unit}
            value={upTo}
            onChange={(e) => setUpTo(e.target.value)}
          />
        </div>
        <Button variant="outline" size="sm" className="mb-1" disabled={!changed} loading={saving} onClick={() => void save()}>
          {t('action.save', 'Save')}
        </Button>
      </div>
      {problem && <p className="text-sm text-destructive">{problem}</p>}
    </div>
  );
}

export function ReorderLevels({
  item,
  places,
  rules,
  canEdit,
  onSaved,
}: {
  item: InventoryItem;
  places: InventoryLocation[];
  rules: InventoryReorderRule[];
  canEdit: boolean;
  onSaved: () => void;
}) {
  const t = useTranslation('admin');
  const variants = item.variants.filter((v) => !v.archived);
  if (places.length === 0 || variants.length === 0) return null;
  return (
    <section className="space-y-2 rounded-lg border border-border p-5" data-testid="item-reorder">
      <h2 className="text-sm font-semibold text-muted-foreground">{t('inventory.reorder.title', 'Reorder levels')}</h2>
      <p className="text-sm text-muted-foreground">
        {t('inventory.reorder.hint', 'Under its level a place shows red, and the Stock assistant suggests an order. Leave it empty for no level.')}
      </p>
      <div className="divide-y divide-border">
        {variants.flatMap((v) =>
          places.map((place) => {
            const options = Object.values(v.options ?? {});
            return (
              <Row
                key={`${v.id}-${place.id}`}
                item={item}
                variantId={v.id}
                variantLabel={variants.length > 1 ? (options.length > 0 ? options.join(' · ') : v.sku) : null}
                place={place}
                rule={rules.find((r) => r.variant_id === v.id && r.location_id === place.id)}
                canEdit={canEdit}
                onSaved={onSaved}
              />
            );
          })
        )}
      </div>
    </section>
  );
}
