'use client';

/**
 * One quote in the customer area (WHIT-1416, design section 41 §8.3): the
 * quote as it was sent, and Accept or Decline while it is waiting. The
 * customer is signed in, so Accept answers in their own name.
 */

import Link from 'next/link';
import { use, useState } from 'react';
import { api } from '@/lib/api/client';
import { areaHref, useCustomerArea } from '@/lib/customer-area';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Button } from '@whity/ui/button';
import { Card, CardContent } from '@whity/ui/card';
import { Textarea } from '@whity/ui/textarea';
import { useDateDisplay } from '@whity/features/datetime';
import { useTranslation } from '@whity/features/i18n';
import type { components } from '@whity/api-client';
import { UserText } from '@/components/ui/user-text';
import { LoadState, PageTitle, serverText, useAreaLoad } from '@/components/customer-area/area-parts';
import { useBillWords } from '@/components/customer-area/area-rows';
import { DocumentLines } from '@/components/customer-area/document-parts';

type Quote = components['schemas']['CustomerAreaQuote'];
type QuoteLine = { description: string; quantity: string; price_minor: number; discount_bp?: number; line_total_minor: number };

export default function CustomerQuote({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const quoteId = Number(id);
  const t = useTranslation('customer');
  const { handle } = useCustomerArea();
  const [state, retry] = useAreaLoad<Quote>(
    () => api.GET('/api/v1/customer-area/{handle}/bills/quotes/{id}', { params: { path: { handle, id: quoteId } } }),
    [handle, quoteId],
  );

  return (
    <div className="space-y-5">
      <Link href={areaHref(handle, 'bills')} className="text-sm text-muted-foreground hover:underline">
        {t('doc.back', 'All bills')}
      </Link>
      <LoadState state={state} onRetry={retry} />
      {state.kind === 'ready' && <QuoteView handle={handle} quote={state.data} onAnswered={retry} />}
    </div>
  );
}

function QuoteView({ handle, quote, onAnswered }: { handle: string; quote: Quote; onAnswered: () => void }) {
  const t = useTranslation('customer');
  const dates = useDateDisplay();
  const words = useBillWords();
  const [answer, setAnswer] = useState<'decline' | null>(null);
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const waiting = quote.status === 'sent';
  const lines = (quote.lines as unknown as QuoteLine[]).map((l) => ({ ...l, total_minor: l.line_total_minor }));

  const send = async (accepted: boolean) => {
    setBusy(true);
    setProblem(null);
    const path = { handle, id: quote.id };
    const result = accepted
      ? await api.POST('/api/v1/customer-area/{handle}/bills/quotes/{id}/accept', { params: { path }, body: {} })
      : await api.POST('/api/v1/customer-area/{handle}/bills/quotes/{id}/decline', { params: { path }, body: { reason: reason.trim() } });
    setBusy(false);
    if (result.response.ok) {
      onAnswered();
    } else {
      setProblem(serverText(result.error) ?? t('quote.failed', 'Your answer didn’t reach the business. Try again.'));
    }
  };

  return (
    <>
      <PageTitle>
        {words.kind.quote}{' '}
        <bdi dir="ltr" className="font-mono">
          {quote.number}
        </bdi>
      </PageTitle>
      <p className="text-muted-foreground">
        {words.status({ kind: 'quote', status: quote.status, overdue: false, due_minor: 0 })} ·{' '}
        {t('quote.validUntil', 'Valid until {date}', { date: dates.date(quote.valid_until) ?? '' })}
      </p>
      <DocumentLines
        lines={lines}
        currency={quote.currency}
        subtotal={quote.subtotal_minor ?? 0}
        tax={quote.tax_minor ?? 0}
        total={quote.total_minor}
        pricesIncludeTax={quote.prices_include_tax ?? false}
      />
      {(quote.terms ?? '') !== '' && (
        <Card>
          <CardContent className="py-4 text-sm">
            <p className="mb-1 font-medium">{t('quote.terms', 'Terms')}</p>
            <UserText className="whitespace-pre-line text-muted-foreground">{quote.terms ?? ''}</UserText>
          </CardContent>
        </Card>
      )}
      {problem && (
        <Alert variant="destructive">
          <AlertDescription>{problem}</AlertDescription>
        </Alert>
      )}
      {waiting && answer === null && (
        <div className="flex flex-col gap-2 sm:flex-row max-sm:sticky max-sm:bottom-20 max-sm:bg-background max-sm:py-2">
          <Button size="lg" disabled={busy} onClick={() => void send(true)} data-testid="customer-quote-accept">
            {t('quote.accept', 'Accept')}
          </Button>
          <Button size="lg" variant="outline" onClick={() => setAnswer('decline')} data-testid="customer-quote-decline">
            {t('quote.decline', 'Decline')}
          </Button>
        </div>
      )}
      {waiting && answer === 'decline' && (
        <Card>
          <CardContent className="space-y-4 py-5">
            <Textarea
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              maxLength={1000}
              dir="auto"
              aria-label={t('quote.reason', 'Why, if you’d like to say (optional)')}
              placeholder={t('quote.reason', 'Why, if you’d like to say (optional)')}
            />
            <div className="flex flex-col gap-2 sm:flex-row">
              <Button size="lg" disabled={busy} onClick={() => void send(false)}>
                {t('quote.declineConfirm', 'Decline the quote')}
              </Button>
              <Button size="lg" variant="outline" onClick={() => setAnswer(null)}>
                {t('quote.back', 'Go back')}
              </Button>
            </div>
          </CardContent>
        </Card>
      )}
    </>
  );
}
