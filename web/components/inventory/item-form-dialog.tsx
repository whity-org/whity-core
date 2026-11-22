'use client';

/**
 * Adding or changing an item (WHIT-1232, design section 31, register IV.4–IV.6;
 * the card is a brand ask, built from section 21's patterns meanwhile).
 *
 * Simple by default: a name in Arabic and English, what kind of item, the unit
 * its stock is counted in, a SKU and barcode, and prices. "Comes in sizes or
 * colours" opens one option (Size: S, M, L) and makes a variant for each
 * value. When adding, opening stock in a place can be set in the same step.
 * One main button.
 */

import { useState } from 'react';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useWorkspaceCurrency } from '@/lib/branding-context';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { BilingualInput, type BilingualValue } from '@whity/ui/bilingual-input';
import { Button } from '@whity/ui/button';
import { MoneyInput } from '@whity/ui/money/money-input';
import { parseMoney, toDecimalString } from '@whity/ui/money/currency';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@whity/ui/select';
import { Switch } from '@whity/ui/switch';
import { useLanguageCode, useTranslation } from '@whity/features/i18n';
import {
  ITEM_KINDS,
  inventoryApi,
  inventoryErrorText,
  nameIn,
  type InventoryItem,
  type InventoryItemRequest,
  type InventoryLocation,
  type InventoryUnit,
  type ItemKind,
} from '@/lib/inventory/api';

export const TRACKING = ['none', 'lot', 'serial'] as const;
export type Tracking = (typeof TRACKING)[number];

export function trackingLabel(t: ReturnType<typeof useTranslation>, tracking: string): string {
  switch (tracking) {
    case 'lot':
      return t('inventory.tracking.lot', 'Batches');
    case 'serial':
      return t('inventory.tracking.serial', 'Serial numbers');
    default:
      return t('inventory.tracking.none', 'Nothing more');
  }
}

export function kindLabel(t: ReturnType<typeof useTranslation>, kind: string): string {
  switch (kind) {
    case 'consumable':
      return t('inventory.kind.consumable', 'Used up');
    case 'service':
      return t('inventory.kind.service', 'Service, no stock');
    case 'made':
      return t('inventory.kind.made', 'Made here');
    default:
      return t('inventory.kind.stocked', 'Kept in stock');
  }
}

export function ItemFormDialog({
  open,
  onOpenChange,
  item,
  units,
  places,
  onSaved,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** The item to change, or null to add one. */
  item: InventoryItem | null;
  units: InventoryUnit[];
  places: InventoryLocation[];
  onSaved: (item: InventoryItem) => void;
}) {
  const t = useTranslation('admin');
  const language = useLanguageCode();
  const workspaceCurrency = useWorkspaceCurrency();
  const currency = item?.currency ?? workspaceCurrency;
  const adding = item === null;

  const [names, setNames] = useState<BilingualValue>(() => ({ ...(item?.names ?? {}) }));
  const [kind, setKind] = useState<ItemKind>((item?.kind as ItemKind | undefined) ?? 'stocked');
  const [unit, setUnit] = useState(item?.base_unit.code ?? 'pc');
  const [sku, setSku] = useState(item && item.variants.length === 1 ? item.variants[0].sku : '');
  const [barcode, setBarcode] = useState(item && item.variants.length === 1 ? (item.variants[0].barcodes[0]?.code ?? '') : '');
  const [salePrice, setSalePrice] = useState(item?.sale_price_minor != null ? toDecimalString(item.sale_price_minor, currency) : '');
  const [purchasePrice, setPurchasePrice] = useState(
    item?.purchase_price_minor != null ? toDecimalString(item.purchase_price_minor, currency) : ''
  );
  const [hasVariants, setHasVariants] = useState(false);
  const [optionName, setOptionName] = useState('');
  const [optionValues, setOptionValues] = useState('');
  const [openingPlace, setOpeningPlace] = useState<string>(places[0] ? String(places[0].id) : '');
  const [openingQuantity, setOpeningQuantity] = useState('');
  // Batches and expiry (WHIT-1235, design 31 §3.5): off by default.
  const [tracking, setTracking] = useState<Tracking>((item?.tracking as Tracking | undefined) ?? 'none');
  const [expires, setExpires] = useState(item?.expires ?? false);
  const [shelfLife, setShelfLife] = useState(item?.shelf_life_days != null ? String(item.shelf_life_days) : '');
  const [openingBatch, setOpeningBatch] = useState('');
  const [openingExpiry, setOpeningExpiry] = useState('');
  const [saving, setSaving] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);

  const price = (text: string): number | null => (text.trim() === '' ? null : parseMoney(text, currency));

  const save = async () => {
    const cleanNames = Object.fromEntries(Object.entries(names).filter(([, v]) => typeof v === 'string' && v.trim() !== '')) as Record<string, string>;
    if (Object.keys(cleanNames).length === 0) {
      setProblem(t('inventory.error.name', 'Give the item a name.'));
      return;
    }
    const body: InventoryItemRequest = {
      name: cleanNames,
      kind,
      sale_price_minor: price(salePrice),
      purchase_price_minor: price(purchasePrice),
    };
    if (kind !== 'service') {
      if (shelfLife.trim() !== '' && !/^\d{1,5}$/.test(shelfLife.trim())) {
        setProblem(t('inventory.error.shelfLife', 'Shelf life is a whole number of days.'));
        return;
      }
      if (adding || tracking !== item?.tracking) body.tracking = tracking;
      body.expires = expires;
      body.shelf_life_days = expires && shelfLife.trim() !== '' ? Number(shelfLife.trim()) : null;
    }
    if (adding || unit !== item?.base_unit.code) body.unit = unit;
    if (adding && hasVariants) {
      const values = optionValues
        .split(/[,،]/)
        .map((v) => v.trim())
        .filter((v) => v !== '');
      if (optionName.trim() === '' || values.length < 2) {
        setProblem(t('inventory.error.variants', 'Name the option (such as Size) and give at least two values.'));
        return;
      }
      body.variants = values.map((value) => ({ options: { [optionName.trim()]: value } }));
    } else if (adding || (item && item.variants.length === 1)) {
      if (sku.trim() !== '') body.sku = sku.trim();
      if (barcode.trim() !== '') body.barcodes = [barcode.trim()];
      if (!adding && item) {
        body.variants = [{ id: item.variants[0].id, sku: sku.trim() || undefined, barcodes: barcode.trim() === '' ? [] : [barcode.trim()], options: {} }];
        delete body.sku;
        delete body.barcodes;
      }
    }

    setSaving(true);
    setProblem(null);
    try {
      const saved = adding ? await inventoryApi.createItem(body) : await inventoryApi.updateItem(item.id, body);
      if (adding && kind !== 'service' && openingPlace !== '' && openingQuantity.trim() !== '' && saved.variants.length === 1) {
        await inventoryApi.adjust({
          kind: 'opening',
          lines: [
            {
              variant_id: saved.variants[0].id,
              location_id: Number(openingPlace),
              change: openingQuantity.trim(),
              ...(tracking !== 'none' && openingBatch.trim() !== '' ? { lot_code: openingBatch.trim() } : {}),
              ...(tracking !== 'none' && openingExpiry !== '' ? { expires_on: openingExpiry } : {}),
            },
          ],
        });
      }
      onSaved(saved);
    } catch (error) {
      setProblem(inventoryErrorText(error));
    } finally {
      setSaving(false);
    }
  };

  const unitName = (u: InventoryUnit) => `${nameIn(u.names, u.name, language)} (${u.code})`;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl" data-testid="item-form">
        <DialogHeader>
          <DialogTitle>{adding ? t('inventory.item.new', 'New item') : t('inventory.item.change', 'Change item')}</DialogTitle>
          <DialogDescription>
            {t('inventory.item.formHint', 'What you buy, sell, make or use. Stock is counted in its unit.')}
          </DialogDescription>
        </DialogHeader>
        <form
          className="space-y-4"
          onSubmit={(e) => {
            e.preventDefault();
            void save();
          }}
        >
          <BilingualInput
            id="item-name"
            label={t('inventory.field.name', 'Name')}
            value={names}
            onChange={setNames}
            required
          />
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="space-y-1.5">
              <label className="text-sm font-medium" htmlFor="item-kind">
                {t('inventory.field.kind', 'Kind')}
              </label>
              <Select value={kind} onValueChange={(v) => setKind(v as ItemKind)}>
                <SelectTrigger id="item-kind">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {ITEM_KINDS.map((k) => (
                    <SelectItem key={k} value={k}>
                      {kindLabel(t, k)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1.5">
              <label className="text-sm font-medium" htmlFor="item-unit">
                {t('inventory.field.unit', 'Counted in')}
              </label>
              <Select value={unit} onValueChange={setUnit}>
                <SelectTrigger id="item-unit">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {units.map((u) => (
                    <SelectItem key={u.id} value={u.code}>
                      {unitName(u)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </div>

          {adding && (
            <div className="flex items-center justify-between gap-3 rounded-lg border border-border p-3">
              <label htmlFor="item-variants" className="text-sm">
                {t('inventory.field.hasVariants', 'Comes in sizes or colours')}
              </label>
              <Switch id="item-variants" checked={hasVariants} onCheckedChange={setHasVariants} />
            </div>
          )}

          {adding && hasVariants ? (
            <div className="grid gap-4 sm:grid-cols-2">
              <Input
                id="item-option"
                label={t('inventory.field.option', 'Option')}
                placeholder={t('inventory.field.optionHint', 'Size')}
                value={optionName}
                onChange={(e) => setOptionName(e.target.value)}
              />
              <Input
                id="item-option-values"
                label={t('inventory.field.optionValues', 'Values')}
                helperText={t('inventory.field.optionValuesHint', 'Separate them with commas: S, M, L. Each one is a variant with its own stock.')}
                value={optionValues}
                onChange={(e) => setOptionValues(e.target.value)}
              />
            </div>
          ) : (
            (adding || (item && item.variants.length === 1)) && (
              <div className="grid gap-4 sm:grid-cols-2">
                <Input
                  id="item-sku"
                  dir="ltr"
                  className="font-mono"
                  label={t('inventory.field.sku', 'SKU')}
                  helperText={adding ? t('inventory.field.skuHint', 'Made up for you when left empty.') : undefined}
                  value={sku}
                  onChange={(e) => setSku(e.target.value)}
                />
                <Input
                  id="item-barcode"
                  dir="ltr"
                  inputMode="numeric"
                  className="font-mono"
                  label={t('inventory.field.barcode', 'Barcode')}
                  value={barcode}
                  onChange={(e) => setBarcode(e.target.value)}
                />
              </div>
            )
          )}

          <div className="grid gap-4 sm:grid-cols-2">
            <div className="space-y-1.5">
              <label className="text-sm font-medium" htmlFor="item-sale-price">
                {t('inventory.field.salePrice', 'Selling price')}
              </label>
              <MoneyInput id="item-sale-price" currency={currency} value={salePrice} onValueChange={setSalePrice} />
            </div>
            <div className="space-y-1.5">
              <label className="text-sm font-medium" htmlFor="item-purchase-price">
                {t('inventory.field.purchasePrice', 'Buying price')}
              </label>
              <MoneyInput id="item-purchase-price" currency={currency} value={purchasePrice} onValueChange={setPurchasePrice} />
            </div>
          </div>

          {kind !== 'service' && (
            <section className="space-y-3 border-t border-border pt-4" data-testid="item-tracking">
              <h3 className="text-sm font-semibold">{t('inventory.tracking.title', 'Batches and expiry')}</h3>
              <div className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-1.5">
                  <label className="text-sm font-medium" htmlFor="item-tracking-select">
                    {t('inventory.tracking.label', 'Keep track of')}
                  </label>
                  <Select value={tracking} onValueChange={(v) => setTracking(v as Tracking)}>
                    <SelectTrigger id="item-tracking-select">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {TRACKING.map((k) => (
                        <SelectItem key={k} value={k}>
                          {trackingLabel(t, k)}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  <p className="text-xs text-muted-foreground">
                    {tracking === 'serial'
                      ? t('inventory.tracking.serialHint', 'Each piece is received with its own serial number.')
                      : tracking === 'lot'
                        ? t('inventory.tracking.lotHint', 'Each delivery is a batch with its own code. What leaves takes the batch that expires first.')
                        : t('inventory.tracking.noneHint', 'Stock is one amount in each place.')}
                  </p>
                </div>
                <div className="space-y-3">
                  <div className="flex items-center justify-between gap-3 rounded-lg border border-border p-3">
                    <label htmlFor="item-expires" className="text-sm">
                      {t('inventory.tracking.expires', 'It goes off')}
                    </label>
                    <Switch id="item-expires" checked={expires} onCheckedChange={setExpires} />
                  </div>
                  {expires && (
                    <Input
                      id="item-shelf-life"
                      dir="ltr"
                      inputMode="numeric"
                      className="font-mono"
                      label={t('inventory.tracking.shelfLife', 'Keeps for, in days')}
                      helperText={t('inventory.tracking.shelfLifeHint', 'Gives a batch its expiry date when none is typed.')}
                      value={shelfLife}
                      onChange={(e) => setShelfLife(e.target.value)}
                    />
                  )}
                </div>
              </div>
            </section>
          )}

          {adding && kind !== 'service' && !hasVariants && places.length > 0 && (
            <section className="space-y-3 border-t border-border pt-4">
              <h3 className="text-sm font-semibold">{t('inventory.opening.title', 'Stock you have now')}</h3>
              <div className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-1.5">
                  <label className="text-sm font-medium" htmlFor="item-opening-place">
                    {t('inventory.field.place', 'Place')}
                  </label>
                  <Select value={openingPlace} onValueChange={setOpeningPlace}>
                    <SelectTrigger id="item-opening-place">
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
                  id="item-opening-quantity"
                  dir="ltr"
                  inputMode="decimal"
                  className="font-mono"
                  label={t('inventory.field.quantityIn', 'Quantity, in {unit}', { unit })}
                  value={openingQuantity}
                  onChange={(e) => setOpeningQuantity(e.target.value)}
                />
                {tracking !== 'none' && (
                  <>
                    <Input
                      id="item-opening-batch"
                      dir="ltr"
                      className="font-mono"
                      label={tracking === 'serial' ? t('inventory.field.serial', 'Serial number') : t('inventory.field.batch', 'Batch code')}
                      value={openingBatch}
                      onChange={(e) => setOpeningBatch(e.target.value)}
                    />
                    {expires && (
                      <Input
                        id="item-opening-expiry"
                        type="date"
                        dir="ltr"
                        label={t('inventory.field.expiresOn', 'Expires on')}
                        value={openingExpiry}
                        onChange={(e) => setOpeningExpiry(e.target.value)}
                      />
                    )}
                  </>
                )}
              </div>
            </section>
          )}

          {problem && (
            <Alert variant="destructive">
              <AlertDescription>{problem}</AlertDescription>
            </Alert>
          )}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>
              {t('action.cancel', 'Cancel')}
            </Button>
            <Button type="submit" loading={saving} data-testid="item-form-save">
              {adding ? t('inventory.item.add', 'Add item') : t('inventory.item.save', 'Save')}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
