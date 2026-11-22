'use client';

/**
 * The PUBLIC quote page (WHIT-1342, design section 34 §3.3, register QT.5):
 * what the quote email's button and the printed quote's QR open.
 *
 * It lives outside the `(protected)` group, so it needs no sign-in, and it
 * wears the workspace's logo and colour (SU.1, SU.10) through `PublicShell`.
 * The customer sees the quote (its lines, totals and terms, and how long it
 * is valid) and answers it: Accept, typing their name, or Decline, with a
 * reason if they want to give one. Once answered, the page says so.
 *
 * Every other state is said in plain words and says what to do next, never
 * "error" (SU.10): a link that opens nothing any more, a quote that has
 * expired, been withdrawn or replaced by a newer revision, too many tries
 * from one place (the endpoints are rate-limited), or the server not
 * answering.
 *
 * The page mirrors from `<html dir>`; numbers, the quote's number and amounts
 * stay Western digits, left to right.
 */

import { use, useEffect, useState } from 'react';
import { PublicShell } from '@/components/auth/public-shell';
import { OutcomeIcon, type OutcomeTone } from '@/components/auth/public-outcome';
import { Input } from '@/components/ui/input';
import { UserText } from '@/components/ui/user-text';
import type { WorkspaceBrand } from '@/lib/branding-context';
import { api } from '@/lib/api/client';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Button } from '@whity/ui/button';
import { Card, CardContent } from '@whity/ui/card';
import type { IconName } from '@whity/ui/icon';
import { Money } from '@whity/ui/money/money';
import { Textarea } from '@whity/ui/textarea';
import { DateDisplayProvider, useDateDisplay } from '@whity/features/datetime';
import { useTranslation } from '@whity/features/i18n';
import type { components } from '@whity/api-client';

type PublicQuote = Omit<components['schemas']['PublicSalesQuote'], 'workspace'> & {
  workspace: { name: string; brand?: WorkspaceBrand | null };
};

type Phase =
  | { kind: 'loading' }
  | { kind: 'gone' }
  | { kind: 'busy' }
  | { kind: 'failed' }
  | { kind: 'ready'; quote: PublicQuote };

/** "2.500" as "2.5": quantities read best without trailing zeros. */
function quantityText(decimal: string): string {
  if (!decimal.includes('.')) return decimal;
  return decimal.replace(/0+$/, '').replace(/\.$/, '');
}

function serverText(error: unknown): string | null {
  const text = (error as { error?: unknown } | null | undefined)?.error;
  return typeof text === 'string' && text !== '' ? text : null;
}

export default function PublicQuotePage({ params }: { params: Promise<{ token: string }> }) {
  const { token } = use(params);
  const t = useTranslation('auth');
  const [phase, setPhase] = useState<Phase>({ kind: 'loading' });
  const [reload, setReload] = useState(0);

  useEffect(() => {
    let live = true;
    if (!/^[a-f0-9]{64}$/.test(token)) {
      queueMicrotask(() => live && setPhase({ kind: 'gone' }));
      return () => {
        live = false;
      };
    }
    api
      .GET('/api/v1/public/quotes/{token}', { params: { path: { token } } })
      .then(({ data, response }) => {
        if (!live) return;
        if (response.status === 404) setPhase({ kind: 'gone' });
        else if (response.status === 429) setPhase({ kind: 'busy' });
        else if (!response.ok || !data) setPhase({ kind: 'failed' });
        else setPhase({ kind: 'ready', quote: (data as { data: PublicQuote }).data });
      })
      .catch(() => live && setPhase({ kind: 'failed' }));
    return () => {
      live = false;
    };
  }, [token, reload]);

  const brand = phase.kind === 'ready' ? (phase.quote.workspace.brand ?? null) : null;

  return (
    <PublicShell brand={brand} wide>
      <DateDisplayProvider hidden={false}>
        {phase.kind === 'loading' && <div className="h-64 animate-pulse rounded-lg bg-muted" aria-busy="true" aria-label={t('quote.loading', 'Opening the quote…')} />}
        {phase.kind === 'gone' && (
          <Outcome
            icon="link"
            tone="muted"
            title={t('quote.gone.title', 'This link doesn’t open a quote')}
            body={t('quote.gone.body', 'It may have been replaced by a newer link. Ask the business that sent it for a new one.')}
          />
        )}
        {phase.kind === 'busy' && (
          <Outcome
            icon="clock"
            tone="warn"
            title={t('quote.busy.title', 'Too many tries from here')}
            body={t('quote.busy.body', 'Wait a minute, then open the link again.')}
          />
        )}
        {phase.kind === 'failed' && (
          <Outcome
            icon="sync"
            tone="warn"
            title={t('quote.failed.title', 'The quote didn’t open')}
            body={t('quote.failed.body', 'Check your connection and try again.')}
            action={<Button onClick={() => setReload((n) => n + 1)}>{t('quote.failed.retry', 'Try again')}</Button>}
          />
        )}
        {phase.kind === 'ready' && <QuoteView token={token} quote={phase.quote} onAnswered={(quote) => setPhase({ kind: 'ready', quote })} />}
      </DateDisplayProvider>
    </PublicShell>
  );
}

function Outcome({ icon, tone, title, body, action }: { icon: IconName; tone: OutcomeTone; title: string; body: string; action?: React.ReactNode }) {
  return (
    <Card>
      <CardContent className="space-y-4 py-8 text-center" data-testid="quote-outcome">
        <OutcomeIcon name={icon} tone={tone} className="mx-auto" />
        <h1 className="text-xl font-semibold">{title}</h1>
        <p className="text-muted-foreground">{body}</p>
        {action}
      </CardContent>
    </Card>
  );
}

function QuoteView({ token, quote, onAnswered }: { token: string; quote: PublicQuote; onAnswered: (quote: PublicQuote) => void }) {
  const t = useTranslation('auth');
  const dates = useDateDisplay();
  const [answer, setAnswer] = useState<'accept' | 'decline' | null>(null);
  const [name, setName] = useState('');
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const business = quote.workspace.name || t('quote.theBusiness', 'the business');
  const validUntil = dates.date(quote.valid_until) ?? '';
  const open = quote.status === 'sent' && !quote.replaced;

  const send = async () => {
    if (answer === 'accept' && name.trim() === '') {
      setProblem(t('quote.needName', 'Type your name to accept the quote.'));
      return;
    }
    setBusy(true);
    setProblem(null);
    try {
      const result =
        answer === 'accept'
          ? await api.POST('/api/v1/public/quotes/{token}/accept', { params: { path: { token } }, body: { name: name.trim() } })
          : await api.POST('/api/v1/public/quotes/{token}/decline', { params: { path: { token } }, body: { reason: reason.trim() } });
      if (result.response.ok && result.data) {
        onAnswered({ ...quote, ...(result.data as { data: Omit<PublicSalesQuoteData, 'workspace'> }).data, workspace: quote.workspace });
        return;
      }
      if (result.response.status === 429) {
        setProblem(t('quote.busy.body', 'Wait a minute, then open the link again.'));
      } else if (result.response.status === 404) {
        setProblem(t('quote.gone.body', 'It may have been replaced by a newer link. Ask the business that sent it for a new one.'));
      } else {
        // 409 and 422 carry the server's sentence: the quote has been answered or has expired, or the name is too long.
        setProblem(serverText(result.error) ?? t('quote.sendFailed', 'Your answer didn’t reach {business}. Try again.', { business }));
      }
    } catch {
      setProblem(t('quote.sendFailed', 'Your answer didn’t reach {business}. Try again.', { business }));
    } finally {
      setBusy(false);
    }
  };

  let state: React.ReactNode = null;
  if (quote.replaced) {
    state = (
      <StateLine tone="muted" icon="sync" testId="quote-replaced">
        {t('quote.replaced', '{business} has sent a newer version of this quote. Open the newest link to answer.', { business })}
      </StateLine>
    );
  } else if (quote.status === 'accepted' || quote.status === 'converted') {
    state = (
      <StateLine tone="ok" icon="check" testId="quote-accepted">
        {quote.decided_by_name
          ? t('quote.accepted', 'Accepted by {name} on {date}. {business} will be in touch.', {
              name: quote.decided_by_name,
              date: dates.date(quote.decided_at ?? '') ?? '',
              business,
            })
          : t('quote.acceptedNoName', 'Accepted. {business} will be in touch.', { business })}
      </StateLine>
    );
  } else if (quote.status === 'declined') {
    state = (
      <StateLine tone="muted" icon="close" testId="quote-declined">
        {t('quote.declined', 'Declined on {date}. Thank you for letting {business} know.', { date: dates.date(quote.decided_at ?? '') ?? '', business })}
      </StateLine>
    );
  } else if (quote.status === 'expired') {
    state = (
      <StateLine tone="warn" icon="clock" testId="quote-expired">
        {t('quote.expired', 'This quote was valid until {date}. Ask {business} for a new one.', { date: validUntil, business })}
      </StateLine>
    );
  } else if (quote.status === 'cancelled') {
    state = (
      <StateLine tone="muted" icon="close" testId="quote-withdrawn">
        {t('quote.withdrawn', '{business} has withdrawn this quote. Ask them if you still need it.', { business })}
      </StateLine>
    );
  }

  return (
    <div className="space-y-6">
      <header className="space-y-1 text-center">
        <p className="text-sm text-muted-foreground">
          <UserText>{business}</UserText>
        </p>
        <h1 className="text-2xl font-semibold">
          {t('quote.title', 'Quote')}
          {quote.number && (
            <bdi dir="ltr" className="ms-2 font-mono">
              {quote.number}
            </bdi>
          )}
        </h1>
        <p className="text-muted-foreground">
          {t('quote.for', 'For {customer} · valid until {date}', { customer: quote.customer, date: validUntil })}
        </p>
      </header>

      {state}

      <Card>
        <CardContent className="p-0">
          <ul className="divide-y divide-border" data-testid="quote-public-lines">
            {quote.lines.map((line, i) => (
              <li key={i} className="flex items-start justify-between gap-4 px-5 py-3">
                <div className="min-w-0">
                  <UserText className="block">{line.description}</UserText>
                  <span className="text-sm text-muted-foreground">
                    <bdi dir="ltr" className="font-mono">
                      {quantityText(line.quantity)}
                      {line.unit ? ` ${line.unit}` : ''} × <Money minor={line.price_minor} currency={quote.currency} />
                    </bdi>
                    {(line.discount_bp ?? 0) > 0 && (
                      <span className="ms-2">
                        {t('quote.discount', 'Discount')}{' '}
                        <bdi dir="ltr" className="font-mono">
                          {(line.discount_bp ?? 0) / 100}%
                        </bdi>
                      </span>
                    )}
                  </span>
                </div>
                <Money minor={line.line_total_minor} currency={quote.currency} className="shrink-0" />
              </li>
            ))}
          </ul>
          <dl className="space-y-2 border-t border-border px-5 py-4 text-sm">
            <div className="flex justify-between">
              <dt className="text-muted-foreground">
                {quote.prices_include_tax ? t('quote.subtotalIncl', 'Subtotal (prices include tax)') : t('quote.subtotal', 'Subtotal')}
              </dt>
              <dd>
                <Money minor={quote.subtotal_minor ?? 0} currency={quote.currency} />
              </dd>
            </div>
            <div className="flex justify-between">
              <dt className="text-muted-foreground">{t('quote.tax', 'Sales tax')}</dt>
              <dd>
                <Money minor={quote.tax_minor ?? 0} currency={quote.currency} />
              </dd>
            </div>
            <div className="flex justify-between text-base font-semibold">
              <dt>{t('quote.total', 'Total')}</dt>
              <dd data-testid="quote-public-total">
                <Money minor={quote.total_minor} currency={quote.currency} />
              </dd>
            </div>
          </dl>
          {(quote.terms ?? '') !== '' && (
            <div className="border-t border-border px-5 py-4 text-sm">
              <p className="mb-1 font-medium">{t('quote.terms', 'Terms')}</p>
              <UserText className="whitespace-pre-line text-muted-foreground">{quote.terms ?? ''}</UserText>
            </div>
          )}
        </CardContent>
      </Card>

      {open && (
        <Card>
          <CardContent className="space-y-4 py-5" data-testid="quote-public-answer">
            {answer === null ? (
              <>
                <p className="text-center">{t('quote.ask', 'Would you like to go ahead with this quote?')}</p>
                <div className="flex flex-col gap-2 sm:flex-row-reverse sm:justify-center">
                  <Button size="lg" onClick={() => setAnswer('accept')} data-testid="quote-public-accept">
                    {t('quote.accept', 'Accept')}
                  </Button>
                  <Button size="lg" variant="outline" onClick={() => setAnswer('decline')} data-testid="quote-public-decline">
                    {t('quote.decline', 'Decline')}
                  </Button>
                </div>
              </>
            ) : (
              <form
                className="space-y-4"
                onSubmit={(e) => {
                  e.preventDefault();
                  void send();
                }}
              >
                {answer === 'accept' ? (
                  <Input
                    id="quote-public-name"
                    dir="auto"
                    autoComplete="name"
                    label={t('quote.name', 'Your name')}
                    helperText={t('quote.nameHint', 'Typing your name accepts the quote for {customer}.', { customer: quote.customer })}
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                  />
                ) : (
                  <div className="space-y-1.5">
                    <label htmlFor="quote-public-reason" className="text-sm font-medium">
                      {t('quote.reason', 'Why, if you’d like to say (optional)')}
                    </label>
                    <Textarea id="quote-public-reason" dir="auto" rows={3} value={reason} onChange={(e) => setReason(e.target.value)} />
                  </div>
                )}
                {problem && (
                  <Alert variant="destructive">
                    <AlertDescription>{problem}</AlertDescription>
                  </Alert>
                )}
                <div className="flex flex-col gap-2 sm:flex-row-reverse">
                  <Button type="submit" size="lg" loading={busy} data-testid="quote-public-send">
                    {answer === 'accept' ? t('quote.acceptConfirm', 'Accept the quote') : t('quote.declineConfirm', 'Decline the quote')}
                  </Button>
                  <Button
                    type="button"
                    size="lg"
                    variant="outline"
                    disabled={busy}
                    onClick={() => {
                      setAnswer(null);
                      setProblem(null);
                    }}
                  >
                    {t('quote.back', 'Back')}
                  </Button>
                </div>
              </form>
            )}
          </CardContent>
        </Card>
      )}
    </div>
  );
}

type PublicSalesQuoteData = components['schemas']['PublicSalesQuote'];

function StateLine({ tone, icon, testId, children }: { tone: OutcomeTone; icon: IconName; testId: string; children: React.ReactNode }) {
  return (
    <div className="flex items-center gap-3 rounded-lg border border-border bg-card p-4" data-testid={testId}>
      <OutcomeIcon name={icon} tone={tone} className="size-10 shrink-0" />
      <p>{children}</p>
    </div>
  );
}
