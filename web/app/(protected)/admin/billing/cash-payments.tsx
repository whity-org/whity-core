'use client';

import { useState } from 'react';
import { api } from '@/lib/api/client';
import type { components } from '@whity/api-client';
import { useToast } from '@/lib/toast-context';
import { useFetch } from '@/hooks/useFetch';
import { useTranslation } from '@whity/features/i18n';
import { useDateDisplay } from '@whity/features/datetime';
import { formatMoney } from '@whity/ui/money/currency';
import { Button } from '@whity/ui/button';
import { Badge } from '@whity/ui/badge';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@whity/ui/select';

type CashPayment = components['schemas']['CashPayment'];

/**
 * Cash payments for paid plugin plans (WHIT-1153), operator only. A cash
 * payment covers a workspace's plugin like a subscription for the plan's
 * period; a mistake is voided with a reason, never deleted.
 */
export function CashPayments({ addonPlanKeys }: { addonPlanKeys: string[] }) {
  const t = useTranslation('admin');
  const dates = useDateDisplay();
  const { addToast } = useToast();
  const plans = addonPlanKeys.filter((key) => key.startsWith('plugin.'));

  const [workspace, setWorkspace] = useState('');
  const [planKey, setPlanKey] = useState(plans[0] ?? '');
  const [quantity, setQuantity] = useState('1');
  const [amount, setAmount] = useState('');
  const [currency, setCurrency] = useState('JOD');
  const [receipt, setReceipt] = useState('');
  const [note, setNote] = useState('');
  const [paidOn, setPaidOn] = useState(new Date().toISOString().slice(0, 10));
  const [saving, setSaving] = useState(false);
  const [voiding, setVoiding] = useState<number | null>(null);
  const [reason, setReason] = useState('');

  const workspaceId = Number.parseInt(workspace, 10);
  const { data: payments, refetch } = useFetch(async () => {
    if (!Number.isInteger(workspaceId) || workspaceId <= 0) return [] as CashPayment[];
    const { data } = await api.GET('/api/v1/billing/cash-payments', { params: { query: { workspace: workspaceId } } });
    return data?.data ?? [];
  }, [workspaceId]);

  if (plans.length === 0) {
    return null;
  }

  const record = async () => {
    setSaving(true);
    const { error } = await api.POST('/api/v1/billing/cash-payments', {
      body: {
        workspace: workspaceId,
        planKey,
        quantity: Number.parseInt(quantity, 10) || 1,
        amount,
        currency,
        receiptNumber: receipt,
        note,
        paidOn,
      },
    });
    setSaving(false);
    if (error) {
      addToast(error.error || t('billing.cash.error', 'The payment could not be recorded.'), 'error');
      return;
    }
    addToast(t('billing.cash.recorded', 'Cash payment recorded.'), 'success');
    setReceipt('');
    setAmount('');
    setNote('');
    refetch();
  };

  const voidPayment = async (id: number) => {
    const { error } = await api.POST('/api/v1/billing/cash-payments/{id}/void', { params: { path: { id } }, body: { reason } });
    if (error) {
      addToast(error.error || t('billing.cash.voidError', 'It could not be voided.'), 'error');
      return;
    }
    setVoiding(null);
    setReason('');
    refetch();
  };

  return (
    <section className="space-y-4" aria-labelledby="cash-payments-title">
      <div className="space-y-1">
        <h2 id="cash-payments-title" className="text-base font-semibold text-foreground">
          {t('billing.cash.title', 'Cash payments')}
        </h2>
        <p className="text-sm text-muted-foreground">
          {t(
            'billing.cash.description',
            'For customers who pay in cash. It covers the workspace’s plugin like a subscription for the plan’s period, starting on the date paid or when its last cash payment ends. Before it ends, the workspace is reminded to pay again or switch to paying online.',
          )}
        </p>
      </div>

      <div className="grid gap-3 rounded-card border border-border p-4 sm:grid-cols-2">
        <label className="space-y-1 text-sm">
          <span>{t('billing.cash.workspace', 'Workspace number')}</span>
          <Input inputMode="numeric" value={workspace} onChange={(e) => setWorkspace(e.target.value)} />
        </label>
        <label className="space-y-1 text-sm">
          <span>{t('billing.cash.plan', 'Plan')}</span>
          <Select value={planKey} onValueChange={setPlanKey}>
            <SelectTrigger aria-label={t('billing.cash.plan', 'Plan')}>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {plans.map((key) => (
                <SelectItem key={key} value={key}>
                  {key}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </label>
        <label className="space-y-1 text-sm">
          <span>{t('billing.cash.quantity', 'Chairs or seats')}</span>
          <Input inputMode="numeric" value={quantity} onChange={(e) => setQuantity(e.target.value)} />
        </label>
        <label className="space-y-1 text-sm">
          <span>{t('billing.cash.amount', 'Amount paid')}</span>
          <div className="flex gap-2">
            <Input inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} placeholder="45.000" aria-label={t('billing.cash.amount', 'Amount paid')} />
            <Input className="w-24" value={currency} onChange={(e) => setCurrency(e.target.value.toUpperCase())} aria-label={t('billing.cash.currency', 'Currency')} />
          </div>
        </label>
        <label className="space-y-1 text-sm">
          <span>{t('billing.cash.receipt', 'Receipt number')}</span>
          <Input value={receipt} onChange={(e) => setReceipt(e.target.value)} />
        </label>
        <label className="space-y-1 text-sm">
          <span>{t('billing.cash.paidOn', 'Date paid')}</span>
          <Input type="date" value={paidOn} onChange={(e) => setPaidOn(e.target.value)} />
        </label>
        <label className="space-y-1 text-sm sm:col-span-2">
          <span>{t('billing.cash.note', 'Note (optional)')}</span>
          <Input value={note} onChange={(e) => setNote(e.target.value)} />
        </label>
        <div className="sm:col-span-2">
          <Button variant="outline" disabled={saving || !Number.isInteger(workspaceId) || workspaceId <= 0} onClick={() => void record()}>
            {t('billing.cash.record', 'Record cash payment')}
          </Button>
        </div>
      </div>

      {(payments ?? []).length > 0 ? (
        <ul className="divide-y divide-border rounded-card border border-border text-sm">
          {(payments ?? []).map((payment) => (
            <li key={payment.id} className="flex flex-wrap items-center justify-between gap-3 p-3">
              <div className="space-y-1">
                <p className="font-medium">
                  {t('billing.cash.row', 'Receipt {number} · {plan} × {quantity}', {
                    number: payment.receipt_number,
                    plan: payment.plan_key,
                    quantity: payment.quantity,
                  })}
                </p>
                <p className="text-muted-foreground">
                  {t('billing.cash.covers', 'Paid {paid} · covers until {until}', {
                    paid: dates.date(payment.paid_on) ?? '—',
                    until: dates.date(payment.period_end) ?? '—',
                  })}
                </p>
                {payment.voided_at ? (
                  <p className="text-muted-foreground">{t('billing.cash.voidedBecause', 'Voided: {reason}', { reason: payment.void_reason ?? '' })}</p>
                ) : null}
              </div>
              <div className="flex items-center gap-2">
                <span className="font-medium">{formatMoney(payment.amount_minor, payment.currency)}</span>
                {payment.voided_at ? (
                  <Badge variant="secondary">{t('billing.cash.voided', 'Voided')}</Badge>
                ) : voiding === payment.id ? (
                  <>
                    <Input
                      className="w-56"
                      value={reason}
                      onChange={(e) => setReason(e.target.value)}
                      placeholder={t('billing.cash.reason', 'Why is it voided?')}
                      aria-label={t('billing.cash.reason', 'Why is it voided?')}
                    />
                    <Button variant="outline" size="sm" disabled={reason.trim() === ''} onClick={() => void voidPayment(payment.id)}>
                      {t('billing.cash.confirmVoid', 'Void')}
                    </Button>
                  </>
                ) : (
                  <Button variant="outline" size="sm" onClick={() => setVoiding(payment.id)}>
                    {t('billing.cash.void', 'Void…')}
                  </Button>
                )}
              </div>
            </li>
          ))}
        </ul>
      ) : null}
    </section>
  );
}
