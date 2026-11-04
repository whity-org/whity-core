'use client';

import { Affiliates } from './affiliates';
import { TierFeatures } from './tier-features';
import { TierLifecycle } from './tier-lifecycle';
import { useState } from 'react';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { useFetch } from '@/hooks/useFetch';
import { useCapabilities } from '@/hooks/useCapabilities';
import { PLANS_MANAGE } from '@/lib/capabilities';
import { AdminHeader } from '@/components/admin/admin-header';
import { Button } from '@whity/ui/button';
import { Input } from '@/components/ui/input';
import { MoneyInput } from '@whity/ui/money/money-input';
import { parseMoney, toDecimalString } from '@whity/ui/money/currency';
import { Badge } from '@whity/ui/badge';
import { EmptyState } from '@whity/ui/empty-state';
import { AccessDeniedPanel, AreaError } from '@whity/ui/page-states';
import { TechnicalDetails } from '@whity/ui/display-preferences';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@whity/ui/select';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { IconPlus } from '@tabler/icons-react';
import { useTranslation } from '@whity/features/i18n';

/**
 * BILLING: the plan catalogue and what each plan costs.
 *
 * The plan API has existed since migration 055 and had no screen at all, so
 * every tier this instance sells was invisible unless somebody read the
 * database. Prices had neither.
 *
 * AMOUNTS ARE ENTERED AND SHOWN IN MINOR UNITS — 4900, not 49.00 — and the
 * field says so. That is deliberate rather than lazy: converting at this
 * boundary means deciding how many decimal places the currency has, and KWD has
 * three where SAR has two. A field that accepted "49.00" would have to know
 * which, get it wrong for one of them, and be wrong by a factor of ten in a
 * number nobody re-reads. The operator entering a price knows the denomination;
 * the form does not.
 *
 * RETIRED PRICES STAY VISIBLE, greyed. A list of only live prices cannot explain
 * a charge somebody is querying, and "what were we charging in March" is a
 * question this screen exists to answer.
 */

interface Plan {
  id: number;
  plan_key: string;
  name: string;
  description: string | null;
  is_active: boolean;
}

interface PlanPrice {
  id: number;
  plan_id: number;
  currency: string;
  unit_amount: number;
  billing_period: string;
  is_per_seat: boolean;
  is_active: boolean;
}

/**
 * The billing periods, and the labels for them.
 *
 * The select builds its key by interpolation, which no static scanner can read,
 * so the three reachable keys are declared here and the extractor takes them
 * from this block. There are exactly three and they are a closed set — nothing
 * about them is genuinely dynamic, so declaring beats suppressing.
 *
 * @i18n-keys admin
 *   billing.price.period.month = Month
 *   billing.price.period.year = Year
 *   billing.price.period.once = One-off
 */
const PERIODS = ['month', 'year', 'once'] as const;

/** The plans route answered 403: not a failed load, and not an empty catalogue. */
const FORBIDDEN = 'forbidden';

export default function BillingPage() {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const { hasPermission } = useCapabilities();
  const t = useTranslation('admin');
  const canManage = hasPermission(PLANS_MANAGE);

  const { data, loading, error, refetch } = useFetch(async () => {
    const plansRes = await apiClient('/api/v1/plans');
    if (plansRes.status === 403) {
      throw new Error(FORBIDDEN);
    }
    if (!plansRes.ok) {
      throw new Error(t('billing.error.load', 'Failed to load the plan catalogue'));
    }
    const plans = ((await plansRes.json()).data ?? []) as Plan[];

    // Prices per plan, in parallel. Each is allowed to fail on its own — one
    // unreadable plan must not blank the whole screen, and the row says so
    // rather than showing an empty price list that looks like "no prices".
    const priced = await Promise.all(
      plans.map(async (plan) => {
        try {
          const res = await apiClient(`/api/v1/plans/${plan.id}/prices`);
          if (!res.ok) return { plan, prices: null };
          return { plan, prices: ((await res.json()).data ?? []) as PlanPrice[] };
        } catch {
          return { plan, prices: null };
        }
      })
    );

    return priced;
  }, [apiClient]);

  const [pricing, setPricing] = useState<Plan | null>(null);
  const [creatingPlan, setCreatingPlan] = useState(false);

  return (
    <div className="space-y-4">
      <AdminHeader
        title={t('billing.title', 'Plans & pricing')}
        description={t(
          'billing.description',
          'The tiers this instance sells and what each one costs. Managed by the system tenant.'
        )}
        action={
          canManage && error !== FORBIDDEN ? (
            <Button className="gap-1" data-testid="new-plan" onClick={() => setCreatingPlan(true)}>
              <IconPlus size={16} />
              {t('billing.plan.new', 'New plan')}
            </Button>
          ) : undefined
        }
      />

      {loading && <p className="text-sm text-muted-foreground">{t('common.loading', 'Loading…')}</p>}
      {error === FORBIDDEN && (
        <AccessDeniedPanel
          title={t('billing.denied.title', 'You don’t have access to plans and pricing')}
          description={t(
            'billing.denied.description',
            'Plans are managed by the people who run this Whity installation.'
          )}
        />
      )}
      {error && error !== FORBIDDEN && (
        <AreaError
          message={error}
          retryLabel={t('billing.error.retry', 'Try again')}
          onRetry={refetch}
        />
      )}

      {!loading && !error && (data ?? []).length === 0 && (
        <EmptyState
          title={t('billing.empty.title', 'No plans yet')}
          description={t(
            'billing.empty.description',
            'A plan is a tier — what a tenant gets. Create one, then give it a price.'
          )}
          action={
            canManage ? (
              <Button onClick={() => setCreatingPlan(true)}>{t('billing.plan.new', 'New plan')}</Button>
            ) : undefined
          }
        />
      )}

      <div className="space-y-3">
        {(data ?? []).map(({ plan, prices }) => (
          <section key={plan.id} className="rounded-lg border border-border p-4" data-testid={`plan-${plan.id}`}>
            <div className="flex items-start justify-between gap-4">
              <div>
                <h2 className="flex items-center gap-2 font-medium">
                  {plan.name}
                  <code className="text-xs text-muted-foreground">{plan.plan_key}</code>
                  {!plan.is_active && (
                    <Badge variant="secondary">{t('billing.plan.inactive', 'Inactive')}</Badge>
                  )}
                </h2>
                {plan.description && (
                  <p className="text-sm text-muted-foreground">{plan.description}</p>
                )}
              </div>
              {canManage && (
                <Button
                  size="sm"
                  variant="outline"
                  className="gap-1"
                  data-testid={`add-price-${plan.id}`}
                  onClick={() => setPricing(plan)}
                >
                  <IconPlus size={14} />
                  {t('billing.price.add', 'Add a price')}
                </Button>
              )}
            </div>

            {/* WHAT THIS TIER STILL HOLDS, and what may be done about it.
                Shown per tier rather than behind a delete attempt: somebody
                deciding whether to retire a tier needs to know it has
                subscribers before they act, not after being refused. */}
            {canManage && (
              <TierLifecycle
                plan={plan}
                allPlans={(data ?? []).map((entry) => ({
                  id: entry.plan.id,
                  name: entry.plan.name,
                  is_active: entry.plan.is_active,
                }))}
                onChanged={refetch}
              />
            )}

            {/* A plan whose prices could not be read says so. An empty list here
                would be indistinguishable from "this plan has no prices", which
                is a different and much less alarming thing. */}
            {prices === null ? (
              <p className="mt-3 text-sm text-destructive" data-testid={`prices-unreadable-${plan.id}`}>
                {t('billing.price.unreadable', 'This plan’s prices could not be read.')}
              </p>
            ) : prices.length === 0 ? (
              <p className="mt-3 text-sm text-muted-foreground" data-testid={`prices-empty-${plan.id}`}>
                {t('billing.price.none', 'No price yet — this plan cannot be sold.')}
              </p>
            ) : (
              <ul className="mt-3 space-y-1">
                {prices.map((price) => (
                  <li
                    key={price.id}
                    className={`flex items-center gap-3 text-sm ${price.is_active ? '' : 'text-muted-foreground'}`}
                    data-testid={`price-${price.id}`}
                  >
                    <span className="font-medium" dir="ltr">
                      {price.currency} {toDecimalString(price.unit_amount, price.currency)}
                    </span>
                    <span className="text-muted-foreground">
                      {t('billing.price.per', 'per {period}', { period: price.billing_period })}
                    </span>
                    {price.is_per_seat && (
                      <Badge variant="secondary">{t('billing.price.perSeat', 'per seat')}</Badge>
                    )}
                    {!price.is_active && (
                      <Badge variant="outline">{t('billing.price.retired', 'Retired')}</Badge>
                    )}
                    {canManage && price.is_active && (
                      <Button
                        variant="ghost"
                        size="sm"
                        data-testid={`retire-price-${price.id}`}
                        onClick={async () => {
                          const res = await apiClient(
                            `/api/v1/plans/${plan.id}/prices/${price.id}`,
                            { method: 'DELETE' }
                          );
                          if (!res.ok) {
                            const body = (await res.json().catch(() => null)) as { error?: string } | null;
                            addToast(
                              body?.error ?? t('billing.price.retireFailed', 'Could not retire this price.'),
                              'error'
                            );
                            return;
                          }
                          // Tier 1: it happens at once and can be undone. There is no
                          // un-retire route, so Undo adds the same price again on the
                          // same terms (a retired price blocks nothing).
                          addToast(
                            t('billing.price.retired.done', 'Price retired.'),
                            'success',
                            10000,
                            {
                              label: t('billing.price.retired.undo', 'Undo'),
                              onClick: async () => {
                                const back = await apiClient(`/api/v1/plans/${plan.id}/prices`, {
                                  method: 'POST',
                                  body: JSON.stringify({
                                    currency: price.currency,
                                    unit_amount: price.unit_amount,
                                    billing_period: price.billing_period,
                                    is_per_seat: price.is_per_seat,
                                  }),
                                });
                                if (!back.ok) {
                                  addToast(t('billing.price.undoFailed', 'Could not bring this price back.'), 'error');
                                  return;
                                }
                                refetch();
                              },
                            }
                          );
                          refetch();
                        }}
                      >
                        {t('billing.price.retire', 'Retire')}
                      </Button>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </section>
        ))}
      </div>

      {/* WHAT EACH TIER INCLUDES, on the same screen as what each tier COSTS.
          They are one decision — "is there a reason to move up from Plus" is
          answered by reading a price against a feature list — and splitting
          them across two screens is how you end up selling three tiers that
          differ only in the number on the invoice. */}
      {!loading && !error && canManage && <TierFeatures />}

      {/*
        WHO SENDS US CUSTOMERS. On this page rather than its own because it is
        the same gate (`plans:manage` on the system tenant) and the same
        question: what this instance sells, and what it costs to sell it.
      */}
      {!loading && !error && canManage && <Affiliates />}

      {creatingPlan && (
        <CreatePlanDialog
          onClose={() => setCreatingPlan(false)}
          onSaved={() => {
            setCreatingPlan(false);
            refetch();
          }}
        />
      )}

      {pricing && (
        <AddPriceDialog
          plan={pricing}
          onClose={() => setPricing(null)}
          onSaved={() => {
            setPricing(null);
            refetch();
          }}
        />
      )}
    </div>
  );
}

/**
 * Adding a price.
 *
 * The amount field takes MINOR UNITS and says so in its own label rather than in
 * a tooltip somebody has to hover: it is the single most misreadable field on
 * the screen, and getting it wrong is a factor-of-a-hundred error that produces
 * a perfectly plausible-looking price.
 */
function AddPriceDialog({
  plan,
  onClose,
  onSaved,
}: {
  plan: Plan;
  onClose: () => void;
  onSaved: () => void;
}) {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const t = useTranslation('admin');

  const [currency, setCurrency] = useState('JOD');
  const [amount, setAmount] = useState('');
  const [period, setPeriod] = useState<string>('month');
  const [perSeat, setPerSeat] = useState(false);
  const [saving, setSaving] = useState(false);

  // The field holds normal units ("49.000"); the API takes minor units. More
  // decimals than the currency has is refused by parseMoney, never rounded.
  let parsed = 0;
  let amountIsUsable = false;
  if (amount.trim() !== '' && /^[A-Z]{3}$/.test(currency)) {
    try {
      parsed = parseMoney(amount, currency);
      amountIsUsable = parsed >= 0;
    } catch {
      amountIsUsable = false;
    }
  }

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('billing.price.dialog.title', 'Price “{plan}”', { plan: plan.name })}</DialogTitle>
          <DialogDescription>
            {t(
              'billing.price.dialog.description',
              'A plan may carry one live price per currency, period and seat basis. Retire the existing one before replacing it.'
            )}
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-3">
          <label className="block space-y-1">
            <span className="text-sm">{t('billing.price.currency', 'Currency (ISO code)')}</span>
            <Input
              value={currency}
              maxLength={3}
              data-testid="price-currency"
              onChange={(e) => setCurrency(e.target.value.toUpperCase())}
            />
          </label>

          <label className="block space-y-1">
            <span className="text-sm">{t('billing.price.amount', 'Price')}</span>
            <MoneyInput
              currency={currency}
              value={amount}
              data-testid="price-amount"
              onValueChange={setAmount}
              tooManyDecimalsLabel={(decimals, code) =>
                t('billing.price.tooManyDecimals', 'No more than {decimals} decimals for {currency}.', {
                  decimals,
                  currency: code,
                })
              }
            />
            {amount.trim() !== '' && !amountIsUsable && (
              <span className="text-xs text-destructive" data-testid="price-amount-error">
                {t('billing.price.amountInvalid', 'Enter the price as an amount, for example 49.000.')}
              </span>
            )}
          </label>

          <label className="block space-y-1">
            <span className="text-sm">{t('billing.price.period', 'Billing period')}</span>
            <Select value={period} onValueChange={setPeriod}>
              <SelectTrigger data-testid="price-period">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {PERIODS.map((p) => (
                  <SelectItem key={p} value={p}>
                    {t(`billing.price.period.${p}`, p)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </label>

          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={perSeat}
              data-testid="price-per-seat"
              onChange={(e) => setPerSeat(e.target.checked)}
            />
            {t('billing.price.perSeatLabel', 'Charge this amount per seat')}
          </label>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            {t('common.cancel', 'Cancel')}
          </Button>
          <Button
            disabled={!amountIsUsable || saving}
            data-testid="price-save"
            onClick={async () => {
              setSaving(true);
              const res = await apiClient(`/api/v1/plans/${plan.id}/prices`, {
                method: 'POST',
                body: JSON.stringify({
                  currency,
                  unit_amount: parsed,
                  billing_period: period,
                  is_per_seat: perSeat,
                }),
              });
              setSaving(false);

              if (!res.ok) {
                const body = (await res.json().catch(() => null)) as { error?: string } | null;
                // The server's own words. A 409 explains that a live price
                // already exists on these terms and what to do about it, and no
                // rewording here would be more accurate than that.
                addToast(body?.error ?? t('billing.price.saveFailed', 'Could not save this price.'), 'error');
                return;
              }

              addToast(t('billing.price.saved', 'Price added.'), 'success');
              onSaved();
            }}
          >
            {t('billing.price.save', 'Add price')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

/** Plans have been creatable through the API since the catalogue shipped; this is the screen for it. */
function CreatePlanDialog({ onClose, onSaved }: { onClose: () => void; onSaved: () => void }) {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const t = useTranslation('admin');
  const [name, setName] = useState('');
  const [description, setDescription] = useState('');
  const [key, setKey] = useState('');
  const [saving, setSaving] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);

  // People name a plan; the key is made from the name unless they set it (technical details).
  const planKey =
    key.trim() !== '' ? key.trim() : name.trim().toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');

  const save = async () => {
    setSaving(true);
    setProblem(null);
    try {
      const res = await apiClient('/api/v1/plans', {
        method: 'POST',
        body: JSON.stringify({
          plan_key: planKey,
          name: name.trim(),
          description: description.trim() === '' ? null : description.trim(),
        }),
      });
      if (!res.ok) {
        const body = (await res.json().catch(() => null)) as { error?: string; details?: Record<string, string> } | null;
        const detail = body?.details ? Object.values(body.details)[0] : undefined;
        setProblem(detail ?? body?.error ?? t('billing.plan.saveFailed', 'Could not create this plan.'));
        return;
      }
      addToast(t('billing.plan.saved', 'Plan created. Give it a price next.'), 'success');
      onSaved();
    } finally {
      setSaving(false);
    }
  };

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('billing.plan.dialog.title', 'New plan')}</DialogTitle>
          <DialogDescription>
            {t('billing.plan.dialog.description', 'A plan is a tier: what a workspace gets. You add its price after.')}
          </DialogDescription>
        </DialogHeader>
        <div className="space-y-3">
          <label className="block space-y-1">
            <span className="text-sm">{t('billing.plan.name', 'Name')}</span>
            <Input value={name} data-testid="plan-name" onChange={(e) => setName(e.target.value)} />
          </label>
          <label className="block space-y-1">
            <span className="text-sm">{t('billing.plan.description', 'Description (optional)')}</span>
            <Input value={description} data-testid="plan-description" onChange={(e) => setDescription(e.target.value)} />
          </label>
          <TechnicalDetails>
            <label className="block space-y-1">
              <span className="text-sm">{t('billing.plan.key', 'Key')}</span>
              <Input value={key} dir="ltr" placeholder={planKey} data-testid="plan-key" onChange={(e) => setKey(e.target.value)} />
            </label>
          </TechnicalDetails>
          {problem && (
            <p className="text-sm text-destructive" data-testid="plan-error">
              {problem}
            </p>
          )}
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            {t('common.cancel', 'Cancel')}
          </Button>
          <Button disabled={name.trim() === '' || planKey === '' || saving} data-testid="plan-save" onClick={() => void save()}>
            {t('billing.plan.create', 'Create plan')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
