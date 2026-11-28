'use client';

/**
 * A booking's deposit (WHIT-1445, design section 39 §9.3, AP.26): the amount
 * in the booking's currency and what happened to it, read from the booking.
 *
 * Refund is a person's click by someone with `calendar:manage`, never
 * automatic, and it asks first: money that went back can't come back. When
 * the payment provider can't refund from Whity, the person refunds it in the
 * provider's own dashboard and marks it refunded here. Both are audited.
 */

import { useState } from 'react';
import { Badge } from '@whity/ui/badge';
import { Button } from '@whity/ui/button';
import { ConfirmAction } from '@whity/ui/confirm-action';
import { formatMoney } from '@whity/ui/money/currency';
import { useTranslation } from '@whity/features/i18n';
import { useDateDisplay } from '@whity/features/datetime';
import { useToast } from '@/lib/toast-context';
import { CalendarRequestError, calendarApi, type CalendarBooking } from '@/lib/calendar/api';
import { useCalendarAccess } from './use-calendar-basics';
import { useCalendarWords } from './use-calendar-words';

type State = 'due' | 'paid' | 'kept' | 'to_refund' | 'refunded' | 'applied' | 'not_paid';

function money(minor: number, currency: string): string {
  try {
    return formatMoney(minor, currency, 'en-GB');
  } catch {
    return `${currency} ${minor}`;
  }
}

export function DepositSection({ booking, timeZone, onChanged }: { booking: CalendarBooking; timeZone: string; onChanged: (booking: CalendarBooking) => void }) {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  const dates = useDateDisplay({ timeZone });
  const { addToast } = useToast();
  const { canManage } = useCalendarAccess();
  const [asking, setAsking] = useState(false);
  const [askingOutside, setAskingOutside] = useState(false);
  const [busy, setBusy] = useState(false);
  const deposit = booking.deposit ?? null;
  if (deposit === null) return null;

  const amount = money(deposit.amount_minor, deposit.currency);
  const state = deposit.state as State;
  const label: Record<State, string> = {
    due: t('deposit.due', 'Waiting for payment'),
    paid: t('deposit.paid', 'Paid'),
    kept: t('deposit.kept', 'Kept'),
    to_refund: t('deposit.toRefund', 'To refund'),
    refunded: t('deposit.refunded', 'Refunded'),
    applied: t('deposit.applied', 'Counts toward the bill'),
    not_paid: t('deposit.notPaid', 'Not paid'),
  };
  const why: Partial<Record<State, string>> = {
    kept: t('deposit.keptWhy', 'A late cancel, or they didn’t come.'),
    to_refund: t('deposit.toRefundWhy', 'Cancelled in time, or by you. Nothing is refunded until someone presses Refund.'),
    applied: t('deposit.appliedWhy', 'They came, so it counts toward their bill.'),
  };
  const refundable = canManage && (state === 'to_refund' || state === 'kept');
  const customer = booking.customer?.name ?? '';

  const refund = async (outside: boolean) => {
    setAsking(false);
    setAskingOutside(false);
    setBusy(true);
    try {
      const changed = await calendarApi.refundDeposit(booking.id, outside);
      addToast(outside ? t('deposit.markedToast', 'Marked as refunded') : t('deposit.refundedToast', '{amount} refunded to {name}', { amount, name: customer }), 'success');
      onChanged(changed);
    } catch (error) {
      if (!outside && error instanceof CalendarRequestError && error.status === 409) {
        setAskingOutside(true);
      } else {
        addToast(words.errorText(error, t('deposit.error', 'The refund didn’t go through. Nothing was refunded. Try again.')), 'error');
      }
    } finally {
      setBusy(false);
    }
  };

  return (
    <section className="space-y-2 rounded-card border border-border px-4 py-3" data-testid="booking-deposit">
      <div className="flex flex-wrap items-center gap-2">
        <h3 className="m-0 min-w-0 flex-1 text-sm font-semibold text-foreground">{t('deposit.title', 'Deposit')}</h3>
        <span className="font-mono text-sm text-foreground" dir="ltr">
          {amount}
        </span>
        <Badge variant={state === 'to_refund' ? 'warning' : state === 'refunded' || state === 'applied' || state === 'paid' ? 'success' : 'outline'} data-testid="booking-deposit-state">
          {label[state]}
        </Badge>
      </div>
      {why[state] && <p className="m-0 text-sm text-muted-foreground">{why[state]}</p>}
      {state === 'refunded' && deposit.refunded_by && (
        <p className="m-0 text-sm text-muted-foreground">
          {t('deposit.refundedBy', 'Refunded by {name} on {when}', { name: deposit.refunded_by.name, when: dates.dateTime(deposit.refunded_at ?? null) ?? '—' })}
        </p>
      )}
      {refundable && (
        <Button variant="outline" size="sm" loading={busy} onClick={() => setAsking(true)} data-testid="deposit-refund">
          {t('deposit.refund', 'Refund {amount}', { amount })}
        </Button>
      )}
      <ConfirmAction
        open={asking}
        onOpenChange={setAsking}
        title={t('deposit.refundTitle', 'Refund {amount} to {name}?', { amount, name: customer })}
        description={t('deposit.refundBody', 'The money goes back through your payment provider. This can’t be undone.')}
        confirmLabel={t('deposit.refundConfirm', 'Refund')}
        cancelLabel={t('action.cancel', 'Cancel')}
        onConfirm={() => refund(false)}
      />
      <ConfirmAction
        open={askingOutside}
        onOpenChange={setAskingOutside}
        title={t('deposit.outsideTitle', 'Refund it in your payment provider')}
        description={t('deposit.outsideBody', 'Your payment provider can’t refund from Whity. Refund {amount} in its own dashboard, then mark it refunded here.', { amount })}
        confirmLabel={t('deposit.markRefunded', 'Mark as refunded')}
        cancelLabel={t('action.cancel', 'Cancel')}
        onConfirm={() => refund(true)}
      />
    </section>
  );
}
