'use client';

/**
 * Writing a quote (WHIT-1341, design section 34 §3.1-§3.2): the customer (a
 * contact marked as one), how long it is valid, and its lines. A line is an
 * item (found by name in either language or SKU, its price filled in when
 * left empty) or a free line with words of its own, for workspaces that sell
 * services and never open Inventory. Each line has a quantity, a price in the
 * quote's currency, a discount and a tax rate as percents. Terms print on the
 * quote; notes stay with the team.
 *
 * New quotes and drafts share this form; one main button saves. The brand
 * team's card is an Ask; this follows section 21's record page meanwhile.
 */

import { useEffect, useState } from 'react';
import { DatePicker } from '@/components/ui/date-picker';
import { Input } from '@/components/ui/input';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useFetch } from '@/hooks/useFetch';
import { contactsApi } from '@/lib/contacts/api';
import { inventoryApi, nameIn, type InventoryItem } from '@/lib/inventory/api';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Button } from '@whity/ui/button';
import { Checkbox } from '@whity/ui/checkbox';
import { Icon } from '@whity/ui/icon';
import { Money } from '@whity/ui/money/money';
import { MoneyInput } from '@whity/ui/money/money-input';
import { parseMoney, toDecimalString } from '@whity/ui/money/currency';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@whity/ui/select';
import { Textarea } from '@whity/ui/textarea';
import { useLanguageCode, useTranslation } from '@whity/features/i18n';
import {
  percentText,
  percentToBp,
  salesErrorText,
  type SalesQuote,
  type SalesQuoteLineRequest,
  type SalesQuoteRequest,
} from '@/lib/sales/api';

export interface QuoteLineDraft {
  key: string;
  variantId: number | null;
  sku: string | null;
  unit: string | null;
  description: string;
  quantity: string;
  /** In normal units of the quote's currency; empty on an item line for the item's own price. */
  price: string;
  discount: string;
  tax: string;
}

let keys = 0;
const nextKey = () => `l${++keys}`;

function blankLine(): QuoteLineDraft {
  return { key: nextKey(), variantId: null, sku: null, unit: null, description: '', quantity: '1', price: '', discount: '', tax: '' };
}

function isoDay(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

/** The lines of a saved quote, to change. */
export function linesOf(quote: SalesQuote): QuoteLineDraft[] {
  return (quote.lines ?? []).map((l) => ({
    key: nextKey(),
    variantId: l.variant_id ?? null,
    sku: l.sku ?? null,
    unit: l.unit ?? null,
    description: l.description,
    quantity: l.quantity,
    price: toDecimalString(l.price_minor, quote.currency),
    discount: l.discount_bp ? percentText(l.discount_bp) : '',
    tax: l.tax_bp ? percentText(l.tax_bp) : '',
  }));
}

export interface QuoteFormProps {
  /** The draft being changed; a new quote when left out. */
  quote?: SalesQuote;
  /** The workspace's currency, for a new quote (CU.1). */
  currency: string;
  /** Start from this customer (from a contact's page). */
  customerId?: number;
  saveLabel: string;
  onSave: (body: SalesQuoteRequest) => Promise<void>;
  onCancel: () => void;
}

export function QuoteForm({ quote, currency: workspaceCurrency, customerId, saveLabel, onSave, onCancel }: QuoteFormProps) {
  const t = useTranslation('admin');
  const language = useLanguageCode();
  const { has } = useCapabilities();
  const currency = quote?.currency ?? workspaceCurrency;
  const customers = useFetch(() => contactsApi.list({ kind: 'customer', perPage: 200 }), []);
  const [customer, setCustomer] = useState(quote ? String(quote.contact_id) : customerId !== undefined ? String(customerId) : '');
  const [validUntil, setValidUntil] = useState<string | null>(quote?.valid_until ?? null);
  const [includeTax, setIncludeTax] = useState(quote?.prices_include_tax ?? false);
  const [lines, setLines] = useState<QuoteLineDraft[]>(() => (quote ? linesOf(quote) : [blankLine()]));
  const [terms, setTerms] = useState(quote?.terms ?? '');
  const [notes, setNotes] = useState(quote?.notes ?? '');
  const [saving, setSaving] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);

  const change = (key: string, patch: Partial<QuoteLineDraft>) => setLines((all) => all.map((l) => (l.key === key ? { ...l, ...patch } : l)));
  const remove = (key: string) => setLines((all) => (all.length > 1 ? all.filter((l) => l.key !== key) : [blankLine()]));

  /** What a line comes to before tax, for the form's own sum; the saved quote's totals are the server's. */
  const lineMinor = (line: QuoteLineDraft): number | null => {
    if (line.price.trim() === '') return null;
    try {
      const price = parseMoney(line.price, currency);
      const quantity = Number(line.quantity);
      const discount = percentToBp(line.discount) ?? 0;
      return Number.isFinite(quantity) ? Math.round(price * quantity * (1 - discount / 10000)) : null;
    } catch {
      return null;
    }
  };
  const known = lines.map(lineMinor);
  const sum = known.every((v) => v !== null) ? known.reduce<number>((a, b) => a + (b ?? 0), 0) : null;

  const save = async () => {
    if (customer === '') {
      setProblem(t('sales.form.noCustomer', 'Choose the customer.'));
      return;
    }
    const body: SalesQuoteLineRequest[] = [];
    for (const [i, line] of lines.entries()) {
      const n = i + 1;
      const quantity = line.quantity.trim();
      if (!/^\d+(\.\d+)?$/.test(quantity) || Number(quantity) <= 0) {
        setProblem(t('sales.form.badQuantity', 'Line {n}: the quantity is a number more than 0.', { n }));
        return;
      }
      if (line.variantId === null && line.description.trim() === '') {
        setProblem(t('sales.form.noDescription', 'Line {n}: say what it is, or choose an item.', { n }));
        return;
      }
      const discount = percentToBp(line.discount);
      const tax = percentToBp(line.tax);
      if (discount === null || tax === null) {
        setProblem(t('sales.form.badPercent', 'Line {n}: a discount or tax is a percent from 0 to 100.', { n }));
        return;
      }
      let price: number | undefined;
      if (line.price.trim() !== '') {
        try {
          price = parseMoney(line.price, currency);
        } catch {
          setProblem(t('sales.form.badPrice', 'Line {n}: the price doesn’t look right.', { n }));
          return;
        }
      } else if (line.variantId === null) {
        setProblem(t('sales.form.noPrice', 'Line {n}: give a price.', { n }));
        return;
      }
      body.push({
        ...(line.variantId !== null ? { variant_id: line.variantId } : {}),
        ...(line.unit ? { unit: line.unit } : {}),
        description: line.description.trim(),
        quantity,
        ...(price !== undefined ? { price_minor: price } : {}),
        discount_bp: discount,
        tax_bp: tax,
      });
    }
    setSaving(true);
    setProblem(null);
    try {
      await onSave({
        contact_id: Number(customer),
        ...(validUntil ? { valid_until: validUntil } : {}),
        ...(quote ? {} : { currency }),
        prices_include_tax: includeTax,
        terms: terms.trim(),
        notes: notes.trim(),
        lines: body,
      });
    } catch (error) {
      setProblem(salesErrorText(error));
    } finally {
      setSaving(false);
    }
  };

  const customerList = customers.data?.data ?? [];

  return (
    <form
      className="@container space-y-6"
      data-testid="quote-form"
      onSubmit={(e) => {
        e.preventDefault();
        void save();
      }}
    >
      <section className="grid gap-4 rounded-lg border border-border p-5 sm:grid-cols-3">
        <div className="space-y-1.5">
          <label className="text-sm font-medium" htmlFor="quote-customer">
            {t('sales.form.customer', 'Customer')}
          </label>
          <Select value={customer} onValueChange={setCustomer}>
            <SelectTrigger id="quote-customer">
              <SelectValue placeholder={t('sales.form.chooseCustomer', 'Choose a customer')} />
            </SelectTrigger>
            <SelectContent>
              {customerList.map((c) => (
                <SelectItem key={c.id} value={String(c.id)}>
                  {c.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          {customers.data !== null && customerList.length === 0 && (
            <p className="text-sm text-muted-foreground">{t('sales.form.noCustomers', 'No customers yet: add one in Contacts first.')}</p>
          )}
        </div>
        <DatePicker
          label={t('sales.form.validUntil', 'Valid until')}
          value={validUntil ?? undefined}
          onChange={(d) => setValidUntil(d ? isoDay(d) : null)}
          helperText={validUntil === null ? t('sales.form.validDefault', 'Leave it empty for the workspace’s usual number of days.') : undefined}
        />
        <label className="flex items-center gap-2 self-end pb-2 text-sm">
          <Checkbox checked={includeTax} onCheckedChange={(v) => setIncludeTax(v === true)} data-testid="quote-include-tax" />
          {t('sales.form.includeTax', 'Prices include tax')}
        </label>
      </section>

      <section className="space-y-3 rounded-lg border border-border p-5">
        <h2 className="font-semibold">{t('sales.form.lines', 'What you are quoting')}</h2>
        {has('inventory:read') && (
          <ItemSearch
            language={language}
            onPick={(item, variantId) => {
              const variant = item.variants.find((v) => v.id === variantId);
              const options = Object.values(variant?.options ?? {}).join(' ');
              const name = nameIn(item.names, item.name, language) + (options ? ` ${options}` : '');
              setLines((all) => {
                const line: QuoteLineDraft = {
                  ...blankLine(),
                  variantId,
                  sku: variant?.sku ?? null,
                  description: name,
                  price: variant?.sale_price_minor != null ? toDecimalString(variant.sale_price_minor, currency) : '',
                };
                // An empty first line is replaced, not kept.
                const first = all[0];
                return all.length === 1 && first && first.variantId === null && first.description === '' && first.price === '' ? [line] : [...all, line];
              });
            }}
          />
        )}
        <div className="space-y-3" data-testid="quote-lines">
          {lines.map((line, i) => {
            const amount = lineMinor(line);
            return (
              <div
                key={line.key}
                className="grid grid-cols-2 items-end gap-3 rounded-md border border-border p-3 @2xl:grid-cols-4 @5xl:grid-cols-[minmax(0,1fr)_6rem_9rem_6rem_6rem_auto]"
              >
                <div className="col-span-full @5xl:col-span-1">
                  <Input
                    id={`quote-line-${line.key}-description`}
                    dir="auto"
                    label={line.sku ? t('sales.form.itemLine', 'Item {sku}', { sku: line.sku }) : t('sales.form.description', 'Description')}
                    value={line.description}
                    onChange={(e) => change(line.key, { description: e.target.value })}
                  />
                </div>
                <Input
                  id={`quote-line-${line.key}-quantity`}
                  dir="ltr"
                  inputMode="decimal"
                  label={line.unit ? t('sales.form.quantityIn', 'Quantity ({unit})', { unit: line.unit }) : t('sales.form.quantity', 'Quantity')}
                  value={line.quantity}
                  onChange={(e) => change(line.key, { quantity: e.target.value })}
                />
                <div className="space-y-1.5">
                  <label className="text-sm font-medium" htmlFor={`quote-line-${line.key}-price`}>
                    {t('sales.form.price', 'Price of one')}
                  </label>
                  <MoneyInput
                    id={`quote-line-${line.key}-price`}
                    currency={currency}
                    value={line.price}
                    onValueChange={(v) => change(line.key, { price: v })}
                    placeholder={line.variantId !== null ? t('sales.form.itemPrice', 'Item’s price') : undefined}
                  />
                </div>
                <Input
                  id={`quote-line-${line.key}-discount`}
                  dir="ltr"
                  inputMode="decimal"
                  label={t('sales.form.discount', 'Discount %')}
                  value={line.discount}
                  onChange={(e) => change(line.key, { discount: e.target.value })}
                />
                <Input
                  id={`quote-line-${line.key}-tax`}
                  dir="ltr"
                  inputMode="decimal"
                  label={t('sales.form.tax', 'Tax %')}
                  value={line.tax}
                  onChange={(e) => change(line.key, { tax: e.target.value })}
                />
                <div className="flex items-center gap-2 pb-1">
                  <span className="min-w-20 text-end text-sm">{amount !== null ? <Money minor={amount} currency={currency} /> : null}</span>
                  <Button type="button" variant="outline" size="sm" onClick={() => remove(line.key)} aria-label={t('sales.form.removeLine', 'Remove line {n}', { n: i + 1 })}>
                    <Icon name="delete" />
                  </Button>
                </div>
              </div>
            );
          })}
        </div>
        <div className="flex flex-wrap items-center justify-between gap-3">
          <Button type="button" variant="outline" onClick={() => setLines((all) => [...all, blankLine()])} data-testid="quote-add-line">
            <Icon name="plus" />
            {t('sales.form.addLine', 'Add a line')}
          </Button>
          {sum !== null && (
            <p className="text-sm">
              {t('sales.form.sumBeforeTax', 'Before tax:')} <Money minor={sum} currency={currency} className="font-semibold" />
            </p>
          )}
        </div>
      </section>

      <section className="grid gap-4 rounded-lg border border-border p-5 sm:grid-cols-2">
        <div className="space-y-1.5">
          <label htmlFor="quote-terms" className="text-sm font-medium">
            {t('sales.form.terms', 'Terms')}
          </label>
          <Textarea id="quote-terms" dir="auto" rows={3} value={terms} onChange={(e) => setTerms(e.target.value)} />
          <p className="text-sm text-muted-foreground">{t('sales.form.termsHint', 'Printed on the quote, for the customer.')}</p>
        </div>
        <div className="space-y-1.5">
          <label htmlFor="quote-notes" className="text-sm font-medium">
            {t('sales.form.notes', 'Notes for the team')}
          </label>
          <Textarea id="quote-notes" dir="auto" rows={3} value={notes} onChange={(e) => setNotes(e.target.value)} />
          <p className="text-sm text-muted-foreground">{t('sales.form.notesHint', 'Never shown to the customer.')}</p>
        </div>
      </section>

      {problem && (
        <Alert variant="destructive">
          <AlertDescription>{problem}</AlertDescription>
        </Alert>
      )}
      <div className="flex justify-end gap-2">
        <Button type="button" variant="outline" onClick={onCancel} disabled={saving}>
          {t('action.cancel', 'Cancel')}
        </Button>
        <Button type="submit" loading={saving} data-testid="quote-save">
          {saveLabel}
        </Button>
      </div>
    </form>
  );
}

/** Find an item by name or SKU and add it as a line; each variant is its own choice. */
function ItemSearch({ language, onPick }: { language: string | null; onPick: (item: InventoryItem, variantId: number) => void }) {
  const t = useTranslation('admin');
  const [q, setQ] = useState('');
  const [found, setFound] = useState<InventoryItem[]>([]);

  useEffect(() => {
    const text = q.trim();
    if (text.length < 2) return;
    let live = true;
    const timer = setTimeout(() => {
      inventoryApi
        .items({ q: text, perPage: 8 })
        .then((page) => {
          if (live) setFound(page.data);
        })
        .catch(() => {
          if (live) setFound([]);
        });
    }, 250);
    return () => {
      live = false;
      clearTimeout(timer);
    };
  }, [q]);

  return (
    <div className="space-y-2">
      <Input
        id="quote-item-search"
        dir="auto"
        label={t('sales.form.findItem', 'Add an item')}
        placeholder={t('sales.form.findItemHint', 'Name or SKU')}
        value={q}
        onChange={(e) => setQ(e.target.value)}
      />
      {q.trim().length >= 2 && found.length > 0 && (
        <ul className="divide-y divide-border rounded-md border border-border" data-testid="quote-item-results">
          {found.flatMap((item) =>
            item.variants
              .filter((v) => !v.archived)
              .map((v) => {
                const options = Object.values(v.options ?? {}).join(' ');
                return (
                  <li key={v.id}>
                    <button
                      type="button"
                      className="flex min-h-12 w-full items-center justify-between gap-3 px-3 text-start text-sm hover:bg-muted focus-visible:outline-2 focus-visible:outline-ring"
                      onClick={() => {
                        onPick(item, v.id);
                        setQ('');
                        setFound([]);
                      }}
                    >
                      <span>
                        {nameIn(item.names, item.name, language)}
                        {options && <span className="ms-1 text-muted-foreground">{options}</span>}
                      </span>
                      <bdi dir="ltr" className="font-mono text-sm text-muted-foreground">
                        {v.sku}
                      </bdi>
                    </button>
                  </li>
                );
              })
          )}
        </ul>
      )}
    </div>
  );
}
