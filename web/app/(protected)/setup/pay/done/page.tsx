'use client';

import { Suspense, useState } from 'react';
import Link from 'next/link';
import { useRouter, useSearchParams } from 'next/navigation';
import { apiFetch } from '@/lib/api/client';
import { useFetch } from '@/hooks/useFetch';
import { templatePickerPath } from '@/lib/workspace-template';
import { PAYMENT_STEP_PATH } from '@/lib/billing/workspace-trial';
import { PublicOutcome } from '@/components/auth/public-outcome';
import { Button } from '@whity/ui/button';
import { useDateDisplay } from '@whity/features/datetime';
import { useTranslation } from '@whity/features/i18n';
import { useHomePath } from '@/lib/branding-context';

/**
 * Back from adding a card in the payment step (WHIT-1207).
 *
 * Asks the server what happened (`/api/v1/billing/return`, which asks the
 * provider; the query string proves nothing), says so in plain words, and
 * carries on with setting the workspace up. A card that didn't go through
 * offers Try again and Not now; not being able to check yet is not a failure.
 */
interface BillingReturn {
  has_access: boolean;
  status: string | null;
  plan: string | null;
  access_until: string | null;
  cancel_at_period_end: boolean;
  checkout_status: string | null;
}

const ENDED_UNPAID = new Set(['failed', 'declined', 'expired', 'canceled', 'cancelled']);

function PaymentStepDoneContent() {
  const t = useTranslation('common');
  const router = useRouter();
  const homePath = useHomePath();
  const dates = useDateDisplay();
  const searchParams = useSearchParams();
  const reference = searchParams.get('checkout') ?? '';
  const [leaving, setLeaving] = useState(false);

  const { data, loading, error } = useFetch(async () => {
    const query = reference !== '' ? `?checkout=${encodeURIComponent(reference)}` : '';
    const res = await apiFetch(`/api/v1/billing/return${query}`);
    if (!res.ok) throw new Error('return');
    const body = (await res.json().catch(() => undefined)) as { data?: BillingReturn } | undefined;
    return body?.data as BillingReturn;
  }, [reference]);

  const goOn = async () => {
    setLeaving(true);
    router.replace((await templatePickerPath()) ?? homePath);
  };

  const continueButton = (
    <Button size="lg" className="w-full" onClick={() => void goOn()} disabled={leaving} data-testid="workspace-pay-continue">
      {t('workspacePay.done.continue', 'Continue setting up')}
    </Button>
  );

  if (loading) {
    return (
      <PublicOutcome
        testId="workspace-pay-checking"
        icon="clock"
        tone="dash"
        title={t('workspacePay.done.checking', 'Checking your card…')}
        description={t('workspacePay.done.checkingDetail', 'This takes a few seconds.')}
      />
    );
  }

  if (error !== null || !data) {
    return (
      <PublicOutcome
        testId="workspace-pay-unknown"
        icon="clock"
        tone="warn"
        title={t('workspacePay.done.unknown', 'Not confirmed yet')}
        description={t(
          'workspacePay.done.unknownDetail',
          'Your card may still have been added. We couldn’t confirm it just now; Billing will show it shortly.'
        )}
      >
        {continueButton}
      </PublicOutcome>
    );
  }

  if (data.has_access) {
    const until = dates.date(data.access_until);
    return (
      <PublicOutcome
        testId="workspace-pay-added"
        icon="success"
        tone="ok"
        title={t('workspacePay.done.added', 'Card added')}
        description={
          data.status === 'trialing' && until !== null
            ? t('workspacePay.done.addedTrial', 'Your trial stays free until {date}. The first charge is then.', { date: until })
            : t('workspacePay.done.addedPaid', 'Thanks. Your payment went through.')
        }
      >
        {continueButton}
      </PublicOutcome>
    );
  }

  const failed = data.checkout_status !== null && ENDED_UNPAID.has(data.checkout_status);
  return (
    <PublicOutcome
      testId={failed ? 'workspace-pay-failed' : 'workspace-pay-pending'}
      icon={failed ? 'card' : 'clock'}
      tone={failed ? 'muted' : 'warn'}
      title={failed ? t('workspacePay.done.failed', 'The card wasn’t added') : t('workspacePay.done.pending', 'Not confirmed yet')}
      description={
        failed
          ? t('workspacePay.done.failedDetail', 'Nothing was charged. Your trial goes on; you can add a card now or later on Billing.')
          : t('workspacePay.done.pendingDetail', 'The card is still being checked. Billing will show it shortly.')
      }
    >
      {failed && (
        <Button asChild size="lg" className="w-full">
          <Link href={PAYMENT_STEP_PATH}>{t('workspacePay.done.tryAgain', 'Try again')}</Link>
        </Button>
      )}
      {failed ? (
        <Button size="lg" variant="outline" className="w-full" onClick={() => void goOn()} disabled={leaving}>
          {t('workspacePay.skip', 'Not now')}
        </Button>
      ) : (
        continueButton
      )}
    </PublicOutcome>
  );
}

export default function PaymentStepDonePage() {
  return (
    <div className="mx-auto max-w-md py-8">
      <Suspense fallback={null}>
        <PaymentStepDoneContent />
      </Suspense>
    </div>
  );
}
