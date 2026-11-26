'use client';

/**
 * The page of one offered time (WHIT-1442, design section 39 §8, AP.23), from
 * the link in the waitlist email. One column, 17px, 48px targets (AP.30).
 *
 * Take this time books it: the first booking the database accepts wins. If
 * someone else was first, it says so plainly and they stay on the waitlist.
 * The workspace's brand, no sign-in.
 */

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { Button } from '@whity/ui/button';
import { Skeleton } from '@whity/ui/skeleton';
import { useTranslation } from '@whity/features/i18n';
import { safeTimeZone } from '@whity/features/datetime';
import { PublicShell } from '@/components/auth/public-shell';
import { OutcomeIcon } from '@/components/auth/public-outcome';
import { publicBookingApi, PublicBookingError, isManageToken, type PublicWaitlistOffer } from '@/lib/booking/public-api';
import { InYourZone, useWhenAt } from './booking-parts';
import { useBookingWords } from './use-booking-words';

type Phase = 'loading' | 'ready' | 'invalid' | 'error';
type Outcome = { kind: 'booked'; manageToken: string | null } | { kind: 'taken' | 'expired' | 'closed' };

export function WaitlistOfferScreen({ token }: { token: string }) {
  const t = useTranslation('booking');
  const words = useBookingWords();
  const [phase, setPhase] = useState<Phase>(isManageToken(token) ? 'loading' : 'invalid');
  const [offer, setOffer] = useState<PublicWaitlistOffer | null>(null);
  const [outcome, setOutcome] = useState<Outcome | null>(null);
  const [taking, setTaking] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const [attempt, setAttempt] = useState(0);

  useEffect(() => {
    if (!isManageToken(token)) return;
    let cancelled = false;
    publicBookingApi
      .offer(token)
      .then((found) => {
        if (cancelled) return;
        setOffer(found);
        setPhase('ready');
      })
      .catch((error) => {
        if (cancelled) return;
        setPhase(error instanceof PublicBookingError && error.status === 404 ? 'invalid' : 'error');
      });
    return () => {
      cancelled = true;
    };
  }, [token, attempt]);

  const timeZone = safeTimeZone(offer?.time_zone ?? offer?.workspace_time_zone ?? 'UTC');
  const whenAt = useWhenAt(timeZone);

  if (phase === 'loading') {
    return (
      <PublicShell>
        <Card aria-busy="true">
          <CardHeader>
            <Skeleton className="h-7 w-2/3" />
          </CardHeader>
          <CardContent>
            <span className="sr-only">{t('offer.loading', 'Loading the time…')}</span>
            <Skeleton className="h-24 rounded-card" />
          </CardContent>
        </Card>
      </PublicShell>
    );
  }
  if (phase !== 'ready' || offer === null) {
    return (
      <PublicShell>
        <Card className="text-base" data-testid={`offer-${phase}`}>
          <CardHeader className="flex items-center gap-4">
            <OutcomeIcon name={phase === 'invalid' ? 'search' : 'offline'} tone={phase === 'invalid' ? 'muted' : 'warn'} />
            <CardTitle className="text-xl font-semibold">
              {phase === 'invalid' ? t('offer.invalid', 'This link doesn’t work') : t('offer.failed', 'The time didn’t load')}
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            <CardDescription className="text-base">
              {phase === 'invalid'
                ? t('offer.invalidBody', 'It may be from an old email. Your latest email from the business has the right link.')
                : t('failed.description', 'Check your connection and try again.')}
            </CardDescription>
            {phase === 'error' && (
              <Button variant="outline" size="lg" onClick={() => { setPhase('loading'); setAttempt((n) => n + 1); }}>
                {t('action.retry', 'Try again')}
              </Button>
            )}
          </CardContent>
        </Card>
      </PublicShell>
    );
  }

  const state: string = outcome?.kind ?? offer.state;
  const take = async () => {
    setTaking(true);
    setProblem(null);
    try {
      const booked = await publicBookingApi.acceptOffer(token);
      setOutcome({ kind: 'booked', manageToken: booked.manage_token ?? null });
    } catch (error) {
      if (error instanceof PublicBookingError && error.status === 409) {
        setOutcome({ kind: error.code === 'expired' ? 'expired' : error.code === 'closed' ? 'closed' : 'taken' });
      } else {
        setProblem(words.errorText(error, t('offer.error', 'The time could not be booked. Try again.')));
      }
    } finally {
      setTaking(false);
    }
  };
  const serviceName = words.name(offer.service.name);

  return (
    <PublicShell brand={offer.brand ?? null}>
      <Card className="text-base" data-testid="offer-card">
        <CardHeader className="gap-3">
          <div className="flex items-center gap-4">
            {state === 'booked' && <OutcomeIcon name="success" tone="ok" />}
            {(state === 'taken' || state === 'expired' || state === 'closed') && <OutcomeIcon name="clock" tone="muted" />}
            <CardTitle className="text-xl font-semibold" data-testid="offer-title">
              {state === 'booked'
                ? t('offer.bookedTitle', 'You’re booked')
                : state === 'taken'
                  ? t('offer.takenTitle', 'Someone took this time first')
                  : state === 'expired'
                    ? t('offer.expiredTitle', 'This time can no longer be booked')
                    : state === 'closed'
                      ? t('offer.closedTitle', 'This offer has closed')
                      : t('offer.title', 'A time you wanted is free')}
            </CardTitle>
          </div>
          <CardDescription className="text-base" data-testid="offer-description">
            {state === 'booked'
              ? t('offer.bookedBody', 'We’ve emailed you the details and a link to change or cancel it.')
              : state === 'taken' || state === 'expired'
                ? t('offer.stillWaiting', 'You’re still on the waitlist. We’ll email you when another time frees up.')
                : state === 'closed'
                  ? t('offer.closedBody', 'You’re no longer on the waitlist for this.')
                  : t('offer.body', '{workspace} let everyone waiting for this know at once: the first to take it gets it.', { workspace: offer.workspace.name })}
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-5">
          <dl className="m-0 grid gap-3">
            <div>
              <dt className="text-sm text-muted-foreground">{t('offer.what', 'What')}</dt>
              <dd className="m-0 font-medium text-foreground">{serviceName}</dd>
            </div>
            <div>
              <dt className="text-sm text-muted-foreground">{t('offer.when', 'When')}</dt>
              <dd className="m-0 font-medium text-foreground" data-testid="offer-when">{whenAt(offer.starts_at)}</dd>
            </div>
            {offer.staff_name ? (
              <div>
                <dt className="text-sm text-muted-foreground">{t('offer.with', 'With')}</dt>
                <dd className="m-0 font-medium text-foreground">{offer.staff_name}</dd>
              </div>
            ) : null}
          </dl>
          <InYourZone item={offer} timeZone={timeZone} />
          {problem && <p className="m-0 text-destructive" role="alert">{problem}</p>}
          {state === 'open' && (
            <Button size="lg" className="w-full sm:w-auto" onClick={() => void take()} loading={taking} data-testid="offer-take">
              {t('offer.take', 'Take this time')}
            </Button>
          )}
          {state === 'booked' && outcome?.kind === 'booked' && outcome.manageToken && (
            <Link href={`/booking/${outcome.manageToken}`} className="inline-flex min-h-12 items-center font-medium text-primary hover:underline" data-testid="offer-manage">
              {t('offer.seeBooking', 'See your booking')}
            </Link>
          )}
        </CardContent>
      </Card>
    </PublicShell>
  );
}
