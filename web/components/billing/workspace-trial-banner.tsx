'use client';

import { useEffect, useState } from 'react';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useAuth } from '@/lib/auth-context';
import { BILLING_PAY } from '@/lib/capabilities';
import {
  REMINDER_HIDE_HOURS,
  fetchWorkspaceTrial,
  reminderHidden,
  reminderHideKey,
  startWorkspaceCheckout,
  trialBannerKind,
  type WorkspaceTrial,
} from '@/lib/billing/workspace-trial';
import { Button } from '@whity/ui/button';
import { Icon } from '@whity/ui/icon';
import { moneyText } from '@whity/ui/money/currency';
import { useDateDisplay } from '@whity/features/datetime';
import { useTranslation, type TranslateFn } from '@whity/features/i18n';

/**
 * A line across the app about a further workspace's trial (WHIT-1207, owner
 * 2026-11-18), styled like the plan-limit banner (TplPlanLimit).
 *
 * - Read-only (the trial ended unpaid): everyone sees it. People who can pay
 *   get Pay, straight to checkout; everyone else is told to ask an owner.
 *   Nothing was deleted, and it says so. It can't be hidden.
 * - Reminder: from 7 days before the trial ends, only for people who can pay
 *   and only while no card is on file. Add a card keeps the rest of the trial
 *   free. "Not now" hides it on this device for a day.
 */
export function WorkspaceTrialBanner() {
  const t = useTranslation('admin');
  const dates = useDateDisplay();
  const { user, memberships } = useAuth();
  const { hasPermission, loading } = useCapabilities();
  const tenantId = user?.tenant_id ?? null;
  const canPay = !loading && hasPermission(BILLING_PAY);
  const hideKey = reminderHideKey(tenantId);
  const [trial, setTrial] = useState<WorkspaceTrial | null>(null);
  const [hidden, setHidden] = useState(true);
  const [starting, setStarting] = useState(false);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    let stored: string | null = null;
    try {
      stored = window.localStorage.getItem(hideKey);
    } catch {
      stored = null;
    }
    const timer = setTimeout(() => setHidden(reminderHidden(stored)), 0);
    return () => clearTimeout(timer);
  }, [hideKey]);

  useEffect(() => {
    if (tenantId === null) return;
    let live = true;
    fetchWorkspaceTrial()
      .then((state) => {
        if (live) setTrial(state);
      })
      .catch(() => {
        if (live) setTrial(null);
      });
    return () => {
      live = false;
    };
  }, [tenantId]);

  const kind = loading ? null : trialBannerKind(trial, canPay);
  if (kind === null || trial === null || (kind === 'reminder' && hidden)) return null;

  const workspace =
    memberships.find((m) => m.tenant_id === tenantId)?.tenant_name || t('billing.trial.yourWorkspace', 'Your workspace');
  const payOnline = trial.pay_online && trial.price !== null;

  const pay = async () => {
    setStarting(true);
    setFailed(false);
    const problem = await startWorkspaceCheckout(trial.plan_key);
    if (problem !== null) {
      setFailed(true);
      setStarting(false);
    }
  };

  const hide = () => {
    try {
      window.localStorage.setItem(hideKey, new Date(Date.now() + REMINDER_HIDE_HOURS * 3_600_000).toISOString());
    } catch {
      // Storage refused: it hides for this visit only.
    }
    setHidden(true);
  };

  const action = (label: string) =>
    canPay && payOnline ? (
      <Button size="sm" variant="outline" onClick={() => void pay()} disabled={starting} data-testid="workspace-trial-pay">
        {starting ? t('billing.trial.opening', 'Opening…') : label}
      </Button>
    ) : null;

  return (
    <div
      role="status"
      data-testid={kind === 'read_only' ? 'workspace-read-only-banner' : 'workspace-trial-reminder'}
      className="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-border bg-warning-tint px-6 py-2 text-sm text-foreground md:px-8"
    >
      <Icon name={kind === 'read_only' ? 'lock' : 'clock'} size={18} className="text-warning-text" />
      <span className="min-w-0 flex-1">
        {kind === 'read_only' ? (
          <ReadOnlyWords t={t} trial={trial} workspace={workspace} canPay={canPay} payOnline={payOnline} />
        ) : (
          <ReminderWords t={t} trial={trial} workspace={workspace} endDate={dates.date(trial.trial_ends_at)} payOnline={payOnline} />
        )}
        {failed && (
          <span className="ms-1 text-destructive" data-testid="workspace-trial-error">
            {t('billing.trial.error', 'The payment page couldn’t be opened. Try again, or go to Billing.')}
          </span>
        )}
      </span>
      {kind === 'read_only'
        ? action(t('billing.trial.pay', 'Pay'))
        : action(trial.card_step ? t('billing.trial.addCard', 'Add a card') : t('billing.trial.pay', 'Pay'))}
      {kind === 'reminder' && (
        <Button size="sm" variant="ghost" onClick={hide}>
          {t('billing.trial.notNow', 'Not now')}
        </Button>
      )}
    </div>
  );
}

/** The amount inside a sentence: a hole, isolated left to right (CU.2). */
function withPrice(sentence: string, trial: WorkspaceTrial) {
  const [before, after] = sentence.split('{price}');
  if (after === undefined || trial.price === null) return <>{sentence}</>;

  return (
    <>
      {before}
      <bdi dir="ltr" className="font-mono">
        {moneyText(trial.price.unit_amount, trial.price.currency)}
      </bdi>
      {after}
    </>
  );
}

function ReadOnlyWords({
  t,
  trial,
  workspace,
  canPay,
  payOnline,
}: {
  t: TranslateFn;
  trial: WorkspaceTrial;
  workspace: string;
  canPay: boolean;
  payOnline: boolean;
}) {
  const next = !canPay
    ? t('billing.trial.readOnly.askOwner', 'Ask an owner to pay so changes can be made again.')
    : payOnline
      ? t('billing.trial.readOnly.pay', 'Pay {price} a month to make changes again.')
      : t('billing.trial.readOnly.offline', 'Ask the people who run this Whity how to pay.');

  return (
    <>
      <span className="font-semibold">
        {t('billing.trial.readOnly.title', '{workspace} is read-only.', { workspace })}
      </span>{' '}
      {t('billing.trial.readOnly.why', 'The trial of {plan} ended without payment. Nothing was deleted.', { plan: trial.plan_name })}{' '}
      {withPrice(next, trial)}
    </>
  );
}

function ReminderWords({
  t,
  trial,
  workspace,
  endDate,
  payOnline,
}: {
  t: TranslateFn;
  trial: WorkspaceTrial;
  workspace: string;
  endDate: string | null;
  payOnline: boolean;
}) {
  const days = trial.days_left ?? 0;
  const title =
    days <= 1
      ? t('billing.trial.reminder.titleOne', 'The free trial of {workspace} ends tomorrow.', { workspace })
      : t('billing.trial.reminder.title', 'The free trial of {workspace} ends in {days} days.', { workspace, days });
  const next = !payOnline
    ? t('billing.trial.reminder.offline', 'To keep making changes after it, ask the people who run this Whity how to pay.')
    : endDate !== null
      ? t('billing.trial.reminder.next', 'Add a card to keep making changes after {date}; it’s {price} a month.', { date: endDate })
      : t('billing.trial.reminder.nextNoDate', 'Add a card to keep making changes after the trial; it’s {price} a month.');

  return (
    <>
      <span className="font-semibold">{title}</span> {withPrice(next, trial)}
    </>
  );
}
