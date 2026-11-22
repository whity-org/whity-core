'use client';

/**
 * Changing stock by hand (WHIT-1232, register IV.14, `TplAdjustments`): a
 * variant, a place, how much more or less, and why, from the workspace's own
 * reasons that go that way (WHIT-1233): taking stock out offers expired,
 * damaged, end of day unsold...; adding it offers found, returned or a count
 * difference. A change worth more than the workspace's value waits for
 * approval; the rest goes into the stock history at once and can be undone
 * from there. One main button.
 *
 * For an item that keeps batches (WHIT-1235), stock added names its batch
 * (and its expiry when the item goes off); stock taken out may name one, or
 * the batch that expires first is taken.
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
import { useReasons } from '@/lib/inventory/use-reasons';
import {
  inventoryApi,
  inventoryErrorText,
  nameIn,
  reasonsFor,
  type InventoryBatch,
  type InventoryItem,
  type InventoryLocation,
} from '@/lib/inventory/api';

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
  const { reasons: allReasons } = useReasons();
  const [reason, setReason] = useState<string>('damaged');
  const [note, setNote] = useState('');
  const [batchCode, setBatchCode] = useState('');
  const [expiresOn, setExpiresOn] = useState('');
  const tracked = item.tracking !== 'none';
  const [saving, setSaving] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);

  const reasons = useMemo(() => reasonsFor(allReasons, direction), [allReasons, direction]);
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
              ...(tracked && batchCode.trim() !== '' ? { lot_code: batchCode.trim() } : {}),
              ...(tracked && direction === 'in' && expiresOn !== '' ? { expires_on: expiresOn } : {}),
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
            {t('inventory.adjust.hint2', '{item}. Kept in the history; above the approval value it waits for approval.', {
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
          {tracked && (
            <div className="grid gap-4 sm:grid-cols-2" data-testid="adjust-batch">
              <Input
                id="adjust-batch"
                dir="ltr"
                className="font-mono"
                label={item.tracking === 'serial' ? t('inventory.field.serial', 'Serial number') : t('inventory.field.batch', 'Batch code')}
                helperText={
                  direction === 'out'
                    ? t('inventory.adjust.batchOutHint', 'Leave it empty to take the batch that expires first.')
                    : undefined
                }
                value={batchCode}
                onChange={(e) => setBatchCode(e.target.value)}
              />
              {direction === 'in' && item.expires && (
                <Input
                  id="adjust-expiry"
                  type="date"
                  dir="ltr"
                  label={t('inventory.field.expiresOn', 'Expires on')}
                  value={expiresOn}
                  onChange={(e) => setExpiresOn(e.target.value)}
                />
              )}
            </div>
          )}
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
                  <SelectItem key={r.code} value={r.code}>
                    {nameIn(r.names, r.name, language)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <Input
            id="adjust-note"
            dir="auto"
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
