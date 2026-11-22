'use client';

/**
 * A new purchase order (WHIT-1234, register IV.12): the supplier (a contact
 * marked as one), the place it is received into, when it should arrive, and
 * what is ordered in the unit it is bought in. A line without a price takes
 * what the supplier sells it for. It is saved as a draft and opens, to be
 * checked and sent. One main button.
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
import { DatePicker } from '@/components/ui/date-picker';
import { Input } from '@/components/ui/input';
import { useFetch } from '@/hooks/useFetch';
import { contactsApi } from '@/lib/contacts/api';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Button } from '@whity/ui/button';
import { parseMoney } from '@whity/ui/money/currency';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@whity/ui/select';
import { useLanguageCode, useTranslation } from '@whity/features/i18n';
import { StockLinesEditor, type StockLine } from '@/components/inventory/stock-lines-editor';
import {
  inventoryApi,
  inventoryErrorText,
  nameIn,
  type InventoryLocation,
  type InventoryPurchaseOrder,
} from '@/lib/inventory/api';

function isoDay(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

export function PurchaseOrderDialog({
  open,
  onOpenChange,
  places,
  currency,
  supplierId,
  onSaved,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  places: InventoryLocation[];
  /** The workspace's currency, which the order is in (CU.1). */
  currency: string;
  /** Start from this supplier (from a contact's page). */
  supplierId?: number;
  onSaved: (order: InventoryPurchaseOrder) => void;
}) {
  const t = useTranslation('admin');
  const language = useLanguageCode();
  const suppliers = useFetch(() => contactsApi.list({ kind: 'supplier', perPage: 200 }), []);
  const [supplier, setSupplier] = useState(supplierId !== undefined ? String(supplierId) : '');
  const [place, setPlace] = useState(String(places[0]?.id ?? ''));
  const [expected, setExpected] = useState<string | null>(null);
  const [notes, setNotes] = useState('');
  const [lines, setLines] = useState<StockLine[]>([]);
  const [saving, setSaving] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);

  const save = async () => {
    if (supplier === '') {
      setProblem(t('inventory.po.noSupplier', 'Choose the supplier.'));
      return;
    }
    const body: { variant_id: number; quantity: string; unit: string; price_minor?: number }[] = [];
    for (const line of lines) {
      const q = line.quantity.trim();
      if (!/^\d+(\.\d+)?$/.test(q) || Number(q) <= 0) {
        setProblem(t('inventory.error.lines', 'Add at least one item, each with an amount more than 0.'));
        return;
      }
      let price: number | undefined;
      if ((line.price ?? '').trim() !== '') {
        try {
          price = parseMoney(line.price ?? '', currency);
        } catch {
          setProblem(t('inventory.po.badPrice', '{item}: the price doesn’t look right.', { item: line.label }));
          return;
        }
      }
      body.push({ variant_id: line.variantId, quantity: q, unit: line.unit, ...(price !== undefined ? { price_minor: price } : {}) });
    }
    if (body.length === 0) {
      setProblem(t('inventory.error.lines', 'Add at least one item, each with an amount more than 0.'));
      return;
    }
    setSaving(true);
    setProblem(null);
    try {
      onSaved(
        await inventoryApi.createPurchaseOrder({
          contact_id: Number(supplier),
          location_id: Number(place),
          expected_on: expected,
          currency,
          notes: notes.trim() === '' ? undefined : notes.trim(),
          lines: body,
        })
      );
    } catch (error) {
      setProblem(inventoryErrorText(error));
    } finally {
      setSaving(false);
    }
  };

  const supplierList = suppliers.data?.data ?? [];

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-2xl" data-testid="po-form">
        <DialogHeader>
          <DialogTitle>{t('inventory.po.new', 'New purchase order')}</DialogTitle>
          <DialogDescription>
            {t('inventory.po.newHint', 'It is saved as a draft for you to check and send. Leave a price empty to use what the supplier charges.')}
          </DialogDescription>
        </DialogHeader>
        <form
          className="space-y-4"
          onSubmit={(e) => {
            e.preventDefault();
            void save();
          }}
        >
          <div className="grid gap-4 sm:grid-cols-3">
            <div className="space-y-1.5">
              <label className="text-sm font-medium" htmlFor="po-supplier">
                {t('inventory.po.supplier', 'Supplier')}
              </label>
              <Select value={supplier} onValueChange={setSupplier}>
                <SelectTrigger id="po-supplier">
                  <SelectValue placeholder={t('inventory.po.chooseSupplier', 'Choose a supplier')} />
                </SelectTrigger>
                <SelectContent>
                  {supplierList.map((c) => (
                    <SelectItem key={c.id} value={String(c.id)}>
                      {c.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {suppliers.data !== null && supplierList.length === 0 && (
                <p className="text-xs text-muted-foreground">
                  {t('inventory.po.noSuppliers', 'No suppliers yet: mark a contact as a supplier first.')}
                </p>
              )}
            </div>
            <div className="space-y-1.5">
              <label className="text-sm font-medium" htmlFor="po-place">
                {t('inventory.po.receiveInto', 'Received into')}
              </label>
              <Select value={place} onValueChange={setPlace}>
                <SelectTrigger id="po-place">
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
            <DatePicker
              label={t('inventory.po.expected', 'Expected on')}
              value={expected ?? undefined}
              onChange={(d) => setExpected(d ? isoDay(d) : null)}
            />
          </div>
          <StockLinesEditor lines={lines} onChange={setLines} idPrefix="po" priceCurrency={currency} />
          <Input dir="auto" id="po-notes" label={t('inventory.po.notes', 'Notes for the supplier')} value={notes} onChange={(e) => setNotes(e.target.value)} />
          {problem && (
            <Alert variant="destructive">
              <AlertDescription>{problem}</AlertDescription>
            </Alert>
          )}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>
              {t('action.cancel', 'Cancel')}
            </Button>
            <Button type="submit" loading={saving} data-testid="po-save">
              {t('inventory.po.saveDraft', 'Save draft')}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
