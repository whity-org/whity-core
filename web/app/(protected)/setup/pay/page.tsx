'use client';

import { useEffect, useRef, useState } from 'react';
import { useRouter } from 'next/navigation';
import { useAuth } from '@/lib/auth-context';
import { useCapabilities } from '@/hooks/useCapabilities';
import { BILLING_PAY } from '@/lib/capabilities';
import { templatePickerPath } from '@/lib/workspace-template';
import { fetchWorkspaceTrial, startWorkspaceCheckout, type WorkspaceTrial } from '@/lib/billing/workspace-trial';
import { Button } from '@whity/ui/button';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Card, CardContent } from '@whity/ui/card';
import { PageHeader } from '@whity/ui/page-header';
import { LoadingRows } from '@whity/ui/page-states';
import { moneyText } from '@whity/ui/money/currency';
import { useDateDisplay } from '@whity/features/datetime';
import { useTranslation } from '@whity/features/i18n';
import { useHomePath } from '@/lib/branding-context';

/**
 * The payment step after creating a further workspace (WHIT-1207, owner
 * 2026-11-18).
 *
 * The card is taken now and nothing is charged until the trial ends; "Not now"
 * keeps the trial, and the workspace turns read-only if it ends unpaid. Shown
 * only when the server says a card can be added this way (`card_step`): a
 * provider is set up that can start a trial, the trial still runs and no card
 * is on file. Otherwise, and for anyone who can't pay, it goes straight on to
 * where creating a workspace always went (the template picker, else the
 * dashboard). The first, free workspace never comes here.
 *
 * No card for this screen yet: built from TplWelcomeWorkspace's create step and
 * TplBilling's plan summary, like /workspaces/new.
 */
export default function WorkspacePaymentStepPage() {
  const t = useTranslation('common');
  const router = useRouter();
  const homePath = useHomePath();
  const dates = useDateDisplay();
  const { user, memberships } = useAuth();
  const { hasPermission, loading: capabilitiesLoading } = useCapabilities();
  const [trial, setTrial] = useState<WorkspaceTrial | null | undefined>(undefined);
  const [starting, setStarting] = useState(false);
  const [failed, setFailed] = useState(false);
  const [leaving, setLeaving] = useState(false);

  const canPay = !capabilitiesLoading && hasPermission(BILLING_PAY);

  useEffect(() => {
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
  }, [user?.tenant_id]);

  // Once only: the effect below and the Not now button both lead on.
  const movedOn = useRef(false);
  const onward = async () => {
    if (movedOn.current) return;
    movedOn.current = true;
    router.replace((await templatePickerPath()) ?? homePath);
  };
  const goOn = () => {
    setLeaving(true);
    void onward();
  };

  const offered = trial !== undefined && trial !== null && trial.card_step && trial.price !== null && canPay;

  useEffect(() => {
    if (trial === undefined || capabilitiesLoading || offered) return;
    void onward();
    // eslint-disable-next-line react-hooks/exhaustive-deps -- once the answer is in
  }, [trial, capabilitiesLoading, offered]);

  if (!offered || trial === null || trial === undefined || trial.price === null) {
    return <LoadingRows label={t('workspacePay.loading', 'Getting your workspace ready')} headers={[]} />;
  }

  const workspace =
    memberships.find((m) => m.tenant_id === user?.tenant_id)?.tenant_name || t('workspacePay.thisWorkspace', 'This workspace');
  const price = moneyText(trial.price.unit_amount, trial.price.currency);
  const endDate = dates.date(trial.trial_ends_at);
  const days = trial.days_left ?? 0;

  // "{price}" is a hole so the amount stays left to right inside Arabic (CU.2).
  const chargeSentence =
    endDate !== null
      ? t('workspacePay.charge', 'We’ll charge {price} a month from {date}. Cancel before then and you pay nothing.', { date: endDate })
      : t('workspacePay.chargeNoDate', 'We’ll charge {price} a month when the trial ends. Cancel before then and you pay nothing.');
  const [before, after] = chargeSentence.split('{price}');

  const addCard = async () => {
    setStarting(true);
    setFailed(false);
    const problem = await startWorkspaceCheckout(trial.plan_key, 'setup');
    if (problem !== null) {
      setFailed(true);
      setStarting(false);
    }
  };

  return (
    <div className="mx-auto max-w-xl space-y-6" data-testid="workspace-pay-step">
      <PageHeader
        title={t('workspacePay.title', 'Add a card for {workspace}', { workspace })}
        description={t('workspacePay.description', 'Your {days}-day trial of {plan} is free.', { days, plan: trial.plan_name })}
      />
      <Card>
        <CardContent className="space-y-4 p-6">
          <p className="text-base text-foreground">
            {before}
            <bdi dir="ltr" className="font-mono" data-testid="workspace-pay-price">
              {price}
            </bdi>
            {after}
          </p>
          <p className="text-sm text-muted-foreground">
            {t(
              'workspacePay.notNow',
              'Not now keeps the trial. Without a card, the workspace becomes read-only when the trial ends, until it’s paid. Nothing is deleted.'
            )}
          </p>
          {failed && (
            <Alert variant="destructive" data-testid="workspace-pay-error">
              <AlertDescription>
                {t('workspacePay.error', 'The card page couldn’t be opened. Try again, or choose Not now and add a card later on Billing.')}
              </AlertDescription>
            </Alert>
          )}
          <div className="flex flex-wrap gap-3">
            <Button onClick={() => void addCard()} disabled={starting || leaving}>
              {starting ? t('workspacePay.opening', 'Opening the card page…') : t('workspacePay.addCard', 'Add a card')}
            </Button>
            <Button variant="outline" onClick={goOn} disabled={starting || leaving}>
              {t('workspacePay.skip', 'Not now')}
            </Button>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
