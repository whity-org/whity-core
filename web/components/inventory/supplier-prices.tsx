'use client';

/**
 * Who sells an item and for how much (WHIT-1234, register IV.12): each
 * supplier's price for one unit they sell it in, in its own currency, and
 * which one is preferred (a new purchase order takes its price). People who
 * can change items add and remove prices; people who see purchases read them.
 */

import { useState } from 'react';
import { useFetch } from '@/hooks/useFetch';
import { contactsApi } from '@/lib/contacts/api';
import { Badge } from '@whity/ui/badge';
import { Button } from '@whity/ui/button';
import { Icon } from '@whity/ui/icon';
import { Money } from '@whity/ui/money/money';
import { MoneyInput } from '@whity/ui/money/money-input';
import { parseMoney } from '@whity/ui/money/currency';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@whity/ui/select';
import { Switch } from '@whity/ui/switch';
import { useTranslation } from '@whity/features/i18n';
import { inventoryApi, inventoryErrorText, type InventoryItem } from '@/lib/inventory/api';

export function SupplierPrices({ item, currency, canEdit }: { item: InventoryItem; currency: string; canEdit: boolean }) {
  const t = useTranslation('admin');
  const prices = useFetch(() => inventoryApi.supplierItems({ itemId: item.id }), [item.id]);
  const suppliers = useFetch(() => (canEdit ? contactsApi.list({ kind: 'supplier', perPage: 200 }) : Promise.resolve(null)), [canEdit]);
  const units = [item.base_unit.code, ...item.units.filter((u) => u.purpose !== 'sale').map((u) => u.unit)];
  const variant = item.variants.find((v) => !v.archived);
  const [supplier, setSupplier] = useState('');
  const [unit, setUnit] = useState(units[1] ?? units[0]);
  const [price, setPrice] = useState('');
  const [preferred, setPreferred] = useState(false);
  const [saving, setSaving] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);

  const add = async () => {
    if (supplier === '' || variant === undefined) {
      setProblem(t('inventory.po.noSupplier', 'Choose the supplier.'));
      return;
    }
    let minor: number;
    try {
      minor = parseMoney(price, currency);
    } catch {
      setProblem(t('inventory.prices.badPrice', 'The price doesn’t look right.'));
      return;
    }
    setSaving(true);
    setProblem(null);
    try {
      await inventoryApi.setSupplierItem({
        variant_id: variant.id,
        contact_id: Number(supplier),
        unit,
        price_minor: minor,
        currency,
        is_preferred: preferred,
      });
      setSupplier('');
      setPrice('');
      setPreferred(false);
      prices.refetch();
    } catch (e) {
      setProblem(inventoryErrorText(e));
    } finally {
      setSaving(false);
    }
  };

  const remove = async (id: number) => {
    setProblem(null);
    try {
      await inventoryApi.deleteSupplierItem(id);
      prices.refetch();
    } catch (e) {
      setProblem(inventoryErrorText(e));
    }
  };

  const rows = prices.data ?? [];
  return (
    <section className="space-y-3 rounded-lg border border-border p-5" data-testid="item-suppliers">
      <h2 className="text-sm font-semibold text-muted-foreground">{t('inventory.prices.title', 'Suppliers')}</h2>
      {rows.length === 0 ? (
        <p className="text-sm text-muted-foreground">{t('inventory.prices.none', 'No supplier prices yet.')}</p>
      ) : (
        <ul className="divide-y divide-border text-sm">
          {rows.map((row) => (
            <li key={row.id} className="flex flex-wrap items-center justify-between gap-3 py-2.5">
              <span className="flex items-center gap-2">
                {row.contact_name}
                {row.is_preferred && <Badge variant="success">{t('inventory.prices.preferred', 'Preferred')}</Badge>}
                {row.supplier_sku !== '' && (
                  <bdi dir="ltr" className="font-mono text-xs text-muted-foreground">
                    {row.supplier_sku}
                  </bdi>
                )}
              </span>
              <span className="flex items-center gap-3">
                <span>
                  <Money minor={row.price_minor} currency={row.currency} />{' '}
                  <span className="text-muted-foreground">{t('inventory.prices.per', 'a {unit}', { unit: row.unit })}</span>
                </span>
                {canEdit && (
                  <Button
                    variant="ghost"
                    size="icon"
                    aria-label={t('inventory.prices.remove', 'Remove {supplier}’s price', { supplier: row.contact_name })}
                    onClick={() => void remove(row.id)}
                  >
                    <Icon name="delete" />
                  </Button>
                )}
              </span>
            </li>
          ))}
        </ul>
      )}
      {canEdit && variant !== undefined && (
        <form
          className="flex flex-wrap items-end gap-3 border-t border-border pt-3"
          onSubmit={(e) => {
            e.preventDefault();
            void add();
          }}
        >
          <div className="w-56 space-y-1.5">
            <label className="text-sm font-medium" htmlFor="price-supplier">
              {t('inventory.po.supplier', 'Supplier')}
            </label>
            <Select value={supplier} onValueChange={setSupplier}>
              <SelectTrigger id="price-supplier">
                <SelectValue placeholder={t('inventory.po.chooseSupplier', 'Choose a supplier')} />
              </SelectTrigger>
              <SelectContent>
                {(suppliers.data?.data ?? []).map((c) => (
                  <SelectItem key={c.id} value={String(c.id)}>
                    {c.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          {units.length > 1 && (
            <div className="w-24 space-y-1.5">
              <label className="text-sm font-medium" htmlFor="price-unit">
                {t('inventory.prices.unit', 'Unit')}
              </label>
              <Select value={unit} onValueChange={setUnit}>
                <SelectTrigger id="price-unit">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {units.map((u) => (
                    <SelectItem key={u} value={u}>
                      {u}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          )}
          <div className="w-40 space-y-1.5">
            <label className="text-sm font-medium" htmlFor="price-amount">
              {t('inventory.prices.price', 'Price of one {unit}', { unit })}
            </label>
            <MoneyInput id="price-amount" currency={currency} value={price} onValueChange={setPrice} />
          </div>
          <label className="flex items-center gap-2 pb-2 text-sm" htmlFor="price-preferred">
            <Switch id="price-preferred" checked={preferred} onCheckedChange={setPreferred} />
            {t('inventory.prices.preferred', 'Preferred')}
          </label>
          <Button type="submit" variant="outline" loading={saving}>
            {t('inventory.prices.add', 'Add price')}
          </Button>
          {problem && <p className="w-full text-sm text-destructive">{problem}</p>}
        </form>
      )}
    </section>
  );
}
