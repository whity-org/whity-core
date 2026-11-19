'use client';

/**
 * Changing stock by hand (WHIT-1232, register IV.14, `TplAdjustments`): a
 * variant, a place, how much more or less, and why. Taking stock out asks for
 * one of the reasons (expired, damaged, spoiled...); adding it asks for found,
 * returned or a count correction. The change goes into the stock history and
 * can be undone from there. One main button.
 */

import { useMemo, useState } from 'react';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Button } from '@whity/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@whity/ui/select';
import { Tabs, TabsList, TabsTrigger } from '@whity/ui/tabs';
import { useLanguageCode, useTranslation } from '@whity/features/i18n';
import {
  REASONS_IN,
  REASONS_OUT,
  inventoryApi,
  inventoryErrorText,
  nameIn,
  type InventoryBatch,
  type InventoryItem,
  type InventoryLocation,
} from '@/lib/inventory/api';

export function reasonLabel(t: ReturnType<typeof useTranslation>, reason: string): string {
  const labels: Record<string, string> = {
    expired: t('inventory.reason.expired', 'Expired'),
    damaged: t('inventory.reason.damaged', 'Damaged'),
    spoiled: t('inventory.reason.spoiled', 'Spoiled'),
    used_in_house: t('inventory.reason.usedInHouse', 'Used in-house'),
    sample: t('inventory.reason.sample', 'Sample'),
    theft: t('inventory.reason.theft', 'Theft'),
    count_correction: t('inventory.reason.countCorrection', 'Count correction'),
    found: t('inventory.reason.found', 'Found'),
    returned: t('inventory.reason.returned', 'Returned'),
    other: t('inventory.reason.other', 'Other'),
  };
  return labels[reason] ?? reason;
}

type Direction = 'out' | 'in';

export function AdjustStockDialog({
  open,
  onOpenChange,
  item,
  places,
  initialPlaceId,
  onDone,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  item: InventoryItem;
  places: InventoryLocation[];
  initialPlaceId?: number | null;
  onDone: (batch: InventoryBatch) => void;
}) {
  const t = useTranslation('admin');
  const language = useLanguageCode();
  const variants = item.variants.filter((v) => !v.archived);
  const [variantId, setVariantId] = useState(String(variants[0]?.id ?? ''));
  const [placeId, setPlaceId] = useState(String(initialPlaceId ?? places[0]?.id ?? ''));
  const [direction, setDirection] = useState<Direction>('out');
  const [amount, setAmount] = useState('');
  const [reason, setReason] = useState<string>('damaged');
  const [note, setNote] = useState('');
  const [saving, setSaving] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);

  const reasons = useMemo(() => (direction === 'out' ? REASONS_OUT : REASONS_IN), [direction]);
  const unit = item.base_unit.code;

  const variantName = (id: number) => {
    const v = variants.find((x) => x.id === id);
    if (!v) return '';
    const options = Object.values(v.options ?? {});
    return options.length > 0 ? `${options.join(' · ')} (${v.sku})` : v.sku;
  };

  const save = async () => {
    const clean = amount.trim().replace(/^[-+]/, '');
    if (clean === '' || Number(clean) <= 0) {
      setProblem(t('inventory.error.amount', 'Say how much, more than 0.'));
      return;
    }
    setSaving(true);
    setProblem(null);
    try {
      onDone(
        await inventoryApi.adjust({
          kind: 'adjustment',
          reason_code: reason,
          note: note.trim() === '' ? undefined : note.trim(),
          lines: [
            {
              variant_id: Number(variantId),
              location_id: Number(placeId),
              change: (direction === 'out' ? '-' : '') + clean,
            },
          ],
        })
      );
    } catch (error) {
      setProblem(inventoryErrorText(error));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-lg" data-testid="adjust-form">
        <DialogHeader>
          <DialogTitle>{t('inventory.adjust.title', 'Change stock')}</DialogTitle>
          <DialogDescription>
            {t('inventory.adjust.hint', '{item}. The change is kept in the history and can be undone.', {
              item: nameIn(item.names, item.name, language),
            })}
          </DialogDescription>
        </DialogHeader>
        <form
          className="space-y-4"
          onSubmit={(e) => {
            e.preventDefault();
            void save();
          }}
        >
          <Tabs
            value={direction}
            onValueChange={(v) => {
              const next = v as Direction;
              setDirection(next);
              setReason(next === 'out' ? 'damaged' : 'found');
            }}
          >
            <TabsList>
              <TabsTrigger value="out">{t('inventory.adjust.out', 'Take out')}</TabsTrigger>
              <TabsTrigger value="in">{t('inventory.adjust.in', 'Add')}</TabsTrigger>
            </TabsList>
          </Tabs>
          {variants.length > 1 && (
            <div className="space-y-1.5">
              <label className="text-sm font-medium" htmlFor="adjust-variant">
                {t('inventory.field.variant', 'Variant')}
              </label>
              <Select value={variantId} onValueChange={setVariantId}>
                <SelectTrigger id="adjust-variant">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {variants.map((v) => (
                    <SelectItem key={v.id} value={String(v.id)}>
                      {variantName(v.id)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          )}
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="space-y-1.5">
              <label className="text-sm font-medium" htmlFor="adjust-place">
                {t('inventory.field.place', 'Place')}
              </label>
              <Select value={placeId} onValueChange={setPlaceId}>
                <SelectTrigger id="adjust-place">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {places.map((p) => (
                    <SelectItem key={p.id} value={String(p.id)}>
                      {nameIn(p.names, p.name, language)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <Input
              id="adjust-amount"
              dir="ltr"
              inputMode="decimal"
              className="font-mono"
              label={t('inventory.field.quantityIn', 'Quantity, in {unit}', { unit })}
              value={amount}
              onChange={(e) => setAmount(e.target.value)}
            />
          </div>
          <div className="space-y-1.5">
            <label className="text-sm font-medium" htmlFor="adjust-reason">
              {t('inventory.field.reason', 'Why')}
            </label>
            <Select value={reason} onValueChange={setReason}>
              <SelectTrigger id="adjust-reason">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {reasons.map((r) => (
                  <SelectItem key={r} value={r}>
                    {reasonLabel(t, r)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <Input
            id="adjust-note"
            label={t('inventory.field.note', 'Note')}
            value={note}
            onChange={(e) => setNote(e.target.value)}
          />
          {problem && (
            <Alert variant="destructive">
              <AlertDescription>{problem}</AlertDescription>
            </Alert>
          )}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>
              {t('action.cancel', 'Cancel')}
            </Button>
            <Button type="submit" loading={saving} data-testid="adjust-save">
              {t('inventory.adjust.save', 'Change stock')}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
