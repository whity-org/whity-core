'use client';

import { useState } from 'react';
import { api } from '@/lib/api/client';
import { useToast } from '@/lib/toast-context';
import { useFetch } from '@/hooks/useFetch';
import { useCapabilities } from '@/hooks/useCapabilities';
import { BILLING_PAY } from '@/lib/capabilities';
import { Button } from '@whity/ui/button';
import { useTranslation } from '@whity/features/i18n';
import { navigateExternal } from '@/lib/external-navigate';
import { localizePlanCards, offersYearly, planCards, type BillingPeriod, type EntitlementValue } from '@/lib/billing/plan-cards';
import { PeriodSwitch, PlanCardGrid } from './plan-card-grid';

/**
 * WHAT THIS TENANT CAN BUY, AND THE BUTTON THAT BUYS IT.
 *
 * A workspace behind the payment wall gets 402 on every call, so it renders a
 * dashboard with nothing in it. Sending the reader here is only half an answer:
 * the other half is that when they arrive there has to be something to DO.
 *
 * Every other plan endpoint is gated on `plans:manage`, an operator permission a
 * paying customer never holds — so until `/api/v1/billing/plans` existed, a
 * tenant could be told to pay and had no way to discover what for.
 *
 * AMOUNTS ARE FORMATTED FROM MINOR UNITS, never divided by 100. The Jordanian
 * dinar has three decimal places: 15000 fils is 15.000 JOD, and a screen that
 * divides by 100 shows every customer an amount ten times too large.
 * `formatMoney` knows the exponent and takes the reader's locale.
 *
 * RTL comes from logical properties — `ms-`/`me-`/`text-start` — so the layout
 * mirrors itself with no branch to keep in step.
 */

interface PurchasablePlan {
  plan_key: string;
  is_addon: boolean;
  name: string;
  description: string | null;
  unit_amount: number;
  currency: string;
  billing_period: string;
  is_per_seat: boolean;
  is_per_device: boolean;
  entitlements?: Record<string, EntitlementValue>;
}

/**
 * Which half of the catalogue to show.
 *
 * TIERS AND ADD-ONS HAVE OPPOSITE PRECONDITIONS: a tier is refused to a tenant
 * that already has one, an add-on to a tenant that does not. Listing them
 * together would show every tenant at least one button that answers 409, so the
 * caller says which half applies and the server enforces it regardless.
 */
export type PlanKind = 'tier' | 'addon';

export function AvailablePlans({ kind }: { kind: PlanKind }) {
  const { addToast } = useToast();
  const { hasPermission } = useCapabilities();
  const t = useTranslation('admin');
  const [starting, setStarting] = useState<string | null>(null);
  const [period, setPeriod] = useState<BillingPeriod>('month');

  const canPay = hasPermission(BILLING_PAY);

  const { data, loading } = useFetch(async () => {
    const { data: body, response: res } = await api.GET('/api/v1/billing/plans');
    if (!res.ok) {
      // Not shouted about. This block is an OFFER, and a deployment that sells
      // nothing answers here too; a red error where a price list would go would
      // be alarming and wrong on every self-hosted install.
      return [] as PurchasablePlan[];
    }
    return ((body as { data?: PurchasablePlan[] } | undefined)?.data ?? []) as PurchasablePlan[];
  }, []);

  const wanted = kind === 'addon';
  const plans = (data ?? []).filter((p) => p.is_addon === wanted);

  // Nothing to sell, or still finding out. Rendering an empty "choose a plan"
  // heading would be worse than rendering nothing at all.
  if (loading || plans.length === 0) {
    return null;
  }

  const subscribe = async (planKey: string, billingPeriod: string) => {
    setStarting(planKey);
    try {
      const { data: body, response: res } = await api.POST('/api/v1/billing/checkout', {
        body: { plan_key: planKey, billing_period: billingPeriod } as never,
      });

      if (!res.ok) {
        addToast(
          t('billing.plans.error.start', 'Could not start checkout. Please try again.'),
          'error'
        );
        return;
      }

      const url = (body as { data?: { url?: string } } | undefined)?.data?.url;
      if (url === undefined || url === '') {
        addToast(
          t('billing.plans.error.start', 'Could not start checkout. Please try again.'),
          'error'
        );
        return;
      }

      // Leaves the app entirely: what is behind this URL is the billing
      // service's hosted page, and whether it is a card form or transfer
      // instructions is deliberately not knowable here.
      navigateExternal(url);
    } finally {
      setStarting(null);
    }
  };

  const cards = localizePlanCards(planCards(plans, offersYearly(plans) ? period : 'month'), t);
  // A workspace already on a plan without a paid subscription (Free) is not
  // locked, and choosing the plan it is on does nothing (WHIT-1398). It is
  // offered an upgrade instead, and its own card has no button.
  const current = kind === 'tier' ? cards.find((card) => card.is_current) : undefined;

  return (
    <section className="space-y-4" data-testid="available-plans">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h2 className="text-base font-medium">
            {kind === 'addon'
              ? t('billing.plans.addons.title', 'Add-ons')
              : current !== undefined
                ? t('billing.plans.upgradeTitle', 'Upgrade your workspace')
                : t('billing.plans.title', 'Activate your workspace')}
          </h2>
          <p className="text-sm text-muted-foreground">
            {kind === 'addon'
              ? t(
                  'billing.plans.addons.description',
                  'Bought alongside your plan. Devices are billed for each unit in service.'
                )
              : current !== undefined
                ? t(
                    'billing.plans.upgradeDescription',
                    'You are on {plan}. Choose a plan for more staff, customers and features.',
                    { plan: current.name }
                  )
                : t(
                    'billing.plans.description',
                    'Choose a plan to activate this workspace. Everything else stays locked until then.'
                  )}
          </p>
        </div>
        {offersYearly(plans) && <PeriodSwitch period={period} onChange={setPeriod} />}
      </div>

      <PlanCardGrid
        cards={cards}
        action={(card) => card.is_current ? null : (
          <Button
            variant="outline"
            className="w-full"
            onClick={() => void subscribe(card.plan_key, card.price.billing_period)}
            disabled={!canPay || starting !== null}
            data-testid={`subscribe-${card.plan_key}`}
          >
            {starting === card.plan_key
              ? t('billing.plans.starting', 'Opening checkout…')
              : t('billing.plans.choose', 'Choose {plan}', { plan: card.name })}
          </Button>
        )}
      />

      {/* Said plainly rather than by a disabled button with no explanation:
          someone without the permission needs to know who to ask. */}
      {!canPay && (
        <p className="text-sm text-muted-foreground">
          {t(
            'billing.plans.needsPermission',
            'You do not have permission to pay. Ask a workspace administrator.'
          )}
        </p>
      )}
    </section>
  );
}
