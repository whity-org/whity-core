'use client';

/**
 * Lines of items and quantities for a transfer, for waste (WHIT-1233) or for
 * a purchase order (WHIT-1234): find an item by name in either language, SKU
 * or barcode (a scanner types into the same field), give the quantity, add the
 * next. A variant shows its options beside the name. A purchase order also
 * chooses the unit it is bought in (a 25 kg bag) and may give a price; left
 * empty, the supplier's price for that unit is used. Quantities stay Western
 * digits, left to right (IV.21).
 */

import { useEffect, useState } from 'react';
import { Input } from '@/components/ui/input';
import { Button } from '@whity/ui/button';
import { Icon } from '@whity/ui/icon';
import { MoneyInput } from '@whity/ui/money/money-input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@whity/ui/select';
import { useLanguageCode, useTranslation } from '@whity/features/i18n';
import { inventoryApi, nameIn, type InventoryItem } from '@/lib/inventory/api';

export interface StockLine {
  key: string;
  variantId: number;
  label: string;
  sku: string;
  unit: string;
  quantity: string;
  /** The units it can be bought in, base unit first (purchase orders). */
  units?: string[];
  /** The price of one `unit`, as typed; empty for the supplier's (purchase orders). */
  price?: string;
}

interface Choice {
  variantId: number;
  label: string;
  sku: string;
  unit: string;
  units: string[];
}

function choicesOf(items: InventoryItem[], language: string | null): Choice[] {
  const out: Choice[] = [];
  for (const item of items) {
    if (item.kind === 'service' || item.archived) continue;
    const name = nameIn(item.names, item.name, language);
    for (const v of item.variants.filter((x) => !x.archived)) {
      const options = Object.values(v.options ?? {});
      out.push({
        variantId: v.id,
        label: options.length > 0 ? `${name} · ${options.join(' · ')}` : name,
        sku: v.sku,
        unit: item.base_unit.code,
        units: [item.base_unit.code, ...item.units.filter((u) => u.purpose !== 'sale').map((u) => u.unit)],
      });
    }
  }
  return out;
}

export function StockLinesEditor({
  lines,
  onChange,
  idPrefix,
  priceCurrency,
}: {
  lines: StockLine[];
  onChange: (lines: StockLine[]) => void;
  idPrefix: string;
  /** For a purchase order: the unit is chosen and a price may be given, in this currency. */
  priceCurrency?: string;
}) {
  const t = useTranslation('admin');
  const language = useLanguageCode();
  const [search, setSearch] = useState('');
  const [choices, setChoices] = useState<Choice[]>([]);

  useEffect(() => {
    const q = search.trim();
    if (q === '') return;
    let live = true;
    const id = setTimeout(() => {
      inventoryApi
        .items({ q, perPage: 8 })
        .then((page) => {
          if (live) setChoices(choicesOf(page.data, language).slice(0, 8));
        })
        .catch(() => {
          if (live) setChoices([]);
        });
    }, 250);
    return () => {
      live = false;
      clearTimeout(id);
    };
  }, [search, language]);

  const shown = search.trim() === '' ? [] : choices;

  const add = (choice: Choice) => {
    setSearch('');
    setChoices([]);
    if (lines.some((l) => l.variantId === choice.variantId)) return;
    // A purchase is usually in the bigger unit it is bought in (a bag, not kg).
    const unit = priceCurrency ? (choice.units[1] ?? choice.unit) : choice.unit;
    onChange([...lines, { key: `${choice.variantId}`, ...choice, unit, quantity: '', price: '' }]);
  };

  return (
    <div className="space-y-3">
      {lines.length > 0 && (
        <ul className="divide-y divide-border rounded-md border border-border" data-testid={`${idPrefix}-lines`}>
          {lines.map((line, index) => (
            <li key={line.key} className="flex items-center gap-3 px-3 py-2">
              <div className="min-w-0 flex-1">
                <div className="truncate text-sm font-medium">{line.label}</div>
                <bdi dir="ltr" className="font-mono text-xs text-muted-foreground">
                  {line.sku}
                </bdi>
              </div>
              <div className="w-32">
                <Input
                  id={`${idPrefix}-qty-${index}`}
                  dir="ltr"
                  inputMode="decimal"
                  className="font-mono"
                  aria-label={t('inventory.field.quantityIn', 'Quantity, in {unit}', { unit: line.unit })}
                  placeholder={line.unit}
                  value={line.quantity}
                  onChange={(e) => onChange(lines.map((l) => (l.key === line.key ? { ...l, quantity: e.target.value } : l)))}
                />
              </div>
              {priceCurrency && (line.units?.length ?? 0) > 1 && (
                <div className="w-24">
                  <Select
                    value={line.unit}
                    onValueChange={(unit) => onChange(lines.map((l) => (l.key === line.key ? { ...l, unit } : l)))}
                  >
                    <SelectTrigger aria-label={t('inventory.lines.unit', 'Unit for {item}', { item: line.label })}>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {(line.units ?? []).map((u) => (
                        <SelectItem key={u} value={u}>
                          {u}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
              )}
              {priceCurrency && (
                <div className="w-36">
                  <MoneyInput
                    currency={priceCurrency}
                    value={line.price ?? ''}
                    onValueChange={(price) => onChange(lines.map((l) => (l.key === line.key ? { ...l, price } : l)))}
                    aria-label={t('inventory.lines.price', 'Price of one {unit}', { unit: line.unit })}
                    placeholder={t('inventory.lines.supplierPrice', 'Supplier’s')}
                  />
                </div>
              )}
              <Button
                type="button"
                variant="ghost"
                size="icon"
                aria-label={t('inventory.lines.remove', 'Remove {item}', { item: line.label })}
                onClick={() => onChange(lines.filter((l) => l.key !== line.key))}
              >
                <Icon name="close" />
              </Button>
            </li>
          ))}
        </ul>
      )}
      <div className="relative">
        <Input
          id={`${idPrefix}-find`}
          label={lines.length === 0 ? t('inventory.lines.find', 'Item') : t('inventory.lines.findMore', 'Add another item')}
          placeholder={t('inventory.search', 'Search by name, SKU or barcode')}
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === 'Enter' && shown.length > 0) {
              e.preventDefault();
              add(shown[0]);
            }
          }}
          autoComplete="off"
        />
        {shown.length > 0 && (
          <ul
            role="listbox"
            aria-label={t('inventory.lines.matches', 'Matching items')}
            className="absolute inset-x-0 top-full z-10 mt-1 max-h-64 overflow-auto rounded-md border border-border bg-popover shadow-md"
          >
            {shown.map((choice) => (
              <li key={choice.variantId} role="option" aria-selected={false}>
                <button
                  type="button"
                  className="flex w-full items-center justify-between gap-3 px-3 py-2 text-start text-sm hover:bg-muted focus-visible:bg-muted focus-visible:outline-none"
                  onClick={() => add(choice)}
                >
                  <span className="truncate">{choice.label}</span>
                  <bdi dir="ltr" className="font-mono text-xs text-muted-foreground">
                    {choice.sku}
                  </bdi>
                </button>
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}

/** The lines as the API takes them, or the problem to show. */
export function linesForApi(lines: StockLine[], sign: '' | '-' = ''): { variant_id: number; quantity: string }[] | null {
  if (lines.length === 0) return null;
  const out: { variant_id: number; quantity: string }[] = [];
  for (const line of lines) {
    const q = line.quantity.trim().replace(/^[-+]/, '');
    if (q === '' || !/^\d+(\.\d+)?$/.test(q) || Number(q) <= 0) return null;
    out.push({ variant_id: line.variantId, quantity: sign + q });
  }
  return out;
}
