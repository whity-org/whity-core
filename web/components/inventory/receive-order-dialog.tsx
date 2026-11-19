'use client';

/**
 * Receiving against a purchase order (WHIT-1234, `TplReceiving`, register
 * IV.12): each open line starts at what is still to come; change it to what
 * arrived, or leave it empty when nothing did. More than ordered asks first,
 * and is received only when the person says yes. A note keeps the delivery
 * note's number.
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
import { useLanguageCode, useTranslation } from '@whity/features/i18n';
import {
  InventoryRequestError,
  inventoryApi,
  inventoryErrorText,
  nameIn,
  quantityText,
  type InventoryPurchaseOrder,
} from '@/lib/inventory/api';

/** What is still to come of a line, in its unit ("18" of 24 when 6 arrived). */
function outstanding(quantity: string, received: string): string {
  const decimals = Math.max((quantity.split('.')[1] ?? '').length, (received.split('.')[1] ?? '').length);
  const scale = 10 ** decimals;
  const left = Math.round(Number(quantity) * scale) - Math.round(Number(received) * scale);
  return left <= 0 ? '' : quantityText((left / scale).toFixed(decimals));
}

export function ReceiveOrderDialog({
  order,
  onOpenChange,
  onDone,
}: {
  order: InventoryPurchaseOrder;
  onOpenChange: (open: boolean) => void;
  onDone: (order: InventoryPurchaseOrder) => void;
}) {
  const t = useTranslation('admin');
  const language = useLanguageCode();
  const lines = (order.lines ?? []).filter((l) => !l.is_closed);
  const [amounts, setAmounts] = useState<Record<number, string>>(
    Object.fromEntries(lines.map((l) => [l.id, outstanding(l.quantity, l.received)]))
  );
  const [note, setNote] = useState('');
  const [saving, setSaving] = useState(false);
  const [over, setOver] = useState<string | null>(null);
  const [problem, setProblem] = useState<string | null>(null);

  const save = async (allowOver: boolean) => {
    const body = lines
      .map((l) => ({ line_id: l.id, quantity: (amounts[l.id] ?? '').trim() }))
      .filter((l) => l.quantity !== '' && Number(l.quantity) > 0);
    if (body.length === 0) {
      setProblem(t('inventory.receive.nothing', 'Say what arrived on at least one line.'));
      return;
    }
    setSaving(true);
    setProblem(null);
    try {
      onDone(await inventoryApi.receivePurchaseOrder(order.id, body, allowOver, note.trim() === '' ? undefined : note.trim()));
    } catch (error) {
      if (error instanceof InventoryRequestError && error.status === 409 && error.code === 'over_receipt') {
        setOver(error.serverMessage);
      } else {
        setProblem(inventoryErrorText(error));
      }
    } finally {
      setSaving(false);
    }
  };

  return (
    <Dialog open onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-xl" data-testid="po-receive-form">
        <DialogHeader>
          <DialogTitle>{t('inventory.po.receiveTitle', 'Receive {number}', { number: order.number })}</DialogTitle>
          <DialogDescription>
            {order.contact_name}.{' '}
            {t('inventory.po.receiveHint', 'Each line starts at what is still to come. Change it to what arrived, or clear it if nothing did.')}
          </DialogDescription>
        </DialogHeader>
        <ul className="divide-y divide-border rounded-md border border-border">
          {lines.map((line) => (
            <li key={line.id} className="flex items-center gap-3 px-3 py-2">
              <div className="min-w-0 flex-1">
                <div className="truncate text-sm font-medium">{nameIn(line.item_names, line.item_name, language)}</div>
                <div className="text-xs text-muted-foreground">
                  {t('inventory.po.orderedReceived', 'Ordered {ordered}, received {received}', {
                    ordered: `${quantityText(line.quantity)} ${line.unit}`,
                    received: quantityText(line.received),
                  })}
                </div>
              </div>
              <div className="w-32">
                <Input
                  id={`po-receive-${line.id}`}
                  dir="ltr"
                  inputMode="decimal"
                  className="font-mono"
                  aria-label={t('inventory.receive.arrived', 'Arrived, in {unit}', { unit: line.unit })}
                  placeholder={line.unit}
                  value={amounts[line.id] ?? ''}
                  onChange={(e) => {
                    setOver(null);
                    setAmounts({ ...amounts, [line.id]: e.target.value });
                  }}
                />
              </div>
            </li>
          ))}
        </ul>
        <Input
          id="po-receive-note"
          label={t('inventory.po.deliveryNote', 'Delivery note number or a note')}
          value={note}
          onChange={(e) => setNote(e.target.value)}
        />
        {over !== null && (
          <Alert variant="warning">
            <AlertDescription>
              {t('inventory.po.overAsk', 'More arrived than was ordered. Receive it anyway?')}
              {over ? <span className="mt-1 block text-xs">{over}</span> : null}
            </AlertDescription>
          </Alert>
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
          <Button type="button" loading={saving} onClick={() => void save(over !== null)} data-testid="po-receive-save">
            {over !== null ? t('inventory.po.receiveAnyway', 'Receive anyway') : t('inventory.po.receive', 'Receive')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
