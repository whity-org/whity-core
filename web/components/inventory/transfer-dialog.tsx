'use client';

/**
 * Moving stock from one place to another (WHIT-1233, register IV.16): where
 * from, where to, which items and how many. It leaves now and waits "on the
 * way" until someone receives it, unless it arrives at once (the workspace's
 * setting, which this switch starts from). One main button.
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
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Button } from '@whity/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@whity/ui/select';
import { Switch } from '@whity/ui/switch';
import { useLanguageCode, useTranslation } from '@whity/features/i18n';
import { StockLinesEditor, linesForApi, type StockLine } from '@/components/inventory/stock-lines-editor';
import {
  inventoryApi,
  inventoryErrorText,
  nameIn,
  type InventoryLocation,
  type InventoryOperation,
} from '@/lib/inventory/api';

export function TransferDialog({
  open,
  onOpenChange,
  places,
  oneStepByDefault,
  onDone,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  places: InventoryLocation[];
  oneStepByDefault: boolean;
  onDone: (transfer: InventoryOperation) => void;
}) {
  const t = useTranslation('admin');
  const language = useLanguageCode();
  const [from, setFrom] = useState(String(places[0]?.id ?? ''));
  const [to, setTo] = useState(String(places[1]?.id ?? ''));
  const [lines, setLines] = useState<StockLine[]>([]);
  const [oneStep, setOneStep] = useState(oneStepByDefault);
  const [saving, setSaving] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);

  const placeSelect = (id: string, value: string, onChange: (v: string) => void, label: string) => (
    <div className="space-y-1.5">
      <label className="text-sm font-medium" htmlFor={id}>
        {label}
      </label>
      <Select value={value} onValueChange={onChange}>
        <SelectTrigger id={id}>
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
  );

  const save = async () => {
    if (from === to) {
      setProblem(t('inventory.transfer.samePlace', 'Choose two different places.'));
      return;
    }
    const body = linesForApi(lines);
    if (body === null) {
      setProblem(t('inventory.error.lines', 'Add at least one item, each with an amount more than 0.'));
      return;
    }
    setSaving(true);
    setProblem(null);
    try {
      onDone(
        await inventoryApi.createTransfer({
          from_location_id: Number(from),
          to_location_id: Number(to),
          lines: body,
          one_step: oneStep,
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
      <DialogContent className="sm:max-w-xl" data-testid="transfer-form">
        <DialogHeader>
          <DialogTitle>{t('inventory.transfer.title', 'Transfer stock')}</DialogTitle>
          <DialogDescription>
            {t('inventory.transfer.hint', 'Move items from one place to another. Each move is kept in the history.')}
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
            {placeSelect('transfer-from', from, setFrom, t('inventory.transfer.from', 'From'))}
            {placeSelect('transfer-to', to, setTo, t('inventory.transfer.to', 'To'))}
          </div>
          <StockLinesEditor lines={lines} onChange={setLines} idPrefix="transfer" />
          <label className="flex items-start gap-3 text-sm" htmlFor="transfer-one-step">
            <Switch id="transfer-one-step" checked={oneStep} onCheckedChange={setOneStep} />
            <span>
              <span className="font-medium">{t('inventory.transfer.oneStep', 'It arrives now')}</span>
              <span className="block text-muted-foreground">
                {t('inventory.transfer.oneStepHint', 'Leave this off when it travels, and receive it when it gets there.')}
              </span>
            </span>
          </label>
          {problem && (
            <Alert variant="destructive">
              <AlertDescription>{problem}</AlertDescription>
            </Alert>
          )}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>
              {t('action.cancel', 'Cancel')}
            </Button>
            <Button type="submit" loading={saving} data-testid="transfer-save">
              {oneStep ? t('inventory.transfer.move', 'Move stock') : t('inventory.transfer.send', 'Send')}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
