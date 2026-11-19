'use client';

/**
 * Recording waste (WHIT-1233, `TplAdjustments`, register N5, IV.14): a place,
 * what was thrown away and how much, and why, from the workspace's own
 * reasons. A line worth more than the workspace's value waits for approval;
 * the rest changes stock at once. One main button.
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
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Button } from '@whity/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@whity/ui/select';
import { useLanguageCode, useTranslation } from '@whity/features/i18n';
import { StockLinesEditor, linesForApi, type StockLine } from '@/components/inventory/stock-lines-editor';
import {
  inventoryApi,
  inventoryErrorText,
  nameIn,
  reasonsFor,
  type InventoryLocation,
  type InventoryOperation,
  type InventoryReason,
} from '@/lib/inventory/api';

export function RecordWasteDialog({
  open,
  onOpenChange,
  places,
  reasons,
  onDone,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  places: InventoryLocation[];
  reasons: InventoryReason[];
  onDone: (adjustment: InventoryOperation) => void;
}) {
  const t = useTranslation('admin');
  const language = useLanguageCode();
  const out = reasonsFor(reasons, 'out');
  const [place, setPlace] = useState(String(places[0]?.id ?? ''));
  const [reason, setReason] = useState(out.find((r) => r.is_waste)?.code ?? out[0]?.code ?? '');
  const [lines, setLines] = useState<StockLine[]>([]);
  const [note, setNote] = useState('');
  const [saving, setSaving] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);

  const save = async () => {
    const body = linesForApi(lines, '-');
    if (body === null) {
      setProblem(t('inventory.error.lines', 'Add at least one item, each with an amount more than 0.'));
      return;
    }
    setSaving(true);
    setProblem(null);
    try {
      onDone(
        await inventoryApi.createAdjustment({
          location_id: Number(place),
          reason_code: reason,
          note: note.trim() === '' ? undefined : note.trim(),
          lines: body.map((l) => ({ variant_id: l.variant_id, change: l.quantity })),
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
      <DialogContent className="sm:max-w-xl" data-testid="waste-form">
        <DialogHeader>
          <DialogTitle>{t('inventory.waste.title', 'Record waste')}</DialogTitle>
          <DialogDescription>
            {t('inventory.waste.hint', 'What was thrown away, used up or lost. Above the approval value it waits for approval.')}
          </DialogDescription>
        </DialogHeader>
        <form
          className="space-y-4"
          onSubmit={(e) => {
            e.preventDefault();
            void save();
          }}
        >
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="space-y-1.5">
              <label className="text-sm font-medium" htmlFor="waste-place">
                {t('inventory.field.place', 'Place')}
              </label>
              <Select value={place} onValueChange={setPlace}>
                <SelectTrigger id="waste-place">
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
            <div className="space-y-1.5">
              <label className="text-sm font-medium" htmlFor="waste-reason">
                {t('inventory.field.reason', 'Why')}
              </label>
              <Select value={reason} onValueChange={setReason}>
                <SelectTrigger id="waste-reason">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {out.map((r) => (
                    <SelectItem key={r.code} value={r.code}>
                      {nameIn(r.names, r.name, language)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </div>
          <StockLinesEditor lines={lines} onChange={setLines} idPrefix="waste" />
          <Input dir="auto" id="waste-note" label={t('inventory.field.note', 'Note')} value={note} onChange={(e) => setNote(e.target.value)} />
          {problem && (
            <Alert variant="destructive">
              <AlertDescription>{problem}</AlertDescription>
            </Alert>
          )}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>
              {t('action.cancel', 'Cancel')}
            </Button>
            <Button type="submit" loading={saving} disabled={place === '' || reason === ''} data-testid="waste-save">
              {t('inventory.waste.save', 'Record waste')}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
