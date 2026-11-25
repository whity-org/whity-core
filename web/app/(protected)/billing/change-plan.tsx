'use client';

import { useRef, useState } from 'react';
import { api } from '@/lib/api/client';
import { useToast } from '@/lib/toast-context';
import { useOptionalAuth } from '@/lib/auth-context';
import { useFetch } from '@/hooks/useFetch';
import { useCapabilities } from '@/hooks/useCapabilities';
import { BILLING_PAY } from '@/lib/capabilities';
import { useTranslation } from '@whity/features/i18n';
import { Button } from '@whity/ui/button';
import { ConfirmAction } from '@whity/ui/confirm-action';
import { offersYearly, localizePlanCards, planCards, type BillingPeriod, type EntitlementValue } from '@/lib/billing/plan-cards';
import { PeriodSwitch, PlanCardGrid } from './plan-card-grid';

interface PlanOption {
  plan_key: string;
  name: string;
  description: string | null;
  is_addon: boolean;
  is_current: boolean;
  unit_amount: number;
  currency: string;
  billing_period: string;
  entitlements?: Record<string, EntitlementValue>;
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
  const { addToast } = useToast();
  const { hasPermission } = useCapabilities();
  const auth = useOptionalAuth();
  const t = useTranslation('admin');
  const canPay = hasPermission(BILLING_PAY);
  const [target, setTarget] = useState<PlanOption | null>(null);
  // One key per opened dialog: a retry of this change is safe, a later change is new.
  const idempotencyKey = useRef('');
  const [period, setPeriod] = useState<BillingPeriod>('month');

  const { data } = useFetch(async () => {
    const { data: body, response: res } = await api.GET('/api/v1/billing/plans');
    if (!res.ok) return [] as PlanOption[];
    return ((body as { data?: PlanOption[] } | undefined)?.data ?? []) as PlanOption[];
  }, []);

  const tiers = (data ?? []).filter((plan) => !plan.is_addon);
  const others = tiers.filter((plan) => !plan.is_current);
  const current = tiers.find((plan) => plan.is_current);
  // The workspace's own plan stays in the row, marked, so the others compare against it.
  const cards = localizePlanCards(planCards(tiers, offersYearly(tiers) ? period : 'month'), t);

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
    const { data: body, response: res } = await api.POST('/api/v1/billing/change-plan', {
      body: {
        plan_key: target.plan_key,
        billing_period: target.billing_period,
        idempotency_key: idempotencyKey.current,
      } as never,
    });
    if (!res.ok) {
      addToast(t('billing.changePlan.failed', 'The plan was not changed. Please try again.'), 'error');
      return;
    }
    const takesEffect = (body as { data?: { takes_effect?: string } } | undefined)?.data?.takes_effect;
    addToast(
      takesEffect === 'now'
        ? t('billing.changePlan.doneNow', 'You’re now on {plan}.', { plan: target.name })
        : t('billing.changePlan.doneRenewal', 'You’ll move to {plan} at your next renewal.', { plan: target.name }),
      'success'
    );
    onChanged();
    // A plan that starts now changes limits and banners: refresh them here and in every tab.
    if (takesEffect === 'now') void auth?.sessionChanged();
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
    <section className="space-y-3 pt-2" data-testid="change-plan">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <h3 className="text-sm font-medium">{t('billing.changePlan.title', 'Change plan')}</h3>
        {offersYearly(tiers) && <PeriodSwitch period={period} onChange={setPeriod} />}
      </div>
      <PlanCardGrid
        cards={cards}
        testId="change-plan-cards"
        action={(card) =>
          card.is_current ? null : canPay ? (
            <Button
              variant="outline"
              className="w-full"
              onClick={() => open(card.price as PlanOption)}
              data-testid={`change-plan-${card.plan_key}`}
            >
              {t('billing.changePlan.action', 'Move to {plan}', { plan: card.name })}
            </Button>
          ) : null
        }
      />
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
