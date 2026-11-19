'use client';

/**
 * Starting a stock count (WHIT-1233, register IV.15): one place, everything
 * there or one category, and whether the people counting see what the stock
 * expects (a blind count hides it). Opens the count to enter what's there.
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
import {
  inventoryApi,
  inventoryErrorText,
  nameIn,
  type InventoryCategory,
  type InventoryLocation,
  type InventoryOperation,
} from '@/lib/inventory/api';

export function CountStartDialog({
  open,
  onOpenChange,
  places,
  categories,
  onStarted,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  places: InventoryLocation[];
  categories: InventoryCategory[];
  onStarted: (count: InventoryOperation) => void;
}) {
  const t = useTranslation('admin');
  const language = useLanguageCode();
  const [place, setPlace] = useState(String(places[0]?.id ?? ''));
  const [category, setCategory] = useState('all');
  const [blind, setBlind] = useState(false);
  const [saving, setSaving] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);

  const start = async () => {
    setSaving(true);
    setProblem(null);
    try {
      onStarted(
        await inventoryApi.startCount({
          location_id: Number(place),
          category_id: category === 'all' ? null : Number(category),
          is_blind: blind,
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
      <DialogContent className="sm:max-w-lg" data-testid="count-form">
        <DialogHeader>
          <DialogTitle>{t('inventory.count.title', 'Count stock')}</DialogTitle>
          <DialogDescription>
            {t('inventory.count.hint', 'Count what is on the shelves. Sales while you count are not differences.')}
          </DialogDescription>
        </DialogHeader>
        <form
          className="space-y-4"
          onSubmit={(e) => {
            e.preventDefault();
            void start();
          }}
        >
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="space-y-1.5">
              <label className="text-sm font-medium" htmlFor="count-place">
                {t('inventory.field.place', 'Place')}
              </label>
              <Select value={place} onValueChange={setPlace}>
                <SelectTrigger id="count-place">
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
              <label className="text-sm font-medium" htmlFor="count-category">
                {t('inventory.count.what', 'What to count')}
              </label>
              <Select value={category} onValueChange={setCategory}>
                <SelectTrigger id="count-category">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">{t('inventory.count.everything', 'Everything there')}</SelectItem>
                  {categories.map((c) => (
                    <SelectItem key={c.id} value={String(c.id)}>
                      {nameIn(c.names, c.name, language)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </div>
          <label className="flex items-start gap-3 text-sm" htmlFor="count-blind">
            <Switch id="count-blind" checked={blind} onCheckedChange={setBlind} />
            <span>
              <span className="font-medium">{t('inventory.count.blind', 'Blind count')}</span>
              <span className="block text-muted-foreground">
                {t('inventory.count.blindHint', 'The people counting don’t see what the stock expects.')}
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
            <Button type="submit" loading={saving} disabled={place === ''} data-testid="count-start">
              {t('inventory.count.start', 'Start counting')}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
