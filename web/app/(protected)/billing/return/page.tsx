'use client';

import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { Suspense, useEffect } from 'react';
import { apiFetch } from '@/lib/api/client';
import { useOptionalAuth } from '@/lib/auth-context';
import { useFetch } from '@/hooks/useFetch';
import { PublicOutcome } from '@/components/auth/public-outcome';
import { Button } from '@whity/ui/button';
import { useDateDisplay } from '@whity/features/datetime';
import { useTranslation } from '@whity/features/i18n';

/**
 * WHERE A PAYER LANDS COMING BACK FROM PAYING.
 *
 * THE QUERY STRING ON THIS PAGE IS EVIDENCE OF NOTHING. The payer arrives at
 * `?checkout=<ref>&status=<s>` in a browser they control, and anyone can type
 * `status=completed` into an address bar. Nothing in it is signed and nothing
 * about it proves a payment happened.
 *
 * So this screen renders NOTHING from those parameters. It passes `checkout`
 * through to the server, which re-reads the answer from the billing service and
 * returns what is actually true; every word below comes from that reply. The
 * `status` parameter is not read at all — not even to pick a message, because a
 * message is a claim too, and a screen that says "payment successful" on the
 * strength of a URL is a screen that lies to whoever forged it.
 *
 * WHAT THE SERVER DOES WITH THE VISIT MATTERS AS MUCH AS WHAT IT RETURNS: the
 * same request records the authoritative answer locally, so simply arriving
 * here repairs a missed notification for this tenant.
 *
 * RTL COMES FROM LOGICAL PROPERTIES, not a direction check — `ms-`/`me-`/
 * `text-start` rather than left/right — so the layout mirrors itself under
 * DirectionProvider with no branch to keep in step.
 */

interface BillingReturn {
  has_access: boolean;
  status: string | null;
  plan: string | null;
  access_until: string | null;
  cancel_at_period_end: boolean;
  checkout_status: string | null;
}

/**
 * What the billing service calls a checkout that ended without a payment. Read
 * for WORDING only (the server's own note on `checkout_status`): access is
 * decided by `has_access` alone.
 */
const ENDED_UNPAID = new Set(['failed', 'declined', 'expired', 'canceled', 'cancelled']);

/** Checkouts whose return already refreshed the session, for this page load. */
const refreshedCheckouts = new Set<string>();

function BillingReturnContent() {
  const t = useTranslation('admin');
  const dates = useDateDisplay();
  const searchParams = useSearchParams();

  // Passed through to the server, never trusted here. It only names WHICH
  // session to ask about; the server decides what it means.
  const reference = searchParams.get('checkout') ?? '';

  const { data, loading, error } = useFetch(async () => {
    // `checkout` is accepted by the server but missing from the spec, so this goes through apiFetch.
    const query = reference !== '' ? `?checkout=${encodeURIComponent(reference)}` : '';
    const res = await apiFetch(`/api/v1/billing/return${query}`);
    const body = res.ok ? ((await res.json().catch(() => undefined)) as unknown) : undefined;
    if (!res.ok) {
      throw new Error(
        t('billing.return.error.load', 'We could not confirm your payment just now.')
      );
    }
    return (body as { data?: BillingReturn } | undefined)?.data as BillingReturn;
  }, [reference]);

  // A paid workspace: banners, limits and Billing refresh here and in every tab
  // (2026-11-22). Once per checkout, since the refresh remounts this page.
  const auth = useOptionalAuth();
  const sessionChanged = auth?.sessionChanged;
  const hasAccess = data?.has_access === true;
  useEffect(() => {
    if (!hasAccess || sessionChanged === undefined || refreshedCheckouts.has(reference)) return;
    refreshedCheckouts.add(reference);
    void sessionChanged();
  }, [hasAccess, reference, sessionChanged]);

  const toBilling = (variant: 'default' | 'outline') => (
    <Button asChild size="lg" variant={variant} className="w-full">
      <Link href="/billing">{t('billing.return.toBilling', 'Go to billing')}</Link>
    </Button>
  );

  // Checking is dashed (not yet) and says people can leave (TplPaymentReturn note 3).
  if (loading) {
    return (
      <PublicOutcome
        testId="billing-return-checking"
        icon="clock"
        tone="dash"
        title={t('billing.return.checking', 'Checking your payment…')}
        description={t(
          'billing.return.checking.detail',
          'Confirming with the billing service. This takes a few seconds; you can leave this page.'
        )}
      />
    );
  }

  // AN ERROR IS NOT A REFUSAL. Reaching the billing service can fail while the
  // payment is perfectly fine, so this says what is actually known — that we
  // could not check — and points at the page that will show the real state,
  // rather than announcing a failure the payer did not have.
  if (error !== null || data === null) {
    return (
      <PublicOutcome
        testId="billing-return-unknown"
        icon="clock"
        tone="warn"
        title={t('billing.return.unknown', 'Not confirmed yet')}
        description={t(
          'billing.return.unknown.detail',
          'Your payment may still have gone through. We could not reach the billing service to confirm it just now, and your account will update by itself shortly.'
        )}
      >
        {toBilling('outline')}
      </PublicOutcome>
    );
  }

  if (data.has_access) {
    const renewalDate = dates.date(data.access_until);

    return (
      <PublicOutcome
        testId="billing-return-active"
        icon="success"
        tone="ok"
        title={t('billing.return.active', 'Your subscription is active')}
        description={
          data.plan !== null
            ? t('billing.return.active.detail', 'Thanks. You’re on {plan} from today.', { plan: data.plan })
            : t('billing.return.active.detailPlain', 'Thanks. Your payment went through.')
        }
      >
        <dl className="grid gap-2 text-sm">
          {data.plan !== null && (
            <div className="grid grid-cols-[minmax(6rem,auto)_1fr] gap-4">
              <dt className="text-muted-foreground">{t('billing.return.plan', 'Plan')}</dt>
              <dd className="text-foreground">{data.plan}</dd>
            </div>
          )}
          {/* FORMATTED IN THE READER'S RESOLVED LANGUAGE, not the browser's.
              `toLocaleDateString()` would use whatever locale the device
              happens to be set to, which on an Arabic deployment is routinely
              not the one the reader chose.

              When the reader has dates hidden, `date()` answers null and the
              WHOLE ROW goes — a label with an em dash beside it is worse than
              no label, and the fallback would print the wire timestamp the
              formatter just declined to print. */}
          {renewalDate !== null && (
            <div className="grid grid-cols-[minmax(6rem,auto)_1fr] gap-4">
              <dt className="text-muted-foreground">
                {/* `cancel_at_period_end` does NOT mean locked now: access is live
                    until this date, so the wording changes but nothing stops. */}
                {data.cancel_at_period_end
                  ? t('billing.return.endsOn', 'Your plan ends on')
                  : t('billing.return.renewsOn', 'Renews on')}
              </dt>
              <dd className="text-foreground">{renewalDate}</dd>
            </div>
          )}
        </dl>
        {toBilling('default')}
      </PublicOutcome>
    );
  }

  // No access. Said without blaming the payer for it: a card can be declined
  // after a 3-D Secure challenge is passed, and a push transfer can still be
  // settling, so "not active yet" is both kinder and more accurate than
  // "payment failed" — unless the billing service itself said the checkout
  // ended unpaid, and even then the words say what happens next, never "error".
  if (data.checkout_status !== null && ENDED_UNPAID.has(data.checkout_status)) {
    return (
      <PublicOutcome
        testId="billing-return-failed"
        icon="card"
        tone="muted"
        title={t('billing.return.failed', 'The payment didn’t go through')}
        description={t(
          'billing.return.failed.detail',
          'Nothing has changed on your plan. Try again, or use another card.'
        )}
      >
        <Button asChild size="lg" className="w-full">
          <Link href="/billing">{t('billing.return.tryAgain', 'Try again')}</Link>
        </Button>
      </PublicOutcome>
    );
  }

  return (
    <PublicOutcome
      testId="billing-return-not-active"
      icon="clock"
      tone="warn"
      title={t('billing.return.notActive', 'Not confirmed yet')}
      description={t(
        'billing.return.notActive.detail',
        'Your payment hasn’t been confirmed yet, and nothing has changed on your plan. If you have just paid, this can take a moment; your account will update by itself.'
      )}
    >
      {toBilling('outline')}
    </PublicOutcome>
  );
}

export default function BillingReturnPage() {
  // One card, centred in the app, with one answer (TplPaymentReturn note 1).
  return (
    <div className="mx-auto w-full max-w-md py-6">
      {/* useSearchParams needs a Suspense boundary to avoid opting the whole
          route into client-side rendering at build time. */}
      <Suspense fallback={null}>
        <BillingReturnContent />
      </Suspense>
    </div>
  );
}
