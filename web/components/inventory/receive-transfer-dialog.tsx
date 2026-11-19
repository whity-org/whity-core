'use client';

/**
 * Receiving a transfer on the way (WHIT-1233, register IV.16): each line
 * arrives in full unless the person says less; what didn't arrive is written
 * off as lost on the way. Cancelling sends it back to where it left.
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
import { useTranslation } from '@whity/features/i18n';
import { inventoryApi, inventoryErrorText, quantityText, type InventoryOperation } from '@/lib/inventory/api';

export function ReceiveTransferDialog({
  transfer,
  route,
  onOpenChange,
  onDone,
}: {
  transfer: InventoryOperation;
  /** "Abdoun → Sweifieh", in the reader's language. */
  route: string;
  onOpenChange: (open: boolean) => void;
  onDone: (transfer: InventoryOperation) => void;
}) {
  const t = useTranslation('admin');
  const lines = transfer.lines ?? [];
  const [received, setReceived] = useState<Record<number, string>>(
    Object.fromEntries(lines.map((l) => [l.id, quantityText(l.quantity ?? '0')]))
  );
  const [working, setWorking] = useState<'receive' | 'cancel' | null>(null);
  const [problem, setProblem] = useState<string | null>(null);

  const act = async (what: 'receive' | 'cancel') => {
    setWorking(what);
    setProblem(null);
    try {
      onDone(
        what === 'cancel'
          ? await inventoryApi.cancelTransfer(transfer.id)
          : await inventoryApi.receiveTransfer(
              transfer.id,
              lines
                .filter((l) => Number(received[l.id] ?? '') !== Number(l.quantity))
                .map((l) => ({ line_id: l.id, received: (received[l.id] ?? '').trim() || '0' }))
            )
      );
    } catch (error) {
      setProblem(inventoryErrorText(error));
    } finally {
      setWorking(null);
    }
  };

  return (
    <Dialog open onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-xl" data-testid="receive-form">
        <DialogHeader>
          <DialogTitle>
            {t('inventory.receive.title', 'Receive {number}', { number: transfer.number })}
          </DialogTitle>
          <DialogDescription>
            {route}. {t('inventory.receive.hint', 'Change an amount only if less arrived; the rest is written off as lost on the way.')}
          </DialogDescription>
        </DialogHeader>
        <ul className="divide-y divide-border rounded-md border border-border">
          {lines.map((line) => (
            <li key={line.id} className="flex items-center gap-3 px-3 py-2">
              <div className="min-w-0 flex-1">
                <div className="truncate text-sm font-medium">{line.item_name}</div>
                <div className="text-xs text-muted-foreground">
                  {t('inventory.receive.sent', 'Sent')}{' '}
                  <bdi dir="ltr" className="font-mono">
                    {quantityText(line.quantity ?? '0')} {line.unit}
                  </bdi>
                </div>
              </div>
              <div className="w-32">
                <Input
                  id={`receive-${line.id}`}
                  dir="ltr"
                  inputMode="decimal"
                  className="font-mono"
                  aria-label={t('inventory.receive.arrived', 'Arrived, in {unit}', { unit: line.unit })}
                  value={received[line.id] ?? ''}
                  onChange={(e) => setReceived({ ...received, [line.id]: e.target.value })}
                />
              </div>
            </li>
          ))}
        </ul>
        {problem && (
          <Alert variant="destructive">
            <AlertDescription>{problem}</AlertDescription>
          </Alert>
        )}
        <DialogFooter className="sm:justify-between">
          <Button type="button" variant="outline" onClick={() => void act('cancel')} loading={working === 'cancel'} disabled={working !== null}>
            {t('inventory.receive.cancel', 'Send it back')}
          </Button>
          <Button type="button" onClick={() => void act('receive')} loading={working === 'receive'} disabled={working !== null} data-testid="receive-save">
            {t('inventory.receive.save', 'Received')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
