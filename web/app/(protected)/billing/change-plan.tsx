'use client';

import { useRef, useState } from 'react';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { useFetch } from '@/hooks/useFetch';
import { useCapabilities } from '@/hooks/useCapabilities';
import { BILLING_PAY } from '@/lib/capabilities';
import { useTranslation } from '@whity/features/i18n';
import { Button } from '@whity/ui/button';
import { ConfirmAction } from '@whity/ui/confirm-action';
import { formatMoney } from '@whity/ui/money/currency';

interface PlanOption {
  plan_key: string;
  name: string;
  description: string | null;
  is_addon: boolean;
  is_current: boolean;
  unit_amount: number;
  currency: string;
  billing_period: string;
}

/**
 * Change plan (TplBilling): the plans to compare, and a move that asks first.
 *
 * It moves the subscription the workspace already holds, so nobody ends up paying
 * for two. The amount charged is the billing service's, so the dialog says when
 * the change takes effect and quotes no figure the invoice could contradict.
 *
 * A deployment with nothing to move to falls back to the support line it replaced,
 * so a workspace is never left with neither.
 */
export function ChangePlan({ onChanged }: { onChanged: () => void }) {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const { hasPermission } = useCapabilities();
  const t = useTranslation('admin');
  const canPay = hasPermission(BILLING_PAY);
  const [target, setTarget] = useState<PlanOption | null>(null);
  // One key per opened dialog: a retry of this change is safe, a later change is new.
  const idempotencyKey = useRef('');

  const { data } = useFetch(async () => {
    const res = await apiClient('/api/v1/billing/plans');
    if (!res.ok) return [] as PlanOption[];
    return ((await res.json()).data ?? []) as PlanOption[];
  }, [apiClient]);

  const tiers = (data ?? []).filter((plan) => !plan.is_addon);
  const others = tiers.filter((plan) => !plan.is_current);
  const current = tiers.find((plan) => plan.is_current);

  if (others.length === 0) {
    return (
      <p className="text-xs text-muted-foreground">
        {t('billing.subscription.changeHint', 'To change plan, contact support — your current plan stays active until then.')}
      </p>
    );
  }

  const open = (plan: PlanOption) => {
    idempotencyKey.current = crypto.randomUUID();
    setTarget(plan);
  };

  const confirm = async () => {
    if (target === null) return;
    const res = await apiClient('/api/v1/billing/change-plan', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        plan_key: target.plan_key,
        billing_period: target.billing_period,
        idempotency_key: idempotencyKey.current,
      }),
    });
    if (!res.ok) {
      addToast(t('billing.changePlan.failed', 'The plan was not changed. Please try again.'), 'error');
      return;
    }
    const takesEffect = (await res.json()).data?.takes_effect as string | undefined;
    addToast(
      takesEffect === 'now'
        ? t('billing.changePlan.doneNow', 'You’re now on {plan}.', { plan: target.name })
        : t('billing.changePlan.doneRenewal', 'You’ll move to {plan} at your next renewal.', { plan: target.name }),
      'success'
    );
    onChanged();
  };

  // A dearer plan is charged today and a cheaper one waits, as the server decides.
  // Without a price to compare, both are told, which stays true either way.
  const consequence = (plan: PlanOption): string => {
    if (current && plan.billing_period === current.billing_period) {
      return plan.unit_amount > current.unit_amount
        ? t('billing.changePlan.upgrade', 'This starts now, and you’re charged today for the rest of this period.')
        : t('billing.changePlan.downgrade', 'This starts at your next renewal, with nothing charged or refunded today.');
    }
    return t(
      'billing.changePlan.either',
      'A dearer plan starts now and is charged today for the rest of this period. A cheaper one starts at your next renewal, with nothing refunded.'
    );
  };

  return (
    <section className="space-y-2 pt-2" data-testid="change-plan">
      <h3 className="text-sm font-medium">{t('billing.changePlan.title', 'Change plan')}</h3>
      <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {others.map((plan) => (
          <li key={`${plan.plan_key}-${plan.billing_period}`} className="grid gap-2 rounded-card border border-border bg-card p-4">
            <p className="font-medium">{plan.name}</p>
            {plan.description && <p className="text-sm text-muted-foreground">{plan.description}</p>}
            <p className="text-sm">
              <bdi>{formatMoney(plan.unit_amount, plan.currency)}</bdi>{' '}
              {plan.billing_period === 'year'
                ? t('billing.plans.perYear', 'per year')
                : t('billing.plans.perMonth', 'per month')}
            </p>
            {canPay ? (
              <Button variant="outline" onClick={() => open(plan)} data-testid={`change-plan-${plan.plan_key}`}>
                {t('billing.changePlan.action', 'Move to {plan}', { plan: plan.name })}
              </Button>
            ) : null}
          </li>
        ))}
      </ul>
      {!canPay && (
        <p className="text-xs text-muted-foreground">
          {t('billing.changePlan.noPermission', 'Only someone who can pay for this workspace can change its plan.')}
        </p>
      )}
      <ConfirmAction
        open={target !== null}
        onOpenChange={(next) => {
          if (!next) setTarget(null);
        }}
        title={t('billing.changePlan.confirmTitle', 'Move to {plan}?', { plan: target?.name ?? '' })}
        description={target ? consequence(target) : ''}
        confirmLabel={t('billing.changePlan.confirm', 'Change plan')}
        cancelLabel={t('billing.changePlan.cancel', 'Keep my plan')}
        variant="default"
        onConfirm={confirm}
      />
    </section>
  );
}
