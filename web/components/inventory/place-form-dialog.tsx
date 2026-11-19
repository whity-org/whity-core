'use client';

/**
 * Adding or changing a place stock is kept (WHIT-1232, register IV.7): a
 * warehouse, shop or van, with a name in Arabic and English, a short code and
 * an address. One main button.
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
import { BilingualInput, type BilingualValue } from '@whity/ui/bilingual-input';
import { Button } from '@whity/ui/button';
import { useTranslation } from '@whity/features/i18n';
import { inventoryApi, inventoryErrorText, type InventoryLocation } from '@/lib/inventory/api';

export function PlaceFormDialog({
  open,
  onOpenChange,
  place,
  onSaved,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  place: InventoryLocation | null;
  onSaved: (place: InventoryLocation) => void;
}) {
  const t = useTranslation('admin');
  const [names, setNames] = useState<BilingualValue>(() => ({ ...(place?.names ?? {}) }));
  const [code, setCode] = useState(place?.code ?? '');
  const [address, setAddress] = useState(place?.address ?? '');
  const [saving, setSaving] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);

  const save = async () => {
    const clean = Object.fromEntries(Object.entries(names).filter(([, v]) => typeof v === 'string' && v.trim() !== '')) as Record<string, string>;
    if (Object.keys(clean).length === 0) {
      setProblem(t('inventory.error.placeName', 'Give the place a name.'));
      return;
    }
    setSaving(true);
    setProblem(null);
    try {
      const body = { name: clean, code: code.trim(), address: address.trim() };
      onSaved(place === null ? await inventoryApi.createLocation(body) : await inventoryApi.updateLocation(place.id, body));
    } catch (error) {
      setProblem(inventoryErrorText(error));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-lg" data-testid="place-form">
        <DialogHeader>
          <DialogTitle>{place === null ? t('inventory.place.new', 'New place') : t('inventory.place.change', 'Change place')}</DialogTitle>
          <DialogDescription>{t('inventory.place.hint', 'A warehouse, shop or van where you keep stock.')}</DialogDescription>
        </DialogHeader>
        <form
          className="space-y-4"
          onSubmit={(e) => {
            e.preventDefault();
            void save();
          }}
        >
          <BilingualInput id="place-name" label={t('inventory.field.name', 'Name')} value={names} onChange={setNames} required />
          <Input
            id="place-code"
            dir="ltr"
            className="font-mono"
            label={t('inventory.field.code', 'Short code')}
            value={code}
            onChange={(e) => setCode(e.target.value)}
          />
          <Input id="place-address" label={t('inventory.field.address', 'Address')} value={address} onChange={(e) => setAddress(e.target.value)} />
          {problem && (
            <Alert variant="destructive">
              <AlertDescription>{problem}</AlertDescription>
            </Alert>
          )}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>
              {t('action.cancel', 'Cancel')}
            </Button>
            <Button type="submit" loading={saving} data-testid="place-form-save">
              {place === null ? t('inventory.place.add', 'Add place') : t('inventory.place.save', 'Save')}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
