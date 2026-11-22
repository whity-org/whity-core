'use client';

/**
 * Receiving against a purchase order (WHIT-1234, `TplReceiving`, register
 * IV.12): each open line starts at what is still to come; change it to what
 * arrived, or leave it empty when nothing did. More than ordered asks first,
 * and is received only when the person says yes. A note keeps the delivery
 * note's number.
 *
 * An item that keeps batches asks for the batch code and its expiry; one with
 * serial numbers takes them one per line, and receives one piece for each
 * (WHIT-1235).
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
import { useFetch } from '@/hooks/useFetch';
import { UserText } from '@/components/ui/user-text';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Button } from '@whity/ui/button';
import { Textarea } from '@whity/ui/textarea';
import { useLanguageCode, useTranslation } from '@whity/features/i18n';
import {
  InventoryRequestError,
  inventoryApi,
  inventoryErrorText,
  nameIn,
  quantityText,
  type InventoryPurchaseOrder,
  type ReceiveLine,
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
  const [batches, setBatches] = useState<Record<number, { code: string; expires: string; serials: string }>>({});
  const [note, setNote] = useState('');
  // Which items keep batches or serial numbers, and whether they go off.
  const itemIds = [...new Set(lines.map((l) => l.item_id))];
  const tracked = useFetch(
    async () => Object.fromEntries((await Promise.all(itemIds.map((id) => inventoryApi.item(id)))).map((i) => [i.id, { tracking: i.tracking, expires: i.expires }])),
    [itemIds.join(',')]
  );
  const trackingOf = (itemId: number) => tracked.data?.[itemId]?.tracking ?? 'none';
  const batch = (lineId: number) => batches[lineId] ?? { code: '', expires: '', serials: '' };
  const setBatch = (lineId: number, change: Partial<{ code: string; expires: string; serials: string }>) =>
    setBatches((now) => ({ ...now, [lineId]: { ...(now[lineId] ?? { code: '', expires: '', serials: '' }), ...change } }));
  const [saving, setSaving] = useState(false);
  const [over, setOver] = useState<string | null>(null);
  const [problem, setProblem] = useState<string | null>(null);

  const save = async (allowOver: boolean) => {
    const body: ReceiveLine[] = [];
    for (const l of lines) {
      const b = batch(l.id);
      if (trackingOf(l.item_id) === 'serial') {
        // One piece for each serial number.
        for (const serial of b.serials.split(/[\n,،]/).map((x) => x.trim()).filter((x) => x !== '')) {
          body.push({ line_id: l.id, quantity: '1', lot_code: serial, ...(b.expires !== '' ? { expires_on: b.expires } : {}) });
        }
        continue;
      }
      const quantity = (amounts[l.id] ?? '').trim();
      if (quantity === '' || Number(quantity) <= 0) continue;
      body.push({
        line_id: l.id,
        quantity,
        ...(trackingOf(l.item_id) === 'lot' && b.code.trim() !== '' ? { lot_code: b.code.trim() } : {}),
        ...(trackingOf(l.item_id) === 'lot' && b.expires !== '' ? { expires_on: b.expires } : {}),
      });
    }
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
            <UserText>{order.contact_name}</UserText>.{' '}
            {t('inventory.po.receiveHint', 'Each line starts at what is still to come. Change it to what arrived, or clear it if nothing did.')}
          </DialogDescription>
        </DialogHeader>
        <ul className="divide-y divide-border rounded-md border border-border">
          {lines.map((line) => (
            <li key={line.id} className="space-y-2 px-3 py-2">
              <div className="flex items-center gap-3">
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
                  disabled={trackingOf(line.item_id) === 'serial'}
                />
              </div>
              </div>
              {trackingOf(line.item_id) === 'lot' && (
                <div className="grid gap-2 sm:grid-cols-2" data-testid={`po-receive-batch-${line.id}`}>
                  <Input
                    id={`po-receive-batch-${line.id}`}
                    dir="ltr"
                    className="font-mono"
                    label={t('inventory.field.batch', 'Batch code')}
                    value={batch(line.id).code}
                    onChange={(e) => setBatch(line.id, { code: e.target.value })}
                  />
                  {tracked.data?.[line.item_id]?.expires && (
                    <Input
                      id={`po-receive-expiry-${line.id}`}
                      type="date"
                      dir="ltr"
                      label={t('inventory.field.expiresOn', 'Expires on')}
                      value={batch(line.id).expires}
                      onChange={(e) => setBatch(line.id, { expires: e.target.value })}
                    />
                  )}
                </div>
              )}
              {trackingOf(line.item_id) === 'serial' && (
                <div className="space-y-1.5" data-testid={`po-receive-serials-${line.id}`}>
                  <label className="text-sm font-medium" htmlFor={`po-receive-serials-${line.id}`}>
                    {t('inventory.receive.serials', 'Serial numbers, one per line')}
                  </label>
                  <Textarea
                    id={`po-receive-serials-${line.id}`}
                    dir="ltr"
                    rows={3}
                    className="font-mono"
                    value={batch(line.id).serials}
                    onChange={(e) => setBatch(line.id, { serials: e.target.value })}
                  />
                </div>
              )}
            </li>
          ))}
        </ul>
        <Input
          dir="auto"
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
